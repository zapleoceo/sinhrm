import { A11yModule } from '@angular/cdk/a11y';
import { NgTemplateOutlet } from '@angular/common';
import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  Injector,
  afterNextRender,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatRadioModule } from '@angular/material/radio';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ColumnFilter, FilterValue, RangeValue, ariaSort, isFilterActive } from './table-state';
import { TableSortDirective } from './table-sort.directive';
import { LIVE_FILTER_DEBOUNCE_MS, TableUrlState } from './table-url-state';

let nextId = 0;

/** A choice list longer than this gets a search field over its options. */
const SELECT_SEARCH_MIN = 8;

/**
 * Pause before the dialog's live region speaks a new row count: a screen reader says «Знайдено: N» once the user
 * stops typing (or stepping through a list with the arrows), not after every letter.
 */
export const COUNT_ANNOUNCE_DELAY_MS = 500;

/** Keys that step through a radio list (each step selects): such a choice waits like typing does. */
const STEP_KEYS = new Set(['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End']);

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
 * filter field (text / choice / range). Filters are live, there is no «apply»: text follows the typing (contains,
 * any case), a choice applies on click, a range on change / blur. Through the page's `TableUrlState` the URL (and a
 * server request) follows after LIVE_FILTER_DEBOUNCE_MS; without it the header debounces its output itself.
 * In a choice list a click / Space applies at once, arrow steps wait for the same pause (no request per step).
 * Enter or Esc close the dialog keeping the value and return focus (not while an IME composes: that Enter picks the
 * word); «Очистити» removes it. A URL change from outside while the dialog is open («back») replaces the draft.
 * A dot marks an active filter; a long choice list (> SELECT_SEARCH_MIN options) gets a search field over its
 * options. The row count is announced COUNT_ANNOUNCE_DELAY_MS after it settles.
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
          (detach)="onDetach()"
        >
          <form #panel class="popover" role="dialog" [id]="panelId" [attr.aria-label]="'table.filter.title' | transloco: { column: label() }" cdkTrapFocus
            (submit)="commit($event)" (keydown.enter)="onEnter($event)">
            @switch (filter()?.type) {
              @case ('text') {
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'table.filter.contains' | transloco }}</mat-label>
                  <!-- Live: the list follows the typing (TableUrlState.live → one URL change / request after a pause). -->
                  <input matInput #text type="search" autocomplete="off" maxlength="100" [value]="draftText()"
                    [attr.aria-label]="'table.filter.field' | transloco: { column: label() }"
                    (input)="typeText(text.value)" />
                </mat-form-field>
              }
              @case ('select') {
                @if (searchable()) {
                  <mat-form-field subscriptSizing="dynamic">
                    <mat-label>{{ 'table.filter.searchOptions' | transloco }}</mat-label>
                    <input matInput #search type="search" autocomplete="off" maxlength="100" class="option-search"
                      [attr.aria-controls]="listId" [value]="optionQuery()"
                      (input)="optionQuery.set(search.value)" (keydown)="onSearchKeydown($event)" />
                  </mat-form-field>
                }
                <!-- A click / Space / Enter applies at once; arrow steps wait for a pause (one request, not one per step). -->
                <mat-radio-group class="options" [id]="listId" [attr.aria-label]="label()" [value]="draftText()"
                  (keydown)="onListKeydown($event)" (pointerdown)="stepping = false" (change)="pick($event.value)">
                  <mat-radio-button value="">{{ 'table.filter.all' | transloco }}</mat-radio-button>
                  @for (o of visibleOptions(); track o.value) {
                    <mat-radio-button [value]="o.value">{{ o.text }}</mat-radio-button>
                  } @empty {
                    <p class="none muted">{{ 'table.filter.noOptions' | transloco }}</p>
                  }
                </mat-radio-group>
              }
              @case ('range') {
                <div class="range">
                  <mat-form-field subscriptSizing="dynamic">
                    <mat-label>{{ 'table.filter.from' | transloco }}</mat-label>
                    <input matInput #from [type]="rangeInput()" [value]="draftRange().from ?? ''"
                      (input)="setRange('from', from.value)" (change)="commitRange()" (blur)="commitRange()" />
                  </mat-form-field>
                  <mat-form-field subscriptSizing="dynamic">
                    <mat-label>{{ 'table.filter.to' | transloco }}</mat-label>
                    <input matInput #to [type]="rangeInput()" [value]="draftRange().to ?? ''"
                      (input)="setRange('to', to.value)" (change)="commitRange()" (blur)="commitRange()" />
                  </mat-form-field>
                </div>
              }
            }
            <!-- Always in the DOM (a live region added later is not announced); empty while the count is unknown. -->
            <p class="count" role="status" aria-live="polite">@if (shownCount() !== null) { {{ 'table.filter.found' | transloco: { n: shownCount() } }} }</p>
            <div class="buttons">
              <button mat-button type="button" class="clear" (click)="clear()" [disabled]="!clearable()">{{ 'table.filter.clear' | transloco }}</button>
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
    .none { margin: 0.5rem 0.75rem; }
    .range { display: flex; flex-direction: column; gap: 0.5rem; }
    .count { margin: 0; color: var(--mat-sys-on-surface-variant); font: var(--mat-sys-body-small); }
    .count:empty { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
    .buttons { display: flex; justify-content: flex-end; gap: 0.5rem; }
    @media (pointer: coarse) {
      .options mat-radio-button { display: flex; align-items: center; min-height: 2.75rem; }
      .buttons button { min-height: 2.75rem; }
    }
    @media (prefers-reduced-motion: reduce) { .arrow { transition: none; } }
  `,
})
export class ColumnHeader {
  private readonly table = inject(TableSortDirective, { optional: true });
  private readonly url = inject(TableUrlState, { optional: true });
  private readonly injector = inject(Injector);
  private readonly i18n = inject(TranslocoService);
  /** Translations of the active language (emits again when they load or the language changes). */
  private readonly translations = toSignal(this.i18n.selectTranslation(), { initialValue: {} });

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
  protected readonly listId = `${this.panelId}-options`;
  protected readonly open = signal(false);
  protected readonly draftText = signal('');
  protected readonly draftRange = signal<RangeValue>({ from: null, to: null });
  /** Search over the options of a long choice list. */
  protected readonly optionQuery = signal('');
  /** Row count spoken by the live region: `count()` once it has settled (COUNT_ANNOUNCE_DELAY_MS). */
  protected readonly shownCount = signal<number | null>(null);
  /** True after an arrow key in the choice list, until a pointer or another key: that choice is a step, not a pick. */
  protected stepping = false;

  private readonly trigger = viewChild<ElementRef<HTMLButtonElement>>('trigger');
  private readonly panel = viewChild<ElementRef<HTMLFormElement>>('panel');

  /** Last value sent in this filter session (no repeated output for the same value). */
  private sent: FilterValue = null;
  /** True once this session has sent a value: later edits replace its history entry instead of adding more. */
  private wrote = false;
  /** Own debounce of the output when the page has no TableUrlState. */
  private timer: ReturnType<typeof setTimeout> | null = null;
  private queued: { value: FilterValue } | null = null;
  private countTimer: ReturnType<typeof setTimeout> | null = null;
  private destroyed = false;

  protected readonly canSort = computed(() => this.sortable() && this.table !== null);
  protected readonly dir = computed(() => {
    const sort = this.table?.sort();
    return sort && sort.key === this.key() ? sort.dir : null;
  });
  protected readonly ariaSortValue = computed(() => (this.canSort() ? ariaSort(this.table?.sort() ?? null, this.key()) : null));
  protected readonly active = computed(() => isFilterActive(this.filterValue()));
  /** «Очистити» works for an applied value and for one still being typed. */
  protected readonly clearable = computed(() => this.active() || this.draftText().trim() !== '' || isFilterActive(this.draftRange()));
  /** Rows the table shows now (the table's `appTableSortCount`), announced in the dialog; null = not known. */
  protected readonly count = computed(() => this.table?.count() ?? null);
  /** Options with the text shown (a translation key is translated in the current language). */
  private readonly options = computed<readonly ShownOption[]>(() => {
    const f = this.filter();
    this.translations();
    return f?.type === 'select' ? f.options.map((o) => ({ value: o.value, text: o.i18n ? this.i18n.translate(o.label) : o.label })) : [];
  });
  protected readonly searchable = computed(() => this.options().length > SELECT_SEARCH_MIN);
  /** Options matching the search: contains, any case. «All» is outside the list and always shown. */
  protected readonly visibleOptions = computed(() => {
    const q = this.optionQuery().trim().toLocaleLowerCase();
    return q ? this.options().filter((o) => o.text.toLocaleLowerCase().includes(q)) : this.options();
  });
  protected readonly rangeInput = computed(() => {
    const f = this.filter();
    return f?.type === 'range' ? f.input : 'text';
  });

  constructor() {
    // A header that goes away (page left) drops a value still waiting; TableUrlState drops its own the same way.
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true;
      this.clearTimer();
      this.clearCountTimer();
    });
    // The count speaks once it settles (typing changes it on every letter in a client table).
    effect(() => {
      const n = this.count();
      untracked(() => this.announce(n));
    });
    // «Back» (or any URL change not made by this dialog) while it is open: the field shows what the table now has.
    effect(() => {
      const value = normalize(this.filterValue());
      const writing = this.url?.writing() ?? null;
      untracked(() => this.resync(value, writing !== null));
    });
  }

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
    this.optionQuery.set('');
    this.sent = normalize(value);
    this.wrote = false;
    this.stepping = false;
    this.clearCountTimer();
    this.shownCount.set(this.count());
    this.open.set(true);
  }

  /** Typing in the text filter: the value goes out after a pause, no Enter needed. */
  protected typeText(value: string): void {
    this.draftText.set(value);
    this.send(value, LIVE_FILTER_DEBOUNCE_MS);
  }

  /**
   * A choice applies at once; the dialog stays open (Enter / Esc close it). An arrow step through the list selects
   * too, but waits LIVE_FILTER_DEBOUNCE_MS like typing: running down 30 options makes one request, not 30.
   */
  protected pick(value: string): void {
    this.draftText.set(value);
    const step = this.stepping;
    this.stepping = false;
    this.send(value, step ? LIVE_FILTER_DEBOUNCE_MS : 0);
  }

  protected onListKeydown(event: KeyboardEvent): void {
    this.stepping = STEP_KEYS.has(event.key);
  }

  protected setRange(edge: keyof RangeValue, value: string): void {
    this.draftRange.update((r) => ({ ...r, [edge]: value || null }));
  }

  /** Range: applied on change / blur of a field after a short pause (the other field is often next). */
  protected commitRange(): void {
    this.send(this.draftRange(), LIVE_FILTER_DEBOUNCE_MS);
  }

  /** Enter in a field: the current value goes out now and the dialog closes (the value stays applied). */
  protected onEnter(event: Event): void {
    if (composing(event)) return; // Enter that confirms an IME word: not a commit
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return; // Enter on «Очистити» stays that button's click
    if (target.classList.contains('option-search')) {
      const first = this.visibleOptions()[0];
      if (first && this.optionQuery().trim() !== '') this.pick(first.value);
    }
    this.commit(event);
  }

  /** Form submit: sends the draft at once and closes. */
  protected commit(event: Event): void {
    event.preventDefault();
    const type = this.filter()?.type;
    if (type === 'range') this.send(this.draftRange(), 0);
    else if (type === 'text') this.send(this.draftText(), 0);
    this.close();
  }

  /** Arrow down from the option search moves into the list: onto the chosen option, or the first one shown. */
  protected onSearchKeydown(event: KeyboardEvent): void {
    if (event.key !== 'ArrowDown') return;
    const list = this.panel()?.nativeElement.querySelector(`#${this.listId}`);
    const radio = list?.querySelector<HTMLInputElement>('input[type=radio]:checked') ?? list?.querySelector<HTMLInputElement>('input[type=radio]');
    if (radio) {
      event.preventDefault();
      radio.focus();
    }
  }

  protected clear(): void {
    this.clearTimer();
    this.queued = null;
    this.sent = null;
    this.draftText.set('');
    this.draftRange.set({ from: null, to: null });
    this.filterChange.emit(null);
    this.close();
  }

  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && !composing(event)) {
      event.preventDefault();
      event.stopPropagation();
      this.close();
    }
  }

  /** A click on the funnel itself toggles; any other click outside just closes (focus stays where the user clicked). */
  protected onOutsideClick(event: MouseEvent): void {
    const target = event.target as Node | null;
    if (target && this.trigger()?.nativeElement.contains(target)) return;
    this.flush();
    this.open.set(false);
  }

  /** Overlay detached (closed from outside): a value still waiting goes out now. */
  protected onDetach(): void {
    if (this.destroyed) return; // the overlay goes with the page: nothing is sent while leaving
    this.flush();
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

  /**
   * Sends a filter value unless it is the one already sent. With the page's TableUrlState the output goes at once
   * inside `url.live()` (the page's `update()` waits `delay` ms there; a ClientTable shows the rows meanwhile);
   * without it the header waits `delay` ms itself.
   */
  private send(raw: FilterValue, delay: number): void {
    const value = normalize(raw);
    if (sameFilter(value, this.sent)) {
      if (delay === 0) this.flush();
      return;
    }
    this.sent = value;
    if (this.url) {
      const replace = this.wrote;
      this.wrote = true;
      this.url.live(() => this.filterChange.emit(value), { delay, replace });
      return;
    }
    this.clearTimer();
    this.queued = { value };
    if (delay === 0) this.flush();
    else this.timer = setTimeout(() => this.flush(), delay);
  }

  /** Sends what waits now: the header's own queued value, or the live edit queued in TableUrlState. */
  private flush(): void {
    this.clearTimer();
    const queued = this.queued;
    this.queued = null;
    if (queued) this.filterChange.emit(queued.value);
    this.url?.flush();
  }

  private clearTimer(): void {
    if (this.timer !== null) clearTimeout(this.timer);
    this.timer = null;
  }

  private announce(n: number | null): void {
    this.clearCountTimer();
    if (!this.open()) {
      this.shownCount.set(n);
      return;
    }
    this.countTimer = setTimeout(() => {
      this.countTimer = null;
      this.shownCount.set(n);
    }, COUNT_ANNOUNCE_DELAY_MS);
  }

  private clearCountTimer(): void {
    if (this.countTimer !== null) clearTimeout(this.countTimer);
    this.countTimer = null;
  }

  /**
   * The table's value changed while the dialog is open. Our own edits are still on their way (`writing`, or the
   * header's own queue) or match what was sent — nothing to do. Otherwise the URL changed from outside («back»):
   * the field takes the table's value, and the next edit starts a new history entry.
   */
  private resync(value: FilterValue, writing: boolean): void {
    if (!this.open() || writing || this.queued || sameFilter(value, this.sent)) return;
    this.sent = value;
    this.wrote = false;
    this.draftText.set(typeof value === 'string' ? value : '');
    this.draftRange.set(value !== null && typeof value === 'object' ? { ...value } : { from: null, to: null });
  }

  private close(): void {
    this.flush();
    this.open.set(false);
    this.trigger()?.nativeElement.focus();
  }
}

interface ShownOption {
  value: string;
  text: string;
}

/** Trimmed text, or a range with an edge set; anything empty → null (the filter is off). */
function normalize(value: FilterValue): FilterValue {
  if (!isFilterActive(value)) return null;
  return typeof value === 'string' ? value.trim() : value;
}

/** True for a key event that belongs to an IME composition (229: older engines report the composition this way). */
function composing(event: Event): boolean {
  return event instanceof KeyboardEvent && (event.isComposing || event.keyCode === 229);
}

function sameFilter(a: FilterValue, b: FilterValue): boolean {
  if (a === null || b === null || typeof a === 'string' || typeof b === 'string') return a === b;
  return (a.from ?? null) === (b.from ?? null) && (a.to ?? null) === (b.to ?? null);
}
