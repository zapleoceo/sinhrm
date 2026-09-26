import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { FaIconComponent } from '@fortawesome/angular-fontawesome';
import { channelIconSpec } from './channel-icons';

/**
 * Font Awesome icon of a channel / source / integration (`telegram`, `work_ua`, `google_sheets`…) in its brand color.
 * With `label` (already translated) the icon is announced and gets a tooltip; without it — decorative
 * (`aria-hidden`), for places where the name is printed next to it. `mono` drops the brand color.
 */
@Component({
  selector: 'app-channel-icon',
  imports: [FaIconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'app-channel-icon',
    '[attr.role]': 'label() ? "img" : null',
    '[attr.aria-label]': 'label() || null',
    '[attr.title]': 'label() || null',
    '[attr.aria-hidden]': 'label() ? null : "true"',
    '[attr.data-key]': 'key()',
    '[style.color]': 'mono() ? null : spec().color',
  },
  template: `
    <fa-icon [icon]="spec().icon" [fixedWidth]="true" a11yRole="presentation" />
    @if (spec().badge; as badge) {
      <span class="badge">{{ badge }}</span>
    }
  `,
  styles: `
    :host { display: inline-flex; align-items: center; gap: 0.15rem; line-height: 1; vertical-align: middle; }
    .badge { font: 600 0.6rem/1 var(--mat-sys-label-small-font, inherit); letter-spacing: 0; opacity: 0.85; }
  `,
})
export class ChannelIcon {
  readonly key = input.required<string | null | undefined>();
  /** Translated name; empty → decorative icon. */
  readonly label = input<string | null | undefined>(null);
  readonly mono = input(false);

  protected readonly spec = computed(() => channelIconSpec(this.key()));
}
