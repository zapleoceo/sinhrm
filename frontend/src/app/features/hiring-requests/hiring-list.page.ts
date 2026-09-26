import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { HIRING_STATUSES, HiringRequest, HiringStatus, statusTone } from './hiring-requests.model';
import { HiringRequestsService, hiringErrorKey } from './hiring-requests.service';

type ListMode = 'all' | 'mine' | 'inbox';

/**
 * Hiring requests (tz2 "Вакансії → Заявки"): registry with author, status, current step and progress;
 * /hiring-requests/inbox — requests waiting for my decision (the same page in "inbox" mode).
 */
@Component({
  selector: 'app-hiring-list-page',
  imports: [DatePipe, MatButtonModule, MatButtonToggleModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ (mode() === 'inbox' ? 'hiring.inbox.title' : 'hiring.list.title') | transloco }}</h1>
        <p class="muted">{{ (mode() === 'inbox' ? 'hiring.inbox.subtitle' : 'hiring.list.subtitle') | transloco }}</p>
      </div>
      @if (canCreate()) {
        <a mat-flat-button routerLink="/hiring-requests/new"><mat-icon>add</mat-icon>{{ 'hiring.list.new' | transloco }}</a>
      }
    </header>
    <div class="filters">
      <mat-button-toggle-group [value]="mode()" (change)="setMode($event.value)" hideSingleSelectionIndicator>
        <mat-button-toggle value="all">{{ 'hiring.list.all' | transloco }}</mat-button-toggle>
        <mat-button-toggle value="mine">{{ 'hiring.list.mine' | transloco }}</mat-button-toggle>
        <mat-button-toggle value="inbox">{{ 'hiring.inbox.tab' | transloco }}</mat-button-toggle>
      </mat-button-toggle-group>
      @if (mode() !== 'inbox') {
        <mat-button-toggle-group [value]="status() ?? 'any'" (change)="setStatus($event.value)" hideSingleSelectionIndicator>
          <mat-button-toggle value="any">{{ 'common.all' | transloco }}</mat-button-toggle>
          @for (s of statuses; track s) {
            <mat-button-toggle [value]="s">{{ 'hiring.status.' + s | transloco }}</mat-button-toggle>
          }
        </mat-button-toggle-group>
      }
    </div>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="panel">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ 'hiring.fields.title' | transloco }}</th>
            <th scope="col">{{ 'hiring.fields.requester' | transloco }}</th>
            <th scope="col">{{ 'hiring.fields.status' | transloco }}</th>
            <th scope="col">{{ 'hiring.fields.step' | transloco }}</th>
            <th scope="col">{{ 'hiring.fields.progress' | transloco }}</th>
            <th scope="col">{{ 'hiring.fields.created' | transloco }}</th>
          </tr>
        </thead>
        <tbody>
          @for (r of items(); track r.id) {
            <tr [attr.data-overdue]="r.overdue">
              <td>
                <a [routerLink]="['/hiring-requests', r.id]">{{ r.title }}</a>
                <br /><span class="muted small">{{ r.branch.name }} · ×{{ r.headcount }} · {{ 'hiring.priority.' + r.priority | transloco }}</span>
              </td>
              <td>{{ r.requester?.name ?? '—' }}</td>
              <td><span class="chip" [attr.data-tone]="tone(r.status)">{{ 'hiring.status.' + r.status | transloco }}</span></td>
              <td>
                @if (r.current_step; as step) {
                  {{ step.name }}
                  @if (step.overdue) {
                    <mat-icon inline class="warn" [attr.aria-label]="'hiring.overdue' | transloco">alarm</mat-icon>
                  }
                } @else {
                  —
                }
              </td>
              <td>
                @if (r.progress && r.vacancy) {
                  {{ r.progress.hired }}/{{ r.progress.headcount }}
                } @else {
                  —
                }
              </td>
              <td>{{ r.created_at | date: 'dd.MM.yyyy' }}</td>
            </tr>
          } @empty {
            <tr><td colspan="6" class="muted">{{ 'hiring.list.empty' | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    .filters { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.45rem 0.6rem; border-bottom: 1px solid var(--app-border); font-weight: normal; vertical-align: top; }
    thead th { color: var(--app-muted); font-size: 0.8rem; }
    tr[data-overdue='true'] td:first-child { box-shadow: inset 3px 0 0 var(--app-danger); }
    .chip { padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.8rem; background: var(--app-border); }
    .chip[data-tone='info'] { background: color-mix(in srgb, var(--mat-sys-primary) 15%, transparent); }
    .chip[data-tone='success'] { background: color-mix(in srgb, #2e7d32 18%, transparent); }
    .chip[data-tone='danger'] { background: color-mix(in srgb, var(--app-danger) 18%, transparent); }
    .warn { color: var(--app-danger); }
    .small { font-size: 0.8rem; }
    .panel { overflow-x: auto; }
  `,
})
export class HiringListPage implements OnInit {
  /** Route data: "inbox" opens the approval inbox. */
  readonly view = input<ListMode>('all');
  private readonly api = inject(HiringRequestsService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly statuses = HIRING_STATUSES;
  protected readonly mode = signal<ListMode>('all');
  protected readonly status = signal<HiringStatus | null>(null);
  protected readonly items = signal<HiringRequest[]>([]);
  protected readonly loading = signal(false);
  protected readonly canCreate = signal(false);
  protected readonly tone = statusTone;

  ngOnInit(): void {
    this.mode.set(this.view());
    this.api.meta().subscribe({ next: (m) => this.canCreate.set(m.can_create), error: () => this.canCreate.set(false) });
    this.load();
  }

  protected setMode(mode: ListMode): void {
    this.mode.set(mode);
    this.load();
  }

  protected setStatus(value: HiringStatus | 'any'): void {
    this.status.set(value === 'any' ? null : value);
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    const mode = this.mode();
    const call = mode === 'inbox' ? this.api.inbox() : this.api.list({ status: this.status() ?? undefined, mine: mode === 'mine' });
    call.subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.snack.open(this.i18n.translate(hiringErrorKey(e)), undefined, { duration: 4000 });
      },
    });
  }
}
