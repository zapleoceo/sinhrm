import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { DictionaryItem } from '../../directory/directory.model';
import { DirectoryService } from '../../directory/directory.service';
import { SaveVacancy, VACANCY_STATUSES, Vacancy, VacancyStatus } from '../recruiting.model';
import { recruitingErrorKey } from '../recruiting.service';
import { VacanciesStore } from './vacancies.store';

export interface VacancyDialogData {
  vacancy: Vacancy | null;
  store: VacanciesStore;
}

/** Create / edit a vacancy. Branch and position lists come from the directory (active items only). */
@Component({
  selector: 'app-vacancy-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ (data.vacancy ? 'recruiting.vacancies.edit' : 'recruiting.vacancies.new') | transloco }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <mat-form-field>
          <mat-label>{{ 'recruiting.vacancies.fields.title' | transloco }}</mat-label>
          <input matInput formControlName="title" maxlength="255" required cdkFocusInitial />
          <mat-error>{{ 'recruiting.required' | transloco }}</mat-error>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'recruiting.vacancies.fields.branch' | transloco }}</mat-label>
          <mat-select formControlName="branch_id" required>
            @for (b of branches(); track b.id) {
              <mat-option [value]="b.id">{{ b.name }}</mat-option>
            }
          </mat-select>
          <mat-error>{{ 'recruiting.required' | transloco }}</mat-error>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'recruiting.vacancies.fields.position' | transloco }}</mat-label>
          <mat-select formControlName="position_id">
            <mat-option [value]="null">—</mat-option>
            @for (p of positions(); track p.id) {
              <mat-option [value]="p.id">{{ p.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'recruiting.vacancies.fields.status' | transloco }}</mat-label>
          <mat-select formControlName="status">
            @for (s of statuses; track s) {
              <mat-option [value]="s">{{ 'recruiting.vacancyStatus.' + s | transloco }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'recruiting.vacancies.fields.description' | transloco }}</mat-label>
          <textarea matInput formControlName="description" rows="4" maxlength="10000"></textarea>
        </mat-form-field>
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
    mat-dialog-content { display: flex; flex-direction: column; min-width: min(28rem, 80vw); }
    .error { color: var(--app-danger); margin: 0; }
  `,
})
export class VacancyDialog implements OnInit {
  protected readonly data = inject<VacancyDialogData>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<VacancyDialog, Vacancy>>(MatDialogRef);
  private readonly directory = inject(DirectoryService);

  protected readonly statuses = VACANCY_STATUSES;
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly positions = signal<DictionaryItem[]>([]);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly form = inject(NonNullableFormBuilder).group({
    title: [this.data.vacancy?.title ?? '', [Validators.required, Validators.maxLength(255)]],
    branch_id: [this.data.vacancy?.branch_id ?? (null as number | null), Validators.required],
    position_id: [this.data.vacancy?.position_id ?? (null as number | null)],
    status: [this.data.vacancy?.status ?? ('open' as VacancyStatus)],
    description: [this.data.vacancy?.description ?? ''],
  });

  ngOnInit(): void {
    this.directory.active('branches').subscribe({ next: (list) => this.branches.set(list), error: () => undefined });
    this.directory.active('positions').subscribe({ next: (list) => this.positions.set(list), error: () => undefined });
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    const body: SaveVacancy = {
      title: v.title.trim(),
      branch_id: v.branch_id ?? undefined,
      position_id: v.position_id,
      status: v.status,
      description: v.description.trim() || null,
    };
    this.saving.set(true);
    this.error.set(null);
    this.data.store.save(this.data.vacancy?.id ?? null, body).subscribe({
      next: (saved) => this.ref.close(saved),
      error: (e: unknown) => {
        this.error.set(recruitingErrorKey(e));
        this.saving.set(false);
      },
    });
  }
}
