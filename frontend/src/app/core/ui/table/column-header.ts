import { A11yModule } from '@angular/cdk/a11y';
import { NgTemplateOutlet } from '@angular/common';
import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  computed,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatRadioModule } from '@angular/material/radio';
import { TranslocoPipe } from '@jsverse/transloco';
import { ColumnFilter, FilterOption, FilterValue, RangeValue, ariaSort, isFilterActive } from './table-state';
import { TableSortDirective } from './table-sort.directive';

let nextId = 0;

const POSITIONS: ConnectedPosition[] = [
  { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 4 },
  { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 4 },
  { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -4 },
];

/**
 * Column header with sorting and a filter (docs/modules/core.md → «Заголовок таблицы»):
 * `<th scope="col" app-column-header key="position" [label]="…" [filter]="…" [filterValue]="…" (filterChange)="…">`
 * inside `<table [appTableSort]="sort()" (appTableSortChange)="…">`.
 * The title is a button (Enter/Space sort; aria-sort on the th); the funnel button opens a small dialog with the
 * filter field (text / choice / range); Esc closes it and returns focus. A dot marks an active filter.
 * Content between the tags (e.g. a channel icon) is shown before the title, inside the sort button.
 */
@Component({
  // An attribute on the real <th>: aria-sort and the column name must sit on the header cell itself (an element
  // inside it would break the table semantics), like Material's th[mat-sort-header].
  // eslint-disable-next-line @angular-eslint/component-selector
  selector: 'th[app-column-header]',
  imports: [A11yModule, NgTemplateOutlet, CdkConnectedOverlay, CdkOverlayOrigin, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatRadioModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  // aria-label = the plain title, so a screen reader names the column «Посада», not «Посада, Фільтр…» (the buttons
  // inside keep their own names).
  host: { class: 'app-th', '[attr.aria-sort]': 'ariaSortValue()', '[attr.aria-label]': 'label()' },
  template: `
    <!-- Optional lead content (an icon before the title): projected once, shown in either title variant. -->
    <ng-template #lead><ng-content /></ng-template>
    <span class="cell">
      @if (canSort()) {
        <button type="button" class="title" (click)="toggleSort()">
          <ng-container [ngTemplateOutlet]="lead" />
          <span class="text">{{ label() }}</span>
          <mat-icon class="arrow" aria-hidden="true" [attr.data-dir]="dir()">{{ dir() === 'desc' ? 'arrow_downward' : dir() === 'asc' ? 'arrow_upward' : 'swap_vert' }}</mat-icon>
        </button>
      } @else {
        <span class="title static"><ng-container [ngTemplateOutlet]="lead" />{{ label() }}</span>
      }
      @if (filter()) {
        <button
          #trigger
          type="button"
          class="filter"
          cdkOverlayOrigin
          #origin="cdkOverlayOrigin"
          [class.active]="active()"
          [attr.aria-label]="(active() ? 'table.filter.openActive' : 'table.filter.open') | transloco: { column: label() }"
          aria-haspopup="dialog"
          [attr.aria-expanded]="open()"
          [attr.aria-controls]="open() ? panelId : null"
          (click)="toggleOpen()"
        >
          <mat-icon aria-hidden="true">filter_list</mat-icon>
          @if (active()) {
            <span class="dot" aria-hidden="true"></span>
          }
        </button>
        <ng-template
          cdkConnectedOverlay
          [cdkConnectedOverlayOrigin]="origin"
          [cdkConnectedOverlayOpen]="open()"
          [cdkConnectedOverlayPositions]="positions"
          [cdkConnectedOverlayPush]="true"
          [cdkConnectedOverlayViewportMargin]="8"
          (overlayOutsideClick)="onOutsideClick($event)"
          (overlayKeydown)="onKeydown($event)"
          (attach)="focusFirstField()"
          (detach)="open.set(false)"
        >
          <form #panel class="popover" role="dialog" [id]="panelId" [attr.aria-label]="'table.filter.title' | transloco: { column: label() }" cdkTrapFocus (submit)="apply($event)">
            @switch (filter()?.type) {
              @case ('text') {
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'table.filter.contains' | transloco }}</mat-label>
                  <input matInput #text type="search" autocomplete="off" maxlength="100" [value]="draftText()" (input)="draftText.set(text.value)" />
                </mat-form-field>
              }
              @case ('select') {
                <mat-radio-group class="options" [attr.aria-label]="label()" [value]="draftText()" (change)="draftText.set($event.value)">
                  <mat-radio-button value="">{{ 'table.filter.all' | transloco }}</mat-radio-button>
                  @for (o of options(); track o.value) {
                    <mat-radio-button [value]="o.value">{{ o.i18n ? (o.label | transloco) : o.label }}</mat-radio-button>
                  }
                </mat-radio-group>
              }
              @case ('range') {
                <div class="range">
                  <mat-form-field subscriptSizing="dynamic">
                    <mat-label>{{ 'table.filter.from' | transloco }}</mat-label>
                    <input matInput #from [type]="rangeInput()" [value]="draftRange().from ?? ''" (input)="setRange('from', from.value)" />
                  </mat-form-field>
                  <mat-form-field subscriptSizing="dynamic">
                    <mat-label>{{ 'table.filter.to' | transloco }}</mat-label>
                    <input matInput #to [type]="rangeInput()" [value]="draftRange().to ?? ''" (input)="setRange('to', to.value)" />
                  </mat-form-field>
                </div>
              }
            }
            <div class="buttons">
              <button mat-button type="button" class="clear" (click)="clear()" [disabled]="!active()">{{ 'table.filter.clear' | transloco }}</button>
              <button mat-flat-button type="submit">{{ 'table.filter.apply' | transloco }}</button>
            </div>
          </form>
        </ng-template>
      }
    </span>
  `,
  styles: `
    /* One line, as in .app-table: a wrapped title would outgrow the cell (mat-table cells clip). */
    :host { vertical-align: middle; white-space: nowrap; }
    .cell { display: inline-flex; align-items: center; gap: 0.125rem; }
    .title {
      display: inline-flex; align-items: center; gap: 0.25rem; min-height: 2rem; margin: -0.25rem -0.375rem; padding: 0.25rem 0.375rem;
      border: 0; border-radius: var(--app-radius-sm); background: none; color: inherit; font: inherit; text-align: inherit; cursor: pointer;
    }
    .title.static { cursor: default; }
    button.title:hover { color: var(--mat-sys-on-surface); background: var(--app-row-hover); }
    .arrow { width: 1rem; height: 1rem; font-size: 1rem; line-height: 1; opacity: 0.45; transition: opacity var(--app-fast); }
    .arrow[data-dir] { opacity: 1; color: var(--mat-sys-primary); }
    button.title:hover .arrow, button.title:focus-visible .arrow { opacity: 1; }
    :host([aria-sort='ascending']), :host([aria-sort='descending']) { color: var(--mat-sys-on-surface); }
    .filter {
      position: relative; display: inline-grid; place-items: center; width: 2rem; height: 2rem; flex: none; padding: 0;
      border: 0; border-radius: 50%; background: none; color: inherit; cursor: pointer;
    }
    .filter mat-icon { width: 1.125rem; height: 1.125rem; font-size: 1.125rem; }
    .filter:hover, .filter[aria-expanded='true'] { background: var(--app-row-hover); color: var(--mat-sys-on-surface); }
    .filter.active { color: var(--mat-sys-primary); }
    .dot {
      position: absolute; top: 0.3rem; right: 0.3rem; width: 0.5rem; height: 0.5rem; border-radius: 50%;
      background: var(--mat-sys-primary); box-shadow: 0 0 0 2px var(--app-table-head);
    }
    .title:focus-visible, .filter:focus-visible { outline: 2px solid var(--app-focus-ring); outline-offset: 2px; }
    /* Touch: every target at least 44px (WCAG 2.5.5), without growing the row on desktop. */
    @media (pointer: coarse) {
      .title { min-height: 2.75rem; }
      .filter { width: 2.75rem; height: 2.75rem; }
    }
    .popover {
      display: flex; flex-direction: column; gap: 0.75rem; width: min(18rem, calc(100vw - 2rem)); box-sizing: border-box; padding: 0.875rem;
      border: var(--app-border-w) solid var(--app-border); border-radius: var(--app-radius); background: var(--app-card);
      box-shadow: var(--app-overlay-shadow); color: var(--mat-sys-on-surface); font: var(--mat-sys-body-medium); white-space: normal;
    }
    .options { display: flex; flex-direction: column; max-height: 16rem; overflow-y: auto; }
    .range { display: flex; flex-direction: column; gap: 0.5rem; }
    .buttons { display: flex; justify-content: flex-end; gap: 0.5rem; }
    @media (prefers-reduced-motion: reduce) { .arrow { transition: none; } }
  `,
})
export class ColumnHeader {
  private readonly table = inject(TableSortDirective, { optional: true });
  private readonly injector = inject(Injector);

  /** Column key (the API sort / filter name). */
  readonly key = input.required<string>();
  /** Visible column title (already translated). */
  readonly label = input.required<string>();
  /** false = title only (e.g. a column the API cannot sort). */
  readonly sortable = input(true);
  readonly filter = input<ColumnFilter | null>(null);
  readonly filterValue = input<FilterValue>(null);
  readonly filterChange = output<FilterValue>();

  protected readonly positions = POSITIONS;
  protected readonly panelId = `app-th-filter-${++nextId}`;
  protected readonly open = signal(false);
  protected readonly draftText = signal('');
  protected readonly draftRange = signal<RangeValue>({ from: null, to: null });

  private readonly trigger = viewChild<ElementRef<HTMLButtonElement>>('trigger');
  private readonly panel = viewChild<ElementRef<HTMLFormElement>>('panel');

  protected readonly canSort = computed(() => this.sortable() && this.table !== null);
  protected readonly dir = computed(() => {
    const sort = this.table?.sort();
    return sort && sort.key === this.key() ? sort.dir : null;
  });
  protected readonly ariaSortValue = computed(() => (this.canSort() ? ariaSort(this.table?.sort() ?? null, this.key()) : null));
  protected readonly active = computed(() => isFilterActive(this.filterValue()));
  protected readonly options = computed<readonly FilterOption[]>(() => {
    const f = this.filter();
    return f?.type === 'select' ? f.options : [];
  });
  protected readonly rangeInput = computed(() => {
    const f = this.filter();
    return f?.type === 'range' ? f.input : 'text';
  });

  protected toggleSort(): void {
    this.table?.toggle(this.key());
  }

  protected toggleOpen(): void {
    if (this.open()) {
      this.close();
      return;
    }
    const value = this.filterValue();
    this.draftText.set(typeof value === 'string' ? value : '');
    this.draftRange.set(value !== null && typeof value === 'object' ? { ...value } : { from: null, to: null });
    this.open.set(true);
  }

  protected setRange(edge: keyof RangeValue, value: string): void {
    this.draftRange.update((r) => ({ ...r, [edge]: value || null }));
  }

  protected apply(event: Event): void {
    event.preventDefault();
    const type = this.filter()?.type;
    const value: FilterValue = type === 'range' ? this.draftRange() : this.draftText().trim();
    this.filterChange.emit(isFilterActive(value) ? value : null);
    this.close();
  }

  protected clear(): void {
    this.filterChange.emit(null);
    this.close();
  }

  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      this.close();
    }
  }

  /** A click on the funnel itself toggles; any other click outside just closes (focus stays where the user clicked). */
  protected onOutsideClick(event: MouseEvent): void {
    const target = event.target as Node | null;
    if (target && this.trigger()?.nativeElement.contains(target)) return;
    this.open.set(false);
  }

  protected focusFirstField(): void {
    afterNextRender(
      () => {
        const form = this.panel()?.nativeElement;
        const field = form?.querySelector<HTMLElement>('input:not([type=radio]), input[type=radio]:checked, input[type=radio]');
        field?.focus();
      },
      { injector: this.injector },
    );
  }

  private close(): void {
    this.open.set(false);
    this.trigger()?.nativeElement.focus();
  }
}
