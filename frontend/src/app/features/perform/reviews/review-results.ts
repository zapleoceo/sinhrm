import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { REVIEW_TYPES, ReviewResult, ReviewType, scoreWidth } from '../perform.model';

/**
 * Results of one subject in one cycle: per competency a CSS bar per reviewer type; suppressed groups (fewer than
 * min_reviewers peers/reports) are shown as "hidden to protect anonymity", never as a number.
 */
@Component({
  selector: 'app-review-results',
  imports: [TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @let r = result();
    <h3>{{ r.cycle.name }} <span class="muted">· {{ 'perform.cycleStatus.' + r.cycle.status | transloco }}</span></h3>
    <p class="legend">
      @for (t of types(); track t) {
        <span class="key" [attr.data-type]="t">
          {{ 'perform.reviewType.' + t | transloco }}
          @if (r.groups[t]?.submitted; as s) {
            <em class="muted">({{ 'perform.reviews.inProgress' | transloco: { n: s } }})</em>
          } @else if (r.groups[t]?.suppressed) {
            <em class="muted">({{ 'perform.reviews.suppressed' | transloco: { n: r.min_reviewers } }})</em>
          } @else if (r.groups[t]?.reviewers !== null && r.groups[t]?.reviewers !== undefined) {
            <span class="muted">({{ r.groups[t]?.reviewers }})</span>
          }
        </span>
      }
    </p>
    <table class="scores">
      <tbody>
        @for (c of r.competencies; track c.id) {
          <tr>
            <th scope="row">{{ c.name }} @if (c.average !== null) { <span class="muted">· {{ c.average }}</span> }</th>
            <td>
              @for (t of types(); track t) {
                <div class="barrow">
                  <span class="bar" [attr.data-type]="t" [style.width.%]="width(c.scores[t], c.max)"></span>
                  <span class="num">{{ c.scores[t] ?? '—' }}</span>
                </div>
              }
            </td>
          </tr>
        }
      </tbody>
    </table>
    @if (r.comments.length) {
      <h4>{{ 'perform.reviews.comments' | transloco }}</h4>
      <ul class="comments">
        @for (c of r.comments; track $index) {
          <li><span class="muted">{{ 'perform.reviewType.' + c.type | transloco }}@if (c.author) { · {{ c.author }}}:</span> {{ c.text }}</li>
        }
      </ul>
    }
  `,
  styles: `
    :host { display: block; }
    h3 { font: var(--mat-sys-title-medium); margin: 0.5rem 0; }
    .legend { display: flex; gap: 1rem; flex-wrap: wrap; }
    .key::before { content: ''; display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 2px; margin-right: 0.35rem; background: var(--c); }
    .scores { width: 100%; border-collapse: collapse; }
    .scores th { text-align: left; font-weight: 500; width: 35%; vertical-align: top; padding: 0.4rem 0.5rem 0.4rem 0; }
    .barrow { display: flex; align-items: center; gap: 0.5rem; margin: 0.15rem 0; }
    .bar { display: inline-block; height: 10px; border-radius: 3px; background: var(--c); min-width: 2px; }
    .num { font-size: 0.8rem; color: var(--app-muted); }
    [data-type='self'] { --c: var(--mat-sys-tertiary); }
    [data-type='manager'] { --c: var(--mat-sys-primary); }
    [data-type='peer'] { --c: var(--app-success); }
    [data-type='upward'] { --c: var(--app-warning); }
    .comments { margin: 0; padding-left: 1.25rem; }
  `,
})
export class ReviewResults {
  readonly result = input.required<ReviewResult>();
  protected readonly types = computed<ReviewType[]>(() => REVIEW_TYPES.filter((t) => t in this.result().groups));
  protected readonly width = scoreWidth;
}
