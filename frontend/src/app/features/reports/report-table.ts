import { DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ClientColumn, ClientFilterKind, ClientTable, DATE_RANGE, NUMBER_RANGE, TEXT_FILTER } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { ColumnFilter } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { Cell, ColumnType, Row, barPercent, columnMax } from './reports.model';

interface ReportTableColumn {
  key: string;
  type: ColumnType | 'string' | 'number' | 'date';
}

/** Header filter of a report column by its type: text contains, numbers and dates — a range. */
function filterKind(type: ReportTableColumn['type']): ClientFilterKind {
  return type === 'number' || type === 'percent' ? 'number' : type === 'date' ? 'date' : 'text';
}

const HEADER_FILTER: Record<ClientFilterKind, ColumnFilter> = { text: TEXT_FILTER, select: TEXT_FILTER, number: NUMBER_RANGE, date: DATE_RANGE };

/**
 * A report table (catalog and builder): every column sorts by a click on its title and filters by the funnel next to
 * it (core/ui/table, state in the URL as `r_sort`, `r_<column>`). The «Разом» row is the backend total of the whole
 * report: it stays at the bottom and never takes part in sorting. With a chart spec the label/value columns also get
 * a plain CSS bar (no chart library), in the order of the table.
 */
@Component({
  selector: 'app-report-table',
  imports: [DecimalPipe, TranslocoPipe, TableSortDirective, ColumnHeader],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (chart(); as ch) {
      <div class="chart" role="img" [attr.aria-label]="'reports.chart' | transloco">
        @for (r of view(); track $index) {
          <div class="bar-row">
            <span class="label">{{ text(r[ch.label]) }}</span>
            <span class="bar" [style.width.%]="bar(r[ch.value])"></span>
            <span class="val">{{ text(r[ch.value]) }}</span>
          </div>
        }
      </div>
    }
    <div class="scroll">
      <table class="app-table" [appTableSort]="table.sort()" [appTableSortCount]="table.rows().length" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            @for (c of columns(); track c.key) {
              <th scope="col" [class.num]="numeric(c.type)" app-column-header [key]="c.key" [label]="'reports.columns.' + c.key | transloco"
                [filter]="headerFilter(c.type)" [filterValue]="table.filterValue(c.key)" (filterChange)="table.setFilter(c.key, $event)"></th>
            }
          </tr>
        </thead>
        <tbody>
          @for (r of view(); track $index) {
            <tr>
              @for (c of columns(); track c.key) {
                <td [class.num]="numeric(c.type)">
                  @if (r[c.key] === null || r[c.key] === undefined) {
                    <span class="muted" [title]="'reports.suppressed' | transloco">—</span>
                  } @else if (numeric(c.type)) {
                    {{ asNumber(r[c.key]) | number: '1.0-2' }}{{ c.type === 'percent' ? '%' : '' }}
                  } @else {
                    {{ text(r[c.key]) }}
                  }
                </td>
              }
            </tr>
          } @empty {
            <tr><td [attr.colspan]="columns().length" class="muted">{{ (rows().length ? 'reports.noMatches' : 'reports.noRows') | transloco }}</td></tr>
          }
        </tbody>
        @if (footer(); as t) {
          <tfoot>
            <tr>
              @for (c of columns(); track c.key; let first = $first) {
                <td [class.num]="numeric(c.type)">
                  @if (first) {
                    {{ (table.filtered() ? 'reports.totalAll' : 'reports.total') | transloco }}
                  }
                  @if (t[c.key] === null || t[c.key] === undefined) {
                    @if (!first) {
                      <span class="muted">—</span>
                    }
                  } @else if (numeric(c.type)) {
                    {{ asNumber(t[c.key]) | number: '1.0-2' }}{{ c.type === 'percent' ? '%' : '' }}
                  } @else {
                    {{ text(t[c.key]) }}
                  }
                </td>
              }
            </tr>
          </tfoot>
        }
      </table>
    </div>
  `,
  styles: `
    @use '../../core/ui/styles/trace';
    /* Restyle C «Маршрут»: the chart is a set of route lines (brand), numbers in mono; the bars are drawn once on load
       («trace», transform only) and stay static with prefers-reduced-motion. */
    .chart { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 1rem; }
    .bar-row { display: grid; grid-template-columns: minmax(6rem, 14rem) 1fr auto; gap: 0.75rem; align-items: center; }
    .bar-row .bar { display: block; height: 0.5rem; border-radius: var(--app-radius-pill); background: var(--app-chart-1); min-width: 2px; }
    @include trace.draw('.bar-row .bar');
    .label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85rem; }
    .val { font-family: var(--app-font-mono); font-variant-numeric: tabular-nums; font-size: 0.8rem; font-weight: 500; }
    .scroll { overflow-x: auto; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    td.num { font-family: var(--app-font-mono); font-size: 0.8rem; font-weight: 500; }
    tfoot td {
      position: sticky; bottom: 0; background: var(--app-card-2); font-weight: 700;
      border-top: var(--app-border-w) solid var(--app-border); border-bottom: 0;
    }
  `,
})
export class ReportTable {
  readonly columns = input.required<ReportTableColumn[]>();
  readonly rows = input.required<Row[]>();
  readonly chart = input<{ label: string; value: string } | null>(null);
  /** The backend «Total» row; shown only for 2+ rows. */
  readonly totals = input<Row | null>(null);

  /** Columns come with the report: every one sorts, the filter follows its type. */
  private readonly clientColumns = computed<ClientColumn<Row>[]>(() =>
    this.columns().map((c) => ({ key: c.key, filter: filterKind(c.type), value: (r: Row) => r[c.key] })),
  );
  protected readonly table = new ClientTable<Row>({ rows: this.rows, columns: this.clientColumns, prefix: 'r' });
  /** Rows as shown: filtered and sorted in the browser (the report filters above stay in the API). */
  protected readonly view = this.table.rows;

  protected readonly footer = computed(() => (this.rows().length >= 2 ? this.totals() : null));

  private readonly max = computed(() => {
    const ch = this.chart();
    return ch === null ? 0 : columnMax(this.rows(), ch.value);
  });

  protected bar(value: Cell): number {
    return barPercent(value, this.max());
  }

  protected headerFilter(type: ReportTableColumn['type']): ColumnFilter {
    return HEADER_FILTER[filterKind(type)];
  }

  protected numeric(type: string): boolean {
    return type === 'number' || type === 'percent';
  }

  protected asNumber(value: Cell): number {
    return Number(value);
  }

  protected text(value: Cell | undefined): string {
    return value === null || value === undefined ? '—' : String(value);
  }
}
