import { Clipboard } from '@angular/cdk/clipboard';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { MEETING_DURATIONS, MEETING_TYPES, MeetingType, ScheduledMeeting, toIsoWithOffset } from './google.model';
import { GoogleService, googleErrorKey } from './google.service';

export interface MeetingDialogData {
  candidateId: number;
  candidateName: string;
  candidateEmail: string | null;
}

/**
 * "Schedule a meeting" from the candidate card: creates an event in the connected Google Calendar (Meet link for
 * online). Google sends no invitations; after creation the dialog shows the link to copy. Closes with `true` when
 * an event was created (the card reloads its timeline).
 */
@Component({
  selector: 'app-meeting-dialog',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'google.meeting.title' | transloco }}</h2>
    @if (created(); as ev) {
      <mat-dialog-content class="done">
        <p><mat-icon inline>check_circle</mat-icon> {{ 'google.meeting.created' | transloco }}</p>
        @if (ev.meet_link) {
          <div class="link">
            <a [href]="ev.meet_link" target="_blank" rel="noopener noreferrer">{{ ev.meet_link }}</a>
            <button mat-stroked-button type="button" (click)="copy(ev.meet_link)">
              <mat-icon>content_copy</mat-icon>{{ 'google.meeting.copy' | transloco }}
            </button>
          </div>
        }
        @if (ev.html_link) {
          <a mat-button [href]="ev.html_link" target="_blank" rel="noopener noreferrer">
            <mat-icon>open_in_new</mat-icon>{{ 'google.meeting.open' | transloco }}
          </a>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-flat-button type="button" [mat-dialog-close]="true">{{ 'common.close' | transloco }}</button>
      </mat-dialog-actions>
    } @else {
      <form [formGroup]="form" (ngSubmit)="submit()">
        <mat-dialog-content>
          <mat-form-field>
            <mat-label>{{ 'google.meeting.fields.title' | transloco }}</mat-label>
            <input matInput formControlName="title" maxlength="200" required />
          </mat-form-field>
          <div class="row">
            <mat-form-field>
              <mat-label>{{ 'google.meeting.fields.date' | transloco }}</mat-label>
              <input matInput type="date" formControlName="date" required />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'google.meeting.fields.time' | transloco }}</mat-label>
              <input matInput type="time" formControlName="time" required />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'google.meeting.fields.duration' | transloco }}</mat-label>
              <mat-select formControlName="duration">
                @for (d of durations; track d) {
                  <mat-option [value]="d">{{ d }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
          </div>
          <mat-form-field>
            <mat-label>{{ 'google.meeting.fields.type' | transloco }}</mat-label>
            <mat-select formControlName="type">
              @for (t of types; track t) {
                <mat-option [value]="t">{{ 'google.meeting.types.' + t | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          @if (form.controls.type.value === 'branch') {
            <mat-form-field>
              <mat-label>{{ 'google.meeting.fields.location' | transloco }}</mat-label>
              <input matInput formControlName="location" maxlength="255" />
            </mat-form-field>
          }
          <mat-form-field>
            <mat-label>{{ 'google.meeting.fields.notes' | transloco }}</mat-label>
            <textarea matInput formControlName="notes" rows="2" maxlength="2000"></textarea>
          </mat-form-field>
          <mat-checkbox formControlName="invite">
            {{ 'google.meeting.fields.invite' | transloco }}
          </mat-checkbox>
          @if (!data.candidateEmail) {
            <p class="muted small">{{ 'google.meeting.noEmail' | transloco }}</p>
          }
        </mat-dialog-content>
        <mat-dialog-actions align="end">
          <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
          <button mat-flat-button type="submit" [disabled]="busy()">{{ 'google.meeting.submit' | transloco }}</button>
        </mat-dialog-actions>
      </form>
    }
  `,
  styles: `
    mat-dialog-content { display: flex; flex-direction: column; min-width: min(30rem, 85vw); }
    .row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .row mat-form-field { flex: 1; min-width: 8rem; }
    .link { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
    .small { font-size: 0.8rem; margin: 0; }
  `,
})
export class MeetingDialog {
  protected readonly data = inject<MeetingDialogData>(MAT_DIALOG_DATA);
  private readonly api = inject(GoogleService);
  private readonly clipboard = inject(Clipboard);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly types = MEETING_TYPES;
  protected readonly durations = MEETING_DURATIONS;
  protected readonly busy = signal(false);
  protected readonly created = signal<ScheduledMeeting | null>(null);
  protected readonly form = inject(NonNullableFormBuilder).group({
    title: [this.i18n.translate('google.meeting.eventTitle', { name: this.data.candidateName }), Validators.required],
    date: [tomorrow(), Validators.required],
    time: ['10:00', Validators.required],
    duration: [30],
    type: ['online' as MeetingType],
    location: [''],
    notes: [''],
    invite: [false],
  });

  constructor() {
    if (!this.data.candidateEmail) {
      this.form.controls.invite.disable();
    }
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    this.busy.set(true);
    this.api
      .scheduleMeeting(this.data.candidateId, {
        title: v.title.trim(),
        start: toIsoWithOffset(v.date, v.time),
        duration_minutes: v.duration,
        type: v.type,
        invite_candidate: v.invite && this.data.candidateEmail !== null,
        location: v.type === 'branch' ? v.location.trim() || null : null,
        notes: v.notes.trim() || null,
      })
      .subscribe({
        next: (event) => {
          this.busy.set(false);
          this.created.set(event);
        },
        error: (e: unknown) => {
          this.busy.set(false);
          this.snack.open(this.i18n.translate(googleErrorKey(e)), undefined, { duration: 4000 });
        },
      });
  }

  protected copy(link: string): void {
    this.clipboard.copy(link);
    this.snack.open(this.i18n.translate('google.meeting.copied'), undefined, { duration: 2000 });
  }
}

function tomorrow(): string {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  const pad = (n: number): string => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
