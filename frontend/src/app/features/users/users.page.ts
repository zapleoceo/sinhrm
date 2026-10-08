import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTableModule } from '@angular/material/table';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { INVITABLE_ROLES, USER_ROLES, USER_STATUSES, UserRole, UserStatus, isHrStaff } from '../../core/auth/auth.model';
import { AuthService } from '../../core/auth/auth.service';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { PagedList } from '../../core/ui/table/paged-list';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import {
  ColumnFilter,
  FilterValue,
  RangeValue,
  TableSort,
  dateRangeToParams,
  filterToParam,
  sameQuery,
  sortToParams,
} from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { DictionaryItem } from '../directory/directory.model';
import { DirectoryService } from '../directory/directory.service';
import { InviteUserDialog } from './invite-user.dialog';
import { AdminUser, UpdateUser, UsersQuery, nextRoles } from './users.model';
import { USERS_PAGE_SIZE, usersQueryFromParams } from './users.query';
import { UsersService, userErrorKey } from './users.service';
import { withMember } from '../../core/ui/with-member';

/** API order without ?sort (by name, A→Z): the name column carries the arrow. */
const DEFAULT_SORT: TableSort = { key: 'name', dir: 'asc' };

/**
 * Superadmin users admin: invite, change roles / branches, block/unblock (optimistic with rollback). Column headers
 * sort and filter (core/ui/table): user (name or e-mail), role, status, last sign-in; the state lives in the URL.
 */
@Component({
  selector: 'app-users-page',
  imports: [
    DatePipe,
    MatButtonModule,
    MatChipsModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatPaginatorModule,
    MatProgressBarModule,
    MatSelectModule,
    MatTableModule,
    TableSortDirective,
    ColumnHeader,
    TranslocoPipe,
  ],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './users.page.html',
  styleUrl: './users.page.scss',
})
export class UsersPage implements OnInit {
  private readonly api = inject(UsersService);
  private readonly dialog = inject(MatDialog);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly url = inject(TableUrlState);
  /** A newer query cancels the request still in flight: an old answer never lands over the new filters. */
  private readonly list = new PagedList<AdminUser>();
  private loaded = false;

  /** Superadmin is bootstrap-only (SUPERADMIN_EMAIL) and cannot be assigned from the UI. */
  protected readonly assignableRoles = INVITABLE_ROLES;
  protected readonly columns = ['user', 'role', 'branches', 'status', 'lastLogin', 'actions'];
  private readonly auth = inject(AuthService);
  private readonly directory = inject(DirectoryService);
  /** Active branches offered in the per-user multi-select. */
  protected readonly branchOptions = signal<DictionaryItem[]>([]);
  protected readonly meId = computed(() => this.auth.user()?.id ?? null);

  protected readonly query = signal<UsersQuery>({ page: 1, perPage: USERS_PAGE_SIZE });
  protected readonly textFilter: ColumnFilter = { type: 'text' };
  protected readonly dateFilter: ColumnFilter = { type: 'range', input: 'date' };
  protected readonly roleFilter: ColumnFilter = { type: 'select', options: USER_ROLES.map((r) => ({ value: r, label: `roles.${r}`, i18n: true })) };
  protected readonly statusFilter: ColumnFilter = {
    type: 'select',
    options: USER_STATUSES.map((s) => ({ value: s, label: `statuses.${s}`, i18n: true })),
  };
  /** Shown sort: the URL one, or the API default. */
  protected readonly sort = computed<TableSort>(() => {
    const q = this.query();
    return q.sort ? { key: q.sort, dir: q.dir ?? 'asc' } : DEFAULT_SORT;
  });
  protected readonly lastLogin = computed<RangeValue | null>(() => {
    const q = this.query();
    return q.last_login_from || q.last_login_to ? { from: q.last_login_from ?? null, to: q.last_login_to ?? null } : null;
  });
  protected readonly users = this.list.items;
  protected readonly total = this.list.total;
  protected readonly loading = this.list.loading;
  protected readonly failed = this.list.failed;
  protected readonly pending = signal<ReadonlySet<number>>(new Set());
  /**
   * Selected branch ids per branch-scoped user (recruiter/viewer/employee; HR staff see every branch).
   * A stable array per row: a fresh one on every check would reset an open multi-select.
   */
  protected readonly scopedBranchIds = computed(
    () =>
      new Map(
        this.users()
          .filter((u) => !isHrStaff(u.roles))
          .map((u) => [u.id, u.branches.map((b) => b.id)] as const),
      ),
  );

