import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';
import { EvaluationDetails, scoreBand } from '../scripts.model';

/**
 * Details of a script evaluation: score + engine ("правила" / "AI"), steps ✓/✗ with the quote that matched,
 * whether the next step was fixed, raised objections and recommendations. Used by the timeline badge and the
 * editor's "test on text" panel.
 */
@Component({
  selector: 'app-evaluation-view',
  imports: [MatIconModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @let e = evaluation();
    <div class="head">
      <span class="score" [attr.data-band]="band(e.score)">{{ 'scripts.evaluation.score' | transloco: { score: e.score } }}</span>
      <span class="engine">{{ 'scripts.evaluation.engine.' + e.engine | transloco }}</span>
      <span class="next" [class.ok]="e.next_step.fixed">
        <mat-icon inline>{{ e.next_step.fixed ? 'event_available' : 'event_busy' }}</mat-icon>
        {{ (e.next_step.fixed ? 'scripts.evaluation.nextFixed' : 'scripts.evaluation.nextNotFixed') | transloco }}
      </span>
    </div>
    @if (e.next_step.quote) {
      <blockquote>«{{ e.next_step.quote }}»</blockquote>
    }
    <ol class="steps">
      @for (s of e.steps; track s.id) {
        <li [class.done]="s.done">
          <mat-icon inline [attr.aria-label]="(s.done ? 'scripts.evaluation.done' : 'scripts.evaluation.missed') | transloco">
            {{ s.done ? 'check_circle' : 'cancel' }}
          </mat-icon>
          <span class="title">{{ s.title }}</span>
          @if (s.required) {
            <span class="req">{{ 'scripts.evaluation.required' | transloco }}</span>
          }
          @if (s.quote) {
            <q>{{ s.quote }}</q>
          }
        </li>
      } @empty {
        <li class="muted">{{ 'scripts.evaluation.noSteps' | transloco }}</li>
      }
    </ol>
    @if (raised().length) {
      <p class="muted small">{{ 'scripts.evaluation.objections' | transloco }}: {{ raised().join(', ') }}</p>
    }
    @if (e.recommendations.length) {
      <ul class="recs">
        @for (r of e.recommendations; track $index) {
          <li>
            @switch (r.type) {
              @case ('missed_step') {
                {{ 'scripts.evaluation.rec.missed_step' | transloco: { title: r.title } }}
              }
              @case ('negative_phrase') {
                {{ 'scripts.evaluation.rec.negative_phrase' | transloco: { quote: r.quote } }}
              }
              @default {
                {{ 'scripts.evaluation.rec.' + r.type | transloco }}
              }
            }
          </li>
        }
      </ul>
    }
  `,
  styles: `
    :host { display: block; }
    .head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
    .score { font-weight: 500; padding: 0 0.5rem; border-radius: 999px; border: 1px solid currentColor; }
    .score[data-band='good'] { color: var(--app-success); }
    .score[data-band='mid'] { color: var(--app-warning); }
    .score[data-band='low'] { color: var(--app-danger); }
    .engine { font-size: 0.75rem; color: var(--app-muted); border: 1px dashed var(--app-border); border-radius: 999px; padding: 0 0.4rem; }
    .next { display: inline-flex; align-items: center; gap: 0.25rem; color: var(--app-danger); }
    .next.ok { color: var(--app-success); }
    blockquote { margin: 0.25rem 0 0; color: var(--app-muted); font-style: italic; }
    .steps { list-style: none; padding: 0; margin: 0.5rem 0 0; display: flex; flex-direction: column; gap: 0.25rem; }
    .steps li { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.35rem; color: var(--app-danger); }
    .steps li.done { color: inherit; }
    .steps li.done mat-icon { color: var(--app-success); }
    .title { font-weight: 500; }
    .req { font-size: 0.7rem; color: var(--app-muted); }
    q { color: var(--app-muted); font-style: italic; overflow-wrap: anywhere; }
    .recs { margin: 0.5rem 0 0; padding-left: 1.25rem; }
    .small { font-size: 0.8rem; margin: 0.25rem 0 0; }
  `,
})
export class EvaluationView {
  readonly evaluation = input.required<EvaluationDetails>();
  protected readonly band = scoreBand;

  protected raised(): string[] {
    return this.evaluation()
      .objections.filter((o) => o.raised)
      .map((o) => o.trigger);
  }
}
