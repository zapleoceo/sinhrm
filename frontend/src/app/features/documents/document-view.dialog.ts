import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { HrDocument, fileSize } from './documents.model';
import { DocumentsService, documentsErrorKey } from './documents.service';

/**
 * A document: rendered content (sanitized html from the API), attached file, signatures; the employee can
 * acknowledge ("Ознайомлений") or reject with a reason. Closes with the updated document when something changed.
 */
@Component({
  selector: 'app-document-view-dialog',
  imports: [DatePipe, MatButtonModule, MatDialogModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ doc()?.title ?? ('documents.view.title' | transloco) }}</h2>
    <mat-dialog-content>
      @if (loading()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (doc(); as d) {
        <p class="muted">
          {{ d.employee.full_name }} · {{ 'documents.status.' + d.status | transloco }}
          @if (d.category) { · {{ d.category }} }
          @if (d.sent_at) { · {{ 'documents.view.sentAt' | transloco }} {{ d.sent_at | date: 'dd.MM.yyyy' }} }
        </p>
        @if (d.reject_reason) {
          <p class="error">{{ 'documents.view.rejectReason' | transloco }}: {{ d.reject_reason }}</p>
        }
        @if (d.html) {
          <div class="doc" [innerHTML]="d.html"></div>
        }
        @if (d.file; as f) {
          <p>
            <a mat-stroked-button [href]="fileUrl(d)" download>
              <mat-icon>download</mat-icon>{{ f.filename }} ({{ size(f.size) }})
            </a>
          </p>
        }
        @if (d.signatures.length) {
          <h3>{{ 'documents.view.signatures' | transloco }}</h3>
          <ul class="signatures">
            @for (s of d.signatures; track $index) {
              <li>{{ 'documents.signature.' + s.method | transloco }} · {{ s.signed_at | date: 'dd.MM.yyyy HH:mm' }}</li>
            }
          </ul>
        }
        @if (rejecting()) {
          <mat-form-field class="full">
            <mat-label>{{ 'documents.view.reason' | transloco }}</mat-label>
            <textarea matInput rows="3" maxlength="1000" [value]="reason()" (input)="reason.set(val($event))" cdkFocusInitial></textarea>
          </mat-form-field>
        }
      }
      @if (error(); as key) {
        <p class="error" role="alert">{{ key | transloco }}</p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="close()">{{ 'common.close' | transloco }}</button>
      @if (doc()?.can_acknowledge) {
        @if (rejecting()) {
          <button mat-stroked-button type="button" class="danger" [disabled]="busy()" (click)="reject()">{{ 'documents.actions.confirmReject' | transloco }}</button>
        } @else {
          <button mat-button type="button" [disabled]="busy()" (click)="rejecting.set(true)">{{ 'documents.actions.reject' | transloco }}</button>
          <button mat-flat-button type="button" [disabled]="busy()" (click)="acknowledge()">
            <mat-icon>task_alt</mat-icon>{{ 'documents.actions.acknowledge' | transloco }}
          </button>
        }
      }
    </mat-dialog-actions>
  `,
  styles: `
    mat-dialog-content { min-width: min(40rem, 85vw); }
    .doc { padding: 0.75rem 1rem; border: 1px solid var(--app-border); border-radius: var(--app-radius); overflow-wrap: anywhere; }
    .signatures { margin: 0; padding-left: 1.25rem; }
    .error { color: var(--app-danger); }
    .danger { color: var(--app-danger); }
    .full { width: 100%; margin-top: 0.75rem; }
    h3 { font: var(--mat-sys-title-small); margin: 1rem 0 0.25rem; }
  `,
})
export class DocumentViewDialog implements OnInit {
  private readonly id = inject<number>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<DocumentViewDialog, HrDocument>>(MatDialogRef);
  private readonly api = inject(DocumentsService);

  protected readonly doc = signal<HrDocument | null>(null);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly rejecting = signal(false);
  protected readonly reason = signal('');
  private changed = false;

  ngOnInit(): void {
    this.api.get(this.id).subscribe({
      next: (d) => {
        this.doc.set(d);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.error.set(documentsErrorKey(e));
        this.loading.set(false);
      },
    });
  }

  protected fileUrl(d: HrDocument): string {
    return this.api.fileUrl(d.id);
  }

  protected size(bytes: number): string {
    return fileSize(bytes);
  }

  protected val(event: Event): string {
    return (event.target as HTMLTextAreaElement).value;
  }

  protected acknowledge(): void {
    this.run(this.api.acknowledge(this.id));
  }

  protected reject(): void {
    this.run(this.api.reject(this.id, this.reason().trim() || null));
  }

  protected close(): void {
    this.ref.close(this.changed ? (this.doc() ?? undefined) : undefined);
  }

  private run(call: ReturnType<DocumentsService['acknowledge']>): void {
    this.busy.set(true);
    this.error.set(null);
    call.subscribe({
      next: (d) => {
        this.changed = true;
        this.ref.close(d);
      },
      error: (e: unknown) => {
        this.error.set(documentsErrorKey(e));
        this.busy.set(false);
      },
    });
  }
}
