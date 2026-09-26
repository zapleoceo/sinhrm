import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ReportMessage } from './safe-speak.model';

/** The message thread of a report (dates only, no names). */
@Component({
  selector: 'app-safe-speak-thread',
  imports: [DatePipe, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @for (m of messages(); track $index) {
      <article class="msg" [attr.data-author]="m.author">
        <p class="who muted">{{ 'safeSpeak.author.' + m.author | transloco }} · {{ m.created_on | date: 'dd.MM.yyyy' }}</p>
        <p class="text">{{ m.body }}</p>
      </article>
    }
  `,
  styles: `
    :host { display: flex; flex-direction: column; gap: 0.75rem; }
    .msg { max-width: 48rem; padding: 0.5rem 0.75rem; border-radius: var(--app-radius); border: 1px solid var(--app-border); }
    .msg[data-author='handler'] { align-self: flex-end; background: color-mix(in srgb, var(--mat-sys-primary) 6%, transparent); }
    .who { margin: 0 0 0.25rem; font-size: 0.8rem; }
    .text { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
  `,
})
export class SafeSpeakThread {
  readonly messages = input.required<ReportMessage[]>();
}
