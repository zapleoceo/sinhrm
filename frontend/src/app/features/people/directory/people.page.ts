import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { saveBlob } from '../../../core/http/api-error';
import { EmployeeBulkData, EmployeeBulkDialog } from './employee-bulk.dialog';
import { EmployeeBulkResult } from '../people.model';
import { PeopleService } from '../people.service';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { DictionaryItem } from '../../directory/directory.model';
import { DirectoryService } from '../../directory/directory.service';
import { initials } from '../org-tree';
import { canManagePeople } from '../people.access';
import { EMPLOYEE_STATUSES, Employee } from '../people.model';
import { EmployeeDialog, EmployeeDialogData } from '../profile/employee.dialog';
import { PeopleStore, PeopleView } from './people.store';
import { wideDialog } from '../../../core/ui/dialog';

/** People directory: search, filters (branch, department, position, status), table or cards; admins add people. */
@Component({
  selector: 'app-people-page',
  imports: [
    MatButtonModule,
    MatButtonToggleModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatPaginatorModule,
    MatProgressBarModule,
    MatSelectModule,
    RouterLink,
    TranslocoPipe,
  ],
  providers: [PeopleStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'people.directory.title' | transloco }}</h1>
        <p class="muted">{{ 'people.directory.subtitle' | transloco: { n: store.total() } }}</p>
      </div>
      <div class="actions">
        <a mat-stroked-button routerLink="/people/org-chart"><mat-icon>account_tree</mat-icon>{{ 'people.orgChart.title' | transloco }}</a>
        @if (canManage()) {
          <button mat-flat-button type="button" (click)="add()"><mat-icon>person_add</mat-icon>{{ 'people.directory.add' | transloco }}</button>
        }
      </div>
    </header>

    <div class="filters">
      <mat-form-field class="grow" subscriptSizing="dynamic">
        <mat-label>{{ 'people.directory.search' | transloco }}</mat-label>
        <mat-icon matPrefix>search</mat-icon>
        <input matInput type="search" #q (input)="search$.next(q.value)" />
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'people.fields.branch' | transloco }}</mat-label>
        <mat-select [value]="store.query().branch_id" (valueChange)="store.patchQuery({ branch_id: $event })">
          <mat-option [value]="undefined">{{ 'common.all' | transloco }}</mat-option>
          @for (b of branches(); track b.id) {
            <mat-option [value]="b.id">{{ b.name }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'people.fields.department' | transloco }}</mat-label>
        <mat-select [value]="store.query().department_id" (valueChange)="store.patchQuery({ department_id: $event })">
          <mat-option [value]="undefined">{{ 'common.all' | transloco }}</mat-option>
          @for (d of departments(); track d.id) {
            <mat-option [value]="d.id">{{ d.name }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'people.fields.position' | transloco }}</mat-label>
        <mat-select [value]="store.query().position_id" (valueChange)="store.patchQuery({ position_id: $event })">
          <mat-option [value]="undefined">{{ 'common.all' | transloco }}</mat-option>
          @for (p of positions(); track p.id) {
            <mat-option [value]="p.id">{{ p.name }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'people.fields.status' | transloco }}</mat-label>
        <mat-select [value]="store.query().status" (valueChange)="store.patchQuery({ status: $event })">
          <mat-option [value]="undefined">{{ 'people.directory.working' | transloco }}</mat-option>
          @for (s of statuses; track s) {
            <mat-option [value]="s">{{ 'people.status.' + s | transloco }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <mat-button-toggle-group
        [value]="store.view()"
        (change)="setView($event.value)"
        [attr.aria-label]="'people.directory.view' | transloco"
        hideSingleSelectionIndicator
      >
        <mat-button-toggle value="table" [attr.aria-label]="'people.directory.table' | transloco"><mat-icon>table_rows</mat-icon></mat-button-toggle>
        <mat-button-toggle value="cards" [attr.aria-label]="'people.directory.cards' | transloco"><mat-icon>grid_view</mat-icon></mat-button-toggle>
      </mat-button-toggle-group>
    </div>

    <section class="panel" aria-live="polite">
      @if (store.loading()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (store.failed()) {
        <div class="state">
          <p>{{ 'people.loadError' | transloco }}</p>
          <button mat-stroked-button type="button" (click)="store.load()">{{ 'common.retry' | transloco }}</button>
        </div>
      } @else if (!store.loading() && store.items().length === 0) {
        <p class="state muted">{{ 'people.directory.empty' | transloco }}</p>
      } @else if (store.view() === 'table') {
        @if (canManage() && selected().size > 0) {
          <div class="bulk-bar">
            <span>{{ 'bulk.selected' | transloco: { n: selected().size } }}</span>
            <button mat-stroked-button type="button" (click)="bulk()">{{ 'bulk.actions' | transloco }}</button>
            <button mat-stroked-button type="button" (click)="exportCsv()">{{ 'bulk.people.export' | transloco }}</button>
            <button mat-button type="button" (click)="clear()">{{ 'bulk.clear' | transloco }}</button>
          </div>
        }
        <table class="people app-table">
          <thead>
            <tr>
              @if (canManage()) {
                <th scope="col"></th>
              }
              <th scope="col">{{ 'people.fields.fullName' | transloco }}</th>
              <th scope="col">{{ 'people.fields.position' | transloco }}</th>
              <th scope="col" class="wide">{{ 'people.fields.department' | transloco }}</th>
              <th scope="col" class="wide">{{ 'people.fields.branch' | transloco }}</th>
              <th scope="col" class="wide">{{ 'people.fields.contacts' | transloco }}</th>
              <th scope="col" class="wide">{{ 'people.fields.manager' | transloco }}</th>
            </tr>
          </thead>
          <tbody>
            @for (e of store.items(); track e.id) {
              <tr>
                @if (canManage()) {
                  <td><mat-checkbox [checked]="selected().has(e.id)" (change)="toggle(e.id)" [attr.aria-label]="e.full_name" /></td>
                }
                <td>
                  <a class="person" [routerLink]="['/people', e.id]">
                    <span class="avatar" aria-hidden="true">{{ initialsOf(e) }}</span>
                    <span class="nm">{{ e.full_name }}</span>
                    @if (e.status !== 'active') {
                      <span class="badge app-pill" [attr.data-status]="e.status">{{ 'people.status.' + e.status | transloco }}</span>
                    }
                  </a>
                </td>
                <td>{{ e.position?.name ?? '—' }}</td>
                <td class="wide">{{ e.department?.name ?? '—' }}</td>
                <td class="wide">{{ e.branch?.name ?? '—' }}</td>
                <td class="wide"><span class="contacts">
                  @if (e.work_email) {
                    <a [href]="'mailto:' + e.work_email">{{ e.work_email }}</a>
                  }
                  @if (e.phone) {
                    <a class="mono" [href]="'tel:' + e.phone">{{ e.phone }}</a>
                  }
                </span></td>
                <td class="wide">
                  @if (e.manager) {
                    <a [routerLink]="['/people', e.manager.id]">{{ e.manager.name }}</a>
                  } @else {
                    —
                  }
                </td>
              </tr>
            }
          </tbody>
        </table>
      } @else {
        <ul class="cards">
          @for (e of store.items(); track e.id) {
            <li>
              <a class="card" [routerLink]="['/people', e.id]">
                <span class="avatar big" aria-hidden="true">{{ initialsOf(e) }}</span>
                <strong>{{ e.full_name }}</strong>
                <span class="muted">{{ e.position?.name ?? '—' }}</span>
                <span class="muted small">{{ e.department?.name }}{{ e.department && e.branch ? ' · ' : '' }}{{ e.branch?.name }}</span>
              </a>
            </li>
          }
        </ul>
      }
      <mat-paginator
        [length]="store.total()"
        [pageIndex]="(store.query().page ?? 1) - 1"
        [pageSize]="store.query().perPage"
        [pageSizeOptions]="[20, 50, 100]"
        (page)="onPage($event)"
      />
    </section>
  `,
  styles: `
    .bulk-bar {
      display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; padding: 0.5rem 1rem;
      border-bottom: var(--app-border-w) solid var(--app-track); background: var(--app-row-selected);
    }
    .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .people td { vertical-align: middle; }
    .person { display: inline-grid; grid-template-columns: auto 1fr; align-items: center; column-gap: 0.75rem; color: inherit; text-decoration: none; font-weight: 600; }
    .person .badge { grid-column: 2; justify-self: start; margin-top: 0.2rem; }
    .person:hover .nm, .person:focus-visible .nm { color: var(--mat-sys-primary); }
    .contacts { display: flex; flex-direction: column; gap: 0.1rem; }
    .contacts a { overflow-wrap: anywhere; font-size: 0.78rem; }
    .person .nm { min-width: 9rem; }
    .contacts .mono { font-size: 0.78rem; white-space: nowrap; }
    /* Avatar = «station»: a ring in the line colour, initials inside. */
    .avatar {
      display: inline-grid; place-items: center; width: 2.25rem; height: 2.25rem; box-sizing: border-box; border-radius: 50%; flex: none;
      border: 2px solid var(--mat-sys-primary); background: var(--app-card); color: var(--mat-sys-primary);
      font: 600 0.75rem/1 var(--app-font-text); grid-row: 1 / span 2;
    }
    .avatar.big { width: 3.5rem; height: 3.5rem; border-width: 2.5px; font: 700 1.1rem/1 var(--app-font-display); }
    .badge[data-status='on_leave'] { --pill-text: var(--app-info-text); --pill-bg: var(--app-info-bg); --pill-line: transparent; }
    .badge[data-status='terminated']::before { border-style: dashed; }
    .cards { list-style: none; margin: 0; padding: 1rem; display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); }
    .card {
      display: flex; flex-direction: column; align-items: center; gap: 0.25rem; padding: 1.25rem 1rem 1rem; text-align: center; height: 100%;
      box-sizing: border-box; border: var(--app-border-w) solid var(--app-border); border-radius: var(--app-radius); background: var(--app-card);
      color: inherit; text-decoration: none; transition: border-color var(--app-fast), transform var(--app-fast);
    }
    .card .avatar { margin-bottom: 0.35rem; }
    .card strong { font: var(--mat-sys-title-medium); }
    .card:hover, .card:focus-visible { border-color: var(--mat-sys-primary); transform: translateY(-1px); }
    .small { font-size: 0.8rem; }
    @media (max-width: 900px) { .wide { display: none; } }
    @media (max-width: 600px) {
      .filters .grow { flex-basis: 100%; }
      .filters mat-form-field:not(.grow) { flex: 1 1 calc(50% - 0.375rem); min-width: 0; }
      .cards { padding: 0.75rem; grid-template-columns: 1fr; }
    }
    @media (prefers-reduced-motion: reduce) { .card { transition: none; } .card:hover { transform: none; } }
  `,
})
export class PeoplePage implements OnInit {
  protected readonly store = inject(PeopleStore);
  private readonly directory = inject(DirectoryService);
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly search$ = new Subject<string>();
  protected readonly statuses = EMPLOYEE_STATUSES;
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly departments = signal<DictionaryItem[]>([]);
  protected readonly positions = signal<DictionaryItem[]>([]);
  private readonly people = inject(PeopleService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly selected = signal(new Set<number>());
  protected readonly canManage = computed(() => canManagePeople(this.auth.user()?.roles ?? []));

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.store.patchQuery({ q: q.trim() || undefined }));
    this.directory.active('branches').subscribe({ next: (l) => this.branches.set(l), error: () => undefined });
    this.directory.active('departments').subscribe({ next: (l) => this.departments.set(l), error: () => undefined });
    this.directory.active('positions').subscribe({ next: (l) => this.positions.set(l), error: () => undefined });
    this.store.load();
  }

  protected initialsOf(e: Employee): string {
    return initials(e.full_name);
  }

  protected setView(view: PeopleView): void {
    this.store.setView(view);
  }

  protected onPage(e: PageEvent): void {
    this.store.setPage(e.pageIndex + 1, e.pageSize);
  }

  protected toggle(id: number): void {
    const next = new Set(this.selected());
    if (!next.delete(id)) next.add(id);
    this.selected.set(next);
  }

  protected clear(): void {
    this.selected.set(new Set<number>());
  }

  protected bulk(): void {
    const data: EmployeeBulkData = {
      ids: [...this.selected()],
      departments: this.departments(),
      positions: this.positions(),
      managers: this.store.items().map((e) => ({ id: e.id, name: e.full_name })),
    };
    this.dialog
      .open(EmployeeBulkDialog, { data })
      .afterClosed()
      .subscribe((results: EmployeeBulkResult[] | undefined) => {
        if (!results) return;
        const ok = results.filter((r) => r.ok).length;
        this.snack.open(this.i18n.translate('bulk.done', { ok, total: results.length }), undefined, { duration: 5000 });
        this.clear();
        this.store.load();
      });
  }

  protected exportCsv(): void {
    this.people.exportCsv([...this.selected()]).subscribe({ next: (b) => saveBlob(b, 'employees.csv'), error: () => undefined });
  }

  protected add(): void {
    this.dialog
      .open<EmployeeDialog, EmployeeDialogData, Employee>(EmployeeDialog, wideDialog({ employee: null }))
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          void this.router.navigate(['/people', saved.id]);
        }
      });
  }
}
