import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';

/** One switched-on column filter: `column` — translation key of the column title, `value` — what it filters by. */
export interface ActiveFilter {
  key: string;
  column: string;
  value: string;
  /** `value` is a translation key (a status, a role…). */
  i18n?: boolean;
}

/**
 * Chips of the column filters that are on but cannot be seen in a header right now (the column is hidden on a
 * narrow screen, or the list is shown as cards): «Column: value ×», and «Reset all» when there are several.
 * Same state as the headers (the URL); the page decides which filters to list and when to show the row.
 * `<app-active-filters [filters]="…" (remove)="setFilter($event, null)" (clearAll)="…" />`
 */
@Component({
  selector: 'app-active-filters',
  imports: [MatButtonModule, MatIconModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (filters().length > 0) {
      <div class="row" role="group" [attr.aria-label]="'table.active.title' | transloco">
        @for (f of filters(); track f.key) {
          <span class="chip">
            <span class="text"><span class="column">{{ f.column | transloco }}:</span> {{ f.i18n ? (f.value | transloco) : f.value }}</span>
            <button type="button" class="remove" [attr.aria-label]="'table.active.remove' | transloco: { column: (f.column | transloco) }" (click)="remove.emit(f.key)">
              <mat-icon aria-hidden="true">close</mat-icon>
            </button>
          </span>
        }
        @if (filters().length > 1) {
          <button mat-button type="button" class="all" (click)="clearAll.emit()">{{ 'table.active.clearAll' | transloco }}</button>
        }
      </div>
    }
  `,
  styles: `
    :host { display: block; }
    .row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; }
    .chip {
      display: inline-flex; align-items: center; gap: 0.125rem; max-width: 100%; min-height: 2rem; box-sizing: border-box;
      padding: 0 0.125rem 0 0.75rem; border: var(--app-border-w) solid var(--app-border); border-radius: 999px;
      background: var(--app-card); color: var(--mat-sys-on-surface); font: var(--mat-sys-label-large);
    }
    .text { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .column { color: var(--app-muted); }
    .remove {
      display: inline-grid; place-items: center; width: 1.75rem; height: 1.75rem; flex: none; padding: 0;
      border: 0; border-radius: 50%; background: none; color: inherit; cursor: pointer;
    }
    .remove mat-icon { width: 1.125rem; height: 1.125rem; font-size: 1.125rem; }
    .remove:hover { background: var(--app-row-hover); }
    .remove:focus-visible { outline: 2px solid var(--app-focus-ring); outline-offset: 2px; }
    /* Touch: every target at least 44px (WCAG 2.5.5). */
    @media (pointer: coarse) {
      .chip { min-height: 2.75rem; }
      .remove { width: 2.75rem; height: 2.75rem; }
    }
  `,
})
export class ActiveFilters {
  readonly filters = input.required<readonly ActiveFilter[]>();
  /** Key of the filter to switch off. */
  readonly remove = output<string>();
  readonly clearAll = output<void>();
}
