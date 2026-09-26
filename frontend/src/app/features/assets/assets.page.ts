import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
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
import { Observable, Subject, debounceTime, switchMap } from 'rxjs';
import { Employee } from '../people/people.model';
import { PeopleService } from '../people/people.service';
import { ASSET_STATUSES, Asset, AssetQuery, AssetStatus, AssetType, RETURN_STATUSES } from './assets.model';
import { AssetsService, assetsErrorKey } from './assets.service';
import { toIsoDate, toIsoDateOrNull } from '../../core/date/iso-date';

/** Inventory (/admin/assets): table with search and status, new asset, hand out / take back with history. */
@Component({
  selector: 'app-assets-page',
  imports: [DatePipe, MatButtonModule, MatDatepickerModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, RouterLink, TranslocoPipe],
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

    <div class="filters">
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'assets.search' | transloco }}</mat-label>
        <input matInput type="search" (input)="patch({ q: val($event) || undefined })" />
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'assets.statusLabel' | transloco }}</mat-label>
        <mat-select [value]="query().status ?? null" (valueChange)="patch({ status: $event ?? undefined })">
          <mat-option [value]="null">{{ 'common.all' | transloco }}</mat-option>
          @for (s of statuses; track s) {
            <mat-option [value]="s">{{ 'assets.status.' + s | transloco }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
    </div>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="panel scroll">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ 'assets.inventoryNumber' | transloco }}</th>
            <th scope="col">{{ 'assets.name' | transloco }}</th>
            <th scope="col">{{ 'assets.type' | transloco }}</th>
            <th scope="col">{{ 'assets.serial' | transloco }}</th>
            <th scope="col">{{ 'assets.statusLabel' | transloco }}</th>
            <th scope="col">{{ 'assets.holder' | transloco }}</th>
            <th scope="col"><span class="visually-hidden">{{ 'assets.actions' | transloco }}</span></th>
          </tr>
        </thead>
        <tbody>
          @for (a of items(); track a.id) {
            <tr>
              <td><strong>{{ a.inventory_number }}</strong></td>
              <td>{{ a.name }}</td>
              <td>{{ a.type?.name ?? '—' }}</td>
              <td>{{ a.serial ?? '—' }}</td>
              <td><span class="status" [attr.data-status]="a.status">{{ 'assets.status.' + a.status | transloco }}</span></td>
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
                      <mat-form-field subscriptSizing="dynamic" class="grow">
                        <mat-label>{{ 'assets.employee' | transloco }}</mat-label>
                        <input matInput (input)="peopleSearch.next(val($event))" [placeholder]="'assets.findEmployee' | transloco" />
                      </mat-form-field>
                      <mat-form-field subscriptSizing="dynamic" class="grow">
                        <mat-label>{{ 'assets.pick' | transloco }}</mat-label>
                        <mat-select [value]="employeeId()" (valueChange)="employeeId.set($event)">
                          @for (p of people(); track p.id) {
                            <mat-option [value]="p.id">{{ p.full_name }}</mat-option>
                          }
                        </mat-select>
                      </mat-form-field>
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
            <tr><td colspan="7" class="muted">{{ 'assets.empty' | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    .form { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; padding: 1rem; margin-bottom: var(--app-gap); }
    .grow { flex: 1 1 12rem; }
    .scroll { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid var(--app-border); font-weight: normal; }
    thead th { color: var(--app-muted); font-size: 0.8rem; }
    .actions { white-space: nowrap; text-align: right; }
    .row { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .status[data-status='assigned'] { color: var(--mat-sys-primary); }
    .status[data-status='repair'] { color: var(--app-warning); }
    .status[data-status='written_off'] { color: var(--app-muted); text-decoration: line-through; }
    .hist { margin: 0; padding-left: 1rem; }
  `,
})
export class AssetsPage implements OnInit {
  private readonly api = inject(AssetsService);
  private readonly peopleApi = inject(PeopleService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly statuses = ASSET_STATUSES;
  protected readonly returnStatuses = RETURN_STATUSES;
  protected readonly items = signal<Asset[]>([]);
  protected readonly types = signal<AssetType[]>([]);
  protected readonly query = signal<AssetQuery>({});
  protected readonly loading = signal(false);
  protected readonly formOpen = signal(false);
  protected readonly newType = signal<number | null>(null);
  protected readonly moving = signal<{ asset: Asset; kind: 'assign' | 'return' } | null>(null);
  protected readonly opened = signal<Asset | null>(null);
  protected readonly employeeId = signal<number | null>(null);
  protected readonly returnStatus = signal<AssetStatus>('in_stock');
  protected readonly people = signal<Employee[]>([]);
  protected readonly peopleSearch = new Subject<string>();
  private readonly reload = new Subject<void>();

  constructor() {
    this.peopleSearch
      .pipe(
        debounceTime(250),
        switchMap((q) => this.peopleApi.list({ q, status: 'active', perPage: 20 })),
        takeUntilDestroyed(),
      )
      .subscribe({ next: (page) => this.people.set(page.data), error: () => this.people.set([]) });
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
    this.reload.next();
    this.api.types().subscribe({ next: (list) => this.types.set(list), error: () => this.types.set([]) });
  }

  protected val(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected patch(p: Partial<AssetQuery>): void {
    this.query.update((q) => ({ ...q, ...p }));
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
