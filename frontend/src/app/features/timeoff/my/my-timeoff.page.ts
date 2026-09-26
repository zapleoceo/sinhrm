import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { PeopleService, peopleErrorKey } from '../../people/people.service';
import { LeaveRequestsStore } from '../leave-requests.store';
import { LeaveRequest } from '../timeoff.model';
import { BalancesPanel } from '../widgets/balances-panel';
import { LeaveRequestForm } from '../widgets/leave-request-form';
import { RequestAction, RequestsList } from '../widgets/requests-list';

/** My time off: balances, a new request (with the server's day count) and my requests with cancel. */
@Component({
  selector: 'app-my-timeoff-page',
  imports: [MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe, BalancesPanel, LeaveRequestForm, RequestsList],
  providers: [LeaveRequestsStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'timeoff.my.title' | transloco }}</h1>
        <p class="muted">{{ 'timeoff.my.subtitle' | transloco }}</p>
      </div>
      <a mat-stroked-button routerLink="/timeoff/calendar"><mat-icon>calendar_month</mat-icon>{{ 'timeoff.calendar.title' | transloco }}</a>
    </header>

    @if (blocked(); as key) {
      <p class="state muted">{{ key | transloco }}</p>
    } @else if (employeeId() !== null) {
      <section class="panel card">
        <h2>{{ 'timeoff.balances' | transloco }}</h2>
        <app-balances-panel [version]="store.version()" />
      </section>
      <section class="panel card">
        <h2>{{ 'timeoff.newRequest' | transloco }}</h2>
        <app-leave-request-form (created)="created($event)" />
      </section>
      <section class="panel card">
        <h2>{{ 'timeoff.requests' | transloco }}</h2>
        @if (store.loading()) {
          <mat-progress-bar mode="indeterminate" />
        }
        <app-requests-list [requests]="store.items()" (act)="act($event)" />
      </section>
    } @else {
      <mat-progress-bar mode="indeterminate" />
    }
  `,
  styles: `
    .card { padding: 1rem; margin-bottom: var(--app-gap); }
    h2 { font: var(--mat-sys-title-medium); margin: 0 0 0.75rem; }
  `,
})
export class MyTimeOffPage implements OnInit {
  protected readonly store = inject(LeaveRequestsStore);
  private readonly people = inject(PeopleService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly employeeId = signal<number | null>(null);
  protected readonly blocked = signal<string | null>(null);

  ngOnInit(): void {
    this.people.me().subscribe({
      next: (me) => {
        this.employeeId.set(me.id);
        this.store.setQuery({ employee_id: me.id });
      },
      error: (e: unknown) => this.blocked.set(peopleErrorKey(e)),
    });
  }

  protected created(request: LeaveRequest): void {
    this.store.added(request);
    this.snack.open(this.i18n.translate('timeoff.created'), undefined, { duration: 3000 });
  }

  protected act(action: RequestAction): void {
    this.store.act(action, (key) => this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 }));
  }
}
