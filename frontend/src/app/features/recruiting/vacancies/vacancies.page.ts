import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatMenuModule } from '@angular/material/menu';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { VACANCY_STATUSES, Vacancy, VacancyTemplate } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { canWriteRecruiting } from '../recruiting.access';
import { VacanciesStore } from './vacancies.store';

/**
 * Vacancies in the user's branches: search, status and «Активні» filters (active = open AND published on /jobs, the
 * backend Vacancy::scopeActive rule), create/edit on the full form page (/vacancies/create, /vacancies/:id/edit),
 * «Створити з шаблону»; a row opens the kanban board.
 */
@Component({
  selector: 'app-vacancies-page',
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatMenuModule,
    MatPaginatorModule,
    MatProgressBarModule,
    MatSelectModule,
    RouterLink,
    TranslocoPipe,
  ],
  providers: [VacanciesStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'recruiting.vacancies.title' | transloco }}</h1>
        <p class="muted">{{ 'recruiting.vacancies.subtitle' | transloco }}</p>
        <p class="active-total" aria-live="polite">
          <span class="dot on" aria-hidden="true"></span>{{ 'recruiting.vacancies.activeTotal' | transloco: { n: store.activeCount() } }}
        </p>
      </div>
      @if (canWrite()) {
        <div class="head-actions">
          @if (templates().length > 0) {
            <button mat-stroked-button type="button" [matMenuTriggerFor]="tplMenu">
              <mat-icon>content_copy</mat-icon>{{ 'recruiting.form.fromTemplate' | transloco }}
            </button>
            <mat-menu #tplMenu="matMenu">
              @for (t of templates(); track t.id) {
                @if (t.can_manage) {
                  <button mat-menu-item type="button" [matMenuTriggerFor]="tplActions" [matMenuTriggerData]="{ t }">{{ t.name }}</button>
                } @else {
                  <button mat-menu-item type="button" (click)="fromTemplate(t)">{{ t.name }}</button>
                }
              }
            </mat-menu>
            <!-- Rename / delete only for the author or an admin (API can_manage, VacancyTemplatePolicy). -->
            <mat-menu #tplActions="matMenu">
              <ng-template matMenuContent let-t="t">
                <button mat-menu-item type="button" (click)="fromTemplate(t)">
                  <mat-icon>content_copy</mat-icon>{{ 'recruiting.form.useTemplate' | transloco }}
                </button>
                <button mat-menu-item type="button" (click)="renameTemplate(t)">
                  <mat-icon>edit</mat-icon>{{ 'recruiting.form.renameTemplate' | transloco }}
                </button>
                <button mat-menu-item type="button" (click)="deleteTemplate(t)">
                  <mat-icon>delete</mat-icon>{{ 'recruiting.form.deleteTemplate' | transloco }}
                </button>
              </ng-template>
            </mat-menu>
          }
          <a mat-flat-button routerLink="/vacancies/create">
            <mat-icon>add</mat-icon>{{ 'recruiting.vacancies.new' | transloco }}
          </a>
        </div>
      }
    </header>

    <div class="filters">
      <mat-form-field class="grow" subscriptSizing="dynamic">
        <mat-label>{{ 'recruiting.search' | transloco }}</mat-label>
        <mat-icon matPrefix>search</mat-icon>
        <input matInput type="search" #q (input)="search$.next(q.value)" />
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'recruiting.vacancies.fields.status' | transloco }}</mat-label>
        <mat-select [value]="store.query().status" (valueChange)="store.patchQuery({ status: $event })">
          <mat-option [value]="undefined">{{ 'common.all' | transloco }}</mat-option>
          @for (s of statuses; track s) {
            <mat-option [value]="s">{{ 'recruiting.vacancyStatus.' + s | transloco }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <button
        mat-stroked-button
        type="button"
        class="active-filter"
        [class.on]="store.query().active"
        [attr.aria-pressed]="store.query().active === true"
        (click)="toggleActive()"
      >
        <span class="dot on" aria-hidden="true"></span>{{ 'recruiting.vacancies.activeFilter' | transloco }} ({{ store.activeCount() }})
      </button>
    </div>

    <section class="panel" aria-live="polite">
      @if (store.loading()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (store.failed()) {
        <div class="state">
          <p>{{ 'recruiting.loadError' | transloco }}</p>
          <button mat-stroked-button type="button" (click)="store.load()">{{ 'common.retry' | transloco }}</button>
        </div>
      } @else if (!store.loading() && store.items().length === 0) {
        <p class="state muted">{{ 'recruiting.vacancies.empty' | transloco }}</p>
      } @else {
        <ul class="rows">
          @for (v of store.items(); track v.id) {
            <li class="row">
              <a class="main" [routerLink]="['/vacancies', v.id]">
                <strong>{{ v.title }}</strong>
                <span class="muted">{{ v.branch?.name }} · {{ v.recruiter?.name }}</span>
              </a>
              <span class="state-chip" [class.on]="v.is_active">
                <span class="dot" [class.on]="v.is_active" aria-hidden="true"></span>{{ activeLabel(v) | transloco }}
              </span>
              <span class="count" [title]="'recruiting.vacancies.activeCount' | transloco">
                <mat-icon>person</mat-icon><span class="mono">{{ v.active_applications_count }}</span><span class="mono total">/{{ v.applications_count }}</span>
              </span>
              @if (canWrite()) {
                <a mat-icon-button [routerLink]="['/vacancies', v.id, 'edit']" [attr.aria-label]="'recruiting.vacancies.edit' | transloco">
                  <mat-icon>edit</mat-icon>
                </a>
              }
            </li>
          }
        </ul>
      }
      <mat-paginator
        [length]="store.total()"
        [pageIndex]="(store.query().page ?? 1) - 1"
        [pageSize]="store.query().perPage"
        [pageSizeOptions]="[20, 50, 100]"
        (page)="onPage($event)"
      />
    </section>
  `,
  styles: `
    .row {
      display: flex; align-items: center; gap: 1rem; padding: 0.5rem 1rem; min-height: 52px; box-sizing: border-box;
      border-bottom: var(--app-border-w) solid var(--app-track); transition: background-color var(--app-fast);
    }
    .row:last-child { border-bottom: 0; }
    .row:hover { background: var(--app-row-hover); }
    .main { flex: 1; display: flex; flex-direction: column; gap: 0.1rem; color: inherit; text-decoration: none; min-width: 0; }
    .main strong { font-weight: 700; overflow-wrap: anywhere; }
    .main .muted { font-size: 0.8125rem; }
    .main:hover strong, .main:focus-visible strong { color: var(--mat-sys-primary); }
    .head-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .active-total { display: inline-flex; align-items: center; gap: 0.4rem; margin: 0.35rem 0 0; font: var(--mat-sys-label-large); }
    /* Status marker: active = filled circle (good), otherwise a dashed ring — shape + text, never colour alone. */
    .dot { display: inline-block; vertical-align: middle; width: 0.5rem; height: 0.5rem; box-sizing: border-box; border-radius: 50%; border: 2px dashed var(--app-muted); flex: none; }
    .dot.on { border: 2px solid var(--app-good-text); background: var(--app-good-text); }
    .state-chip {
      display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.15rem 0.6rem; border-radius: var(--app-radius-pill);
      border: var(--app-border-w) solid var(--app-border); color: var(--app-muted); font: var(--mat-sys-label-medium); white-space: nowrap;
    }
    .state-chip.on { color: var(--app-good-text); background: var(--app-good-bg); border-color: transparent; }
    .active-filter .dot { margin-right: 0.4rem; }
    .active-filter.on { background: var(--app-good-bg); color: var(--app-good-text); border-color: currentColor; }
    .count { display: inline-flex; align-items: center; gap: 0.25rem; min-width: 4.5rem; font-variant-numeric: tabular-nums; }
    .count .total { color: var(--app-muted); }
    .count mat-icon { font-size: 18px; width: 18px; height: 18px; color: var(--app-muted); }
    @media (max-width: 600px) {
      .row { flex-wrap: wrap; gap: 0.25rem 0.75rem; padding: 0.6rem 0.75rem; }
      .main { flex-basis: 100%; }
      .count { margin-right: auto; }
    }
    @media (prefers-reduced-motion: reduce) { .row { transition: none; } }
  `,
})
export class VacanciesPage implements OnInit {
  protected readonly store = inject(VacanciesStore);
  private readonly router = inject(Router);
  private readonly api = inject(RecruitingService);
  protected readonly templates = signal<VacancyTemplate[]>([]);
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly search$ = new Subject<string>();
  protected readonly statuses = VACANCY_STATUSES;
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.store.patchQuery({ q: q.trim() || undefined }));
    this.store.load();
    if (this.canWrite()) {
      this.api.vacancyTemplates().subscribe({ next: (list) => this.templates.set(list), error: () => undefined });
    }
  }

  /** Why a vacancy is (not) active: green «Активна» only when open AND published. */
  protected activeLabel(v: Vacancy): string {
    if (v.is_active) {
      return 'recruiting.vacancies.state.active';
    }
    return v.status === 'open' ? 'recruiting.vacancies.state.unpublished' : `recruiting.vacancies.state.${v.status}`;
  }

  protected toggleActive(): void {
    const on = !this.store.query().active;
    this.store.patchQuery(on ? { active: true, status: undefined } : { active: undefined, status: 'open' });
  }

  protected fromTemplate(t: VacancyTemplate): void {
    void this.router.navigate(['/vacancies/create'], { queryParams: { template: t.id } });
  }

  protected renameTemplate(t: VacancyTemplate): void {
    const name = window.prompt(this.i18n.translate('recruiting.form.templateName'), t.name)?.trim();
    if (!name || name === t.name) {
      return;
    }
    this.api.renameVacancyTemplate(t.id, name).subscribe({
      next: (saved) => this.templates.update((list) => list.map((x) => (x.id === saved.id ? saved : x))),
      error: () => undefined,
    });
  }

  protected deleteTemplate(t: VacancyTemplate): void {
    if (!window.confirm(this.i18n.translate('recruiting.form.deleteTemplateConfirm', { name: t.name }))) {
      return;
    }
    this.api.deleteVacancyTemplate(t.id).subscribe({
      next: () => this.templates.update((list) => list.filter((x) => x.id !== t.id)),
      error: () => undefined,
    });
  }

  protected onPage(e: PageEvent): void {
    this.store.setPage(e.pageIndex + 1, e.pageSize);
  }
}
