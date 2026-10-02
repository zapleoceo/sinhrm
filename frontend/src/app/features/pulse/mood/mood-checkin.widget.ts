import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { MOODS, MoodToday, moodEmoji } from '../pulse.model';
import { PulseService } from '../pulse.service';

/**
 * Home-page mood check-in: "How is your mood today?" with five emoji and an optional comment, once a day on the
 * configured weekdays. After answering it shows today's mood. Hidden for users without an employee record.
 * Faces are 44px touch targets; the picked one is a filled ring (shape + aria-checked, not colour alone).
 */
@Component({
  selector: 'app-mood-checkin',
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatInputModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (state(); as s) {
      @if (s.has_employee) {
        <section class="panel card mood">
          @if (s.ask) {
            <h2>{{ s.question }}</h2>
            <div class="faces" role="radiogroup" [attr.aria-label]="s.question">
              @for (m of moods; track $index) {
                <button type="button" role="radio" class="face" [class.on]="picked() === $index + 1" [attr.aria-checked]="picked() === $index + 1"
                  [attr.aria-label]="'pulse.mood.level.' + ($index + 1) | transloco" (click)="picked.set($index + 1)">{{ m }}</button>
              }
            </div>
            @if (picked()) {
              <mat-form-field class="full" subscriptSizing="dynamic">
                <mat-label>{{ 'pulse.mood.comment' | transloco }}</mat-label>
                <input matInput [(ngModel)]="comment" maxlength="1000" />
              </mat-form-field>
              <button mat-flat-button type="button" (click)="send()">{{ 'pulse.mood.send' | transloco }}</button>
            }
          } @else if (s.today) {
            <p class="today"><span class="emoji">{{ emoji(s.today.score) }}</span> {{ 'pulse.mood.thanks' | transloco }}</p>
          } @else {
            <p class="muted">{{ 'pulse.mood.notToday' | transloco }}</p>
          }
          <a mat-button routerLink="/pulse/mood">{{ 'pulse.mood.history' | transloco }}</a>
        </section>
      }
    }
  `,
  styles: `
    :host { display: contents; }
    .mood { padding: 1.25rem; }
    .mood h2 { margin: 0 0 0.75rem; font: var(--mat-sys-title-medium); }
    .faces { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .face {
      display: grid; place-items: center; width: 3rem; height: 3rem; min-width: 44px; min-height: 44px; padding: 0; box-sizing: border-box;
      font-size: 1.75rem; line-height: 1; border-radius: 50%; border: var(--app-border-w) solid var(--app-border); background: var(--app-card); cursor: pointer;
      transition: border-color var(--app-fast) ease, transform var(--app-fast) ease;
    }
    .face:hover { border-color: var(--mat-sys-outline); transform: translateY(-1px); }
    .face.on { border: 3px solid var(--mat-sys-primary); background: color-mix(in srgb, var(--mat-sys-primary) 12%, var(--app-card)); }
    .full { width: 100%; margin: 0.75rem 0 0.5rem; }
    .today { display: flex; align-items: center; gap: 0.5rem; margin: 0 0 0.25rem; }
    .emoji { font-size: 2rem; }
    @media (prefers-reduced-motion: reduce) {
      .face { transition: none; }
      .face:hover { transform: none; }
    }
  `,
})
export class MoodCheckinWidget implements OnInit {
  private readonly api = inject(PulseService);
  protected readonly moods = MOODS;
  protected readonly state = signal<MoodToday | null>(null);
  protected readonly picked = signal<number | null>(null);
  protected readonly emoji = moodEmoji;
  protected comment = '';

  ngOnInit(): void {
    this.api.moodToday().subscribe({ next: (s) => this.state.set(s), error: () => this.state.set(null) });
  }

  protected send(): void {
    const score = this.picked();
    if (!score) {
      return;
    }
    this.api.checkIn(score, this.comment || null).subscribe({
      next: (entry) => this.state.update((s) => (s ? { ...s, ask: false, today: entry } : s)),
      error: () => undefined,
    });
  }
}
