import { isHrStaff } from '../../../core/auth/auth.model';
import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../../core/auth/auth.service';
import { MoodEntry, MoodSettings, TeamMood, moodEmoji } from '../pulse.model';
import { PulseService, pulseErrorKey } from '../pulse.service';
import { MoodCheckinWidget } from './mood-checkin.widget';

/**
 * Mood (/pulse/mood): today's check-in, my history (visible to me only), the team trend by week for managers
 * (their people) and admins (everyone) — suppressed below the minimum group — and the settings (admins).
 */
@Component({
  selector: 'app-mood-page',
  imports: [DatePipe, FormsModule, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, TranslocoPipe, MoodCheckinWidget],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'pulse.mood.title' | transloco }}</h1>
        <p class="muted">{{ 'pulse.mood.subtitle' | transloco }}</p>
      </div>
    </header>

    <div class="grid">
      <app-mood-checkin />
      <section class="panel box">
        <h2><mat-icon inline>lock</mat-icon> {{ 'pulse.mood.mine' | transloco }}</h2>
        <div class="strip">
          @for (e of history(); track e.day) {
            <span class="day" [title]="(e.day | date: 'dd.MM') + (e.comment ? ' — ' + e.comment : '')">
              <span class="emoji">{{ emoji(e.score) }}</span>
              <span class="muted small">{{ e.day | date: 'dd.MM' }}</span>
            </span>
          } @empty {
            <p class="muted">{{ 'pulse.mood.noHistory' | transloco }}</p>
          }
        </div>
      </section>
    </div>

    @if (team(); as t) {
      <section class="panel box">
        <h2>{{ 'pulse.mood.team' | transloco }}</h2>
        @if (t.coverage; as c) {
          <p class="muted">{{ 'pulse.mood.coverage' | transloco: { answered: c.answered, total: c.total } }}</p>
        } @else {
          <p class="muted">{{ 'pulse.mood.smallTeam' | transloco: { n: t.min_group } }}</p>
        }
        <div class="trend" role="img" [attr.aria-label]="'pulse.mood.team' | transloco">
          @for (w of t.weeks; track w.week_start) {
            <div class="wk">
              <span class="val">{{ w.average ?? '—' }}</span>
              <span class="col" [class.hidden]="w.suppressed" [style.height.%]="w.average ? (w.average / 5) * 100 : 4"></span>
              <span class="muted small">{{ w.week_start | date: 'dd.MM' }}</span>
            </div>
          }
        </div>
        @if (t.comments.length) {
          <h3>{{ 'pulse.mood.comments' | transloco }}</h3>
          <ul class="comments">
            @for (c of t.comments; track $index) {
              <li>{{ c }}</li>
            }
          </ul>
        }
      </section>
    }

    @if (isAdmin() && settings(); as s) {
      <section class="panel box">
        <h2>{{ 'pulse.mood.settings' | transloco }}</h2>
        <div class="filters">
          @for (d of weekdays; track d) {
            <mat-checkbox [checked]="s.weekdays.includes(d)" (change)="toggleDay(s, d)">{{ 'people.weekday.' + d | transloco }}</mat-checkbox>
          }
        </div>
        <div class="filters">
          <mat-form-field subscriptSizing="dynamic" class="grow">
            <mat-label>{{ 'pulse.mood.question' | transloco }}</mat-label>
            <input matInput [(ngModel)]="s.question" />
          </mat-form-field>
          <mat-checkbox [(ngModel)]="s.required">{{ 'pulse.fields.required' | transloco }}</mat-checkbox>
          <mat-form-field subscriptSizing="dynamic" class="num">
            <mat-label>{{ 'pulse.mood.alertDrop' | transloco }}</mat-label>
            <input matInput type="number" step="0.1" min="0.1" max="4" [(ngModel)]="s.alert_drop" />
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic" class="num">
            <mat-label>{{ 'pulse.waves.minGroup' | transloco }}</mat-label>
            <input matInput type="number" min="5" [(ngModel)]="s.min_group" />
          </mat-form-field>
          <button mat-flat-button type="button" (click)="saveSettings(s)">{{ 'pulse.save' | transloco }}</button>
        </div>
      </section>
    }
  `,
  styles: `
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); gap: 1rem; margin-bottom: 1rem; }
    .box { padding: 1rem; margin-bottom: 1rem; }
    .box h2 { margin: 0 0 0.5rem; font: var(--mat-sys-title-medium); }
    .strip { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .day { display: flex; flex-direction: column; align-items: center; }
    .emoji { font-size: 1.5rem; }
    .small { font-size: 0.7rem; }
    .trend { display: flex; align-items: flex-end; gap: 0.5rem; height: 10rem; }
    .wk { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; }
    .col { width: 100%; max-width: 2.5rem; background: var(--mat-sys-primary); border-radius: 4px 4px 0 0; }
    .col.hidden { background: repeating-linear-gradient(45deg, var(--app-border) 0 4px, transparent 4px 8px); }
    .val { font-size: 0.8rem; }
    .comments { margin: 0; padding-left: 1.25rem; }
    .num { width: 9rem; }
  `,
})
export class MoodPage implements OnInit {
  private readonly api = inject(PulseService);
  private readonly auth = inject(AuthService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly weekdays = [1, 2, 3, 4, 5, 6, 7];
  protected readonly history = signal<MoodEntry[]>([]);
  protected readonly team = signal<TeamMood | null>(null);
  protected readonly settings = signal<MoodSettings | null>(null);
  protected readonly isAdmin = computed(() => isHrStaff(this.auth.user()?.roles ?? []));
  protected readonly emoji = moodEmoji;

  ngOnInit(): void {
    this.api.myMood(30).subscribe({ next: (h) => this.history.set([...h].reverse()), error: () => this.history.set([]) });
    // 403 for employees without reports: the team block simply stays hidden.
    this.api.teamMood({ weeks: 8 }).subscribe({ next: (t) => this.team.set(t), error: () => this.team.set(null) });
    if (this.isAdmin()) {
      this.api.moodSettings().subscribe({ next: (s) => this.settings.set({ ...s, weekdays: [...s.weekdays] }), error: () => undefined });
    }
  }

  protected toggleDay(s: MoodSettings, day: number): void {
    s.weekdays = s.weekdays.includes(day) ? s.weekdays.filter((d) => d !== day) : [...s.weekdays, day].sort();
  }

  protected saveSettings(s: MoodSettings): void {
    this.api.saveMoodSettings({ ...s, alert_drop: Number(s.alert_drop), min_group: Number(s.min_group) }).subscribe({
      next: (saved) => {
        this.settings.set(saved);
        this.toast('pulse.saved');
      },
      error: (e: unknown) => this.toast(pulseErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
