import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, OnInit, computed, effect, inject, signal, untracked, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { ActivatedRoute, ParamMap, RouterLink, convertToParamMap } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable, Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { toIsoDate, toIsoDateOrNull } from '../../core/date/iso-date';
import { ClientColumn, ClientTable, TEXT_FILTER, translatedSelect } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { PagedList } from '../../core/ui/table/paged-list';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { ColumnFilter, intParam, oneOfParam, sameQuery, textParam } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { PersonPicker, PickerValue } from '../people/picker/person-picker';
import { ASSET_STATUSES, ASSET_STATUS_TONE, Asset, AssetQuery, AssetStatus, AssetType, RETURN_STATUSES } from './assets.model';
import { AssetsService, assetsErrorKey } from './assets.service';
import { NotifyService } from '../../core/ui/notify.service';

/**
 * Columns of the inventory table (sorted and filtered on the page). Status and type also go to the API as server
 * filters (it returns at most 500 rows, so they must narrow the query, not only the page).
 */
export const ASSET_COLUMNS: readonly ClientColumn<Asset>[] = [
  { key: 'inventory', value: (a) => a.inventory_number, filter: 'text' },
  { key: 'name', value: (a) => a.name, filter: 'text' },
  { key: 'type', value: (a) => a.type?.name, filter: 'select', filterValue: (a) => (a.type ? String(a.type.id) : null) },
  { key: 'serial', value: (a) => a.serial, filter: 'text' },
  { key: 'status', value: (a) => ASSET_STATUSES.indexOf(a.status), filter: 'select', filterValue: (a) => a.status },
  { key: 'holder', value: (a) => a.employee?.full_name, filter: 'text' },
];

/** API query of the URL: search, status and type (junk values are dropped, never sent). */
export function assetQueryFromParams(params: ParamMap): AssetQuery {
  return { q: textParam(params, 'q'), status: oneOfParam(params, 'status', ASSET_STATUSES), type_id: intParam(params, 'type') };
}

/**
 * Inventory (/admin/assets): search on top, sortable / filterable column headers (state in the URL), new asset,
 * hand out / take back with history.
 */
