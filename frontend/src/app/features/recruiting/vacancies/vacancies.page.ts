import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { VACANCY_STATUSES, Vacancy } from '../recruiting.model';
import { canWriteRecruiting } from '../recruiting.access';
import { VacanciesStore } from './vacancies.store';
import { VacancyDialog, VacancyDialogData } from './vacancy.dialog';

/** Vacancies in the user's branches: search, status filter, create/edit; a row opens the kanban board. */
@Component({
  selector: 'app-vacancies-page',
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
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
      </div>
      @if (canWrite()) {
        <button mat-flat-button type="button" (click)="edit(null)">
          <mat-icon>add</mat-icon>{{ 'recruiting.vacancies.new' | transloco }}
        </button>
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
              <span class="status" [attr.data-status]="v.status">{{ 'recruiting.vacancyStatus.' + v.status | transloco }}</span>
              <span class="count" [title]="'recruiting.vacancies.activeCount' | transloco">
                <mat-icon>person</mat-icon>{{ v.active_applications_count }}/{{ v.applications_count }}
              </span>
              @if (canWrite()) {
                <button mat-icon-button type="button" (click)="edit(v)" [attr.aria-label]="'recruiting.vacancies.edit' | transloco">
                  <mat-icon>edit</mat-icon>
                </button>
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
    .rows { list-style: none; margin: 0; padding: 0; }
    .row {
      display: flex; align-items: center; gap: 1rem; padding: 0.5rem 1rem;
      border-bottom: 1px solid var(--app-border);
    }
    .main { flex: 1; display: flex; flex-direction: column; color: inherit; text-decoration: none; min-width: 0; }
    .main:hover strong, .main:focus-visible strong { color: var(--mat-sys-primary); }
    .status[data-status='closed'] { color: var(--app-muted); }
    .status[data-status='paused'] { color: var(--app-warning); }
    .count { display: inline-flex; align-items: center; gap: 0.25rem; font-variant-numeric: tabular-nums; }
    .count mat-icon { font-size: 18px; width: 18px; height: 18px; }
  `,
})
export class VacanciesPage implements OnInit {
  protected readonly store = inject(VacanciesStore);
  private readonly dialog = inject(MatDialog);
  private readonly auth = inject(AuthService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly search$ = new Subject<string>();
  protected readonly statuses = VACANCY_STATUSES;
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.store.patchQuery({ q: q.trim() || undefined }));
    this.store.load();
  }

  protected onPage(e: PageEvent): void {
    this.store.setPage(e.pageIndex + 1, e.pageSize);
  }

  protected edit(vacancy: Vacancy | null): void {
    this.dialog.open<VacancyDialog, VacancyDialogData>(VacancyDialog, { data: { vacancy, store: this.store } });
  }
}
