import { toSignal } from '@angular/core/rxjs-interop';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { Router } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { CANDIDATE_SOURCES, Candidate, CandidateSource, DuplicateCandidate, SaveCandidate } from '../recruiting.model';
import { RecruitingService, duplicateOf, recruitingErrorKey } from '../recruiting.service';
import { ChannelIcon } from '../../../core/ui/channel-icon';

export interface CandidateDialogData {
  vacancyId?: number;
}

/**
 * New candidate. If the contacts match a visible candidate (409), the dialog offers to open the existing card; a match
 * in another branch only shows a message (the API does not disclose it). Duplicates are never created.
 */
@Component({
  selector: 'app-candidate-dialog',
  imports: [ChannelIcon, ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'recruiting.candidates.new' | transloco }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <mat-form-field>
          <mat-label>{{ 'recruiting.candidates.fields.fullName' | transloco }}</mat-label>
          <input matInput formControlName="full_name" required maxlength="255" cdkFocusInitial />
          <mat-error>{{ 'recruiting.required' | transloco }}</mat-error>
        </mat-form-field>
        <div class="two">
          <mat-form-field>
            <mat-label>{{ 'recruiting.candidates.fields.phone' | transloco }}</mat-label>
            <input matInput formControlName="phone" type="tel" autocomplete="off" placeholder="+380…" />
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'recruiting.candidates.fields.email' | transloco }}</mat-label>
            <input matInput formControlName="email" type="email" autocomplete="off" />
          </mat-form-field>
        </div>
        <div class="two">
          <mat-form-field>
            <mat-label>{{ 'recruiting.candidates.fields.telegram' | transloco }}</mat-label>
            <input matInput formControlName="telegram_username" autocomplete="off" placeholder="@username" />
          </mat-form-field>
          <mat-form-field>
            <mat-label>{{ 'recruiting.candidates.fields.source' | transloco }}</mat-label>
            <mat-select formControlName="source">
              @for (s of sources; track s) {
                <mat-option [value]="s"><app-channel-icon [key]="s" /> {{ 'recruiting.source.' + s | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
        </div>
        <mat-form-field>
          <mat-label>{{ 'recruiting.channels.channel' | transloco }}</mat-label>
          <mat-select formControlName="channel_id">
            <mat-option [value]="null">{{ 'recruiting.channels.auto' | transloco }}</mat-option>
            @for (ch of channels(); track ch.id) {
              <mat-option [value]="ch.id">{{ ch.name }}</mat-option>
            }
          </mat-select>
          <mat-hint>{{ 'recruiting.channels.autoHint' | transloco }}</mat-hint>
        </mat-form-field>
        @if (duplicate(); as dup) {
          <div class="dup" role="alert">
            <p>{{ 'recruiting.candidates.duplicate' | transloco: { by: ('recruiting.matchedBy.' + dup.matched_by | transloco) } }}</p>
            <button mat-stroked-button type="button" (click)="openExisting(dup.existing_id)">{{ 'recruiting.candidates.openExisting' | transloco }}</button>
          </div>
        }
        @if (error(); as key) {
          <p class="error" role="alert">{{ key | transloco }}</p>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
        <button mat-flat-button type="submit" [disabled]="saving()">{{ 'recruiting.save' | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `
    mat-dialog-content { display: flex; flex-direction: column; min-width: min(30rem, 84vw); }
    .two { display: grid; grid-template-columns: 1fr 1fr; gap: 0 0.75rem; }
    .dup { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
    .dup p { flex-basis: 100%; margin: 0; color: var(--app-warning); }
    .error { color: var(--app-danger); margin: 0; }
    @media (max-width: 560px) { .two { grid-template-columns: 1fr; } }
  `,
})
export class CandidateDialog {
  private readonly data = inject<CandidateDialogData | null>(MAT_DIALOG_DATA, { optional: true });
  private readonly api = inject(RecruitingService);
  private readonly router = inject(Router);
  private readonly ref = inject<MatDialogRef<CandidateDialog, Candidate>>(MatDialogRef);

  protected readonly sources = CANDIDATE_SOURCES;
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly duplicate = signal<DuplicateCandidate | null>(null);
  protected readonly form = inject(NonNullableFormBuilder).group({
    full_name: ['', [Validators.required, Validators.minLength(2)]],
    phone: [''],
    email: ['', Validators.email],
    telegram_username: [''],
    source: ['manual' as CandidateSource],
    channel_id: [null as number | null],
  });
  protected readonly channels = toSignal(this.api.channels(), { initialValue: [] });

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    const body: SaveCandidate = {
      full_name: v.full_name.trim(),
      phone: v.phone.trim() || null,
      email: v.email.trim() || null,
      telegram_username: v.telegram_username.trim() || null,
      source: v.source,
      channel_id: v.channel_id,
      vacancy_id: this.data?.vacancyId ?? null,
    };
    this.saving.set(true);
    this.error.set(null);
    this.api.createCandidate(body).subscribe({
      next: (c) => this.ref.close(c),
      error: (e: unknown) => {
        this.saving.set(false);
        const dup = duplicateOf(e);
        this.duplicate.set(dup);
        this.error.set(dup ? null : recruitingErrorKey(e));
      },
    });
  }

  protected openExisting(id: number): void {
    this.ref.close();
    void this.router.navigate(['/candidates', id]);
  }
}
