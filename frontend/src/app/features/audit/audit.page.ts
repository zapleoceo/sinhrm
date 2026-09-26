import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatTableModule } from '@angular/material/table';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { toIsoDate } from '../../core/date/iso-date';
import { AuditRow, auditActionKey, auditEntityKey, toAuditRow } from './audit.format';
import { AUDIT_ACTIONS, AUDIT_ENTITY_TYPES, AuditOptions, AuditQuery } from './audit.model';
import { AuditService } from './audit.service';

/** A filter choice with its i18n key (null → show the raw value). */
interface FilterOption {
  value: string;
  key: string | null;
}

/** Superadmin audit log: filters (user, entity type, action, date range), paginated table of changes. */
@Component({
  selector: 'app-audit-page',
  imports: [
    DatePipe,
    MatButtonModule,
    MatDatepickerModule,
    MatFormFieldModule,
    MatInputModule,
    MatPaginatorModule,
    MatProgressBarModule,
    MatSelectModule,
    MatTableModule,
    RouterLink,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './audit.page.html',
  styleUrl: './audit.page.scss',
})
export class AuditPage implements OnInit {
  private readonly api = inject(AuditService);

  protected readonly columns = ['time', 'user', 'action', 'entity', 'changes'];
  protected readonly query = signal<AuditQuery>({ page: 1, perPage: 20 });
  protected readonly rows = signal<AuditRow[]>([]);
  protected readonly total = signal(0);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  private readonly options = signal<AuditOptions | null>(null);

  protected readonly users = computed(() => this.options()?.users ?? []);
  protected readonly entityTypes = computed<FilterOption[]>(() =>
    (this.options()?.entity_types ?? [...AUDIT_ENTITY_TYPES]).map((value) => ({ value, key: auditEntityKey(value) })),
  );
  protected readonly actions = computed<FilterOption[]>(() =>
    (this.options()?.actions ?? [...AUDIT_ACTIONS]).map((value) => ({ value, key: auditActionKey(value) })),
  );

  ngOnInit(): void {
    this.load();
    this.api.options().subscribe({
      next: (o) => this.options.set(o),
      error: () => this.options.set(null),
    });
  }

  protected load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.list(this.query()).subscribe({
      next: (page) => {
        this.rows.set(page.data.map(toAuditRow));
        this.total.set(page.meta.total);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  protected onUser(user_id: number | undefined): void {
    this.patchQuery({ user_id });
  }

  protected onEntityType(entity_type: string | undefined): void {
    this.patchQuery({ entity_type });
  }

  protected onAction(action: string | undefined): void {
    this.patchQuery({ action });
  }

  protected onFrom(date: Date | null): void {
    this.patchQuery({ from: toIsoDate(date) || undefined });
  }

  protected onTo(date: Date | null): void {
    this.patchQuery({ to: toIsoDate(date) || undefined });
  }

  protected onPage(e: PageEvent): void {
    this.query.update((q) => ({ ...q, page: e.pageIndex + 1, perPage: e.pageSize }));
    this.load();
  }

  private patchQuery(patch: Partial<AuditQuery>): void {
    this.query.update((q) => ({ ...q, ...patch, page: 1 }));
    this.load();
  }
}
