import { ChangeDetectionStrategy, Component, OnInit, inject, input, output, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { SEND_CHANNELS, SendChannel, SendMessage } from '../../channels/channels.model';
import { ChannelsService } from '../../channels/channels.service';
import { TemplateMenu } from '../../scripts/templates/template-menu';
import { Channel, Direction, LogTouch, MANUAL_CHANNELS } from '../recruiting.model';
import { ChannelIcon } from '../../../core/ui/channel-icon';

/**
 * Logs a touch by hand: note (text required), call (minutes), meeting, or a messenger/e-mail contact made outside.
 * Emits the body; the parent sends it and calls reset() on success.
 * "Шаблон" inserts a filled message template of the active scripts (Scripts module) into the text.
 * Messengers (Telegram / WhatsApp / Viber) that are connected (demo or live) also get "Надіслати": the message goes
 * out through the channel (Channels module). If the channel turns out not connected, the composer offers to log it.
 * E-mail sends through the connected Gmail (optional subject); Gmail connected read-only → a reconnect hint and a
 * disabled "Надіслати".
 */
@Component({
  selector: 'app-touch-composer',
  imports: [
    ChannelIcon,
    ReactiveFormsModule,
    MatButtonModule,
    MatButtonToggleModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    TranslocoPipe,
    TemplateMenu,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <form class="composer" [formGroup]="form" (ngSubmit)="submit()">
      <div class="line">
        <mat-button-toggle-group formControlName="channel" [attr.aria-label]="'recruiting.composer.channel' | transloco" hideSingleSelectionIndicator>
          @for (c of channels; track c) {
            <mat-button-toggle [value]="c" [title]="'recruiting.channel.' + c | transloco">
              <app-channel-icon [key]="c" />
              <span class="visually-hidden">{{ 'recruiting.channel.' + c | transloco }}</span>
            </mat-button-toggle>
          }
        </mat-button-toggle-group>
        <mat-form-field subscriptSizing="dynamic" class="dir">
          <mat-label>{{ 'recruiting.composer.direction' | transloco }}</mat-label>
          <mat-select formControlName="direction">
            <mat-option value="out">{{ 'recruiting.direction.out' | transloco }}</mat-option>
            <mat-option value="in">{{ 'recruiting.direction.in' | transloco }}</mat-option>
          </mat-select>
        </mat-form-field>
        @if (form.controls.channel.value === 'call' || form.controls.channel.value === 'meeting') {
          <mat-form-field subscriptSizing="dynamic" class="min">
            <mat-label>{{ 'recruiting.composer.minutes' | transloco }}</mat-label>
            <input matInput type="number" min="0" max="1440" formControlName="minutes" />
          </mat-form-field>
        }
      </div>
      @if (canSend() && form.controls.channel.value === 'email') {
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'channels.composer.subject' | transloco }}</mat-label>
          <input matInput formControlName="subject" maxlength="255" />
        </mat-form-field>
      }
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'recruiting.composer.body' | transloco }}</mat-label>
        <textarea matInput formControlName="body" rows="2" maxlength="10000"></textarea>
      </mat-form-field>
      @if (reconnectToSend()) {
        <p class="fallback" role="status">
          <mat-icon>sync_problem</mat-icon>
          <span class="grow">{{ 'channels.composer.reconnectGmail' | transloco }}</span>
        </p>
      }
      @if (notConnected()) {
        <p class="fallback" role="status">
          <mat-icon>link_off</mat-icon>
          <span class="grow">{{ 'channels.composer.notConnected' | transloco }}</span>
          <button mat-button type="button" (click)="submit()">{{ 'channels.composer.logManually' | transloco }}</button>
        </p>
      }
      <div class="actions">
        <app-template-menu [candidateId]="candidateId()" (picked)="insertTemplate($event)" />
        <span class="grow"></span>
        @if (canSend()) {
          <button mat-stroked-button type="submit" [disabled]="busy() || !valid()">{{ 'recruiting.composer.submit' | transloco }}</button>
          <button mat-flat-button type="button" [disabled]="busy() || !sendable()" (click)="send()">
            <mat-icon>send</mat-icon>
            {{ (sendMode() === 'demo' ? 'channels.composer.sendDemo' : 'channels.composer.send') | transloco }}
          </button>
        } @else if (reconnectToSend()) {
          <button mat-stroked-button type="submit" [disabled]="busy() || !valid()">{{ 'recruiting.composer.submit' | transloco }}</button>
          <button mat-flat-button type="button" disabled>
            <mat-icon>send</mat-icon>
            {{ 'channels.composer.send' | transloco }}
          </button>
        } @else {
          <button mat-flat-button type="submit" [disabled]="busy() || !valid()">{{ 'recruiting.composer.submit' | transloco }}</button>
        }
      </div>
    </form>
  `,
  styles: `
    .composer { display: flex; flex-direction: column; gap: 0.5rem; }
    .line { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
    .dir { width: 9rem; }
    .min { width: 7rem; }
    .actions { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
    .grow { flex: 1; }
    .fallback { display: flex; align-items: center; gap: 0.5rem; margin: 0; font-size: 0.9rem; color: var(--mat-sys-error); }
  `,
})
export class TouchComposer implements OnInit {
  private readonly channelsApi = inject(ChannelsService);

  readonly candidateId = input.required<number>();
  readonly logged = output<LogTouch>();
  /** "Надіслати": the parent sends it through the channel and calls reset() / offerManual(). */
  readonly sent = output<SendMessage>();

  protected readonly channels = MANUAL_CHANNELS;
  protected readonly busy = signal(false);
  protected readonly form = inject(NonNullableFormBuilder).group({
    channel: ['note' as Channel],
    direction: ['out' as Direction],
    body: [''],
    subject: [''],
    minutes: [null as number | null],
  });
  /** The last send failed with channel_not_connected: offer to log the touch by hand. */
  protected readonly notConnected = signal(false);
  private readonly channel = toSignal(this.form.controls.channel.valueChanges, { initialValue: this.form.controls.channel.value });

  ngOnInit(): void {
    this.channelsApi.ensureAvailability();
    this.form.controls.channel.valueChanges.subscribe(() => this.notConnected.set(false));
  }

  /** Mode of the selected channel when it is a messenger that can send; off otherwise. */
  protected sendMode(): 'off' | 'demo' | 'live' {
    const channel = this.channel();
    return SEND_CHANNELS.includes(channel) ? this.channelsApi.modeOf(channel) : 'off';
  }

  /** E-mail chosen while Gmail is connected read-only (no gmail.send): sending needs a reconnect. */
  protected reconnectToSend(): boolean {
    return this.channel() === 'email' && this.channelsApi.reasonOf('email') === 'reconnect_to_send';
  }

  protected canSend(): boolean {
    return this.sendMode() !== 'off';
  }

  protected sendable(): boolean {
    return this.form.controls.body.value.trim() !== '';
  }

  /** A note needs text; other channels are meaningful as a fact. */
  protected valid(): boolean {
    const v = this.form.getRawValue();
    return v.channel !== 'note' || v.body.trim() !== '';
  }

  setBusy(on: boolean): void {
    this.busy.set(on);
  }

  reset(): void {
    this.form.patchValue({ body: '', subject: '', minutes: null });
    this.busy.set(false);
    this.notConnected.set(false);
  }

  /** The channel is not connected after all: keep the text and offer "log manually". */
  offerManual(): void {
    this.busy.set(false);
    this.notConnected.set(true);
  }

  protected send(): void {
    if (!this.sendable() || this.busy() || !this.canSend()) {
      return;
    }
    this.busy.set(true);
    const message: SendMessage = { channel: this.channel() as SendChannel, text: this.form.controls.body.value.trim() };
    const subject = this.form.controls.subject.value.trim();
    if (message.channel === 'email' && subject) {
      message.subject = subject;
    }
    this.sent.emit(message);
  }

  /** Appends the template to the text (a blank line between it and what was typed before). */
  protected insertTemplate(text: string): void {
    const body = this.form.controls.body.value.trimEnd();
    this.form.controls.body.setValue(body ? `${body}

${text}` : text);
  }

  protected submit(): void {
    if (!this.valid() || this.busy()) {
      return;
    }
    const v = this.form.getRawValue();
    const body: LogTouch = { channel: v.channel, direction: v.direction };
    if (v.body.trim()) {
      body.body = v.body.trim();
    }
    if (v.minutes !== null && v.minutes > 0) {
      body.duration_sec = Math.round(v.minutes * 60);
    }
    this.busy.set(true);
    this.logged.emit(body);
  }
}
