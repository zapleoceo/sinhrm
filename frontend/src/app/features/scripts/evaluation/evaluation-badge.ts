import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { EvaluationSummary } from '../../recruiting/recruiting.model';
import { TouchEvaluation, scoreBand } from '../scripts.model';
import { ScriptsService, scriptsErrorKey } from '../scripts.service';
import { EvaluationView } from './evaluation-view';

/**
 * Score chip on a timeline touchpoint. Click expands the details (loaded once from
 * GET /api/touchpoints/{id}/evaluation).
 */
@Component({
  selector: 'app-evaluation-badge',
  imports: [MatIconModule, MatProgressBarModule, TranslocoPipe, EvaluationView],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button type="button" class="chip" [attr.data-band]="band(summary().score)" [attr.aria-expanded]="open()" (click)="toggle()">
      <mat-icon inline>fact_check</mat-icon>
      {{ 'scripts.evaluation.badge' | transloco: { score: summary().score } }}
      <span class="engine">{{ 'scripts.evaluation.engine.' + summary().engine | transloco }}</span>
      @if (!summary().next_step_fixed) {
        <mat-icon inline [attr.aria-label]="'scripts.evaluation.nextNotFixed' | transloco">event_busy</mat-icon>
      }
      <mat-icon inline>{{ open() ? 'expand_less' : 'expand_more' }}</mat-icon>
    </button>
    @if (open()) {
      <div class="details">
        @if (details(); as d) {
          <app-evaluation-view [evaluation]="d" />
        } @else if (error(); as key) {
          <p class="muted">{{ key | transloco }}</p>
        } @else {
          <mat-progress-bar mode="indeterminate" />
        }
      </div>
    }
  `,
  styles: `
    :host { display: block; margin-top: 0.25rem; }
    .chip {
      display: inline-flex; align-items: center; gap: 0.25rem; font: inherit; font-size: 0.8rem; cursor: pointer;
      background: transparent; color: inherit; border: 1px solid var(--app-border); border-radius: 999px; padding: 0 0.5rem;
    }
    .chip[data-band='good'] { border-color: var(--app-success); }
    .chip[data-band='mid'] { border-color: var(--app-warning); }
    .chip[data-band='low'] { border-color: var(--app-danger); }
    .engine { color: var(--app-muted); }
    .details { margin-top: 0.5rem; padding: 0.75rem; border: 1px solid var(--app-border); border-radius: var(--app-radius); }
  `,
})
export class EvaluationBadge {
  readonly touchpointId = input.required<number>();
  readonly summary = input.required<EvaluationSummary>();

  private readonly api = inject(ScriptsService);
  protected readonly open = signal(false);
  protected readonly details = signal<TouchEvaluation | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly band = scoreBand;

  protected toggle(): void {
    this.open.update((o) => !o);
    if (this.open() && !this.details()) {
      this.error.set(null);
      this.api.evaluation(this.touchpointId()).subscribe({
        next: (d) => this.details.set(d),
        error: (e: unknown) => this.error.set(scriptsErrorKey(e)),
      });
    }
  }
}
