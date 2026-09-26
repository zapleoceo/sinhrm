import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, effect, input, signal, untracked } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuditRow, toAuditRow } from './audit.format';
import { AuditLoader, AuditPaging } from './audit.model';

/** Compact, paginated change history of one entity; embed as a tab/section and pass a page loader. */
@Component({
  selector: 'app-audit-history',
  imports: [DatePipe, MatButtonModule, MatPaginatorModule, MatProgressBarModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (failed()) {
      <div class="state">
        <p>{{ 'audit.loadError' | transloco }}</p>
        <button mat-stroked-button type="button" (click)="load()">{{ 'common.retry' | transloco }}</button>
      </div>
    } @else if (!loading() && rows().length === 0) {
      <p class="state muted">{{ 'audit.empty' | transloco }}</p>
    } @else {
      <ol class="entries">
        @for (r of rows(); track r.id) {
          <li>
            <div class="line">
              <strong>{{ r.actionKey ? (r.actionKey | transloco) : r.action }}</strong>
              @if (r.link) {
                <a [routerLink]="r.link">{{ r.entityKey ? (r.entityKey | transloco) : r.entityType }} #{{ r.entityId }}</a>
              } @else {
                <span>{{ r.entityKey ? (r.entityKey | transloco) : r.entityType }} #{{ r.entityId }}</span>
              }
              <span class="spacer"></span>
              <span class="muted">{{ r.userName ?? ('audit.system' | transloco) }}</span>
              <time class="muted" [attr.datetime]="r.at">{{ r.at | date: 'dd.MM.yyyy HH:mm' }}</time>
            </div>
            @for (line of r.lines; track $index) {
              <div class="change">{{ line }}</div>
            }
          </li>
        }
      </ol>
    }
    @if (total() > paging().perPage) {
      <mat-paginator [length]="total()" [pageIndex]="paging().page - 1" [pageSize]="paging().perPage" [hidePageSize]="true" (page)="onPage($event)" />
    }
  `,
  styles: `
    :host { display: block; padding: 0.5rem 0; }
    .entries { list-style: none; margin: 0; padding: 0; }
    .entries li { padding: 0.5rem 0; border-bottom: 1px solid var(--app-border); }
    .line { display: flex; flex-wrap: wrap; gap: 0.25rem 0.75rem; align-items: baseline; }
    .spacer { flex: 1; }
    .change { font-size: 0.85rem; color: var(--app-muted); overflow-wrap: anywhere; }
    .state { padding: 1.5rem 1rem; text-align: center; }
  `,
})
export class AuditHistory {
  /** Page loader, e.g. `(p) => audit.employeeHistory(id, p)`; a new function reloads from page 1. */
  readonly loader = input.required<AuditLoader>();

  protected readonly paging = signal<AuditPaging>({ page: 1, perPage: 10 });
  protected readonly rows = signal<AuditRow[]>([]);
  protected readonly total = signal(0);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);

  constructor() {
    effect(() => {
      this.loader();
      untracked(() => {
        this.paging.update((p) => ({ ...p, page: 1 }));
        this.load();
      });
    });
  }

  protected load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.loader()(this.paging()).subscribe({
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

  protected onPage(e: PageEvent): void {
    this.paging.set({ page: e.pageIndex + 1, perPage: e.pageSize });
    this.load();
  }
}
