import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslocoPipe } from '@jsverse/transloco';
import { WriteRequest } from '../assistant.model';

/** Confirmation card of a write the assistant proposed: nothing runs until «Виконати». */
@Component({
  selector: 'app-assistant-confirm',
  imports: [MatButtonModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="assistant-confirm" role="group" [attr.aria-label]="'assistant.confirm.title' | transloco">
      <span class="label">{{ 'assistant.confirm.title' | transloco }}</span>
      <p class="summary">{{ request().summary }}</p>
      <code class="route">{{ request().method }} /api/{{ request().path }}</code>
      @if (body()) {
        <details>
          <summary>{{ 'assistant.confirm.body' | transloco }}</summary>
          <pre>{{ body() }}</pre>
        </details>
      }
      <div class="actions">
        <button mat-button type="button" (click)="decide.emit(false)">{{ 'assistant.confirm.cancel' | transloco }}</button>
        <button mat-flat-button type="button" (click)="decide.emit(true)">{{ 'assistant.confirm.run' | transloco }}</button>
      </div>
    </div>
  `,
  styles: `
    .assistant-confirm {
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
      padding: 0.75rem;
      border: 1.5px dashed var(--mat-sys-primary);
      border-radius: var(--app-radius);
      background: color-mix(in srgb, var(--mat-sys-primary) 6%, var(--mat-sys-surface));
    }
    .label { font: var(--mat-sys-label-medium); color: var(--mat-sys-primary); }
    .summary { margin: 0; font: var(--mat-sys-title-small); white-space: pre-wrap; }
    .route { font: 12px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--app-muted); word-break: break-all; }
    details { font: var(--mat-sys-body-small); }
    summary { cursor: pointer; color: var(--app-muted); }
    pre {
      max-height: 12rem;
      margin: 0.4rem 0 0;
      padding: 0.5rem;
      overflow: auto;
      border-radius: 8px;
      background: var(--mat-sys-surface-container);
      font: 12px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
  `,
})
export class AssistantConfirm {
  readonly request = input.required<WriteRequest>();
  readonly decide = output<boolean>();

  protected readonly body = computed(() => {
    const body = this.request().body;
    return body && Object.keys(body).length > 0 ? JSON.stringify(body, null, 2) : '';
  });
}
