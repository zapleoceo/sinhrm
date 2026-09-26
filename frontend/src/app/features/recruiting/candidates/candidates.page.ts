import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, effect, inject, input } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { CandidateCard } from '../card/candidate-card';
import { canWriteRecruiting } from '../recruiting.access';
import { APPLICATION_STATUSES, CANDIDATE_SOURCES, Candidate } from '../recruiting.model';
import { CandidateDialog } from './candidate.dialog';
import { RecruitingService } from '../recruiting.service';
import { CandidatesStore } from './candidates.store';

/** Keys that must not hijack typing in inputs. */
function isTyping(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null;
  return !!el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
}

/**
 * Split view: candidates list on the left, the open card on the right (/candidates/:id). j/k or ↓/↑ move between
 * candidates without leaving the card; "/" focuses the search.
 */
@Component({
  selector: 'app-candidates-page',
  imports: [
    CandidateCard,
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
  providers: [CandidatesStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(document:keydown)': 'onKey($event)' },
  template: `
    <div class="split" [class.has-card]="id()">
      <aside class="list">
        <header class="list-head">
          <h1>{{ 'recruiting.candidates.title' | transloco }}</h1>
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
                <mat-option [value]="s">{{ 'recruiting.source.' + s | transloco }}</mat-option>
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
            <li role="option" [attr.aria-selected]="c.id === id()">
              <a [routerLink]="['/candidates', c.id]" [class.active]="c.id === id()">
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
  `,
  styles: `
    .split { display: grid; grid-template-columns: minmax(16rem, 22rem) 1fr; gap: var(--app-gap); align-items: start; }
    .list { position: sticky; top: 0; display: flex; flex-direction: column; gap: 0.5rem; max-height: calc(100vh - 6rem); }
    .list-head { display: flex; justify-content: space-between; align-items: center; }
    .list-head h1 { font: var(--mat-sys-title-large); margin: 0; }
    .filters mat-form-field { flex: 1 1 8rem; }
    .items { list-style: none; margin: 0; padding: 0; overflow-y: auto; flex: 1; }
    .items a {
      display: grid; grid-template-columns: 1fr auto; padding: 0.4rem 0.6rem; border-radius: 8px;
      color: inherit; text-decoration: none;
    }
    .items a:hover { background: var(--mat-sys-surface-container-high); }
    .items a.active { background: var(--mat-sys-secondary-container); color: var(--mat-sys-on-secondary-container); }
    .name { font-weight: 500; }
    .sub { grid-column: 1; font-size: 0.8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .stale { grid-column: 2; grid-row: 1 / span 2; align-self: center; color: var(--app-warning); }
    @media (max-width: 900px) {
      .split { grid-template-columns: 1fr; }
      .list { position: static; max-height: none; }
      .split.has-card .list { display: none; }
    }
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
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));

  constructor() {
    effect(() => this.store.selectedId.set(this.id() ?? null));
  }

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.store.patchQuery({ q: q.trim() || undefined }));
    this.store.load();
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

  protected onKey(e: KeyboardEvent): void {
    if (e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target) || this.dialog.openDialogs.length > 0) {
      return;
    }
    if (e.key === '/') {
      e.preventDefault();
      document.getElementById('candidate-search')?.focus();
      return;
    }
    const step = e.key === 'j' || e.key === 'ArrowDown' ? 1 : e.key === 'k' || e.key === 'ArrowUp' ? -1 : 0;
    if (step === 0) {
      return;
    }
    const next = this.store.neighbour(step);
    if (next !== null) {
      e.preventDefault();
      void this.router.navigate(['/candidates', next]);
    }
  }
}
