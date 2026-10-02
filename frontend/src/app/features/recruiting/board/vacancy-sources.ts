import { DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, effect, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { VacancySourceRow } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { ChannelIcon } from '../../../core/ui/channel-icon';
import { ClientTable, NUMBER_RANGE, TEXT_FILTER, distinctValues, translatedSelect } from '../../../core/ui/table/client-table';
import { ColumnHeader } from '../../../core/ui/table/column-header';
import { TableSortDirective } from '../../../core/ui/table/table-sort.directive';
import { TableUrlState } from '../../../core/ui/table/table-url-state';

/**
 * Tz3 vacancy block "where applicants came from": channel × how added, count and share (collapsible). Headers sort
 * and filter (core/ui/table, URL `src_sort`, `src_<column>`); a link with them opens the block already expanded.
 */
@Component({
  selector: 'app-vacancy-sources',
  imports: [ChannelIcon, DecimalPipe, TranslocoPipe, TableSortDirective, ColumnHeader],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <details class="panel" [open]="table.touched()">
      <summary>{{ 'recruiting.channels.vacancySources' | transloco: { n: total() } }}</summary>
      <div class="scroll">
      <table class="app-table" [appTableSort]="table.sort()" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="channel" [label]="'recruiting.channels.channel' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('channel')" (filterChange)="table.setFilter('channel', $event)"></th>
            <th scope="col" app-column-header key="via" [label]="'recruiting.channels.addedVia' | transloco"
              [filter]="viaFilter()" [filterValue]="table.filterValue('via')" (filterChange)="table.setFilter('via', $event)"></th>
            <th scope="col" class="num" app-column-header key="count" [label]="'recruiting.channels.count' | transloco"
              [filter]="numberRange" [filterValue]="table.filterValue('count')" (filterChange)="table.setFilter('count', $event)"></th>
            <th scope="col" class="num" app-column-header key="share" [label]="'recruiting.channels.share' | transloco"
              [filter]="numberRange" [filterValue]="table.filterValue('share')" (filterChange)="table.setFilter('share', $event)"></th>
          </tr>
        </thead>
        <tbody>
          @for (r of table.rows(); track $index) {
            <tr>
              <td>{{ r.name ?? ('recruiting.channels.none' | transloco) }}</td>
              <td>
                @if (r.added_via) {
                  <app-channel-icon [key]="r.added_via" /> {{ 'recruiting.addedVia.' + r.added_via | transloco }}
                } @else {
                  —
                }
              </td>
              <td class="num">{{ r.count }}</td>
              <td class="num">{{ r.share_pct | number: '1.0-1' }}%</td>
            </tr>
          } @empty {
            <tr><td colspan="4" class="muted">{{ (rows().length ? 'table.noMatches' : 'recruiting.channels.noApplicants') | transloco }}</td></tr>
          }
        </tbody>
      </table>
      </div>
    </details>
  `,
  styles: `
    details { padding: 0.5rem 1rem; margin-bottom: var(--app-gap); }
    summary { cursor: pointer; font: var(--mat-sys-title-small); }
    .scroll { overflow-x: auto; margin-top: 0.5rem; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
  `,
})
export class VacancySources {
  readonly vacancyId = input.required<number>();
  private readonly api = inject(RecruitingService);
  protected readonly rows = signal<VacancySourceRow[]>([]);
  protected readonly total = signal(0);
  protected readonly textFilter = TEXT_FILTER;
  protected readonly numberRange = NUMBER_RANGE;
  protected readonly viaFilter = translatedSelect(
    () => distinctValues(this.rows(), (r) => r.added_via),
    (v) => 'recruiting.addedVia.' + v,
  );
  /** API order: most applicants first. */
  protected readonly table = new ClientTable<VacancySourceRow>({
    rows: this.rows,
    prefix: 'src',
    defaultSort: { key: 'count', dir: 'desc' },
    columns: [
      { key: 'channel', value: (r) => r.name, filter: 'text' },
      { key: 'via', value: (r) => r.added_via, filter: 'select' },
      { key: 'count', value: (r) => r.count, filter: 'number' },
      { key: 'share', value: (r) => r.share_pct, filter: 'number' },
    ],
  });

  constructor() {
    effect(() => {
      const id = this.vacancyId();
      this.api.vacancySources(id).subscribe({
        next: (rows) => {
          this.rows.set(rows);
          this.total.set(rows.reduce((sum, r) => sum + r.count, 0));
        },
        error: () => this.rows.set([]),
      });
    });
  }
}
