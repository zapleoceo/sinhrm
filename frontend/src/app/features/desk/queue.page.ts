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
import { ClientTable, NUMBER_RANGE, TEXT_FILTER, translatedSelect } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { CASE_STATUSES, CASE_STATUS_TONE, CaseStatus, DeskCase, DeskCategory, slaState } from './desk.model';
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
    TableSortDirective,
    ColumnHeader,
  ],
  providers: [TableUrlState],
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
              <td class="app-num">{{ c.id }}</td>
              <td><a [routerLink]="['/desk/cases', c.id]">{{ c.subject }}</a><br /><span class="muted small app-num">{{ c.created_at | date: 'dd.MM HH:mm' }}</span></td>
              <td>{{ c.employee.full_name }}</td>
              <td>{{ c.category.name }}</td>
              <td>{{ c.assignee?.name ?? '—' }}</td>
              <td><span class="app-pill" [attr.data-tone]="statusTone[c.status]">{{ 'desk.status.' + c.status | transloco }}</span></td>
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
      <!-- Categories: sort and filter in the headers (core/ui/table), state in the URL as cat_sort / cat_<column>. -->
      <table class="app-table" [appTableSort]="cats.sort()" (appTableSortChange)="cats.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="name" [label]="'desk.categories.name' | transloco"
              [filter]="textFilter" [filterValue]="cats.filterValue('name')" (filterChange)="cats.setFilter('name', $event)"></th>
            <th scope="col" class="num" app-column-header key="first" [label]="'desk.categories.firstResponse' | transloco"
              [filter]="numberRange" [filterValue]="cats.filterValue('first')" (filterChange)="cats.setFilter('first', $event)"></th>
            <th scope="col" class="num" app-column-header key="resolve" [label]="'desk.categories.resolve' | transloco"
              [filter]="numberRange" [filterValue]="cats.filterValue('resolve')" (filterChange)="cats.setFilter('resolve', $event)"></th>
            <th scope="col" app-column-header key="active" [label]="'desk.categories.active' | transloco"
              [filter]="activeFilter()" [filterValue]="cats.filterValue('active')" (filterChange)="cats.setFilter('active', $event)"></th>
          </tr>
        </thead>
        <tbody>
          @for (k of cats.rows(); track k.id) {
            <tr>
              <td>{{ k.name }}</td>
              <td class="num app-num">{{ k.first_response_hours ?? '—' }}</td>
              <td class="num app-num">{{ k.resolve_hours ?? '—' }}</td>
              <td><mat-slide-toggle [checked]="k.active" (change)="toggleCategory(k, $event.checked)" [attr.aria-label]="k.name" /></td>
            </tr>
          } @empty {
            @if (categories().length) {
              <tr><td colspan="4" class="muted">{{ 'table.noMatches' | transloco }}</td></tr>
            }
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
    th, td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: var(--app-border-w) solid var(--app-track); font-weight: normal; vertical-align: top; }
    thead th { color: var(--app-muted); font: var(--mat-sys-label-medium); font-weight: 700; border-bottom-color: var(--app-border); white-space: nowrap; }
    tr[data-sla='breached'] td:first-child { box-shadow: inset 4px 0 0 var(--app-danger); }
    tbody tr:hover { background: var(--app-row-hover); }
    .app-num { font-size: 0.8rem; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .cats { margin-top: var(--app-gap); padding: 1rem 1.25rem; }
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
  protected readonly statusTone = CASE_STATUS_TONE;
  protected readonly status = signal<CaseStatus | 'open'>('open');
  protected readonly items = signal<DeskCase[]>([]);
  protected readonly categories = signal<DeskCategory[]>([]);
  protected readonly loading = signal(false);
  protected readonly textFilter = TEXT_FILTER;
  protected readonly numberRange = NUMBER_RANGE;
  protected readonly activeFilter = translatedSelect(() => ['true', 'false'], (v) => (v === 'true' ? 'table.yes' : 'table.no'));
  /** Categories table: API order is by name (the arrow sits there until the user picks another column). */
  protected readonly cats = new ClientTable<DeskCategory>({
    rows: this.categories,
    prefix: 'cat',
    defaultSort: { key: 'name', dir: 'asc' },
    columns: [
      { key: 'name', value: (k) => k.name, filter: 'text' },
      { key: 'first', value: (k) => k.first_response_hours, filter: 'number' },
      { key: 'resolve', value: (k) => k.resolve_hours, filter: 'number' },
      { key: 'active', value: (k) => (k.active ? 0 : 1), filter: 'select', filterValue: (k) => String(k.active) },
    ],
  });
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
