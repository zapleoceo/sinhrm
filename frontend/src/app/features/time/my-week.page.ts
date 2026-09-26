import { DatePipe, DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, numberAttribute, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { GridRow, TimeWeek, addDays, addWeeks, dayTotals, entriesFromRows, expectedRow, gridTotals, mondayOf, rowsFromEntries } from './time.model';
import { TimeService, timeErrorKey } from './time.service';

/**
 * The week grid (/time?week=&employee_id=): lines (project / category / note) × Monday…Sunday hours, leave and
 * holidays from TimeOff shown per day and not counted as missing, live totals (expected, worked, overtime, missing),
 * "fill by schedule" quick entry, save and submit; the manager sees the same grid read-only with approve/reject.
 */
@Component({
  selector: 'app-my-week-page',
  imports: [DatePipe, DecimalPipe, MatButtonModule, MatIconModule, MatProgressBarModule, MatTooltipModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ (employeeId() ? 'time.week.titleOf' : 'time.week.title') | transloco: { name: data()?.employee?.full_name ?? '' } }}</h1>
        <p class="muted">
          {{ 'time.week.range' | transloco: { from: (weekStart() | date: 'dd.MM'), to: (weekEnd() | date: 'dd.MM.yyyy') } }}
          @if (data(); as w) {
            · <span class="chip" [attr.data-status]="w.status">{{ 'time.status.' + w.status | transloco }}</span>
          }
        </p>
      </div>
      <div class="row">
        <button mat-icon-button type="button" (click)="go(-1)" [attr.aria-label]="'time.week.prev' | transloco"><mat-icon>chevron_left</mat-icon></button>
        <button mat-button type="button" (click)="goToday()">{{ 'time.week.today' | transloco }}</button>
        <button mat-icon-button type="button" (click)="go(1)" [attr.aria-label]="'time.week.next' | transloco"><mat-icon>chevron_right</mat-icon></button>
      </div>
    </header>
    @if (busy()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (data(); as w) {
      @if (w.decision_comment) {
        <p class="note" [attr.data-status]="w.status">{{ 'time.week.comment' | transloco }}: {{ w.decision_comment }} — {{ w.decided_by?.name }}</p>
      }
      <div class="panel grid-wrap">
        <table class="grid">
          <thead>
            <tr>
              <th scope="col">{{ 'time.week.project' | transloco }}</th>
              <th scope="col">{{ 'time.week.category' | transloco }}</th>
              @for (d of w.days; track d.date) {
                <th scope="col" class="num" [class.off]="d.scheduled === 0 || d.holiday">
                  {{ 'time.weekday.' + d.weekday | transloco }}<br /><span class="small">{{ d.date | date: 'dd.MM' }}</span>
                  @if (d.holiday) {
                    <br /><span class="tag holiday">{{ 'time.week.holiday' | transloco }}</span>
                  }
                  @if (d.leave; as l) {
                    <br /><span class="tag leave" [matTooltip]="l.type">{{ l.fraction < 1 ? '½ ' : '' }}{{ l.type }}</span>
                  }
                </th>
              }
              <th scope="col" class="num">Σ</th>
              <th scope="col"><span class="sr-only">{{ 'common.delete' | transloco }}</span></th>
            </tr>
          </thead>
          <tbody>
            @for (row of rows(); track $index; let r = $index) {
              <tr>
                <td><input class="cell text" [value]="row.project" [disabled]="!editable()" (change)="setText(r, 'project', $event)" [attr.aria-label]="'time.week.project' | transloco" /></td>
                <td><input class="cell text" [value]="row.category" [disabled]="!editable()" (change)="setText(r, 'category', $event)" [attr.aria-label]="'time.week.category' | transloco" /></td>
                @for (h of row.hours; track $index; let d = $index) {
                  <td class="num">
                    <input class="cell" type="number" min="0" max="24" step="0.25" [value]="h || ''" [disabled]="!editable()" (change)="setHours(r, d, $event)"
                      [attr.aria-label]="('time.weekday.' + w.days[d].weekday | transloco) + ' ' + (row.project || '')" />
                  </td>
                }
                <td class="num strong">{{ sum(row.hours) | number: '1.0-2' }}</td>
                <td>
                  @if (editable()) {
                    <button mat-icon-button type="button" (click)="removeRow(r)" [attr.aria-label]="'common.delete' | transloco"><mat-icon>close</mat-icon></button>
                  }
                </td>
              </tr>
            }
          </tbody>
          <tfoot>
            <tr>
              <th scope="row" colspan="2">{{ 'time.week.worked' | transloco }}</th>
              @for (t of totalsByDay(); track $index; let d = $index) {
                <td class="num" [class.over]="t > w.days[d].expected" [class.short]="t < w.days[d].expected">{{ t | number: '1.0-2' }}</td>
              }
              <td class="num strong">{{ totals().worked | number: '1.0-2' }}</td>
              <td></td>
            </tr>
            <tr class="muted">
              <th scope="row" colspan="2">{{ 'time.week.expected' | transloco }}</th>
              @for (d of w.days; track d.date) {
                <td class="num">{{ d.expected | number: '1.0-2' }}</td>
              }
              <td class="num">{{ totals().expected | number: '1.0-2' }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="summary">
        <span>{{ 'time.week.worked' | transloco }}: <strong>{{ totals().worked | number: '1.0-2' }}</strong></span>
        <span>{{ 'time.week.expected' | transloco }}: <strong>{{ totals().expected | number: '1.0-2' }}</strong></span>
        <span [class.over]="totals().overtime > 0">{{ 'time.week.overtime' | transloco }}: <strong>{{ totals().overtime | number: '1.0-2' }}</strong></span>
        <span [class.short]="totals().missing > 0">{{ 'time.week.missing' | transloco }}: <strong>{{ totals().missing | number: '1.0-2' }}</strong></span>
        <span class="muted">{{ 'time.week.absence' | transloco }}: {{ totals().absence | number: '1.0-2' }}</span>
        <span class="muted">{{ 'time.week.schedule' | transloco: { hours: w.schedule.hours_per_day } }} ({{ 'time.scheduleSource.' + w.schedule.source | transloco }})</span>
      </div>
      <div class="row actions">
        @if (editable()) {
          <button mat-stroked-button type="button" (click)="addRow()"><mat-icon>add</mat-icon>{{ 'time.week.addRow' | transloco }}</button>
          <button mat-stroked-button type="button" (click)="fillExpected()"><mat-icon>auto_fix_high</mat-icon>{{ 'time.week.fill' | transloco }}</button>
          <span class="spacer"></span>
          <button mat-stroked-button type="button" [disabled]="busy()" (click)="save(false)"><mat-icon>save</mat-icon>{{ 'common.save' | transloco }}</button>
          <button mat-flat-button type="button" [disabled]="busy()" (click)="save(true)"><mat-icon>send</mat-icon>{{ 'time.week.submit' | transloco }}</button>
        }
        @if (w.can.decide && w.timesheet_id) {
          <span class="spacer"></span>
          <button mat-stroked-button type="button" (click)="decide(false)"><mat-icon>close</mat-icon>{{ 'time.approvals.reject' | transloco }}</button>
          <button mat-flat-button type="button" (click)="decide(true)"><mat-icon>check</mat-icon>{{ 'time.approvals.approve' | transloco }}</button>
        }
      </div>
    }
  `,
  styles: `
    .row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
    .grid-wrap { overflow-x: auto; }
    .grid { width: 100%; border-collapse: collapse; }
    .grid th, .grid td { padding: 0.25rem 0.35rem; border-bottom: 1px solid var(--app-border); font-weight: normal; text-align: left; vertical-align: top; }
    .grid thead th { color: var(--app-muted); font-size: 0.8rem; }
    .grid th.off { opacity: 0.6; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .strong { font-weight: 600; }
    .cell { width: 4.2rem; padding: 0.25rem; border: 1px solid var(--app-border); border-radius: 4px; background: transparent; color: inherit; text-align: right; font: inherit; }
    .cell.text { width: 9rem; text-align: left; }
    .cell:disabled { border-color: transparent; }
    .tag { font-size: 0.7rem; border-radius: 4px; padding: 0 0.25rem; }
    .tag.holiday { background: color-mix(in srgb, var(--mat-sys-primary) 15%, transparent); }
    .tag.leave { background: color-mix(in srgb, #2e7d32 18%, transparent); }
    .small { font-size: 0.75rem; }
    .over { color: #b26a00; }
    .short { color: var(--app-danger); }
    .summary { display: flex; gap: 1.25rem; flex-wrap: wrap; margin: 0.75rem 0; }
    .actions { margin-top: 0.5rem; }
    .spacer { flex: 1; }
    .chip { padding: 0.05rem 0.45rem; border-radius: 999px; background: var(--app-border); font-size: 0.8rem; }
    .chip[data-status='approved'] { background: color-mix(in srgb, #2e7d32 18%, transparent); }
    .chip[data-status='rejected'] { background: color-mix(in srgb, var(--app-danger) 18%, transparent); }
    .note { padding: 0.5rem 0.75rem; border-left: 3px solid var(--app-border); }
    .note[data-status='rejected'] { border-color: var(--app-danger); }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
  `,
})
export class MyWeekPage {
  /** Query params (?week=Y-m-d&employee_id=N). */
  readonly week = input<string | undefined>(undefined);
  private readonly query = toSignal(inject(ActivatedRoute).queryParamMap);
  /** ?employee_id= (a manager / admin viewing someone's week); undefined = my own week. */
  protected readonly employeeId = computed(() => {
    const raw = this.query()?.get('employee_id');
    return raw ? numberAttribute(raw) : undefined;
  });
  private readonly api = inject(TimeService);
  private readonly router = inject(Router);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly data = signal<TimeWeek | null>(null);
  protected readonly rows = signal<GridRow[]>([]);
  protected readonly busy = signal(false);
  protected readonly weekStart = computed(() => mondayOf(this.week() ?? new Date().toISOString().slice(0, 10)));
  protected readonly weekEnd = computed(() => addDays(this.weekStart(), 6));
  protected readonly editable = computed(() => this.data()?.can.edit ?? false);
  protected readonly totalsByDay = computed(() => dayTotals(this.rows()));
  protected readonly totals = computed(() => gridTotals(this.rows(), this.data()?.days ?? []));

  constructor() {
    effect(() => this.load(this.weekStart(), this.employeeId()));
  }

  protected sum(hours: number[]): number {
    return hours.reduce((a, b) => a + b, 0);
  }

  protected go(delta: number): void {
    void this.router.navigate([], { queryParams: { week: addWeeks(this.weekStart(), delta) }, queryParamsHandling: 'merge' });
  }

  protected goToday(): void {
    void this.router.navigate([], { queryParams: { week: null }, queryParamsHandling: 'merge' });
  }

  protected setHours(row: number, day: number, event: Event): void {
    const value = Number((event.target as HTMLInputElement).value);
    const hours = Number.isFinite(value) ? Math.max(0, Math.min(24, value)) : 0;
    this.rows.update((rows) => rows.map((r, i) => (i === row ? { ...r, hours: r.hours.map((h, j) => (j === day ? hours : h)) } : r)));
  }

  protected setText(row: number, field: 'project' | 'category', event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.rows.update((rows) => rows.map((r, i) => (i === row ? { ...r, [field]: value } : r)));
  }

  protected addRow(): void {
    this.rows.update((rows) => [...rows, { project: '', category: '', note: '', hours: [0, 0, 0, 0, 0, 0, 0] }]);
  }

  protected removeRow(index: number): void {
    this.rows.update((rows) => rows.filter((_, i) => i !== index));
  }

  /** Quick entry: replaces the grid with one line of the expected hours per day. */
  protected fillExpected(): void {
    const w = this.data();
    if (w !== null) {
      this.rows.set([expectedRow(w.days)]);
    }
  }

  protected save(submit: boolean): void {
    const start = this.weekStart();
    const employee = this.employeeId();
    this.busy.set(true);
    this.api.save(start, entriesFromRows(this.rows(), start), employee).subscribe({
      next: (w) => {
        if (!submit) {
          this.apply(w);
          this.toast('time.week.saved');
          return;
        }
        this.api.submit(start, employee).subscribe({ next: (s) => this.apply(s), error: (e: unknown) => this.fail(e) });
      },
      error: (e: unknown) => this.fail(e),
    });
  }

  protected decide(approve: boolean): void {
    const id = this.data()?.timesheet_id;
    if (id === undefined || id === null) {
      return;
    }
    const comment = approve ? null : window.prompt(this.i18n.translate('time.approvals.reason'));
    if (!approve && !comment) {
      return;
    }
    this.busy.set(true);
    this.api.decide(id, approve, comment).subscribe({ next: (d) => this.apply(d), error: (e: unknown) => this.fail(e) });
  }

  private load(week: string, employeeId: number | undefined): void {
    this.busy.set(true);
    this.api.week(week, employeeId).subscribe({ next: (w) => this.apply(w), error: (e: unknown) => this.fail(e) });
  }

  private apply(w: TimeWeek): void {
    this.busy.set(false);
    this.data.set(w);
    const rows = rowsFromEntries(w.entries, w.week_start);
    this.rows.set(rows.length > 0 || !w.can.edit ? rows : [{ project: '', category: '', note: '', hours: [0, 0, 0, 0, 0, 0, 0] }]);
  }

  private fail(e: unknown): void {
    this.busy.set(false);
    this.toast(timeErrorKey(e));
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
