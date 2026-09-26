import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { CreateDocument, DocumentTemplate, HrDocument } from '../documents.model';
import { DocumentsService, documentsErrorKey, unknownVariables } from '../documents.service';

export type CreateMode = 'template' | 'manual';

export interface CreateFormValue {
  mode: CreateMode;
  template_id: number | null;
  title: string;
  category: string;
  content_md: string;
}

/** Request body for POST /api/documents from the dialog form; null when required fields are missing. */
export function createBody(employeeId: number, v: CreateFormValue): CreateDocument | null {
  const title = v.title.trim();
  const category = v.category.trim();
  if (v.mode === 'template') {
    if (v.template_id === null) {
      return null;
    }
    return { employee_id: employeeId, template_id: v.template_id, ...(title ? { title } : {}), ...(category ? { category } : {}) };
  }
  if (title === '') {
    return null;
  }
  return { employee_id: employeeId, title, ...(category ? { category } : {}), content_md: v.content_md };
}

/** Admin: a new document for an employee — generated from a template (title override) or written in markdown. */
@Component({
  selector: 'app-document-create-dialog',
  imports: [ReactiveFormsModule, MatButtonModule, MatButtonToggleModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'documents.create.title' | transloco }}</h2>
    <form [formGroup]="form" (ngSubmit)="submit()">
      <mat-dialog-content>
        <mat-button-toggle-group formControlName="mode" hideSingleSelectionIndicator>
          <mat-button-toggle value="template">{{ 'documents.create.fromTemplate' | transloco }}</mat-button-toggle>
          <mat-button-toggle value="manual">{{ 'documents.create.manual' | transloco }}</mat-button-toggle>
        </mat-button-toggle-group>
        @if (form.controls.mode.value === 'template') {
          <mat-form-field>
            <mat-label>{{ 'documents.fields.template' | transloco }}</mat-label>
            <mat-select formControlName="template_id">
              @for (t of templates(); track t.id) {
                <mat-option [value]="t.id">{{ t.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
        }
        <mat-form-field>
          <mat-label>{{ (form.controls.mode.value === 'template' ? 'documents.create.titleOverride' : 'documents.fields.title') | transloco }}</mat-label>
          <input matInput formControlName="title" maxlength="200" />
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'documents.fields.category' | transloco }}</mat-label>
          <input matInput formControlName="category" maxlength="100" />
        </mat-form-field>
        @if (form.controls.mode.value === 'manual') {
          <mat-form-field>
            <mat-label>{{ 'documents.fields.content' | transloco }}</mat-label>
            <textarea matInput formControlName="content_md" rows="10"></textarea>
            <mat-hint>{{ 'documents.templates.markdownHint' | transloco }}</mat-hint>
          </mat-form-field>
        }
        @if (error(); as key) {
          <p class="error" role="alert">
            {{ key | transloco }}
            @if (unknown().length) { ({{ unknown().join(', ') }}) }
          </p>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
        <button mat-flat-button type="submit" [disabled]="saving()">{{ 'documents.create.submit' | transloco }}</button>
      </mat-dialog-actions>
    </form>
  `,
  styles: `
    mat-dialog-content { display: flex; flex-direction: column; gap: 0.5rem; min-width: min(34rem, 85vw); }
    mat-button-toggle-group { align-self: flex-start; margin-bottom: 0.75rem; }
    .error { color: var(--app-danger); margin: 0; }
  `,
})
export class DocumentCreateDialog implements OnInit {
  private readonly employeeId = inject<number>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<DocumentCreateDialog, HrDocument>>(MatDialogRef);
  private readonly api = inject(DocumentsService);

  protected readonly templates = signal<DocumentTemplate[]>([]);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly unknown = signal<string[]>([]);
  protected readonly form = inject(NonNullableFormBuilder).group({
    mode: ['template' as CreateMode],
    template_id: [null as number | null],
    title: [''],
    category: [''],
    content_md: [''],
  });

  ngOnInit(): void {
    this.api.templates().subscribe({ next: (list) => this.templates.set(list.filter((t) => !t.archived)), error: () => this.templates.set([]) });
  }

  protected submit(): void {
    const body = createBody(this.employeeId, this.form.getRawValue());
    if (body === null) {
      this.error.set('documents.create.required');
      return;
    }
    this.saving.set(true);
    this.error.set(null);
    this.unknown.set([]);
    this.api.create(body).subscribe({
      next: (doc) => this.ref.close(doc),
      error: (e: unknown) => {
        this.error.set(documentsErrorKey(e));
        this.unknown.set(unknownVariables(e));
        this.saving.set(false);
      },
    });
  }
}
