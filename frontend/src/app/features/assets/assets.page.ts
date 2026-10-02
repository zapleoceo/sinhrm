import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Observable, Subject, debounceTime, distinctUntilChanged, switchMap } from 'rxjs';
import { PersonPicker, PickerValue } from '../people/picker/person-picker';
import { ASSET_STATUSES, ASSET_STATUS_TONE, Asset, AssetQuery, AssetStatus, AssetType, RETURN_STATUSES } from './assets.model';
import { AssetsService, assetsErrorKey } from './assets.service';
import { toIsoDate, toIsoDateOrNull } from '../../core/date/iso-date';
import { ClientColumns, ClientTable, ClientTableQuery, enumFilter } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { ColumnFilter } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';

/**
 * Columns of the inventory table. Status and type are server filters (the API caps the list at 500 rows, so they
 * must narrow the query, not the page); `q` is the search above the table, also sent to the API.
 */
export const ASSET_COLUMNS: ClientColumns<Asset> = {
  q: { filter: 'text' },
  inventory: { sort: (a) => a.inventory_number, filter: 'text', match: (a) => a.inventory_number },
  name: { sort: (a) => a.name, filter: 'text', match: (a) => a.name },
  type: { sort: (a) => a.type?.name, filter: 'select' },
  serial: { sort: (a) => a.serial, filter: 'text', match: (a) => a.serial },
  status: { sort: (a) => ASSET_STATUSES.indexOf(a.status), filter: 'select', values: ASSET_STATUSES },
  holder: { sort: (a) => a.employee?.full_name, filter: 'text', match: (a) => a.employee?.full_name },
};

/** API query of the table state: only the server filters (junk type ids are dropped, not sent). */
export function assetQueryOf(q: ClientTableQuery): AssetQuery {
  const text = (v: unknown): string | undefined => (typeof v === 'string' ? v : undefined);
  const type = text(q.filters['type']);
  return {
    q: text(q.filters['q']),
    status: text(q.filters['status']) as AssetStatus | undefined,
    type_id: type && /^\d+$/.test(type) ? Number(type) : undefined,
  };
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
        <input matInput type="search" #q [value]="table.filter('q') ?? ''" (input)="search$.next(q.value)" />
      </mat-form-field>
    </div>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="panel">
      <table class="app-table assets" [appTableSort]="table.sort()" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="inventory" [label]="'assets.inventoryNumber' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('inventory')" (filterChange)="table.setFilter('inventory', $event)"></th>
            <th scope="col" app-column-header key="name" [label]="'assets.name' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('name')" (filterChange)="table.setFilter('name', $event)"></th>
            <th scope="col" app-column-header key="type" [label]="'assets.type' | transloco"
              [filter]="typeFilter()" [filterValue]="table.filter('type')" (filterChange)="table.setFilter('type', $event)"></th>
            <th scope="col" app-column-header key="serial" [label]="'assets.serial' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('serial')" (filterChange)="table.setFilter('serial', $event)"></th>
            <th scope="col" app-column-header key="status" [label]="'assets.statusLabel' | transloco"
              [filter]="statusFilter()" [filterValue]="table.filter('status')" (filterChange)="table.setFilter('status', $event)"></th>
            <th scope="col" app-column-header key="holder" [label]="'assets.holder' | transloco"
              [filter]="textFilter" [filterValue]="table.filter('holder')" (filterChange)="table.setFilter('holder', $event)"></th>
            <th scope="col"><span class="visually-hidden">{{ 'assets.actions' | transloco }}</span></th>
          </tr>
        </thead>
        <tbody>
          @for (a of rows(); track a.id) {
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
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly statusTone = ASSET_STATUS_TONE;
  protected readonly returnStatuses = RETURN_STATUSES;
  protected readonly items = signal<Asset[]>([]);
  protected readonly types = signal<AssetType[]>([]);
  /** Server part of the table state (search, status, type): a change reloads the list. */
  private readonly query = signal<AssetQuery>({});
  protected readonly loading = signal(false);
  protected readonly formOpen = signal(false);
  protected readonly newType = signal<number | null>(null);
  protected readonly moving = signal<{ asset: Asset; kind: 'assign' | 'return' } | null>(null);
  protected readonly opened = signal<Asset | null>(null);
  protected readonly employeeId = signal<number | null>(null);
  protected readonly returnStatus = signal<AssetStatus>('in_stock');
  private readonly reload = new Subject<void>();
  protected readonly search$ = new Subject<string>();
  // Declared after `query` and `reload`: the URL is read right away.
  protected readonly table = new ClientTable(ASSET_COLUMNS, { key: 'inventory', dir: 'asc' }, (q) => this.onQuery(q));
  protected readonly rows = this.table.rows(this.items);
  protected readonly textFilter: ColumnFilter = { type: 'text' };
  protected readonly statusFilter = enumFilter(ASSET_STATUSES, 'assets.status.');

  protected readonly typeFilter = computed<ColumnFilter>(() => ({ type: 'select', options: this.types().map((t) => ({ value: String(t.id), label: t.name })) }));

  constructor() {
    this.reload
      .pipe(
        debounceTime(200),
        switchMap(() => {
          this.loading.set(true);
          return this.api.list(this.query());
        }),
        takeUntilDestroyed(),
      )
      .subscribe({
        next: (list) => {
          this.items.set(list);
          this.loading.set(false);
        },
        error: (e: unknown) => {
          this.loading.set(false);
          this.toast(assetsErrorKey(e));
        },
      });
  }

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.table.setFilter('q', q.trim() || null));
    this.reload.next();
    this.api.types().subscribe({ next: (list) => this.types.set(list), error: () => this.types.set([]) });
  }

  protected asId(value: PickerValue): number | null {
    return typeof value === 'number' ? value : null;
  }

  /** URL changed: reload only when a server filter changed (sort and page-side filters need no request). */
  private onQuery(q: ClientTableQuery): void {
    const next = assetQueryOf(q);
    const prev = this.query();
    if (next.q === prev.q && next.status === prev.status && next.type_id === prev.type_id) return;
    this.query.set(next);
    this.reload.next();
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
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
