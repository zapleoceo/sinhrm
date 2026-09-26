import { Clipboard } from '@angular/cdk/clipboard';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { IntegrationStatus } from '../integrations/integrations.model';
import { ChannelInfo } from './channels.model';
import { ChannelsService, channelErrorKey, webhookUrlForConsole } from './channels.service';

/**
 * Channel part of an integration card (superadmin): webhook URL to paste in the provider console (copy button),
 * "Register webhook" (Telegram, Viber), "Send test" to a recipient typed here, "Simulate event" in demo mode.
 * Renders nothing for an integration that is not a channel.
 */
@Component({
  selector: 'app-channel-panel',
  imports: [ReactiveFormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (info(); as ch) {
      <section class="panel" [attr.aria-label]="'channels.panel.title' | transloco">
        <h4>{{ 'channels.panel.title' | transloco }}</h4>
        <p class="muted">{{ 'channels.auth.' + ch.auth | transloco }}</p>
        <div class="url">
          <code>{{ consoleUrl() }}</code>
          <button mat-icon-button type="button" (click)="copy()" [attr.aria-label]="'channels.panel.copy' | transloco" [title]="'channels.panel.copy' | transloco">
            <mat-icon>content_copy</mat-icon>
          </button>
        </div>
        @if (ch.handshake) {
          <p class="muted hint">{{ 'channels.panel.handshakeHint' | transloco }}</p>
        }
        <p class="muted hint">{{ 'channels.steps.' + ch.key | transloco }}</p>

        <div class="buttons">
          @if (ch.can_register) {
            <button mat-stroked-button type="button" [disabled]="busy() || status() === 'off'" (click)="register()">
              <mat-icon>link</mat-icon>
              {{ 'channels.panel.register' | transloco }}
            </button>
          }
          @if (status() === 'demo') {
            <button mat-stroked-button type="button" [disabled]="busy()" (click)="simulate()">
              <mat-icon>science</mat-icon>
              {{ 'channels.panel.simulate' | transloco }}
            </button>
          }
        </div>

        @if (ch.can_send) {
          <form class="test" [formGroup]="testForm" (ngSubmit)="sendTest()">
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'channels.panel.testTo.' + ch.key | transloco }}</mat-label>
              <input matInput formControlName="to" autocomplete="off" maxlength="64" />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'channels.panel.testText' | transloco }}</mat-label>
              <input matInput formControlName="text" autocomplete="off" maxlength="1000" />
            </mat-form-field>
            <button mat-stroked-button type="submit" [disabled]="busy() || status() === 'off' || testForm.invalid">
              <mat-icon>send</mat-icon>
              {{ 'channels.panel.sendTest' | transloco }}
            </button>
          </form>
        }
      </section>
    }
  `,
  styles: `
    .panel { display: flex; flex-direction: column; gap: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--mat-sys-outline-variant); }
    h4 { margin: 0; }
    .url { display: flex; align-items: center; gap: 0.25rem; }
    code { overflow-wrap: anywhere; font-size: 0.85rem; padding: 0.25rem 0.5rem; border-radius: 6px; background: var(--mat-sys-surface-container); }
    .hint { font-size: 0.85rem; margin: 0; }
    .buttons { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .test { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
    .grow { flex: 1; min-width: 12rem; }
  `,
})
export class ChannelPanel implements OnInit {
  private readonly api = inject(ChannelsService);
  private readonly clipboard = inject(Clipboard);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  readonly key = input.required<string>();
  readonly status = input.required<IntegrationStatus>();
  /** Something was written to the integration log (the card reloads "last events"). */
  readonly acted = output<void>();

  protected readonly info = signal<ChannelInfo | null>(null);
  protected readonly busy = signal(false);
  protected readonly consoleUrl = computed(() => {
    const info = this.info();
    return info ? webhookUrlForConsole(info, this.i18n.translate('channels.panel.tokenPlaceholder')) : '';
  });
  protected readonly testForm = inject(NonNullableFormBuilder).group({
    to: ['', [Validators.required, Validators.maxLength(64)]],
    text: [''],
  });

  ngOnInit(): void {
    this.api.adminOverview().subscribe({
      next: (list) => this.info.set(list.find((c) => c.key === this.key()) ?? null),
      error: () => this.info.set(null),
    });
  }

  protected copy(): void {
    if (this.clipboard.copy(this.consoleUrl())) {
      this.toast('channels.panel.copied');
    }
  }

  protected register(): void {
    this.run(this.api.registerWebhook(this.key()), 'channels.panel.registered');
  }

  protected simulate(): void {
    this.run(this.api.simulate(this.key(), {}), 'channels.panel.simulated');
  }

  protected sendTest(): void {
    const v = this.testForm.getRawValue();
    this.run(this.api.sendTest(this.key(), v.to.trim(), v.text.trim()), 'channels.panel.testSent');
  }

  private run(request: Observable<unknown>, okKey: string): void {
    this.busy.set(true);
    request.subscribe({
      next: () => {
        this.busy.set(false);
        this.toast(okKey);
        this.acted.emit();
      },
      error: (e: unknown) => {
        this.busy.set(false);
        this.toast(channelErrorKey(e));
        this.acted.emit();
      },
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
