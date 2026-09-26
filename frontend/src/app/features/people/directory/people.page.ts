import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { DictionaryItem } from '../../directory/directory.model';
import { DirectoryService } from '../../directory/directory.service';
import { initials } from '../org-tree';
import { canManagePeople } from '../people.access';
import { EMPLOYEE_STATUSES, Employee } from '../people.model';
import { EmployeeDialog, EmployeeDialogData } from '../profile/employee.dialog';
import { PeopleStore, PeopleView } from './people.store';

/** People directory: search, filters (branch, department, position, status), table or cards; admins add people. */
@Component({
  selector: 'app-people-page',
  imports: [
    MatButtonModule,
    MatButtonToggleModule,
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
        <table class="people">
          <thead>
            <tr>
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
                <td>
                  <a class="person" [routerLink]="['/people', e.id]">
                    <span class="avatar" aria-hidden="true">{{ initialsOf(e) }}</span>
                    <span>{{ e.full_name }}</span>
                    @if (e.status !== 'active') {
                      <span class="badge" [attr.data-status]="e.status">{{ 'people.status.' + e.status | transloco }}</span>
                    }
                  </a>
                </td>
                <td>{{ e.position?.name ?? '—' }}</td>
                <td class="wide">{{ e.department?.name ?? '—' }}</td>
                <td class="wide">{{ e.branch?.name ?? '—' }}</td>
                <td class="wide contacts">
                  @if (e.work_email) {
                    <a [href]="'mailto:' + e.work_email">{{ e.work_email }}</a>
                  }
                  @if (e.phone) {
                    <a [href]="'tel:' + e.phone">{{ e.phone }}</a>
                  }
                </td>
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
    .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .people { width: 100%; border-collapse: collapse; }
    .people th, .people td { text-align: left; padding: 0.5rem 1rem; border-bottom: 1px solid var(--app-border); }
    .people th { font: var(--mat-sys-label-large); color: var(--app-muted); }
    .person { display: inline-flex; align-items: center; gap: 0.5rem; color: inherit; text-decoration: none; font-weight: 500; }
    .person:hover span:nth-child(2) { color: var(--mat-sys-primary); }
    .contacts { display: flex; flex-direction: column; font-size: 0.85rem; }
    .avatar {
      display: inline-grid; place-items: center; width: 2rem; height: 2rem; border-radius: 50%; flex: none;
      background: var(--mat-sys-secondary-container); color: var(--mat-sys-on-secondary-container); font-size: 0.8rem;
    }
    .avatar.big { width: 3.5rem; height: 3.5rem; font-size: 1.2rem; }
    .badge { font-size: 0.75rem; color: var(--app-warning); }
    .badge[data-status='terminated'] { color: var(--app-muted); }
    .cards { list-style: none; margin: 0; padding: 1rem; display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); }
    .card {
      display: flex; flex-direction: column; align-items: center; gap: 0.25rem; padding: 1rem; text-align: center;
      border: 1px solid var(--app-border); border-radius: var(--app-radius); color: inherit; text-decoration: none;
    }
    .card:hover, .card:focus-visible { border-color: var(--mat-sys-primary); }
    .small { font-size: 0.8rem; }
    @media (max-width: 900px) { .wide { display: none; } }
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

  protected add(): void {
    this.dialog
      .open<EmployeeDialog, EmployeeDialogData, Employee>(EmployeeDialog, { data: { employee: null } })
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          void this.router.navigate(['/people', saved.id]);
        }
      });
  }
}
