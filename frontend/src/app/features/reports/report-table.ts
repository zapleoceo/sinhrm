import { DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { Cell, ColumnType, Row, barPercent, columnMax } from './reports.model';

/** A report table; with a chart spec the label/value columns also get a plain CSS bar (no chart library). */
@Component({
  selector: 'app-report-table',
  imports: [DecimalPipe, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (chart(); as ch) {
      <div class="chart" role="img" [attr.aria-label]="'reports.chart' | transloco">
        @for (r of rows(); track $index) {
          <div class="bar-row">
            <span class="label">{{ text(r[ch.label]) }}</span>
            <span class="bar" [style.width.%]="bar(r[ch.value])"></span>
            <span class="val">{{ text(r[ch.value]) }}</span>
          </div>
        }
      </div>
    }
    <div class="scroll">
      <table>
        <thead>
          <tr>
            @for (c of columns(); track c.key) {
              <th scope="col" [class.num]="numeric(c.type)">{{ 'reports.columns.' + c.key | transloco }}</th>
            }
          </tr>
        </thead>
        <tbody>
          @for (r of rows(); track $index) {
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
            <tr><td [attr.colspan]="columns().length" class="muted">{{ 'reports.noRows' | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    .chart { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 1rem; }
    .bar-row { display: grid; grid-template-columns: minmax(6rem, 14rem) 1fr auto; gap: 0.5rem; align-items: center; }
    .bar-row .bar { display: block; height: 0.6rem; border-radius: 999px; background: var(--mat-sys-primary); min-width: 2px; }
    .label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85rem; }
    .val { font-variant-numeric: tabular-nums; font-size: 0.85rem; }
    .scroll { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.3rem 0.5rem; border-bottom: 1px solid var(--app-border); font-weight: normal; }
    thead th { color: var(--app-muted); font-size: 0.8rem; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
  `,
})
export class ReportTable {
  readonly columns = input.required<{ key: string; type: ColumnType | 'string' | 'number' | 'date' }[]>();
  readonly rows = input.required<Row[]>();
  readonly chart = input<{ label: string; value: string } | null>(null);

  private readonly max = computed(() => {
    const ch = this.chart();
    return ch === null ? 0 : columnMax(this.rows(), ch.value);
  });

  protected bar(value: Cell): number {
    return barPercent(value, this.max());
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
