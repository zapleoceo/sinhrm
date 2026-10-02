import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { BoardPage } from '../board/board.page';
import { CandidateCard } from '../card/candidate-card';
import { CandidatesView, readViewPref, saveViewPref } from './candidates-view';
import { canWriteRecruiting } from '../recruiting.access';
import { APPLICATION_STATUSES, BulkResult, CANDIDATE_SOURCES, Candidate, Vacancy } from '../recruiting.model';
import { CandidateDialog } from './candidate.dialog';
import { CandidateBulkDialog } from './candidate-bulk.dialog';
import { RecruitingService } from '../recruiting.service';
import { CandidatesStore } from './candidates.store';
import { ChannelIcon } from '../../../core/ui/channel-icon';

/**
 * Split view: candidates list on the left, the open card on the right (/candidates/:id).
 */
@Component({
  selector: 'app-candidates-page',
  imports: [
    BoardPage,
    ChannelIcon,
    MatButtonToggleModule,
    CandidateCard,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatPaginatorModule,
    MatProgressBarModule,
    MatSelectModule,
    NgTemplateOutlet,
    RouterLink,
    TranslocoPipe,
  ],
  providers: [CandidatesStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-template #viewToggle>
      <mat-button-toggle-group [value]="view()" (change)="setView($event.value)" [attr.aria-label]="'recruiting.personalBoard.view' | transloco" hideSingleSelectionIndicator>
        <mat-button-toggle value="list">{{ 'recruiting.personalBoard.list' | transloco }}</mat-button-toggle>
        <mat-button-toggle value="board">{{ 'recruiting.personalBoard.board' | transloco }}</mat-button-toggle>
      </mat-button-toggle-group>
    </ng-template>
    @if (view() === 'board') {
      <section class="board-view">
        <header class="list-head">
          <h1>{{ 'recruiting.candidates.title' | transloco }}</h1>
          <ng-container *ngTemplateOutlet="viewToggle" />
        </header>
        <mat-form-field class="vacancy-pick" subscriptSizing="dynamic">
          <mat-label>{{ 'recruiting.personalBoard.vacancy' | transloco }}</mat-label>
          <mat-select [value]="vacancyId()" (valueChange)="pickVacancy($event)">
            @for (v of vacancies(); track v.id) {
              <mat-option [value]="v.id">{{ v.title }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        @if (selectedVacancy(); as v) {
          <app-board-page [id]="v.id" personal />
        } @else {
          <p class="state muted">{{ 'recruiting.personalBoard.pickVacancy' | transloco }}</p>
        }
      </section>
    } @else {
    <div class="split" [class.has-card]="id()">
      <aside class="list">
        <header class="list-head">
          <h1>{{ 'recruiting.candidates.title' | transloco }}</h1>
          <ng-container *ngTemplateOutlet="viewToggle" />
          @if (canWrite()) {
            <button mat-icon-button type="button" (click)="create()" [attr.aria-label]="'recruiting.candidates.new' | transloco">
              <mat-icon>person_add</mat-icon>
            </button>
          }
        </header>
        <mat-form-field class="search" subscriptSizing="dynamic">
          <mat-label>{{ 'recruiting.candidates.search' | transloco }}</mat-label>
          <mat-icon matPrefix>search</mat-icon>
          <input matInput type="search" #q id="candidate-search" (input)="search$.next(q.value)" />
        </mat-form-field>
        <div class="filters">
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'recruiting.candidates.fields.status' | transloco }}</mat-label>
            <mat-select [value]="store.query().status" (valueChange)="store.patchQuery({ status: $event })">
              <mat-option [value]="undefined">{{ 'common.all' | transloco }}</mat-option>
              @for (s of statuses; track s) {
                <mat-option [value]="s">{{ 'recruiting.appStatus.' + s | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'recruiting.candidates.fields.source' | transloco }}</mat-label>
            <mat-select [value]="store.query().source" (valueChange)="store.patchQuery({ source: $event })">
              <mat-option [value]="undefined">{{ 'common.all' | transloco }}</mat-option>
              @for (s of sources; track s) {
                <mat-option [value]="s"><app-channel-icon [key]="s" /> {{ 'recruiting.source.' + s | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'recruiting.channels.channel' | transloco }}</mat-label>
            <mat-select [value]="store.query().channel_id" (valueChange)="store.patchQuery({ channel_id: $event })">
              <mat-option [value]="undefined">{{ 'common.all' | transloco }}</mat-option>
              @for (ch of channels(); track ch.id) {
                <mat-option [value]="ch.id">{{ ch.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
        </div>
        @if (canWrite() && selected().size > 0) {
          <div class="bulk-bar">
            <span>{{ 'bulk.selected' | transloco: { n: selected().size } }}</span>
            <button mat-stroked-button type="button" (click)="bulk()">{{ 'bulk.actions' | transloco }}</button>
            <button mat-button type="button" (click)="selected.set(emptySet())">{{ 'bulk.clear' | transloco }}</button>
          </div>
        }
        @if (store.loading()) {
          <mat-progress-bar mode="indeterminate" />
        }
        @if (store.failed()) {
          <div class="state">
            <p>{{ 'recruiting.loadError' | transloco }}</p>
            <button mat-stroked-button type="button" (click)="store.load()">{{ 'common.retry' | transloco }}</button>
          </div>
        }
        <ul class="items" role="listbox" [attr.aria-label]="'recruiting.candidates.title' | transloco">
          @for (c of store.items(); track c.id) {
            <li role="option" [attr.aria-selected]="c.id === id()" class="row">
              @if (canWrite()) {
                <mat-checkbox [checked]="selected().has(c.id)" (change)="toggle(c.id)" [attr.aria-label]="c.full_name" />
              }
              <a [routerLink]="['/candidates', c.id]" [class.active]="c.id === id()">
                <span class="app-station" [class.current]="c.id === id()" [attr.data-kind]="c.applications[0]?.stage?.kind" aria-hidden="true"></span>
                <span class="name">{{ c.full_name }}</span>
                <span class="muted sub">{{ summary(c) }}</span>
                @if (stale(c)) {
                  <mat-icon class="stale" inline [attr.aria-label]="'recruiting.card.stale' | transloco">schedule</mat-icon>
                }
              </a>
            </li>
          } @empty {
            @if (!store.loading()) {
              <li class="state muted">{{ 'recruiting.candidates.empty' | transloco }}</li>
            }
          }
        </ul>
        <mat-paginator
          [length]="store.total()"
          [pageIndex]="(store.query().page ?? 1) - 1"
          [pageSize]="store.query().perPage"
          [hidePageSize]="true"
          (page)="onPage($event)"
        />
      </aside>
      <section class="detail">
        @if (id(); as cid) {
          <app-candidate-card [candidateId]="cid" />
        } @else {
          <p class="state muted">{{ 'recruiting.candidates.pick' | transloco }}</p>
        }
      </section>
    </div>
    }
  `,
  styles: `
    .split { display: grid; grid-template-columns: minmax(16rem, 22rem) 1fr; gap: var(--app-gap); align-items: start; }
    .list {
      position: sticky; top: 0; display: flex; flex-direction: column; gap: 0.5rem; max-height: calc(100vh - 6rem);
      padding: 0.75rem; box-sizing: border-box; border: var(--app-border-w) solid var(--app-border); border-radius: var(--app-radius);
      background: var(--app-card);
    }
    .list-head { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .board-view { display: flex; flex-direction: column; gap: 0.5rem; min-width: 0; }
    .vacancy-pick { max-width: 24rem; }
    .list-head h1 { font: var(--mat-sys-title-large); margin: 0; }
    .filters { margin: 0; gap: 0.5rem; }
    .filters mat-form-field { flex: 1 1 8rem; }
    .items { list-style: none; margin: 0 -0.25rem; padding: 0; overflow-y: auto; flex: 1; }
    .items a {
      display: grid; grid-template-columns: auto minmax(0, 1fr) auto; column-gap: 0.6rem; align-items: center;
      padding: 0.45rem 0.6rem; min-height: 44px; box-sizing: border-box; border-radius: var(--app-radius-sm);
      color: inherit; text-decoration: none; transition: background-color var(--app-fast);
    }
    .items a:hover { background: var(--app-row-hover); }
    .items a.active { background: var(--app-row-selected); box-shadow: inset 3px 0 0 var(--mat-sys-primary); }
    .app-station { grid-row: 1 / span 2; }
    .name { grid-column: 2; font-weight: 600; }
    .row { display: flex; align-items: center; border-bottom: var(--app-border-w) solid var(--app-track); }
    .row:last-child { border-bottom: 0; }
    .row a { flex: 1; min-width: 0; }
    .bulk-bar {
      display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; padding: 0.35rem 0.5rem;
      border-radius: var(--app-radius-sm); background: var(--app-row-selected);
    }
    .sub { grid-column: 2; font-size: 0.8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .stale { grid-column: 3; grid-row: 1 / span 2; align-self: center; color: var(--app-warn-text); }
    .detail {
      min-width: 0; padding: 1.25rem 1.5rem; box-sizing: border-box; border: var(--app-border-w) solid var(--app-border);
      border-radius: var(--app-radius); background: var(--app-card);
    }
    @media (max-width: 900px) {
      .split { grid-template-columns: 1fr; }
      .list { position: static; max-height: none; }
      .split.has-card .list { display: none; }
      .detail { padding: 1rem; }
    }
    @media (max-width: 600px) {
      .list { padding: 0.5rem; }
    }
    @media (prefers-reduced-motion: reduce) { .items a { transition: none; } }
  `,
})
export class CandidatesPage implements OnInit {
  /** Route param :id (optional). */
  readonly id = input<number | undefined, string | undefined>(undefined, {
    transform: (v: string | undefined) => (v ? Number(v) : undefined),
  });

  protected readonly store = inject(CandidatesStore);
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly search$ = new Subject<string>();
  protected readonly statuses = APPLICATION_STATUSES;
  protected readonly sources = CANDIDATE_SOURCES;
  protected readonly channels = toSignal(inject(RecruitingService).channels(), { initialValue: [] });
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly selected = signal(new Set<number>());
  protected readonly emptySet = (): Set<number> => new Set<number>();
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));
  private readonly api = inject(RecruitingService);
  private readonly pref = readViewPref();
  protected readonly view = signal<CandidatesView>(this.pref.view);
  protected readonly vacancyId = signal<number | null>(this.pref.vacancyId);
  protected readonly vacancies = signal<Vacancy[]>([]);
  /** The board shows up only for a vacancy from the loaded list — not for a remembered id before the list arrives. */
  protected readonly selectedVacancy = computed(() => this.vacancies().find((v) => v.id === this.vacancyId()) ?? null);

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.store.patchQuery({ q: q.trim() || undefined }));
    this.store.load();
    if (this.view() === 'board') {
      this.loadVacancies();
    }
  }

  protected setView(view: CandidatesView): void {
    this.view.set(view);
    saveViewPref({ view, vacancyId: this.vacancyId() });
    if (view === 'board') {
      this.loadVacancies();
    }
  }

  protected pickVacancy(id: number): void {
    this.vacancyId.set(id);
    saveViewPref({ view: this.view(), vacancyId: id });
  }

  /** Same scoped list as /vacancies: the board never shows a vacancy (or candidate) the user cannot see. */
  private loadVacancies(): void {
    if (this.vacancies().length > 0) {
      return;
    }
    this.api.vacancies({ perPage: 100 }).subscribe({
      next: (page) => {
        this.vacancies.set(page.data);
        const id = this.vacancyId();
        if (id !== null && !page.data.some((v) => v.id === id)) {
          this.vacancyId.set(null);
        }
      },
      error: () => this.vacancies.set([]),
    });
  }

  protected summary(c: Candidate): string {
    const app = c.applications[0];
    return app ? `${app.vacancy?.title ?? ''} · ${app.stage?.name ?? ''}` : (c.phone ?? c.email ?? '');
  }

  protected stale(c: Candidate): boolean {
    return c.applications.some((a) => a.is_stale);
  }

  protected onPage(e: PageEvent): void {
    this.store.setPage(e.pageIndex + 1, e.pageSize);
  }

  protected toggle(id: number): void {
    const next = new Set(this.selected());
    if (!next.delete(id)) next.add(id);
    this.selected.set(next);
  }

  protected bulk(): void {
    const picked = this.store.items().filter((c) => this.selected().has(c.id));
    this.dialog
      .open(CandidateBulkDialog, { data: picked })
      .afterClosed()
      .subscribe((results: BulkResult[] | undefined) => {
        if (!results) return;
        const ok = results.filter((r) => r.ok).length;
        this.snack.open(this.i18n.translate('bulk.done', { ok, total: results.length }), undefined, { duration: 5000 });
        this.selected.set(new Set());
        this.store.load();
      });
  }

  protected create(): void {
    this.dialog
      .open(CandidateDialog)
      .afterClosed()
      .subscribe((created: Candidate | undefined) => {
        if (created) {
          this.store.load();
          void this.router.navigate(['/candidates', created.id]);
        }
      });
  }

}