  ngOnInit(): void {
    this.url.watch(usersQueryFromParams, (query) => this.apply(query));
    this.directory.active('branches').subscribe({
      next: (list) => this.branchOptions.set(list),
      error: () => this.branchOptions.set([]),
    });
  }

  protected load(): void {
    this.loaded = true;
    this.list.load(this.api.list(this.query()));
  }

  protected onPage(e: PageEvent): void {
    this.url.update({ page: e.pageIndex + 1, perPage: e.pageSize }, { paging: true });
  }

  protected onSort(sort: TableSort | null): void {
    this.url.update(sortToParams(sort));
  }

  /** Header filters: text / chosen value; cleared → removed from the URL (and the page goes back to 1). */
  protected setFilter(name: 'q' | 'role' | 'status', value: FilterValue): void {
    this.url.update({ [name]: filterToParam(value) });
  }

  protected setLastLogin(value: FilterValue): void {
    this.url.update(dateRangeToParams(value, 'last_login_from', 'last_login_to'));
  }

  /**
   * Saves the ticked roles when the picker closes. Superadmin is not in the picker: it stays on a user who has it.
   * Nothing ticked (and no superadmin) = no change: a user always keeps at least one role.
   */
  protected commitRoles(user: AdminUser, selected: UserRole[]): void {
    const roles = nextRoles(user.roles, selected);
    if (roles !== null) {
      this.optimistic(user, { roles }, { roles });
    }
  }

  /** Roles shown in the picker (everything but superadmin). */
  protected pickable(user: AdminUser): UserRole[] {
    return user.roles.filter((r) => r !== 'superadmin');
  }

  /** Saves the selection when the picker closes; only active branches are sent (disabled ones are dropped). */
  protected commitBranches(user: AdminUser, selected: number[]): void {
    const options = this.branchOptions();
    const ids = selected.filter((id) => options.some((o) => o.id === id)).sort((a, b) => a - b);
    const current = user.branches.map((b) => b.id).sort((a, b) => a - b);
    if (ids.length === current.length && ids.every((id, i) => id === current[i])) {
      return;
    }
    const branches = options.filter((o) => ids.includes(o.id)).map((o) => ({ id: o.id, name: o.name, status: o.status }));
    this.optimistic(user, { branches }, { branch_ids: ids });
  }

  /** The Safe Speak handler flag is offered to HR staff only (the API refuses it for other roles). */
  protected canHandle(user: AdminUser): boolean {
    return isHrStaff(user.roles);
  }

  protected toggleHandler(user: AdminUser): void {
    const safe_speak_handler = !user.safe_speak_handler;
    this.optimistic(user, { safe_speak_handler }, { safe_speak_handler });
  }

  protected toggleBlock(user: AdminUser): void {
    const status: UserStatus = user.status === 'active' ? 'blocked' : 'active';
    this.optimistic(user, { status }, { status });
  }

  protected invite(): void {
    this.dialog
      .open<InviteUserDialog, void, AdminUser>(InviteUserDialog)
      .afterClosed()
      .subscribe((created) => {
        if (created) {
          this.toast('users.invited');
          this.load();
        }
      });
  }

  /** Applies the change locally at once; restores the previous row if the API rejects it. */
  private optimistic(user: AdminUser, local: Partial<AdminUser>, body: UpdateUser): void {
    const previous = user;
    this.replace({ ...user, ...local });
    this.setPending(user.id, true);
    this.api.update(user.id, body).subscribe({
      next: (saved) => {
        this.replace(saved);
        this.setPending(user.id, false);
        this.toast('users.saved');
      },
      error: (e: unknown) => {
        this.replace(previous);
        this.setPending(user.id, false);
        this.toast(userErrorKey(e));
      },
    });
  }

  private replace(user: AdminUser): void {
    this.users.update((list) => list.map((u) => (u.id === user.id ? user : u)));
  }

  private setPending(id: number, on: boolean): void {
    this.pending.update((set) => withMember(set, id, on));
  }

  /** New query from the URL: loads unless it is the one already shown. */
  private apply(query: UsersQuery): void {
    if (this.loaded && sameQuery(query, this.query())) return;
    this.query.set(query);
    this.load();
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
