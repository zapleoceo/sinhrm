import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { CHANGEABLE_FIELDS, ChangeRequest, fieldLabelKey } from '../../people/people.model';
import { PeopleService, peopleErrorKey } from '../../people/people.service';
import { LeaveRequest } from '../timeoff.model';
import { TimeOffService, timeoffErrorKey } from '../timeoff.service';
import { RequestAction, RequestsList } from '../widgets/requests-list';

/** Approvals inbox of a manager/admin: pending leave requests and personal-data change requests of their people. */
@Component({
  selector: 'app-approvals-page',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe, RequestsList],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'timeoff.approvals.title' | transloco }}</h1>
        <p class="muted">{{ 'timeoff.approvals.subtitle' | transloco }}</p>
      </div>
      <button mat-button type="button" (click)="load()"><mat-icon>refresh</mat-icon>{{ 'overview.refresh' | transloco }}</button>
    </header>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <section class="panel card">
      <h2>{{ 'timeoff.approvals.leave' | transloco: { n: leave().length } }}</h2>
      <app-requests-list [requests]="leave()" [showEmployee]="true" (act)="act($event)" />
    </section>
    <section class="panel card">
      <h2>{{ 'timeoff.approvals.changes' | transloco: { n: changes().length } }}</h2>
      <ul class="rows">
        @for (c of changes(); track c.id) {
          <li>
            <div class="main">
              <a [routerLink]="['/people', c.employee.id]">{{ c.employee.full_name }}</a>
              @for (f of fields; track f) {
                @if (f in c.changes) {
                  <span class="muted">{{ label(f) | transloco }}: {{ c.changes[f] ?? '—' }}</span>
                }
              }
              <span class="muted small">{{ c.created_at | date: 'dd.MM.yyyy HH:mm' }}</span>
            </div>
            <button mat-stroked-button type="button" (click)="decide(c, true)"><mat-icon>check</mat-icon>{{ 'timeoff.actions.approve' | transloco }}</button>
            <button mat-button type="button" (click)="decide(c, false)">{{ 'timeoff.actions.reject' | transloco }}</button>
          </li>
        } @empty {
          <li class="muted">{{ 'timeoff.approvals.none' | transloco }}</li>
        }
      </ul>
    </section>
  `,
  styles: `
    .card { padding: 1rem; margin-bottom: var(--app-gap); }
    h2 { font: var(--mat-sys-title-medium); margin: 0 0 0.75rem; }
    .rows { list-style: none; margin: 0; padding: 0; }
    .rows li { display: flex; gap: 0.75rem; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--app-border); flex-wrap: wrap; }
    .main { flex: 1; display: flex; flex-direction: column; }
    .small { font-size: 0.8rem; }
  `,
})
export class ApprovalsPage implements OnInit {
  private readonly timeoff = inject(TimeOffService);
  private readonly people = inject(PeopleService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly fields = CHANGEABLE_FIELDS;
  protected readonly label = fieldLabelKey;
  protected readonly leave = signal<LeaveRequest[]>([]);
  protected readonly changes = signal<ChangeRequest[]>([]);
  protected readonly loading = signal(false);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.timeoff.approvals().subscribe({
      next: (list) => {
        this.leave.set(list);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
    this.people.changeRequests({ status: 'pending', perPage: 100 }).subscribe({
      next: (page) => this.changes.set(page.data.filter((c) => c.can_decide)),
      error: () => this.changes.set([]),
    });
  }

  protected act({ request, action }: RequestAction): void {
    this.timeoff.decide(request.id, action).subscribe({
      next: () => this.leave.update((list) => list.filter((r) => r.id !== request.id)),
      error: (e: unknown) => this.toast(timeoffErrorKey(e)),
    });
  }

  protected decide(change: ChangeRequest, approve: boolean): void {
    this.people.decideChange(change.id, approve).subscribe({
      next: () => this.changes.update((list) => list.filter((c) => c.id !== change.id)),
      error: (e: unknown) => this.toast(peopleErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
