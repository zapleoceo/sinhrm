import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { DocumentViewDialog } from '../document-view.dialog';
import { DOCUMENT_FILE_ACCEPT, DOCUMENT_FILE_MAX_BYTES, HrDocument, isEditable } from '../documents.model';
import { DocumentsService, documentsErrorKey } from '../documents.service';
import { DocumentCreateDialog } from './document-create.dialog';

/** Profile tab "Документи": the employee's documents; admins create, attach files, send and archive. */
@Component({
  selector: 'app-employee-documents-tab',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatMenuModule, MatProgressBarModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (canManage()) {
      <div class="filters">
        <button mat-flat-button type="button" (click)="create()"><mat-icon>note_add</mat-icon>{{ 'documents.create.title' | transloco }}</button>
      </div>
    }
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <input #file type="file" hidden [accept]="accept" (change)="upload($event)" />
    <ul class="docs">
      @for (d of items(); track d.id) {
        <li [attr.data-status]="d.status">
          <div class="main">
            <button type="button" class="link" (click)="view(d)">{{ d.title }}</button>
            <span class="muted small">
              {{ d.category ?? '—' }} · {{ d.created_at | date: 'dd.MM.yyyy' }}
              @if (d.file) { · <mat-icon inline>attach_file</mat-icon>{{ d.file.filename }} }
            </span>
          </div>
          <span class="status">{{ 'documents.status.' + d.status | transloco }}</span>
          @if (d.can_manage) {
            <button mat-icon-button type="button" [matMenuTriggerFor]="menu" [attr.aria-label]="'documents.actions.more' | transloco"><mat-icon>more_vert</mat-icon></button>
            <mat-menu #menu="matMenu">
              <button mat-menu-item type="button" (click)="view(d)"><mat-icon>visibility</mat-icon>{{ 'documents.actions.view' | transloco }}</button>
              @if (editable(d)) {
                <button mat-menu-item type="button" (click)="pickFile(d, file)"><mat-icon>upload_file</mat-icon>{{ 'documents.actions.upload' | transloco }}</button>
                <button mat-menu-item type="button" (click)="send(d)"><mat-icon>send</mat-icon>{{ 'documents.actions.send' | transloco }}</button>
              }
              @if (d.status !== 'archived') {
                <button mat-menu-item type="button" (click)="archive(d)"><mat-icon>archive</mat-icon>{{ 'documents.actions.archive' | transloco }}</button>
              }
            </mat-menu>
          }
        </li>
      } @empty {
        @if (!loading()) {
          <li class="muted">{{ 'documents.empty' | transloco }}</li>
        }
      }
    </ul>
  `,
  styles: `
    :host { display: block; padding: 1rem 0; }
    .docs { list-style: none; margin: 0; padding: 0; }
    .docs li { display: flex; gap: 1rem; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--app-border); }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .link { border: 0; background: none; padding: 0; color: inherit; font: inherit; font-weight: 500; text-align: left; cursor: pointer; text-decoration: underline; }
    .docs li[data-status='sent'] .status { color: var(--app-warning); }
    .docs li[data-status='signed'] .status { color: var(--app-success); }
    .docs li[data-status='rejected'] .status { color: var(--app-danger); }
    .docs li[data-status='archived'] { opacity: 0.6; }
    .small { font-size: 0.8rem; }
  `,
})
export class EmployeeDocumentsTab {
  readonly employeeId = input.required<number>();
  /** Admin actions (create); per-document actions follow can_manage from the API. */
  readonly canManage = input(false);

  private readonly api = inject(DocumentsService);
  private readonly dialog = inject(MatDialog);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly accept = DOCUMENT_FILE_ACCEPT;
  protected readonly items = signal<HrDocument[]>([]);
  protected readonly loading = signal(false);
  private uploadTarget: HrDocument | null = null;

  constructor() {
    effect(() => this.load(this.employeeId()));
  }

  protected editable(d: HrDocument): boolean {
    return isEditable(d);
  }

  protected create(): void {
    this.dialog
      .open<DocumentCreateDialog, number, HrDocument>(DocumentCreateDialog, { data: this.employeeId() })
      .afterClosed()
      .subscribe((doc) => doc && this.items.update((list) => [doc, ...list]));
  }

  protected view(d: HrDocument): void {
    this.dialog
      .open<DocumentViewDialog, number, HrDocument>(DocumentViewDialog, { data: d.id })
      .afterClosed()
      .subscribe((doc) => doc && this.replace(doc));
  }

  protected pickFile(d: HrDocument, input: HTMLInputElement): void {
    this.uploadTarget = d;
    input.value = '';
    input.click();
  }

  protected upload(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    const target = this.uploadTarget;
    if (!file || target === null) {
      return;
    }
    if (file.size > DOCUMENT_FILE_MAX_BYTES) {
      this.toast('documents.errors.file_too_large');
      return;
    }
    this.apply(this.api.upload(target.id, file), 'documents.uploaded');
  }

  protected send(d: HrDocument): void {
    this.apply(this.api.send(d.id), 'documents.sent');
  }

  protected archive(d: HrDocument): void {
    this.apply(this.api.update(d.id, { status: 'archived' }), 'documents.archived');
  }

  private load(employeeId: number): void {
    this.loading.set(true);
    this.api.list({ employee_id: employeeId }).subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.toast(documentsErrorKey(e));
      },
    });
  }

  private apply(call: Observable<HrDocument>, okKey: string): void {
    call.subscribe({
      next: (doc) => {
        this.replace(doc);
        this.toast(okKey);
      },
      error: (e: unknown) => this.toast(documentsErrorKey(e)),
    });
  }

  private replace(doc: HrDocument): void {
    this.items.update((list) => list.map((d) => (d.id === doc.id ? doc : d)));
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
