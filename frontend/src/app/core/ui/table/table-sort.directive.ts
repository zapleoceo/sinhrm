import { Directive, input, output } from '@angular/core';
import { TableSort, nextSort } from './table-state';

/**
 * Sort state of one table, shared by its `th[app-column-header]` cells:
 * `<table class="app-table" [appTableSort]="sort()" (appTableSortChange)="onSort($event)">`.
 * The table owner keeps the state (URL, store); the directive only computes the next one on a click.
 * `[appTableSortCount]="table.rows().length"` (or the server total) lets the header filters announce the result.
 */
@Directive({ selector: '[appTableSort]', exportAs: 'appTableSort' })
export class TableSortDirective {
  /** Current sort (pass the default order of the list too, so its column shows the arrow). */
  readonly sort = input<TableSort | null>(null, { alias: 'appTableSort' });
  /** Third click on a column returns to the default order (null) instead of ascending again. */
  readonly clearable = input(false, { alias: 'appTableSortClearable' });
  /**
   * Rows the table shows now (after its filters; a server table — the total). An open header filter announces it
   * in a polite live region as the user types; null = do not announce.
   */
  readonly count = input<number | null>(null, { alias: 'appTableSortCount' });
  readonly appTableSortChange = output<TableSort | null>();

  toggle(key: string): void {
    this.appTableSortChange.emit(nextSort(this.sort(), key, this.clearable()));
  }
}
