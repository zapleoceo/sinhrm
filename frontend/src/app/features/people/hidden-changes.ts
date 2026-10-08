import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ChangeableField, fieldLabelKey } from './people.model';

/**
 * One line listing the fields of a change request whose values the caller may not read (ChangeRequestResource
 * hidden_changes): «Скрыто: адрес, личный e-mail». Nothing is rendered when the list is empty.
 */
@Component({
  selector: 'app-hidden-changes',
  imports: [TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (fields().length) {
      <span class="muted hidden-changes"
        >{{ 'people.changes.hidden' | transloco }}:
        @for (f of fields(); track f; let last = $last) {
          {{ label(f) | transloco }}{{ last ? '' : ',' }}
        }
      </span>
    }
  `,
  styles: `
    :host { display: contents; }
  `,
})
export class HiddenChangesLine {
  readonly fields = input.required<readonly ChangeableField[]>();

  protected readonly label = fieldLabelKey;
}
