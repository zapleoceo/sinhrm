import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ReportMessage } from './safe-speak.model';

/**
 * The message thread of a report (dates only, no names), drawn as a vertical route: each message is a station on one
 * line — the reporter's an open ring, the handler's a filled ring in the brand colour (author is also in the text).
 */
@Component({
  selector: 'app-safe-speak-thread',
  imports: [DatePipe, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @for (m of messages(); track $index) {
      <article class="msg" [attr.data-author]="m.author">
        <p class="who">{{ 'safeSpeak.author.' + m.author | transloco }} · <span class="mono">{{ m.created_on | date: 'dd.MM.yyyy' }}</span></p>
        <p class="text">{{ m.body }}</p>
      </article>
    }
  `,
  styles: `
    :host { position: relative; display: flex; flex-direction: column; gap: 0.75rem; padding-left: 1.5rem; }
    /* The line: a 3px track behind the stations, from the first to the last message. */
    :host::before { content: ''; position: absolute; left: 0.3125rem; top: 0.75rem; bottom: 0.75rem; width: 3px; border-radius: 2px; background: var(--app-track); }
    .msg { position: relative; max-width: 48rem; padding: 0.5rem 0.75rem; border-radius: var(--app-radius); border: var(--app-border-w) solid var(--app-border); background: var(--app-card); }
    .msg::before {
      content: ''; position: absolute; left: calc(-1.5rem + 0.0625rem); top: 0.6rem; width: 0.75rem; height: 0.75rem; box-sizing: border-box;
      border-radius: 50%; border: 3px solid var(--app-stage-new); background: var(--app-card);
    }
    .msg[data-author='handler'] { background: color-mix(in srgb, var(--mat-sys-primary) 6%, var(--app-card)); border-color: color-mix(in srgb, var(--mat-sys-primary) 30%, var(--app-border)); }
    .msg[data-author='handler']::before { border-color: var(--mat-sys-primary); background: var(--mat-sys-primary); }
    .who { margin: 0 0 0.25rem; font: var(--mat-sys-label-medium); color: var(--app-muted); }
    .text { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
  `,
})
export class SafeSpeakThread {
  readonly messages = input.required<ReportMessage[]>();
}
