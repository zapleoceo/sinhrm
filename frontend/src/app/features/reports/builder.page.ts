import { ChangeDetectionStrategy, Component, OnInit, computed, effect, inject, input, numberAttribute, signal } from '@angular/core';
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
import {
  BUILDER_AGGREGATES,
  BUILDER_OPERATORS,
  BuilderAggregate,
  BuilderFilter,
  BuilderOperator,
  BuilderResult,
  BuilderSpec,
  DatasetInfo,
  cleanSpec,
} from './reports.model';
import { ReportTable } from './report-table';
import { ReportsService, reportsErrorKey } from './reports.service';

/**
 * Custom report builder (/reports/builder[?saved=id]): dataset → columns → filters → group by + aggregate.
 * Only whitelisted column keys are offered (PII columns only to admins) — the server checks the same whitelist.
 */
@Component({
  selector: 'app-report-builder-page',
  imports: [MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, RouterLink, TranslocoPipe, ReportTable],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <p class="muted"><a routerLink="/reports/catalog">← {{ 'reports.title' | transloco }}</a></p>
        <h1>{{ 'reports.builder.title' | transloco }}</h1>
        <p class="muted">{{ 'reports.builder.subtitle' | transloco }}</p>
      </div>
    </header>
    <form class="panel form" (submit)="$event.preventDefault(); run()">
      <div class="row">
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'reports.builder.dataset' | transloco }}</mat-label>
          <mat-select [value]="dataset()" (valueChange)="pickDataset($event)">
            @for (d of datasets(); track d.key) {
              <mat-option [value]="d.key">{{ 'reports.datasets.' + d.key | transloco }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic" class="grow">
          <mat-label>{{ 'reports.builder.columns' | transloco }}</mat-label>
          <mat-select multiple [value]="columns()" (valueChange)="columns.set($event)" [disabled]="groupBy() !== null">
            @for (c of available(); track c.key) {
              <mat-option [value]="c.key">{{ 'reports.columns.' + c.key | transloco }}@if (c.pii) { 🔒 }</mat-option>
            }
          </mat-select>
        </mat-form-field>
      </div>
      <h3>{{ 'reports.builder.filters' | transloco }}</h3>
      @for (f of filters(); track $index; let i = $index) {
        <div class="row">
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'reports.builder.column' | transloco }}</mat-label>
            <mat-select [value]="f.column" (valueChange)="patchFilter(i, { column: $event })">
              @for (c of available(); track c.key) {
                <mat-option [value]="c.key">{{ 'reports.columns.' + c.key | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'reports.builder.operator' | transloco }}</mat-label>
            <mat-select [value]="f.op" (valueChange)="patchFilter(i, { op: $event })">
              @for (o of operators; track o) {
                <mat-option [value]="o">{{ 'reports.ops.' + o | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic" class="grow">
            <mat-label>{{ 'reports.builder.value' | transloco }}</mat-label>
            <input matInput maxlength="200" [value]="f.value ?? ''" (change)="patchFilter(i, { value: val($event) })" />
          </mat-form-field>
          <button mat-icon-button type="button" (click)="removeFilter(i)" [attr.aria-label]="'reports.builder.removeFilter' | transloco"><mat-icon>close</mat-icon></button>
        </div>
      }
      <div><button mat-button type="button" (click)="addFilter()" [disabled]="filters().length >= 10"><mat-icon>add</mat-icon>{{ 'reports.builder.addFilter' | transloco }}</button></div>
      <div class="row">
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'reports.builder.groupBy' | transloco }}</mat-label>
          <mat-select [value]="groupBy()" (valueChange)="groupBy.set($event)">
            <mat-option [value]="null">—</mat-option>
            @for (c of available(); track c.key) {
              <mat-option [value]="c.key">{{ 'reports.columns.' + c.key | transloco }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        @if (groupBy() !== null) {
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'reports.builder.aggregate' | transloco }}</mat-label>
            <mat-select [value]="aggregate()" (valueChange)="aggregate.set($event)">
              @for (a of aggregates; track a) {
                <mat-option [value]="a">{{ 'reports.aggregates.' + a | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          @if (aggregate() !== 'count') {
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'reports.builder.aggregateColumn' | transloco }}</mat-label>
              <mat-select [value]="aggregateColumn()" (valueChange)="aggregateColumn.set($event)">
                @for (c of numeric(); track c.key) {
                  <mat-option [value]="c.key">{{ 'reports.columns.' + c.key | transloco }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
          }
        }
      </div>
      <div class="actions">
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'reports.saved.name' | transloco }}</mat-label>
          <input matInput maxlength="120" [value]="name()" (input)="name.set(val($event))" />
        </mat-form-field>
        <button mat-stroked-button type="button" (click)="save()" [disabled]="name().trim() === '' || !dataset()"><mat-icon>bookmark_add</mat-icon>{{ 'reports.save' | transloco }}</button>
        <button mat-stroked-button type="button" (click)="csv()" [disabled]="!dataset()"><mat-icon>download</mat-icon>{{ 'reports.csv' | transloco }}</button>
        <button mat-flat-button type="submit" [disabled]="!dataset()">{{ 'reports.builder.run' | transloco }}</button>
      </div>
    </form>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (result(); as res) {
      <section class="panel card">
        @if (res.truncated) {
          <p class="muted">{{ 'reports.builder.truncated' | transloco }}</p>
        }
        <app-report-table [columns]="resultColumns()" [rows]="res.rows" [chart]="res.spec.group_by ? { label: res.spec.group_by, value: 'value' } : null" />
      </section>
    }
  `,
  styles: `
    .form { display: flex; flex-direction: column; gap: 0.5rem; padding: 1rem; margin-bottom: 1rem; }
    .row { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; }
    .grow { flex: 1 1 14rem; }
    h3 { font: var(--mat-sys-title-small); margin: 0.5rem 0 0; }
    .actions { display: flex; gap: 0.5rem; justify-content: flex-end; flex-wrap: wrap; align-items: center; }
    .card { padding: 1rem; }
  `,
})
export class ReportBuilderPage implements OnInit {
  /** ?saved=<id> opens a saved builder report. */
  readonly saved = input(undefined, { transform: (v: unknown) => (v === undefined || v === null || v === '' ? undefined : numberAttribute(v)) });

  private readonly api = inject(ReportsService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly operators = BUILDER_OPERATORS;
  protected readonly aggregates = BUILDER_AGGREGATES;
  protected readonly datasets = signal<DatasetInfo[]>([]);
  protected readonly dataset = signal<string>('');
  protected readonly columns = signal<string[]>([]);
  protected readonly filters = signal<BuilderFilter[]>([]);
  protected readonly groupBy = signal<string | null>(null);
  protected readonly aggregate = signal<BuilderAggregate>('count');
  protected readonly aggregateColumn = signal<string | null>(null);
  protected readonly name = signal('');
  protected readonly savedId = signal<number | null>(null);
  protected readonly result = signal<BuilderResult | null>(null);
  protected readonly loading = signal(false);
  protected readonly available = computed(() => this.datasets().find((d) => d.key === this.dataset())?.columns ?? []);
  protected readonly numeric = computed(() => this.available().filter((c) => c.type === 'number'));
  protected readonly resultColumns = computed(() => {
    const res = this.result();
    const types = new Map(this.available().map((c) => [c.key, c.type] as const));
    return (res?.columns ?? []).map((key) => ({ key, type: key === 'value' ? 'number' : (types.get(key) ?? 'string') }));
  });

  constructor() {
    effect(() => {
      const id = this.saved();
      if (id !== undefined) {
        this.api.saved().subscribe({
          next: (list) => {
            const found = list.find((s) => s.id === id && s.kind === 'builder');
            if (found && 'dataset' in found.definition) {
              this.load(found.definition);
              this.name.set(found.name);
              this.savedId.set(found.id);
            }
          },
          error: (e: unknown) => this.toast(reportsErrorKey(e)),
        });
      }
    });
  }

  ngOnInit(): void {
    this.api.datasets().subscribe({
      next: (list) => {
        this.datasets.set(list);
        if (this.dataset() === '' && list.length) {
          this.dataset.set(list[0].key);
        }
      },
      error: (e: unknown) => this.toast(reportsErrorKey(e)),
    });
  }

  protected val(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected pickDataset(key: string): void {
    this.dataset.set(key);
    this.columns.set([]);
    this.filters.set([]);
    this.groupBy.set(null);
    this.aggregateColumn.set(null);
    this.result.set(null);
  }

  protected addFilter(): void {
    this.filters.update((list) => [...list, { column: '', op: 'eq', value: '' }]);
  }

  protected removeFilter(index: number): void {
    this.filters.update((list) => list.filter((_, i) => i !== index));
  }

  protected patchFilter(index: number, patch: Partial<BuilderFilter> & { op?: BuilderOperator }): void {
    this.filters.update((list) => list.map((f, i) => (i === index ? { ...f, ...patch } : f)));
  }

  protected spec(): BuilderSpec {
    return cleanSpec({
      dataset: this.dataset(),
      columns: this.columns(),
      filters: this.filters(),
      group_by: this.groupBy(),
      aggregate: { fn: this.aggregate(), column: this.aggregate() === 'count' ? null : this.aggregateColumn() },
    });
  }

  protected run(): void {
    this.loading.set(true);
    this.api.build(this.spec()).subscribe({
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
    this.api.buildCsv(this.spec()).subscribe({ next: (blob) => saveBlob(blob, `${this.dataset()}.csv`), error: (e: unknown) => this.toast(reportsErrorKey(e)) });
  }

  protected save(): void {
    this.api.save(this.savedId(), this.name().trim(), 'builder', this.spec()).subscribe({
      next: (s) => {
        this.savedId.set(s.id);
        this.toast('reports.saved.done');
      },
      error: (e: unknown) => this.toast(reportsErrorKey(e)),
    });
  }

  private load(spec: BuilderSpec): void {
    this.dataset.set(spec.dataset);
    this.columns.set(spec.columns);
    this.filters.set(spec.filters);
    this.groupBy.set(spec.group_by ?? null);
    this.aggregate.set(spec.aggregate?.fn ?? 'count');
    this.aggregateColumn.set(spec.aggregate?.column ?? null);
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
