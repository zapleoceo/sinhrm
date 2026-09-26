import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Subject, catchError, debounceTime, distinctUntilChanged, of, switchMap } from 'rxjs';
import { DOCUMENT_VARIABLES, DocumentTemplate, TemplatePreview, insertVariable } from '../documents.model';
import { DocumentsService, documentsErrorKey, unknownVariables } from '../documents.service';

interface Draft {
  id: number | null;
  name: string;
  category: string;
  body: string;
  archived: boolean;
}

const EMPTY_DRAFT: Draft = { id: null, name: '', category: '', body: '', archived: false };

/** Admin → Шаблони документів: list + editor with variable chips and a live (server-rendered, sanitized) preview. */
@Component({
  selector: 'app-document-templates-page',
  imports: [
    DatePipe,
    MatButtonModule,
    MatChipsModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSlideToggleModule,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'documents.templates.title' | transloco }}</h1>
        <p class="muted">{{ 'documents.templates.subtitle' | transloco }}</p>
      </div>
      <div class="actions">
        <mat-slide-toggle [checked]="withArchived()" (change)="toggleArchived($event.checked)">{{ 'documents.templates.showArchived' | transloco }}</mat-slide-toggle>
        <button mat-flat-button type="button" (click)="edit(null)"><mat-icon>add</mat-icon>{{ 'documents.templates.create' | transloco }}</button>
      </div>
    </header>

    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="layout">
      <ul class="list panel">
        @for (t of templates(); track t.id) {
          <li [class.active]="draft().id === t.id" [class.archived]="t.archived">
            <button type="button" (click)="edit(t)">
              <strong>{{ t.name }}</strong>
              <span class="muted small">{{ t.category ?? '—' }} · {{ t.updated_at | date: 'dd.MM.yyyy' }}@if (t.archived) { · {{ 'documents.templates.archived' | transloco }}}</span>
            </button>
          </li>
        } @empty {
          @if (!loading()) {
            <li class="muted empty">{{ 'documents.templates.empty' | transloco }}</li>
          }
        }
      </ul>

      @if (editing()) {
        @let d = draft();
        <section class="editor">
          <div class="row">
            <mat-form-field class="grow" subscriptSizing="dynamic">
              <mat-label>{{ 'documents.fields.name' | transloco }}</mat-label>
              <input matInput [value]="d.name" (input)="patch({ name: val($event) })" maxlength="200" required />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'documents.fields.category' | transloco }}</mat-label>
              <input matInput [value]="d.category" (input)="patch({ category: val($event) })" maxlength="100" />
            </mat-form-field>
          </div>
          <mat-chip-set [attr.aria-label]="'documents.templates.variables' | transloco">
            @for (v of variables; track v) {
              <mat-chip (click)="insert(area, v)">{{ '{' + v + '}' }}</mat-chip>
            }
          </mat-chip-set>
          <div class="two">
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'documents.fields.body' | transloco }}</mat-label>
              <textarea #area matInput rows="18" [value]="d.body" (input)="setBody(val($event))"></textarea>
              <mat-hint>{{ 'documents.templates.markdownHint' | transloco }}</mat-hint>
            </mat-form-field>
            <div class="preview">
              <p class="muted small">{{ 'documents.templates.preview' | transloco }}</p>
              @if (preview(); as p) {
                @if (p.unknown.length) {
                  <p class="error">{{ 'documents.templates.unknown' | transloco: { list: p.unknown.join(', ') } }}</p>
                }
                <div class="doc" [innerHTML]="p.html"></div>
              }
            </div>
          </div>
          @if (unknown().length) {
            <p class="error" role="alert">{{ 'documents.templates.unknown' | transloco: { list: unknown().join(', ') } }}</p>
          }
          <div class="row">
            <button mat-flat-button type="button" (click)="save()" [disabled]="saving() || d.name.trim() === '' || d.body.trim() === ''">
              <mat-icon>save</mat-icon>{{ 'documents.templates.save' | transloco }}
            </button>
            @if (d.id !== null) {
              <button mat-button type="button" (click)="archive(!d.archived)">
                <mat-icon>{{ d.archived ? 'unarchive' : 'archive' }}</mat-icon>{{ (d.archived ? 'documents.templates.restore' : 'documents.templates.archive') | transloco }}
              </button>
            }
            <button mat-button type="button" (click)="editing.set(false)">{{ 'common.cancel' | transloco }}</button>
          </div>
        </section>
      } @else {
        <p class="muted state">{{ 'documents.templates.pick' | transloco }}</p>
      }
    </div>
  `,
  styles: `
    .actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .layout { display: grid; grid-template-columns: minmax(14rem, 20rem) minmax(0, 1fr); gap: 1rem; align-items: start; }
    .list { list-style: none; margin: 0; padding: 0; }
    .list li { border-bottom: 1px solid var(--app-border); }
    .list li:last-child { border-bottom: 0; }
    .list li.active { background: var(--mat-sys-secondary-container); }
    .list li.archived { opacity: 0.6; }
    .list button {
      display: flex; flex-direction: column; gap: 0.15rem; width: 100%; padding: 0.5rem 0.75rem;
      border: 0; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer;
    }
    .empty { padding: 0.75rem; }
    .editor { display: flex; flex-direction: column; gap: 0.75rem; }
    .row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
    .grow { flex: 1 1 16rem; }
    .two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; }
    .two mat-form-field { width: 100%; }
    .preview { padding: 0.75rem; border-radius: var(--app-radius); background: var(--mat-sys-surface-container-low); overflow-wrap: anywhere; }
    .error { color: var(--app-danger); font-size: 0.85rem; margin: 0; }
    .small { font-size: 0.8rem; }
    mat-chip { cursor: pointer; }
    @media (max-width: 900px) {
      .layout, .two { grid-template-columns: 1fr; }
    }
  `,
})
export class DocumentTemplatesPage implements OnInit {
  private readonly api = inject(DocumentsService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly bodyChanges = new Subject<string>();

  protected readonly variables = DOCUMENT_VARIABLES;
  protected readonly templates = signal<DocumentTemplate[]>([]);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly withArchived = signal(false);
  protected readonly editing = signal(false);
  protected readonly draft = signal<Draft>(EMPTY_DRAFT);
  protected readonly preview = signal<TemplatePreview | null>(null);
  protected readonly unknown = signal<string[]>([]);

  constructor() {
    this.bodyChanges
      .pipe(
        debounceTime(400),
        distinctUntilChanged(),
        switchMap((body) => (body.trim() === '' ? of(null) : this.api.preview(body).pipe(catchError(() => of(null))))),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((p) => this.preview.set(p));
  }

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.api.templates(this.withArchived()).subscribe({
      next: (list) => {
        this.templates.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.toast(documentsErrorKey(e));
      },
    });
  }

  protected toggleArchived(on: boolean): void {
    this.withArchived.set(on);
    this.load();
  }

  protected edit(t: DocumentTemplate | null): void {
    this.draft.set(t === null ? EMPTY_DRAFT : { id: t.id, name: t.name, category: t.category ?? '', body: t.body, archived: t.archived });
    this.unknown.set([]);
    this.preview.set(null);
    this.editing.set(true);
    this.bodyChanges.next(this.draft().body);
  }

  protected val(event: Event): string {
    return (event.target as HTMLInputElement | HTMLTextAreaElement).value;
  }

  protected patch(p: Partial<Draft>): void {
    this.draft.update((d) => ({ ...d, ...p }));
  }

  protected setBody(body: string): void {
    this.patch({ body });
    this.bodyChanges.next(body);
  }

  /** Inserts {Variable} at the caret of the body textarea. */
  protected insert(area: HTMLTextAreaElement, name: string): void {
    const start = area.selectionStart ?? area.value.length;
    const end = area.selectionEnd ?? start;
    const next = insertVariable(area.value, start, end, name);
    this.setBody(next.text);
    queueMicrotask(() => {
      area.focus();
      area.setSelectionRange(next.caret, next.caret);
    });
  }

  protected save(): void {
    const d = this.draft();
    const body = { name: d.name.trim(), body: d.body, category: d.category.trim() || null };
    this.saving.set(true);
    this.unknown.set([]);
    const call = d.id === null ? this.api.createTemplate(body) : this.api.updateTemplate(d.id, body);
    call.subscribe({
      next: (saved) => {
        this.saving.set(false);
        this.upsert(saved);
        this.patch({ id: saved.id });
        this.toast('documents.templates.saved');
      },
      error: (e: unknown) => {
        this.saving.set(false);
        this.unknown.set(unknownVariables(e));
        this.toast(documentsErrorKey(e));
      },
    });
  }

  protected archive(archived: boolean): void {
    const id = this.draft().id;
    if (id === null) {
      return;
    }
    this.api.updateTemplate(id, { archived }).subscribe({
      next: (saved) => {
        this.patch({ archived: saved.archived });
        this.upsert(saved);
      },
      error: (e: unknown) => this.toast(documentsErrorKey(e)),
    });
  }

  private upsert(t: DocumentTemplate): void {
    this.templates.update((list) => (list.some((x) => x.id === t.id) ? list.map((x) => (x.id === t.id ? t : x)) : [t, ...list]));
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
