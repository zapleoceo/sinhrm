import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { CHANNEL_ICONS } from '../recruiting/recruiting.model';
import { MoodCheckinWidget } from '../pulse/mood/mood-checkin.widget';
import { TasksWidget } from '../scripts/tasks/tasks-widget';
import { TaskQuery } from '../scripts/scripts.model';
import { OverviewStore } from './overview.store';

/**
 * Home page: what needs attention today. Counters (click-through), my tasks for today (overdue included),
 * the most stale candidates, the funnel of active applications and touches of the last 7 days by channel;
 * the daily mood check-in (Pulse) for users with an employee record.
 */
@Component({
  selector: 'app-dashboard-page',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe, TasksWidget, MoodCheckinWidget],
  providers: [OverviewStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './dashboard.page.html',
  styleUrl: './dashboard.page.scss',
})
export class DashboardPage implements OnInit {
  protected readonly store = inject(OverviewStore);
  private readonly auth = inject(AuthService);
  protected readonly icons = CHANNEL_ICONS;
  protected readonly firstName = computed(() => (this.auth.user()?.name ?? '').split(' ')[0]);
  protected readonly myTasks: TaskQuery = { mine: true, due: 'today' };

  ngOnInit(): void {
    this.store.load();
  }
}
