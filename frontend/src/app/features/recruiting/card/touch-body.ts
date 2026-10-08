import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';
import { Touchpoint } from '../recruiting.model';

/**
 * The text of a timeline touch. A touch the viewer may not read (TouchpointResource `redacted`: the offer text
 * carries the salary) comes back without a body — instead of an empty bubble it shows a placeholder, so the touch
 * itself stays visible in the timeline.
 */
@Component({
  selector: 'app-touch-body',
  imports: [MatIconModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (touch().redacted) {
      <p class="text redacted"><mat-icon inline>lock</mat-icon>{{ 'recruiting.card.redacted' | transloco }}</p>
    } @else if (touch().body) {
      <p class="text">{{ touch().body }}</p>
    }
  `,
  styles: `
    :host { display: contents; }
    .text { margin: 0.25rem 0; white-space: pre-line; overflow-wrap: anywhere; }
    .text.redacted { display: flex; align-items: center; gap: 0.35rem; color: var(--app-muted); font-style: italic; }
  `,
})
export class TouchBody {
  readonly touch = input.required<Touchpoint>();
}
