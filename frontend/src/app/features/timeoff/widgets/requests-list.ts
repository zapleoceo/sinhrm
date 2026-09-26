import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { LeaveRequest } from '../timeoff.model';

export interface RequestAction {
  request: LeaveRequest;
  action: 'approve' | 'reject' | 'cancel';
}

/** Leave requests as rows with the actions the API allows (can_decide / can_cancel). */
@Component({
  selector: 'app-requests-list',
  imports: [DatePipe, MatButtonModule, MatIconModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ul class="rows">
      @for (r of requests(); track r.id) {
        <li class="row" [attr.data-status]="r.status">
          <span class="dot" [style.background]="r.leave_type.color" aria-hidden="true"></span>
          <div class="main">
            @if (showEmployee()) {
              <a [routerLink]="['/people', r.employee.id]">{{ r.employee.full_name }}</a>
            }
            <span>
              <strong>{{ r.leave_type.name }}</strong> ·
              {{ r.starts_on | date: 'dd.MM.yyyy' }}@if (r.ends_on !== r.starts_on) { – {{ r.ends_on | date: 'dd.MM.yyyy' }}}
              · {{ 'timeoff.daysCount' | transloco: { n: r.days } }}
              @if (r.half_day !== 'none') {
                <span class="muted">({{ 'timeoff.halfDay.' + r.half_day | transloco }})</span>
              }
            </span>
            @if (r.comment) {
              <span class="muted small">«{{ r.comment }}»</span>
            }
            @if (r.decision_comment) {
              <span class="muted small">{{ r.approver?.name }}: «{{ r.decision_comment }}»</span>
            }
          </div>
          <span class="status">{{ 'timeoff.status.' + r.status | transloco }}</span>
          @if (r.can_decide) {
            <button mat-stroked-button type="button" (click)="act.emit({ request: r, action: 'approve' })">
              <mat-icon>check</mat-icon>{{ 'timeoff.actions.approve' | transloco }}
            </button>
            <button mat-button type="button" (click)="act.emit({ request: r, action: 'reject' })">{{ 'timeoff.actions.reject' | transloco }}</button>
          }
          @if (r.can_cancel) {
            <button mat-button type="button" (click)="act.emit({ request: r, action: 'cancel' })">{{ 'timeoff.actions.cancel' | transloco }}</button>
          }
        </li>
      } @empty {
        <li class="muted empty">{{ 'timeoff.noRequests' | transloco }}</li>
      }
    </ul>
  `,
  styles: `
    .rows { list-style: none; margin: 0; padding: 0; }
    .row { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 1rem; border-bottom: 1px solid var(--app-border); flex-wrap: wrap; }
    .row[data-status='rejected'], .row[data-status='cancelled'] { opacity: 0.6; }
    .dot { width: 0.75rem; height: 0.75rem; border-radius: 50%; flex: none; }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 12rem; }
    .row[data-status='pending'] .status { color: var(--app-warning); }
    .row[data-status='approved'] .status { color: var(--app-success); }
    .small { font-size: 0.8rem; }
    .empty { padding: 1rem; }
  `,
})
export class RequestsList {
  readonly requests = input.required<readonly LeaveRequest[]>();
  readonly showEmployee = input(false);
  readonly act = output<RequestAction>();
}
