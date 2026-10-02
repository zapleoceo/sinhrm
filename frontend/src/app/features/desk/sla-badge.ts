import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { DeskCase, SLA_TONE, slaState } from './desk.model';

/** SLA badge of a case: breached (red), running with a due time (amber), met / no target (quiet). */
@Component({
  selector: 'app-sla-badge',
  imports: [DatePipe, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span class="sla app-pill" [attr.data-state]="state()" [attr.data-tone]="tone[state()]" [title]="(c().sla.resolve_due | date: 'dd.MM HH:mm') ?? ''">
      {{ 'desk.sla.' + state() | transloco }}
      @if (state() === 'due' && c().sla.first_response_due && !c().first_response_at) {
        · {{ 'desk.sla.answerBy' | transloco }} {{ c().sla.first_response_due | date: 'dd.MM HH:mm' }}
      }
    </span>
  `,
  styles: `
    /* Look = global .app-pill (colour + marker shape); dates inside read in mono. */
    .sla { font-variant-numeric: tabular-nums; }
  `,
})
export class SlaBadge {
  readonly c = input.required<Pick<DeskCase, 'sla' | 'status' | 'first_response_at'>>();
  protected readonly state = computed(() => slaState(this.c()));
  protected readonly tone = SLA_TONE;
}
