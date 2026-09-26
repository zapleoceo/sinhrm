import { DatePipe, DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { TeamRow, addWeeks, mondayOf } from './time.model';
import { TimeService, timeErrorKey } from './time.service';
import { toIsoDate } from '../../core/date/iso-date';
import { WeekPicker } from './week-picker';

/** Team overview (/time/team?week=): every visible employee's week — status, expected, worked, overtime, missing. */
@Component({
  selector: 'app-time-team-page',
  imports: [DatePipe, DecimalPipe, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe, WeekPicker],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'time.team.title' | transloco }}</h1>
        <p class="muted">{{ 'time.team.subtitle' | transloco: { date: (weekStart() | date: 'dd.MM.yyyy') } }} · {{ 'time.team.missingCount' | transloco: { n: missingCount() } }}</p>
      </div>
      <div class="row">
        <button mat-icon-button type="button" (click)="go(-1)" [attr.aria-label]="'time.week.prev' | transloco"><mat-icon>chevron_left</mat-icon></button>
        <button mat-icon-button type="button" (click)="go(1)" [attr.aria-label]="'time.week.next' | transloco"><mat-icon>chevron_right</mat-icon></button>
        <app-week-picker [weekStart]="weekStart()" (weekChange)="goTo($event)" />
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
            <th scope="col">{{ 'time.team.status' | transloco }}</th>
            <th scope="col" class="num">{{ 'time.week.expected' | transloco }}</th>
            <th scope="col" class="num">{{ 'time.week.worked' | transloco }}</th>
            <th scope="col" class="num">{{ 'time.week.overtime' | transloco }}</th>
            <th scope="col" class="num">{{ 'time.week.missing' | transloco }}</th>
            <th scope="col" class="num">{{ 'time.week.absence' | transloco }}</th>
          </tr>
        </thead>
        <tbody>
          @for (r of rows(); track r.employee.id) {
            <tr>
              <td><a routerLink="/time" [queryParams]="{ week: weekStart(), employee_id: r.employee.id }">{{ r.employee.full_name }}</a></td>
              <td>{{ 'time.status.' + r.status | transloco }}</td>
              <td class="num">{{ r.expected | number: '1.0-2' }}</td>
              <td class="num">{{ r.worked | number: '1.0-2' }}</td>
              <td class="num" [class.over]="r.overtime > 0">{{ r.overtime | number: '1.0-2' }}</td>
              <td class="num" [class.short]="r.missing > 0">{{ r.missing | number: '1.0-2' }}</td>
              <td class="num">{{ r.absence | number: '1.0-2' }}</td>
            </tr>
          } @empty {
            <tr><td colspan="7" class="muted">{{ 'time.team.empty' | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    .row { display: flex; gap: 0.25rem; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid var(--app-border); font-weight: normal; }
    thead th { color: var(--app-muted); font-size: 0.8rem; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .over { color: #b26a00; }
    .short { color: var(--app-danger); }
    .panel { overflow-x: auto; }
  `,
})
export class TimeTeamPage {
  readonly week = input<string | undefined>(undefined);
  private readonly api = inject(TimeService);
  private readonly router = inject(Router);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly rows = signal<TeamRow[]>([]);
  protected readonly loading = signal(false);
  protected readonly weekStart = computed(() => mondayOf(this.week() ?? toIsoDate(new Date())));
  protected readonly missingCount = computed(() => this.rows().filter((r) => r.missing > 0 && r.status !== 'submitted' && r.status !== 'approved').length);

  constructor() {
    effect(() => this.load(this.weekStart()));
  }

  protected go(delta: number): void {
    this.goTo(addWeeks(this.weekStart(), delta));
  }

  protected goTo(week: string): void {
    void this.router.navigate([], { queryParams: { week } });
  }

  private load(week: string): void {
    this.loading.set(true);
    this.api.team(week).subscribe({
      next: (rows) => {
        this.rows.set(rows);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.snack.open(this.i18n.translate(timeErrorKey(e)), undefined, { duration: 4000 });
      },
    });
  }
}
