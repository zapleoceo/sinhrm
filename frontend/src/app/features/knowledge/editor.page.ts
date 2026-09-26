import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { USER_ROLES, UserRole } from '../../core/auth/auth.model';
import { DictionaryItem } from '../directory/directory.model';
import { DirectoryService } from '../directory/directory.service';
import { ArticleStatus, Audience, KbCategory, KbVersion, SaveArticle, parseTags } from './knowledge.model';
import { KnowledgeService, knowledgeErrorKey } from './knowledge.service';

type AudienceType = Audience['type'];

/** Builds the audience payload from the editor controls. */
export function audienceOf(type: AudienceType, branchIds: number[], roles: UserRole[]): Audience {
  if (type === 'branches') {
    return { type, ids: branchIds };
  }
  if (type === 'roles') {
    return { type, roles };
  }
  return { type: 'all' };
}

/** Article editor for admins (/admin/knowledge/new, /admin/knowledge/:id): Markdown, audience, status, history. */
@Component({
  selector: 'app-knowledge-editor-page',
  imports: [DatePipe, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <p class="muted"><a routerLink="/knowledge">← {{ 'knowledge.title' | transloco }}</a></p>
        <h1>{{ (articleId() === null ? 'knowledge.editor.new' : 'knowledge.editor.edit') | transloco }}</h1>
      </div>
    </header>
    <form class="panel form" (submit)="$event.preventDefault(); save()">
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'knowledge.editor.title' | transloco }}</mat-label>
        <input matInput maxlength="200" required [value]="title()" (input)="title.set(val($event))" />
      </mat-form-field>
      <div class="row">
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'knowledge.editor.category' | transloco }}</mat-label>
          <mat-select [value]="categoryId()" (valueChange)="categoryId.set($event)">
            <mat-option [value]="null">—</mat-option>
            @for (c of categories(); track c.id) {
              <mat-option [value]="c.id">{{ c.emoji ?? '' }} {{ c.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic" class="grow">
          <mat-label>{{ 'knowledge.editor.tags' | transloco }}</mat-label>
          <input matInput [value]="tags()" (input)="tags.set(val($event))" />
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'knowledge.editor.status' | transloco }}</mat-label>
          <mat-select [value]="status()" (valueChange)="status.set($event)">
            <mat-option value="draft">{{ 'knowledge.status.draft' | transloco }}</mat-option>
            <mat-option value="published">{{ 'knowledge.status.published' | transloco }}</mat-option>
          </mat-select>
        </mat-form-field>
      </div>
      <div class="row">
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'knowledge.editor.audience' | transloco }}</mat-label>
          <mat-select [value]="audienceType()" (valueChange)="audienceType.set($event)">
            <mat-option value="all">{{ 'knowledge.audience.all' | transloco }}</mat-option>
            <mat-option value="branches">{{ 'knowledge.audience.branches' | transloco }}</mat-option>
            <mat-option value="roles">{{ 'knowledge.audience.roles' | transloco }}</mat-option>
          </mat-select>
        </mat-form-field>
        @if (audienceType() === 'branches') {
          <mat-form-field subscriptSizing="dynamic" class="grow">
            <mat-label>{{ 'knowledge.audience.branches' | transloco }}</mat-label>
            <mat-select multiple [value]="branchIds()" (valueChange)="branchIds.set($event)">
              @for (b of branches(); track b.id) {
                <mat-option [value]="b.id">{{ b.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
        }
        @if (audienceType() === 'roles') {
          <mat-form-field subscriptSizing="dynamic" class="grow">
            <mat-label>{{ 'knowledge.audience.roles' | transloco }}</mat-label>
            <mat-select multiple [value]="roles()" (valueChange)="roles.set($event)">
              @for (r of allRoles; track r) {
                <mat-option [value]="r">{{ 'roles.' + r | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
        }
      </div>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'knowledge.editor.body' | transloco }}</mat-label>
        <textarea matInput rows="16" required [value]="body()" (input)="body.set(val($event))"></textarea>
        <mat-hint>{{ 'knowledge.editor.markdownHint' | transloco }}</mat-hint>
      </mat-form-field>
      <div class="actions">
        @if (articleId() !== null) {
          <a mat-button [routerLink]="['/knowledge', articleId()]">{{ 'knowledge.editor.view' | transloco }}</a>
        }
        <button mat-flat-button type="submit" [disabled]="saving() || title().trim() === '' || body().trim() === ''">{{ 'common.save' | transloco }}</button>
      </div>
    </form>

    @if (versions().length) {
      <section class="panel history">
        <h2>{{ 'knowledge.editor.history' | transloco }}</h2>
        <ul>
          @for (v of versions(); track v.version) {
            <li>
              <strong>v{{ v.version }}</strong> · {{ v.created_at | date: 'dd.MM.yyyy HH:mm' }} · {{ v.title }}
              <button mat-button type="button" (click)="restore(v)">{{ 'knowledge.editor.restore' | transloco }}</button>
            </li>
          }
        </ul>
      </section>
    }
  `,
  styles: `
    .form { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem; }
    .row { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; }
    .grow { flex: 1 1 14rem; }
    .actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
    textarea { font-family: ui-monospace, monospace; }
    .history { margin-top: var(--app-gap); padding: 1rem; }
    .history h2 { font: var(--mat-sys-title-medium); margin: 0 0 0.5rem; }
    .history ul { list-style: none; padding: 0; margin: 0; }
  `,
})
export class KnowledgeEditorPage implements OnInit {
  /** Route param: "new" or an article id. */
  readonly id = input<string>('new');

  private readonly api = inject(KnowledgeService);
  private readonly directory = inject(DirectoryService);
  private readonly router = inject(Router);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly allRoles = USER_ROLES;
  protected readonly articleId = signal<number | null>(null);
  protected readonly categories = signal<KbCategory[]>([]);
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly versions = signal<KbVersion[]>([]);
  protected readonly title = signal('');
  protected readonly body = signal('');
  protected readonly tags = signal('');
  protected readonly categoryId = signal<number | null>(null);
  protected readonly status = signal<ArticleStatus>('draft');
  protected readonly audienceType = signal<AudienceType>('all');
  protected readonly branchIds = signal<number[]>([]);
  protected readonly roles = signal<UserRole[]>([]);
  protected readonly saving = signal(false);

  constructor() {
    effect(() => {
      const raw = this.id();
      const id = raw === 'new' ? null : Number(raw);
      this.articleId.set(id !== null && Number.isFinite(id) ? id : null);
      if (id !== null && Number.isFinite(id)) {
        this.load(id);
      }
    });
  }

  ngOnInit(): void {
    this.api.categories().subscribe({ next: (list) => this.categories.set(list), error: () => this.categories.set([]) });
    this.directory.active('branches').subscribe({ next: (list) => this.branches.set(list), error: () => this.branches.set([]) });
  }

  protected val(event: Event): string {
    return (event.target as HTMLInputElement | HTMLTextAreaElement).value;
  }

  protected restore(v: KbVersion): void {
    this.title.set(v.title);
    this.body.set(v.body_md);
  }

  protected save(): void {
    const body: SaveArticle = {
      title: this.title().trim(),
      body_md: this.body(),
      category_id: this.categoryId(),
      tags: parseTags(this.tags()),
      status: this.status(),
      audience: audienceOf(this.audienceType(), this.branchIds(), this.roles()),
    };
    this.saving.set(true);
    this.api.save(this.articleId(), body).subscribe({
      next: (a) => {
        this.saving.set(false);
        this.snack.open(this.i18n.translate('knowledge.saved'), undefined, { duration: 3000 });
        if (this.articleId() === null) {
          void this.router.navigate(['/admin/knowledge', a.id]);
        } else {
          this.load(a.id);
        }
      },
      error: (e: unknown) => {
        this.saving.set(false);
        this.snack.open(this.i18n.translate(knowledgeErrorKey(e)), undefined, { duration: 4000 });
      },
    });
  }

  private load(id: number): void {
    this.api.get(id).subscribe({
      next: (a) => {
        this.title.set(a.title);
        this.body.set(a.body_md ?? '');
        this.tags.set(a.tags.join(', '));
        this.categoryId.set(a.category?.id ?? null);
        this.status.set(a.status);
        const audience = a.audience ?? { type: 'all' };
        this.audienceType.set(audience.type);
        this.branchIds.set(audience.type === 'branches' ? audience.ids : []);
        this.roles.set(audience.type === 'roles' ? audience.roles : []);
      },
      error: (e: unknown) => this.snack.open(this.i18n.translate(knowledgeErrorKey(e)), undefined, { duration: 4000 }),
    });
    this.api.versions(id).subscribe({ next: (list) => this.versions.set(list), error: () => this.versions.set([]) });
  }
}
