import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, effect, inject, input, numberAttribute, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { DOCUMENT_FILE_ACCEPT, DOCUMENT_FILE_MAX_BYTES, fileSize } from '../documents/documents.model';
import { KbArticle } from '../knowledge/knowledge.model';
import { KnowledgeService } from '../knowledge/knowledge.service';
import { CASE_STATUSES, CaseStatus, DeskCase } from './desk.model';
import { DeskService, deskErrorKey } from './desk.service';
import { SlaBadge } from './sla-badge';

/** One case (/desk/cases/:id): the thread, replies, files; HR also sees internal notes, sets status, links articles. */
@Component({
  selector: 'app-case-page',
  imports: [
    DatePipe,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    RouterLink,
    TranslocoPipe,
    SlaBadge,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (item(); as c) {
      <header class="page-head">
        <div>
          <p class="muted"><a [routerLink]="c.can_manage ? '/desk/queue' : '/desk'">← {{ 'desk.back' | transloco }}</a></p>
          <h1>#{{ c.id }} {{ c.subject }}</h1>
          <p class="muted">{{ c.category.name }} · {{ c.employee.full_name }} · {{ c.created_at | date: 'dd.MM.yyyy HH:mm' }}</p>
        </div>
        <div class="meta">
          <app-sla-badge [c]="c" />
          @if (c.can_manage) {
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'desk.statusLabel' | transloco }}</mat-label>
              <mat-select [value]="c.status" (valueChange)="setStatus($event)">
                @for (s of statuses; track s) {
                  <mat-option [value]="s">{{ 'desk.status.' + s | transloco }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
            @if (c.assignee?.id !== me()) {
              <button mat-stroked-button type="button" (click)="assignToMe()"><mat-icon>person_add</mat-icon>{{ 'desk.assignToMe' | transloco }}</button>
            }
          } @else if (c.status !== 'closed') {
            <button mat-stroked-button type="button" (click)="setStatus('closed')">{{ 'desk.closeCase' | transloco }}</button>
          }
        </div>
      </header>
      <p class="muted">{{ 'desk.assignee' | transloco }}: {{ c.assignee?.name ?? ('desk.unassigned' | transloco) }}</p>

      <section class="panel thread">
        <article class="msg mine">
          <p class="who muted small">{{ c.employee.full_name }} · {{ c.created_at | date: 'dd.MM HH:mm' }}</p>
          <p class="text">{{ c.body }}</p>
        </article>
        @for (m of c.comments ?? []; track m.id) {
          <article class="msg" [class.mine]="m.mine" [class.internal]="m.internal">
            <p class="who muted small">
              {{ m.author?.name ?? '—' }} · {{ m.created_at | date: 'dd.MM HH:mm' }}
              @if (m.internal) { · <strong>{{ 'desk.internal' | transloco }}</strong> }
            </p>
            <p class="text">{{ m.body }}</p>
            @if (m.article) {
              <a class="article" [routerLink]="['/knowledge', m.article.id]"><mat-icon inline>menu_book</mat-icon>{{ m.article.title }}</a>
            }
          </article>
        }
      </section>

      @if (c.attachments?.length) {
        <ul class="files">
          @for (f of c.attachments; track f.id) {
            <li><a [href]="fileUrl(c.id, f.id)"><mat-icon inline>attach_file</mat-icon>{{ f.filename }}</a> <span class="muted small">{{ size(f.size) }}</span></li>
          }
        </ul>
      }

      @if (c.status !== 'closed') {
        <form class="panel reply" (submit)="$event.preventDefault(); send()">
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'desk.reply' | transloco }}</mat-label>
            <textarea matInput rows="3" maxlength="10000" [value]="text()" (input)="text.set(val($event))"></textarea>
          </mat-form-field>
          @if (c.can_manage) {
            <div class="row">
              <mat-checkbox [checked]="internal()" (change)="internal.set($event.checked)">{{ 'desk.internalNote' | transloco }}</mat-checkbox>
              <mat-form-field subscriptSizing="dynamic" class="grow">
                <mat-label>{{ 'desk.linkArticle' | transloco }}</mat-label>
                <mat-select [value]="articleId()" (valueChange)="articleId.set($event)" (openedChange)="$event && loadArticles()">
                  <mat-option [value]="null">—</mat-option>
                  @for (a of articles(); track a.id) {
                    <mat-option [value]="a.id">{{ a.title }}</mat-option>
                  }
                </mat-select>
              </mat-form-field>
            </div>
          }
          <div class="row">
            <input #file type="file" hidden [accept]="accept" (change)="upload($event)" />
            <button mat-stroked-button type="button" (click)="file.click()"><mat-icon>attach_file</mat-icon>{{ 'desk.attach' | transloco }}</button>
            <span class="grow"></span>
            <button mat-flat-button type="submit" [disabled]="saving() || text().trim() === ''">{{ 'desk.send' | transloco }}</button>
          </div>
        </form>
      }
    }
  `,
  styles: `
    .meta { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .thread { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem; margin-bottom: 1rem; }
    .msg { max-width: 48rem; padding: 0.5rem 0.75rem; border-radius: var(--app-radius); border: 1px solid var(--app-border); }
    .msg.mine { align-self: flex-start; }
    .msg:not(.mine) { align-self: flex-end; background: color-mix(in srgb, var(--mat-sys-primary) 6%, transparent); }
    .msg.internal { border-style: dashed; background: color-mix(in srgb, var(--app-warning) 8%, transparent); }
    .who { margin: 0 0 0.25rem; }
    .text { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
    .article { display: inline-flex; gap: 0.25rem; align-items: center; margin-top: 0.25rem; }
    .files { list-style: none; padding: 0; margin: 0 0 1rem; }
    .reply { display: flex; flex-direction: column; gap: 0.5rem; padding: 1rem; }
    .row { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .grow { flex: 1; }
    .small { font-size: 0.8rem; }
  `,
})
export class CasePage {
  readonly id = input.required({ transform: numberAttribute });

  private readonly api = inject(DeskService);
  private readonly kb = inject(KnowledgeService);
  private readonly auth = inject(AuthService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly statuses = CASE_STATUSES;
  protected readonly accept = DOCUMENT_FILE_ACCEPT;
  protected readonly item = signal<DeskCase | null>(null);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly text = signal('');
  protected readonly internal = signal(false);
  protected readonly articleId = signal<number | null>(null);
  protected readonly articles = signal<KbArticle[]>([]);

  constructor() {
    effect(() => this.load(this.id()));
  }

  protected me(): number | null {
    return this.auth.user()?.id ?? null;
  }

  protected val(event: Event): string {
    return (event.target as HTMLTextAreaElement).value;
  }

  protected size(bytes: number): string {
    return fileSize(bytes);
  }

  protected fileUrl(caseId: number, fileId: number): string {
    return this.api.attachmentUrl(caseId, fileId);
  }

  protected loadArticles(): void {
    if (this.articles().length === 0) {
      this.kb.search({}).subscribe({ next: (list) => this.articles.set(list.filter((a) => a.status === 'published')), error: () => this.articles.set([]) });
    }
  }

  protected setStatus(status: CaseStatus): void {
    this.apply(this.api.update(this.id(), { status }));
  }

  protected assignToMe(): void {
    const me = this.me();
    if (me !== null) {
      this.apply(this.api.update(this.id(), { assignee_id: me }));
    }
  }

  protected send(): void {
    const body = this.text().trim();
    if (body === '') {
      return;
    }
    this.saving.set(true);
    const hr = this.item()?.can_manage ?? false;
    this.apply(this.api.comment(this.id(), hr ? { body, internal: this.internal(), article_id: this.articleId() } : { body }), () => {
      this.text.set('');
      this.internal.set(false);
      this.articleId.set(null);
    });
  }

  protected upload(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) {
      return;
    }
    if (file.size > DOCUMENT_FILE_MAX_BYTES) {
      this.toast('desk.errors.file_too_large');
      return;
    }
    this.apply(this.api.attach(this.id(), file));
  }

  private load(id: number): void {
    this.loading.set(true);
    this.api.get(id).subscribe({
      next: (c) => {
        this.item.set(c);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.toast(deskErrorKey(e));
      },
    });
  }

  private apply(call: Observable<DeskCase>, done?: () => void): void {
    call.subscribe({
      next: (c) => {
        this.item.set(c);
        this.saving.set(false);
        done?.();
      },
      error: (e: unknown) => {
        this.saving.set(false);
        this.toast(deskErrorKey(e));
      },
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
