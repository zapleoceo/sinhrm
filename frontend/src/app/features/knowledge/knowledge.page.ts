import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AuthService } from '../../core/auth/auth.service';
import { ArticleQuery, KbArticle, KbCategory, helpfulPercent } from './knowledge.model';
import { KnowledgeService } from './knowledge.service';

/** Knowledge base (/knowledge): categories, search, tags; editors also see drafts and create articles. */
@Component({
  selector: 'app-knowledge-page',
  imports: [DatePipe, MatButtonModule, MatChipsModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'knowledge.title' | transloco }}</h1>
        <p class="muted">{{ 'knowledge.subtitle' | transloco }}</p>
      </div>
      @if (editor()) {
        <a mat-flat-button routerLink="/admin/knowledge/new"><mat-icon>add</mat-icon>{{ 'knowledge.newArticle' | transloco }}</a>
      }
    </header>
    <div class="filters">
      <mat-form-field subscriptSizing="dynamic" class="search">
        <mat-label>{{ 'knowledge.search' | transloco }}</mat-label>
        <mat-icon matPrefix>search</mat-icon>
        <input matInput type="search" maxlength="100" (input)="typed.next(val($event))" />
      </mat-form-field>
    </div>
    <mat-chip-listbox [attr.aria-label]="'knowledge.categories' | transloco" (change)="setCategory($event.value)">
      <mat-chip-option [value]="null" [selected]="query().category_id === undefined">{{ 'common.all' | transloco }}</mat-chip-option>
      @for (c of categories(); track c.id) {
        <mat-chip-option [value]="c.id" [selected]="query().category_id === c.id">{{ c.emoji ?? '' }} {{ c.name }}</mat-chip-option>
      }
    </mat-chip-listbox>
    @if (query().tag) {
      <p class="muted">#{{ query().tag }} <button mat-button type="button" (click)="setTag(undefined)">{{ 'knowledge.clearTag' | transloco }}</button></p>
    }
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <ul class="list panel">
      @for (a of items(); track a.id) {
        <li>
          <a [routerLink]="['/knowledge', a.id]" class="title">{{ a.category?.emoji ?? '📄' }} {{ a.title }}</a>
          <span class="muted small">
            {{ a.category?.name ?? '—' }} · {{ a.updated_at | date: 'dd.MM.yyyy' }}
            @if (a.status === 'draft') { · <strong>{{ 'knowledge.status.draft' | transloco }}</strong> }
            @if (helpful(a); as p) { · 👍 {{ p }}% }
          </span>
          <span class="tags">
            @for (t of a.tags; track t) {
              <button type="button" class="tag" (click)="setTag(t)">#{{ t }}</button>
            }
          </span>
        </li>
      } @empty {
        @if (!loading()) {
          <li class="muted">{{ 'knowledge.empty' | transloco }}</li>
        }
      }
    </ul>
  `,
  styles: `
    .search { min-width: min(28rem, 100%); }
    mat-chip-listbox { display: block; margin: 0.5rem 0 1rem; }
    .list { list-style: none; margin: 0; padding: 0; }
    .list li { display: flex; flex-direction: column; gap: 0.15rem; padding: 0.6rem 0.75rem; border-bottom: 1px solid var(--app-border); }
    .list li:last-child { border-bottom: 0; }
    .title { font-weight: 500; color: inherit; }
    .tags { display: flex; gap: 0.25rem; flex-wrap: wrap; }
    .tag { border: 0; background: none; color: var(--mat-sys-primary); cursor: pointer; padding: 0; font: inherit; font-size: 0.8rem; }
    .small { font-size: 0.8rem; }
  `,
})
export class KnowledgePage implements OnInit {
  private readonly api = inject(KnowledgeService);
  private readonly auth = inject(AuthService);
  protected readonly typed = new Subject<string>();
  protected readonly items = signal<KbArticle[]>([]);
  protected readonly categories = signal<KbCategory[]>([]);
  protected readonly query = signal<ArticleQuery>({});
  protected readonly loading = signal(false);
  /** Admins (HR) write articles; the API enforces it (gate knowledge-manage). */
  protected readonly editor = computed(() => this.auth.hasRole('superadmin') || this.auth.hasRole('admin'));

  constructor() {
    this.typed.pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed()).subscribe((q) => {
      this.query.update((cur) => ({ ...cur, q: q.trim() === '' ? undefined : q.trim() }));
      this.load();
    });
  }

  ngOnInit(): void {
    this.api.categories().subscribe({ next: (list) => this.categories.set(list), error: () => this.categories.set([]) });
    this.load();
  }

  protected val(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected helpful(a: KbArticle): number | null {
    return helpfulPercent(a.votes);
  }

  protected setCategory(id: number | null | undefined): void {
    this.query.update((q) => ({ ...q, category_id: id ?? undefined }));
    this.load();
  }

  protected setTag(tag: string | undefined): void {
    this.query.update((q) => ({ ...q, tag }));
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.api.search(this.query()).subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: () => {
        this.items.set([]);
        this.loading.set(false);
      },
    });
  }
}
