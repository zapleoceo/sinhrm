import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { catchError, of } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { DocPage, groupDocs, searchDocs, visibleDocs } from './docs.model';
import { DocsService } from './docs.service';
import { DocsOverview } from './docs-overview';

/** In-app documentation (/docs, /docs/:slug): plain-language parts of docs/, grouped by module, with search and role filter. */
@Component({
  selector: 'app-docs-page',
  imports: [DocsOverview, MatFormFieldModule, MatIconModule, MatInputModule, RouterLink, RouterLinkActive, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        @if (slug()) {
          <nav class="crumbs" [attr.aria-label]="'docs.map.crumbs' | transloco">
            <a routerLink="/docs" class="back">{{ 'docs.map.back' | transloco }}</a>
          </nav>
        }
        <h1>{{ 'docs.title' | transloco }}</h1>
        <p class="muted">{{ 'docs.subtitle' | transloco }}</p>
      </div>
    </header>
    <div class="layout">
      <aside class="toc panel">
        <mat-form-field subscriptSizing="dynamic" class="search">
          <mat-label>{{ 'docs.search' | transloco }}</mat-label>
          <mat-icon matPrefix>search</mat-icon>
          <input matInput type="search" maxlength="100" [value]="query()" (input)="query.set(val($event))" />
        </mat-form-field>
        @if (query().trim()) {
          <ul class="hits">
            @for (h of hits(); track h.doc.slug) {
              <li>
                <a [routerLink]="['/docs', h.doc.slug]" (click)="query.set('')">{{ h.doc.title }}</a>
                <span class="muted small">{{ h.snippet }}</span>
              </li>
            } @empty {
              <li class="muted">{{ 'docs.noResults' | transloco }}</li>
            }
          </ul>
        } @else {
          @for (g of groups(); track g.group) {
            <h2 class="group">{{ 'docs.groups.' + g.group | transloco }}</h2>
            <ul>
              @for (d of g.docs; track d.slug) {
                <li><a [routerLink]="['/docs', d.slug]" routerLinkActive="active">{{ d.title }}</a></li>
              }
            </ul>
          }
        }
      </aside>
      <section class="doc panel">
        @if (error()) {
          <p class="muted">{{ 'docs.loadError' | transloco }}</p>
        } @else if (current(); as d) {
          <article class="doc-body">
            <h1>{{ d.title }}</h1>
            <div [innerHTML]="d.html"></div>
          </article>
        } @else if (slug()) {
          <p class="muted">{{ 'docs.notFound' | transloco }}</p>
        } @else {
          <app-docs-overview [docs]="docs()" />
        }
      </section>
    </div>
  `,
  styles: `
    .crumbs { font-size: 0.85rem; margin-bottom: 0.25rem; }
    .back { color: var(--mat-sys-primary); text-decoration: none; }
    .layout { display: grid; grid-template-columns: minmax(14rem, 18rem) 1fr; gap: 1rem; align-items: start; }
    @media (max-width: 800px) { .layout { grid-template-columns: 1fr; } }
    .toc { padding: 0.75rem; }
    .search { width: 100%; }
    .toc ul { list-style: none; margin: 0; padding: 0; }
    .toc li { padding: 0.2rem 0; }
    .toc a { color: inherit; text-decoration: none; }
    .toc a.active { color: var(--mat-sys-primary); font-weight: 500; }
    .group { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; margin: 1rem 0 0.25rem; opacity: 0.7; }
    .hits li { display: flex; flex-direction: column; gap: 0.15rem; padding: 0.4rem 0; }
    .small { font-size: 0.8rem; }
    .doc { padding: 1rem 1.5rem; min-width: 0; }
    .doc-body { line-height: 1.55; overflow-wrap: anywhere; }
  `,
})
export class DocsPage {
  private readonly auth = inject(AuthService);
  /** Route param (withComponentInputBinding). */
  readonly slug = input<string>();
  protected readonly query = signal('');
  protected readonly error = signal(false);
  private readonly all = toSignal(
    inject(DocsService)
      .all()
      .pipe(
        catchError(() => {
          this.error.set(true);
          return of<DocPage[]>([]);
        }),
      ),
    { initialValue: [] as DocPage[] },
  );
  protected readonly docs = computed(() => visibleDocs(this.all(), this.auth.user()?.roles ?? []));
  protected readonly groups = computed(() => groupDocs(this.docs()));
  protected readonly hits = computed(() => searchDocs(this.docs(), this.query()));
  protected readonly current = computed(() => this.docs().find((d) => d.slug === this.slug()) ?? null);

  protected val(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }
}
