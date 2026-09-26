import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { CASE_STATUSES, CaseStatus, DeskCase, DeskCategory, slaState } from './desk.model';
import { DeskService, deskErrorKey } from './desk.service';
import { SlaBadge } from './sla-badge';

/** HR queue (/desk/queue): open cases first with SLA badges, filters; categories with their SLA hours. */
@Component({
  selector: 'app-desk-queue-page',
  imports: [
    DatePipe,
    MatButtonModule,
    MatButtonToggleModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
    RouterLink,
    TranslocoPipe,
    SlaBadge,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'desk.queue.title' | transloco }}</h1>
        <p class="muted">{{ 'desk.queue.subtitle' | transloco: { breached: breached() } }}</p>
      </div>
    </header>
    <div class="filters">
      <mat-button-toggle-group [value]="status()" (change)="setStatus($event.value)" hideSingleSelectionIndicator>
        <mat-button-toggle value="open">{{ 'desk.queue.open' | transloco }}</mat-button-toggle>
        @for (s of statuses; track s) {
          <mat-button-toggle [value]="s">{{ 'desk.status.' + s | transloco }}</mat-button-toggle>
        }
      </mat-button-toggle-group>
    </div>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="panel">
      <table>
        <thead>
          <tr>
            <th scope="col">#</th>
            <th scope="col">{{ 'desk.subject' | transloco }}</th>
            <th scope="col">{{ 'desk.employee' | transloco }}</th>
            <th scope="col">{{ 'desk.category' | transloco }}</th>
            <th scope="col">{{ 'desk.assignee' | transloco }}</th>
            <th scope="col">{{ 'desk.statusLabel' | transloco }}</th>
            <th scope="col">SLA</th>
          </tr>
        </thead>
        <tbody>
          @for (c of items(); track c.id) {
            <tr [attr.data-sla]="sla(c)">
              <td>{{ c.id }}</td>
              <td><a [routerLink]="['/desk/cases', c.id]">{{ c.subject }}</a><br /><span class="muted small">{{ c.created_at | date: 'dd.MM HH:mm' }}</span></td>
              <td>{{ c.employee.full_name }}</td>
              <td>{{ c.category.name }}</td>
              <td>{{ c.assignee?.name ?? '—' }}</td>
              <td>{{ 'desk.status.' + c.status | transloco }}</td>
              <td><app-sla-badge [c]="c" /></td>
            </tr>
          } @empty {
            <tr><td colspan="7" class="muted">{{ 'desk.queue.empty' | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>

    <section class="panel cats">
      <h2>{{ 'desk.categories.title' | transloco }}</h2>
      <p class="muted small">{{ 'desk.categories.hint' | transloco }}</p>
      <table>
        <thead>
          <tr>
            <th scope="col">{{ 'desk.categories.name' | transloco }}</th>
            <th scope="col" class="num">{{ 'desk.categories.firstResponse' | transloco }}</th>
            <th scope="col" class="num">{{ 'desk.categories.resolve' | transloco }}</th>
            <th scope="col">{{ 'desk.categories.active' | transloco }}</th>
          </tr>
        </thead>
        <tbody>
          @for (k of categories(); track k.id) {
            <tr>
              <td>{{ k.name }}</td>
              <td class="num">{{ k.first_response_hours ?? '—' }}</td>
              <td class="num">{{ k.resolve_hours ?? '—' }}</td>
              <td><mat-slide-toggle [checked]="k.active" (change)="toggleCategory(k, $event.checked)" [attr.aria-label]="k.name" /></td>
            </tr>
          }
        </tbody>
      </table>
      <form class="row" (submit)="$event.preventDefault(); addCategory(name.value, first.value, resolve.value); name.value = ''">
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'desk.categories.name' | transloco }}</mat-label><input matInput #name maxlength="120" required /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'desk.categories.firstResponse' | transloco }}</mat-label><input matInput #first type="number" min="1" max="2160" /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'desk.categories.resolve' | transloco }}</mat-label><input matInput #resolve type="number" min="1" max="2160" /></mat-form-field>
        <button mat-stroked-button type="submit"><mat-icon>add</mat-icon>{{ 'desk.categories.add' | transloco }}</button>
      </form>
    </section>
  `,
  styles: `
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid var(--app-border); font-weight: normal; vertical-align: top; }
    thead th { color: var(--app-muted); font-size: 0.8rem; }
    tr[data-sla='breached'] td:first-child { box-shadow: inset 3px 0 0 var(--app-danger); }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .cats { margin-top: var(--app-gap); padding: 1rem; }
    .cats h2 { font: var(--mat-sys-title-medium); margin: 0; }
    .row { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-top: 1rem; }
    .small { font-size: 0.8rem; }
    .panel { overflow-x: auto; }
  `,
})
export class DeskQueuePage implements OnInit {
  private readonly api = inject(DeskService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly statuses = CASE_STATUSES;
  protected readonly status = signal<CaseStatus | 'open'>('open');
  protected readonly items = signal<DeskCase[]>([]);
  protected readonly categories = signal<DeskCategory[]>([]);
  protected readonly loading = signal(false);
  protected readonly breached = computed(() => this.items().filter((c) => slaState(c) === 'breached').length);

  ngOnInit(): void {
    this.load();
    this.api.categories(true).subscribe({ next: (list) => this.categories.set(list), error: () => this.categories.set([]) });
  }

  protected sla(c: DeskCase): string {
    return slaState(c);
  }

  protected setStatus(value: CaseStatus | 'open'): void {
    this.status.set(value);
    this.load();
  }

  protected addCategory(name: string, first: string, resolve: string): void {
    if (name.trim() === '') {
      return;
    }
    const hours = (v: string): number | null => (v === '' ? null : Number(v));
    this.api.saveCategory(null, { name: name.trim(), first_response_hours: hours(first), resolve_hours: hours(resolve) }).subscribe({
      next: (k) => this.categories.update((list) => [...list, k]),
      error: (e: unknown) => this.toast(deskErrorKey(e)),
    });
  }

  protected toggleCategory(k: DeskCategory, active: boolean): void {
    this.api.saveCategory(k.id, { active }).subscribe({
      next: (saved) => this.categories.update((list) => list.map((x) => (x.id === saved.id ? saved : x))),
      error: (e: unknown) => this.toast(deskErrorKey(e)),
    });
  }

  private load(): void {
    const status = this.status();
    this.loading.set(true);
    this.api.queue(status === 'open' ? { open: true } : { status }).subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.toast(deskErrorKey(e));
      },
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
