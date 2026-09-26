import { ChangeDetectionStrategy, Component, OnInit, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { saveBlob } from '../../core/http/api-error';
import { DictionaryItem } from '../directory/directory.model';
import { DirectoryService } from '../directory/directory.service';
import { ReportFilter, ReportResult } from './reports.model';
import { ReportTable } from './report-table';
import { ReportsService, reportsErrorKey } from './reports.service';

type Filters = Partial<Record<ReportFilter, string>>;

/** One catalog report (/reports/catalog/:key?from=&to=…): its filters, table + CSS bars, CSV, save. */
@Component({
  selector: 'app-report-view-page',
  imports: [MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, RouterLink, TranslocoPipe, ReportTable],
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
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'reports.filters.' + f | transloco }}</mat-label>
                  <input matInput [type]="f === 'from' || f === 'to' ? 'date' : f === 'weeks' ? 'number' : 'text'" [value]="filters()[f] ?? ''" (change)="set(f, val($event))" />
                </mat-form-field>
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
        <app-report-table [columns]="res.report.columns" [rows]="res.rows" [chart]="res.report.chart" />
      </section>
    }
  `,
  styles: `
    .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .card { padding: 1rem; }
    .filters { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
  `,
})
export class ReportViewPage implements OnInit {
  readonly key = input.required<string>();
  readonly from = input<string | undefined>(undefined);
  readonly to = input<string | undefined>(undefined);
  readonly branch_id = input<string | undefined>(undefined);
  readonly weeks = input<string | undefined>(undefined);
  readonly period = input<string | undefined>(undefined);

  private readonly api = inject(ReportsService);
  private readonly directory = inject(DirectoryService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly String = String;
  protected readonly result = signal<ReportResult | null>(null);
  protected readonly filters = signal<Filters>({});
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly loading = signal(false);

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

  protected val(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected set(key: ReportFilter, value: string | undefined): void {
    this.filters.update((f) => ({ ...f, [key]: value === '' ? undefined : value }));
  }

  protected run(): void {
    this.loading.set(true);
    this.api.run(this.key(), this.filters()).subscribe({
      next: (res) => {
        this.result.set(res);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.toast(reportsErrorKey(e));
      },
    });
  }

  protected csv(): void {
    this.api.csv(this.key(), this.filters()).subscribe({ next: (blob) => saveBlob(blob, `${this.key()}.csv`), error: (e: unknown) => this.toast(reportsErrorKey(e)) });
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
      next: () => this.toast('reports.saved.done'),
      error: (e: unknown) => this.toast(reportsErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
