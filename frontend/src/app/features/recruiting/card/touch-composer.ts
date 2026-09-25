import { ChangeDetectionStrategy, Component, inject, output, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { CHANNEL_ICONS, Channel, Direction, LogTouch, MANUAL_CHANNELS } from '../recruiting.model';

/**
 * Logs a touch by hand: note (text required), call (minutes), meeting, or a messenger/e-mail contact made outside.
 * Ctrl/Cmd+Enter submits. Emits the body; the parent sends it and calls reset() on success.
 */
@Component({
  selector: 'app-touch-composer',
  imports: [ReactiveFormsModule, MatButtonModule, MatButtonToggleModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <form class="composer" [formGroup]="form" (ngSubmit)="submit()" (keydown.control.enter)="submit()" (keydown.meta.enter)="submit()">
      <div class="line">
        <mat-button-toggle-group formControlName="channel" [attr.aria-label]="'recruiting.composer.channel' | transloco" hideSingleSelectionIndicator>
          @for (c of channels; track c) {
            <mat-button-toggle [value]="c" [title]="'recruiting.channel.' + c | transloco">
              <mat-icon>{{ icons[c] }}</mat-icon>
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
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'recruiting.composer.body' | transloco }}</mat-label>
        <textarea matInput formControlName="body" rows="2" maxlength="10000"></textarea>
      </mat-form-field>
      <div class="actions">
        <span class="muted hint">{{ 'recruiting.composer.hint' | transloco }}</span>
        <button mat-flat-button type="submit" [disabled]="busy() || !valid()">{{ 'recruiting.composer.submit' | transloco }}</button>
      </div>
    </form>
  `,
  styles: `
    .composer { display: flex; flex-direction: column; gap: 0.5rem; }
    .line { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
    .dir { width: 9rem; }
    .min { width: 7rem; }
    .actions { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
    .hint { font-size: 0.8rem; }
  `,
})
export class TouchComposer {
  readonly logged = output<LogTouch>();

  protected readonly channels = MANUAL_CHANNELS;
  protected readonly icons = CHANNEL_ICONS;
  protected readonly busy = signal(false);
  protected readonly form = inject(NonNullableFormBuilder).group({
    channel: ['note' as Channel],
    direction: ['out' as Direction],
    body: [''],
    minutes: [null as number | null],
  });

  /** A note needs text; other channels are meaningful as a fact. */
  protected valid(): boolean {
    const v = this.form.getRawValue();
    return v.channel !== 'note' || v.body.trim() !== '';
  }

  setBusy(on: boolean): void {
    this.busy.set(on);
  }

  reset(): void {
    this.form.patchValue({ body: '', minutes: null });
    this.busy.set(false);
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
