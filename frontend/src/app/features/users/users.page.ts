import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
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
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { USER_ROLES, USER_STATUSES, UserRole, UserStatus } from '../../core/auth/auth.model';
import { AuthService } from '../../core/auth/auth.service';
import { InviteUserDialog } from './invite-user.dialog';
import { AdminUser, UpdateUser, UsersQuery } from './users.model';
import { UsersService, userErrorKey } from './users.service';

const SEARCH_DEBOUNCE_MS = 300;

/** Superadmin users admin: search/filter, invite, change role, block/unblock (optimistic with rollback). */
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
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './users.page.html',
  styleUrl: './users.page.scss',
})
export class UsersPage implements OnInit {
  private readonly api = inject(UsersService);
  private readonly dialog = inject(MatDialog);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly search$ = new Subject<string>();

  protected readonly roles = USER_ROLES;
  protected readonly statuses = USER_STATUSES;
  protected readonly columns = ['user', 'role', 'status', 'lastLogin', 'actions'];
  private readonly auth = inject(AuthService);
  protected readonly meId = computed(() => this.auth.user()?.id ?? null);

  protected readonly query = signal<UsersQuery>({ page: 1, perPage: 20 });
  protected readonly users = signal<AdminUser[]>([]);
  protected readonly total = signal(0);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  protected readonly pending = signal<ReadonlySet<number>>(new Set());

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(SEARCH_DEBOUNCE_MS), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.patchQuery({ q: q.trim() || undefined }));
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.list(this.query()).subscribe({
      next: (page) => {
        this.users.set(page.data);
        this.total.set(page.meta.total);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  protected onSearch(value: string): void {
    this.search$.next(value);
  }

  protected onRoleFilter(role: UserRole | undefined): void {
    this.patchQuery({ role });
  }

  protected onStatusFilter(status: UserStatus | undefined): void {
    this.patchQuery({ status });
  }

  protected onPage(e: PageEvent): void {
    this.query.update((q) => ({ ...q, page: e.pageIndex + 1, perPage: e.pageSize }));
    this.load();
  }

  protected changeRole(user: AdminUser, role: UserRole): void {
    this.optimistic(user, { roles: [role] }, { role });
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
    this.pending.update((set) => {
      const next = new Set(set);
      if (on) {
        next.add(id);
      } else {
        next.delete(id);
      }
      return next;
    });
  }

  private patchQuery(patch: Partial<UsersQuery>): void {
    this.query.update((q) => ({ ...q, ...patch, page: 1 }));
    this.load();
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
