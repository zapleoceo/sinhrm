import { ChangeDetectionStrategy, Component, OnInit, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { DictionaryItem } from '../directory/directory.model';
import { DirectoryService } from '../directory/directory.service';
import { ReportFilter, ReportResult } from './reports.model';
import { ReportTable } from './report-table';
import { ReportsService, reportsErrorKey } from './reports.service';
import { fromIsoDate, toIsoDate } from '../../core/date/iso-date';
import { NotifyService } from '../../core/ui/notify.service';
import { ReportRun } from './report-run';
import { eventValue } from '../../core/ui/event-value';

type Filters = Partial<Record<ReportFilter, string>>;

/** One catalog report (/reports/catalog/:key?from=&to=…): its filters, table + CSS bars, CSV, save. */
@Component({
  selector: 'app-report-view-page',
  imports: [MatButtonModule, MatDatepickerModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, RouterLink, TranslocoPipe, ReportTable],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <p class="muted"><a routerLink="/reports/catalog">← {{ 'reports.title' | transloco }}</a></p>
        <h1>{{ 'reports.names.' + key() | transloco }}</h1>
        <p class="muted">{{ 'reports.descriptions.' + key() | transloco }}</p>
      </div>
      <div class="actions">
        <button mat-stroked-button type="button" (click)="csv()" [disabled]="!result()"><mat-icon>download</mat-icon>{{ 'reports.csv' | transloco }}</button>
        <button mat-stroked-button type="button" (click)="save()" [disabled]="!result()"><mat-icon>bookmark_add</mat-icon>{{ 'reports.save' | transloco }}</button>
      </div>
    </header>
    @if (result(); as res) {
      @if (res.report.filters.length) {
        <form class="filters" (submit)="$event.preventDefault(); run()">
          @for (f of res.report.filters; track f) {
            @switch (f) {
              @case ('branch_id') {
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'reports.filters.branch_id' | transloco }}</mat-label>
                  <mat-select [value]="filters()['branch_id'] ?? null" (valueChange)="set('branch_id', $event === null ? undefined : String($event))">
                    <mat-option [value]="null">{{ 'common.all' | transloco }}</mat-option>
                    @for (b of branches(); track b.id) {
                      <mat-option [value]="'' + b.id">{{ b.name }}</mat-option>
                    }
                  </mat-select>
                </mat-form-field>
              }
              @default {
                @if (f === 'from' || f === 'to') {
                  <mat-form-field subscriptSizing="dynamic">
                    <mat-label>{{ 'reports.filters.' + f | transloco }}</mat-label>
                    <input matInput [matDatepicker]="filterDate" [value]="dateOf(f)" (dateChange)="set(f, toIso($event.value) || undefined)" />
                    <mat-datepicker-toggle matIconSuffix [for]="filterDate" />
                    <mat-datepicker #filterDate />
                  </mat-form-field>
                } @else {
                  <mat-form-field subscriptSizing="dynamic">
                    <mat-label>{{ 'reports.filters.' + f | transloco }}</mat-label>
                    <input matInput [type]="f === 'weeks' ? 'number' : 'text'" [value]="filters()[f] ?? ''" (change)="set(f, val($event))" />
                  </mat-form-field>
                }
              }
            }
          }
          <button mat-stroked-button type="submit">{{ 'reports.apply' | transloco }}</button>
        </form>
      }
    }
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (result(); as res) {
      <section class="panel card">
        <app-report-table [columns]="res.report.columns" [rows]="res.rows" [chart]="res.report.chart" [totals]="res.totals" />
      </section>
    }
  `,
  styles: `
    .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .card { padding: 1rem 1.25rem; }
    .filters { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
  `,
})
export class ReportViewPage implements OnInit {
  /** Text of the field that fired the event (core/ui/event-value.ts). */
  protected readonly val = eventValue;
  readonly key = input.required<string>();
  readonly from = input<string | undefined>(undefined);
  readonly to = input<string | undefined>(undefined);
  readonly branch_id = input<string | undefined>(undefined);
  readonly weeks = input<string | undefined>(undefined);
  readonly period = input<string | undefined>(undefined);

  private readonly api = inject(ReportsService);
  private readonly directory = inject(DirectoryService);
  private readonly notify = inject(NotifyService);
  private readonly i18n = inject(TranslocoService);
  protected readonly String = String;
  private readonly report = new ReportRun<ReportResult>();
  protected readonly result = this.report.result;
  protected readonly filters = signal<Filters>({});
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly loading = this.report.loading;

  constructor() {
    effect(() => {
      this.filters.set({ from: this.from(), to: this.to(), branch_id: this.branch_id(), weeks: this.weeks(), period: this.period() });
      this.key();
      this.run();
    });
  }

  ngOnInit(): void {
    this.directory.active('branches').subscribe({ next: (list) => this.branches.set(list), error: () => this.branches.set([]) });
  }

  protected readonly toIso = toIsoDate;

  protected dateOf(key: ReportFilter): Date | null {
    return fromIsoDate(this.filters()[key]);
  }

  protected set(key: ReportFilter, value: string | undefined): void {
    this.filters.update((f) => ({ ...f, [key]: value === '' ? undefined : value }));
  }

  protected run(): void {
    this.report.run(this.api.run(this.key(), this.filters()));
  }

  protected csv(): void {
    this.report.download(this.api.csv(this.key(), this.filters()), `${this.key()}.csv`);
  }

  protected save(): void {
    const name = this.i18n.translate(`reports.names.${this.key()}`);
    const filters: Record<string, string> = {};
    for (const [k, v] of Object.entries(this.filters())) {
      if (v) {
        filters[k] = v;
      }
    }
    this.api.save(null, name, 'catalog', { key: this.key(), filters }).subscribe({
      next: () => this.notify.show('reports.saved.done'),
      error: (e: unknown) => this.notify.show(reportsErrorKey(e)),
    });
  }
}