@Component({
  selector: 'app-assets-page',
  imports: [
    DatePipe,
    FormsModule,
    PersonPicker,
    MatButtonModule,
    MatDatepickerModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    RouterLink,
    TranslocoPipe,
    TableSortDirective,
    ColumnHeader,
  ],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'assets.title' | transloco }}</h1>
        <p class="muted">{{ 'assets.subtitle' | transloco }}</p>
      </div>
      <button mat-flat-button type="button" (click)="formOpen.set(!formOpen())"><mat-icon>add</mat-icon>{{ 'assets.new' | transloco }}</button>
    </header>

    @if (formOpen()) {
      <form class="panel form" (submit)="$event.preventDefault(); create(inv.value, nm.value, serial.value, cost.value, bought.value)">
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'assets.inventoryNumber' | transloco }}</mat-label><input matInput #inv maxlength="64" required /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'assets.name' | transloco }}</mat-label><input matInput #nm maxlength="200" required /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'assets.serial' | transloco }}</mat-label><input matInput #serial maxlength="120" /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'assets.type' | transloco }}</mat-label>
          <mat-select [value]="newType()" (valueChange)="newType.set($event)">
            <mat-option [value]="null">—</mat-option>
            @for (t of types(); track t.id) {
              <mat-option [value]="t.id">{{ t.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'assets.cost' | transloco }}</mat-label><input matInput #cost type="number" min="0" step="0.01" /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'assets.purchasedAt' | transloco }}</mat-label><input matInput [matDatepicker]="dp1" #bought="matDatepickerInput" /><mat-datepicker-toggle matIconSuffix [for]="dp1" /><mat-datepicker #dp1 /></mat-form-field>
        <button mat-flat-button type="submit">{{ 'common.save' | transloco }}</button>
        <span class="grow"></span>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'assets.newType' | transloco }}</mat-label><input matInput #tp maxlength="120" /></mat-form-field>
        <button mat-stroked-button type="button" (click)="addType(tp.value); tp.value = ''">{{ 'assets.addType' | transloco }}</button>
      </form>
    }

    <!-- Search (number, name, serial at once) is not one column, so it stays on top; status and type are filtered in
         their column headers (sent to the API), the other columns on the page. All of it lives in the URL. -->
    <div class="filters">
      <mat-form-field class="grow" subscriptSizing="dynamic">
        <mat-label>{{ 'assets.search' | transloco }}</mat-label>
        <mat-icon matPrefix>search</mat-icon>
        <input matInput type="search" #q (input)="search$.next(q.value)" />
      </mat-form-field>
    </div>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="panel">
      <table class="app-table assets" [appTableSort]="table.sort()" [appTableSortCount]="table.rows().length" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="inventory" [label]="'assets.inventoryNumber' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('inventory')" (filterChange)="table.setFilter('inventory', $event)"></th>
            <th scope="col" app-column-header key="name" [label]="'assets.name' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('name')" (filterChange)="table.setFilter('name', $event)"></th>
            <th scope="col" app-column-header key="type" [label]="'assets.type' | transloco"
              [filter]="typeFilter()" [filterValue]="table.filterValue('type')" (filterChange)="table.setFilter('type', $event)"></th>
            <th scope="col" app-column-header key="serial" [label]="'assets.serial' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('serial')" (filterChange)="table.setFilter('serial', $event)"></th>
            <th scope="col" app-column-header key="status" [label]="'assets.statusLabel' | transloco"
              [filter]="statusFilter()" [filterValue]="table.filterValue('status')" (filterChange)="table.setFilter('status', $event)"></th>
            <th scope="col" app-column-header key="holder" [label]="'assets.holder' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('holder')" (filterChange)="table.setFilter('holder', $event)"></th>
            <th scope="col"><span class="visually-hidden">{{ 'assets.actions' | transloco }}</span></th>
          </tr>
        </thead>
        <tbody>
          @for (a of table.rows(); track a.id) {
            <tr>
              <td><strong class="app-num">{{ a.inventory_number }}</strong></td>
              <td>{{ a.name }}</td>
              <td>{{ a.type?.name ?? '—' }}</td>
              <td class="app-num serial">{{ a.serial ?? '—' }}</td>
              <td><span class="status app-pill" [attr.data-status]="a.status" [attr.data-tone]="statusTone[a.status]">{{ 'assets.status.' + a.status | transloco }}</span></td>
              <td>
                @if (a.employee) { <a [routerLink]="['/people', a.employee.id]" [queryParams]="{ tab: 'assets' }">{{ a.employee.full_name }}</a> } @else { — }
              </td>
              <td class="actions">
                @if (a.status === 'assigned') {
                  <button mat-button type="button" (click)="openMove(a, 'return')">{{ 'assets.return' | transloco }}</button>
                } @else if (a.status === 'in_stock') {
                  <button mat-button type="button" (click)="openMove(a, 'assign')">{{ 'assets.assign' | transloco }}</button>
                } @else {
                  <button mat-button type="button" (click)="toStock(a)">{{ 'assets.toStock' | transloco }}</button>
                }
                <button mat-icon-button type="button" (click)="history(a)" [attr.aria-label]="'assets.history' | transloco"><mat-icon>history</mat-icon></button>
              </td>
            </tr>
            @if (moving()?.asset?.id === a.id) {
              <tr class="move">
                <td colspan="7">
                  <form class="row" (submit)="$event.preventDefault(); move(date.value, cond.value)">
                    @if (moving()?.kind === 'assign') {
                      <app-person-picker class="grow" label="assets.employee" [ngModel]="employeeId()" (ngModelChange)="employeeId.set(asId($event))" [ngModelOptions]="{ standalone: true }" />
                    } @else {
                      <mat-form-field subscriptSizing="dynamic">
                        <mat-label>{{ 'assets.statusLabel' | transloco }}</mat-label>
                        <mat-select [value]="returnStatus()" (valueChange)="returnStatus.set($event)">
                          @for (s of returnStatuses; track s) {
                            <mat-option [value]="s">{{ 'assets.status.' + s | transloco }}</mat-option>
                          }
                        </mat-select>
                      </mat-form-field>
                    }
                    <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'assets.date' | transloco }}</mat-label><input matInput [matDatepicker]="dp2" #date="matDatepickerInput" /><mat-datepicker-toggle matIconSuffix [for]="dp2" /><mat-datepicker #dp2 /></mat-form-field>
                    <mat-form-field subscriptSizing="dynamic" class="grow"><mat-label>{{ 'assets.condition' | transloco }}</mat-label><input matInput #cond maxlength="255" /></mat-form-field>
                    <button mat-flat-button type="submit" [disabled]="moving()?.kind === 'assign' && employeeId() === null">{{ 'common.save' | transloco }}</button>
                    <button mat-button type="button" (click)="moving.set(null)">{{ 'common.cancel' | transloco }}</button>
                  </form>
                </td>
              </tr>
            }
            @if (opened()?.id === a.id) {
              <tr class="move">
                <td colspan="7">
                  <ul class="hist">
                    @for (h of opened()?.history ?? []; track h.id) {
                      <li>{{ h.employee.full_name }} · {{ h.assigned_at | date: 'dd.MM.yyyy' }} — {{ (h.returned_at | date: 'dd.MM.yyyy') ?? '…' }} {{ h.condition_out ?? '' }} {{ h.condition_in ? '→ ' + h.condition_in : '' }}</li>
                    } @empty {
                      <li class="muted">{{ 'assets.noHistory' | transloco }}</li>
                    }
                  </ul>
                </td>
              </tr>
            }
          } @empty {
            <tr><td colspan="7" class="muted">{{ (items().length ? 'table.noMatches' : 'assets.empty') | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    .form { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; padding: 1rem; margin-bottom: var(--app-gap); }
    .grow { flex: 1 1 12rem; }
    /* The hidden «actions» column title is position: absolute — keep it inside the scrolling panel, or it widens the page on phones. */
    .panel { position: relative; }
    .assets td { vertical-align: middle; }
    .actions { white-space: nowrap; text-align: right; }
    .row { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .status[data-status='written_off'] { text-decoration: line-through; }
    .serial { font-size: 0.8rem; }
    .hist { margin: 0; padding-left: 1rem; }
  `,
})
export class AssetsPage implements OnInit {
  private readonly api = inject(AssetsService);
  private readonly notify = inject(NotifyService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly statusTone = ASSET_STATUS_TONE;
  protected readonly returnStatuses = RETURN_STATUSES;
  /** A newer query wins: the previous request is cancelled, so an older answer never overwrites the list. */
  private readonly list = new PagedList<Asset>();
  protected readonly items = this.list.items;
  protected readonly types = signal<AssetType[]>([]);
  protected readonly loading = this.list.loading;
  protected readonly formOpen = signal(false);
  protected readonly newType = signal<number | null>(null);
  protected readonly moving = signal<{ asset: Asset; kind: 'assign' | 'return' } | null>(null);
  protected readonly opened = signal<Asset | null>(null);
  protected readonly employeeId = signal<number | null>(null);
  protected readonly returnStatus = signal<AssetStatus>('in_stock');
  private readonly url = inject(TableUrlState);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap, { initialValue: convertToParamMap({}) });
  /** Server part of the URL (search, status, type): only its change reloads the list, not sort or page filters. */
  private readonly query = computed(() => assetQueryFromParams(this.params()), { equal: sameQuery });
  protected readonly search$ = new Subject<string>();
  /** Search box value from the URL. */
  protected readonly search = computed(() => this.query().q ?? '');
  private readonly searchInput = viewChild<ElementRef<HTMLInputElement>>('q');
  protected readonly table = new ClientTable({ rows: this.items, columns: ASSET_COLUMNS, defaultSort: { key: 'inventory', dir: 'asc' } });
  protected readonly textFilter = TEXT_FILTER;
  protected readonly statusFilter = translatedSelect(() => ASSET_STATUSES, (s) => 'assets.status.' + s);
  protected readonly typeFilter = computed<ColumnFilter>(() => ({ type: 'select', options: this.types().map((t) => ({ value: String(t.id), label: t.name })) }));

  constructor() {
    effect(() => {
      const query = this.query();
      untracked(() => this.load(query));
    });
    // URL → search box («back», a link), but never while the user types in it: the URL holds the trimmed text, and
    // writing it back would eat the space between two words typed after a debounce pause.
    effect(() => {
      const value = this.search();
      const input = this.searchInput()?.nativeElement;
      if (input && input !== input.ownerDocument.activeElement && input.value !== value) input.value = value;
    });
  }

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.url.update({ q: q.trim() || null }));
    this.api.types().subscribe({ next: (list) => this.types.set(list), error: () => this.types.set([]) });
  }

  protected asId(value: PickerValue): number | null {
    return typeof value === 'number' ? value : null;
  }
  protected addType(name: string): void {
    if (name.trim() !== '') {
      this.api.createType(name.trim()).subscribe({ next: (t) => this.types.update((l) => [...l, t]), error: (e: unknown) => this.toast(assetsErrorKey(e)) });
    }
  }

  protected create(inventory: string, name: string, serial: string, cost: string, purchasedAt: Date | null): void {
    this.apply(
      this.api.save(null, {
        inventory_number: inventory.trim(),
        name: name.trim(),
        serial: serial.trim() || null,
        type_id: this.newType(),
        cost: cost === '' ? null : Number(cost),
        purchased_at: toIsoDateOrNull(purchasedAt),
      }),
    );
  }

  protected openMove(asset: Asset, kind: 'assign' | 'return'): void {
    this.opened.set(null);
    this.employeeId.set(null);
    this.returnStatus.set('in_stock');
    this.moving.set({ asset, kind });
  }

  protected move(day: Date | null, condition: string): void {
    const m = this.moving();
    const date = toIsoDate(day);
    if (m === null) {
      return;
    }
    const employeeId = this.employeeId();
    if (m.kind === 'assign' && employeeId !== null) {
      this.apply(this.api.assign(m.asset.id, employeeId, date, condition));
    } else if (m.kind === 'return') {
      this.apply(this.api.return(m.asset.id, this.returnStatus(), date, condition));
    }
  }

  protected toStock(a: Asset): void {
    this.apply(this.api.save(a.id, { status: 'in_stock' }));
  }

  protected history(a: Asset): void {
    this.moving.set(null);
    if (this.opened()?.id === a.id) {
      this.opened.set(null);
      return;
    }
    this.api.get(a.id).subscribe({ next: (full) => this.opened.set(full), error: (e: unknown) => this.toast(assetsErrorKey(e)) });
  }

  private load(query: AssetQuery): void {
    this.list.load(this.api.list(query), { error: (e) => this.toast(assetsErrorKey(e)) });
  }

  private apply(call: Observable<Asset>): void {
    call.subscribe({
      next: (saved) => {
        this.moving.set(null);
        this.toast('assets.saved');
        const exists = this.items().some((a) => a.id === saved.id);
        this.items.update((list) => (exists ? list.map((a) => (a.id === saved.id ? saved : a)) : [saved, ...list]));
      },
      error: (e: unknown) => this.toast(assetsErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.notify.show(key);
  }
}
