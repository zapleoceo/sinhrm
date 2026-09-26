import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { DeskCase, slaState } from './desk.model';

/** SLA badge of a case: breached (red), running with a due time (amber), met / no target (quiet). */
@Component({
  selector: 'app-sla-badge',
  imports: [DatePipe, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span class="sla" [attr.data-state]="state()" [title]="(c().sla.resolve_due | date: 'dd.MM HH:mm') ?? ''">
      {{ 'desk.sla.' + state() | transloco }}
      @if (state() === 'due' && c().sla.first_response_due && !c().first_response_at) {
        · {{ 'desk.sla.answerBy' | transloco }} {{ c().sla.first_response_due | date: 'dd.MM HH:mm' }}
      }
    </span>
  `,
  styles: `
    .sla { display: inline-block; font-size: 0.75rem; padding: 0.1rem 0.5rem; border-radius: 999px; border: 1px solid var(--app-border); color: var(--app-muted); white-space: nowrap; }
    .sla[data-state='breached'] { color: var(--app-danger); border-color: currentColor; font-weight: 500; }
    .sla[data-state='due'] { color: var(--app-warning); border-color: currentColor; }
  `,
})
export class SlaBadge {
  readonly c = input.required<Pick<DeskCase, 'sla' | 'status' | 'first_response_at'>>();
  protected readonly state = computed(() => slaState(this.c()));
}
