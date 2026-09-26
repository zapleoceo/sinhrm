import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, effect, inject, input, numberAttribute, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { KbArticle } from './knowledge.model';
import { KnowledgeService, knowledgeErrorKey } from './knowledge.service';

/**
 * Article view (/knowledge/:id). The HTML comes sanitized from the server (raw HTML escaped, unsafe links dropped);
 * Angular's [innerHTML] sanitizer is a second layer. "Was this helpful?" votes.
 */
@Component({
  selector: 'app-article-page',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (article(); as a) {
      <header class="page-head">
        <div>
          <p class="muted"><a routerLink="/knowledge">← {{ 'knowledge.title' | transloco }}</a></p>
          <h1>{{ a.title }}</h1>
          <p class="muted">
            {{ a.category?.emoji ?? '' }} {{ a.category?.name ?? '—' }} · {{ a.updated_at | date: 'dd.MM.yyyy' }} · v{{ a.version }}
            @if (a.status === 'draft') { · <strong>{{ 'knowledge.status.draft' | transloco }}</strong> }
          </p>
        </div>
        @if (a.can_edit) {
          <a mat-stroked-button [routerLink]="['/admin/knowledge', a.id]"><mat-icon>edit</mat-icon>{{ 'knowledge.edit' | transloco }}</a>
        }
      </header>
      <article class="panel body" [innerHTML]="a.html ?? ''"></article>
      <div class="vote">
        <span>{{ 'knowledge.helpful' | transloco }}</span>
        <button mat-stroked-button type="button" [class.on]="a.my_vote === true" (click)="vote(true)" [attr.aria-pressed]="a.my_vote === true">
          <mat-icon>thumb_up</mat-icon>{{ a.votes.helpful }}
        </button>
        <button mat-stroked-button type="button" [class.on]="a.my_vote === false" (click)="vote(false)" [attr.aria-pressed]="a.my_vote === false">
          <mat-icon>thumb_down</mat-icon>{{ a.votes.not_helpful }}
        </button>
      </div>
    }
  `,
  styles: `
    .body { padding: 1rem 1.25rem; line-height: 1.55; overflow-wrap: anywhere; }
    .vote { display: flex; gap: 0.5rem; align-items: center; margin-top: 1rem; flex-wrap: wrap; }
    .on { border-color: var(--mat-sys-primary); color: var(--mat-sys-primary); }
  `,
})
export class ArticlePage {
  readonly id = input.required({ transform: numberAttribute });

  private readonly api = inject(KnowledgeService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly article = signal<KbArticle | null>(null);
  protected readonly loading = signal(false);

  constructor() {
    effect(() => {
      const id = this.id();
      this.loading.set(true);
      this.api.get(id).subscribe({
        next: (a) => {
          this.article.set(a);
          this.loading.set(false);
        },
        error: (e: unknown) => {
          this.loading.set(false);
          this.toast(knowledgeErrorKey(e));
        },
      });
    });
  }

  protected vote(helpful: boolean): void {
    this.api.vote(this.id(), helpful).subscribe({
      next: (a) => {
        this.article.set(a);
        this.toast('knowledge.thanks');
      },
      error: (e: unknown) => this.toast(knowledgeErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
