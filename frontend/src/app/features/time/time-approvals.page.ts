import { DatePipe, DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { TimesheetApproval } from './time.model';
import { TimeService, timeErrorKey } from './time.service';

/** Manager approvals (/time/approvals): submitted weeks of the subtree (admins: everyone), open the grid or decide. */
@Component({
  selector: 'app-time-approvals-page',
  imports: [DatePipe, DecimalPipe, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'time.approvals.title' | transloco }}</h1>
        <p class="muted">{{ 'time.approvals.subtitle' | transloco }}</p>
      </div>
    </header>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="panel">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ 'time.approvals.employee' | transloco }}</th>
            <th scope="col">{{ 'time.approvals.week' | transloco }}</th>
            <th scope="col" class="num">{{ 'time.week.expected' | transloco }}</th>
            <th scope="col" class="num">{{ 'time.week.worked' | transloco }}</th>
            <th scope="col" class="num">{{ 'time.week.overtime' | transloco }}</th>
            <th scope="col"></th>
          </tr>
        </thead>
        <tbody>
          @for (t of items(); track t.id) {
            <tr>
              <td>{{ t.employee.full_name }}</td>
              <td><a routerLink="/time" [queryParams]="{ week: t.week_start, employee_id: t.employee.id }">{{ t.week_start | date: 'dd.MM.yyyy' }}</a></td>
              <td class="num">{{ t.expected | number: '1.0-2' }}</td>
              <td class="num">{{ t.worked | number: '1.0-2' }}</td>
              <td class="num" [class.over]="t.overtime > 0">{{ t.overtime | number: '1.0-2' }}</td>
              <td class="actions">
                <button mat-icon-button type="button" (click)="decide(t, true)" [attr.aria-label]="'time.approvals.approve' | transloco"><mat-icon>check</mat-icon></button>
                <button mat-icon-button type="button" (click)="decide(t, false)" [attr.aria-label]="'time.approvals.reject' | transloco"><mat-icon>close</mat-icon></button>
              </td>
            </tr>
          } @empty {
            <tr><td colspan="6" class="muted">{{ 'time.approvals.empty' | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid var(--app-border); font-weight: normal; }
    thead th { color: var(--app-muted); font-size: 0.8rem; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .over { color: #b26a00; }
    .actions { white-space: nowrap; text-align: right; }
    .panel { overflow-x: auto; }
  `,
})
export class TimeApprovalsPage implements OnInit {
  private readonly api = inject(TimeService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly items = signal<TimesheetApproval[]>([]);
  protected readonly loading = signal(false);

  ngOnInit(): void {
    this.load();
  }

  protected decide(t: TimesheetApproval, approve: boolean): void {
    const comment = approve ? null : window.prompt(this.i18n.translate('time.approvals.reason'));
    if (!approve && !comment) {
      return;
    }
    this.api.decide(t.id, approve, comment).subscribe({
      next: () => this.items.update((list) => list.filter((x) => x.id !== t.id)),
      error: (e: unknown) => this.snack.open(this.i18n.translate(timeErrorKey(e)), undefined, { duration: 4000 }),
    });
  }

  private load(): void {
    this.loading.set(true);
    this.api.approvals().subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.snack.open(this.i18n.translate(timeErrorKey(e)), undefined, { duration: 4000 });
      },
    });
  }
}
