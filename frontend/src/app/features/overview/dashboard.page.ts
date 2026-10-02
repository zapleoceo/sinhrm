import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { ChannelIcon } from '../../core/ui/channel-icon';
import { MoodCheckinWidget } from '../pulse/mood/mood-checkin.widget';
import { CandidateDialog } from '../recruiting/candidates/candidate.dialog';
import { Candidate } from '../recruiting/recruiting.model';
import { canWriteRecruiting } from '../recruiting/recruiting.access';
import { TasksWidget } from '../scripts/tasks/tasks-widget';
import { TaskQuery } from '../scripts/scripts.model';
import { DayRouteCard } from './day-route';
import { wallClock } from './overview.model';
import { OverviewStore } from './overview.store';

/**
 * Home page (layout C «Маршрут»): header with the date crumb, «Мої задачі · N» and «+ Кандидат»; «Маршрут дня»
 * (today's interviews and my tasks on a time line); counters; a 7+5 grid — funnel with its captions | stale
 * candidates, my tasks | touches of the last 7 days (stacked bar) and who is out today. Blocks that are not in the
 * mock-up (mood check-in, approvals, my week) follow below.
 */
@Component({
  selector: 'app-dashboard-page',
  imports: [ChannelIcon, DatePipe, DayRouteCard, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe, TasksWidget, MoodCheckinWidget],
  providers: [OverviewStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './dashboard.page.html',
  styleUrl: './dashboard.page.scss',
})
export class DashboardPage implements OnInit {
  protected readonly store = inject(OverviewStore);
  private readonly auth = inject(AuthService);
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly lang = toSignal(inject(TranslocoService).langChanges$, { initialValue: 'uk' });

  protected readonly firstName = computed(() => (this.auth.user()?.name ?? '').split(' ')[0]);
  protected readonly myTasks: TaskQuery = { mine: true, due: 'today' };
  protected readonly canCreateCandidate = computed(
    () => canWriteRecruiting(this.auth.user()?.roles ?? []) && this.auth.hasModule('recruiting'),
  );
  /** Zone «today» is cut in by the backend (day_route.timezone); until the data arrives — the browser's zone. */
  private readonly timeZone = computed(() => this.store.data()?.day_route?.timezone);
  protected readonly todayIso = computed(() => wallClock(this.store.loadedAt(), this.timeZone()).date);
  protected readonly todayLabel = computed(() =>
    new Intl.DateTimeFormat(this.lang(), { weekday: 'long', day: 'numeric', month: 'long', timeZone: this.timeZone() }).format(this.store.loadedAt()),
  );

  ngOnInit(): void {
    this.store.load();
  }

  protected createCandidate(): void {
    this.dialog
      .open(CandidateDialog)
      .afterClosed()
      .subscribe((created: Candidate | undefined) => {
        if (created) {
          void this.router.navigate(['/candidates', created.id]);
        }
      });
  }
}
