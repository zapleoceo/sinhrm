import { ChangeDetectionStrategy, Component, computed, effect, inject, input, signal } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { countNodes, expandedToDepth, filterTree, initials } from '../org-tree';
import { OrgNode } from '../people.model';
import { PeopleService } from '../people.service';

/**
 * Org chart as a collapsible tree (nested lists + CSS, no chart library). Search keeps matches with their managers.
 * ?root=<id> shows one subtree (from a profile); "My team" asks the API for the caller's own subtree.
 */
@Component({
  selector: 'app-org-chart-page',
  imports: [
    NgTemplateOutlet,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSlideToggleModule,
    RouterLink,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'people.orgChart.title' | transloco }}</h1>
        <p class="muted">{{ 'people.orgChart.subtitle' | transloco: { n: total() } }}</p>
      </div>
      <a mat-stroked-button routerLink="/people"><mat-icon>groups</mat-icon>{{ 'people.directory.title' | transloco }}</a>
    </header>

    <div class="filters">
      <mat-form-field class="grow" subscriptSizing="dynamic">
        <mat-label>{{ 'people.directory.search' | transloco }}</mat-label>
        <mat-icon matPrefix>search</mat-icon>
        <input matInput type="search" #t [value]="term()" (input)="term.set(t.value)" />
      </mat-form-field>
      <mat-slide-toggle [checked]="mine()" (change)="mine.set($event.checked)">{{ 'people.orgChart.myTeam' | transloco }}</mat-slide-toggle>
      <button mat-button type="button" (click)="expandAll()">{{ 'people.orgChart.expand' | transloco }}</button>
      <button mat-button type="button" (click)="collapseAll()">{{ 'people.orgChart.collapse' | transloco }}</button>
      @if (root()) {
        <a mat-button routerLink="/people/org-chart">{{ 'people.orgChart.all' | transloco }}</a>
      }
    </div>

    <section class="panel tree-panel" aria-live="polite">
      @if (loading()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (failed()) {
        <div class="state">
          <p>{{ 'people.loadError' | transloco }}</p>
          <button mat-stroked-button type="button" (click)="load()">{{ 'common.retry' | transloco }}</button>
        </div>
      } @else {
        <ul class="tree" role="tree">
          @for (n of view().nodes; track n.id) {
            <ng-container *ngTemplateOutlet="node; context: { $implicit: n }" />
          } @empty {
            <li class="muted state">{{ 'people.orgChart.empty' | transloco }}</li>
          }
        </ul>
      }
    </section>

    <ng-template #node let-n>
      <li role="treeitem" aria-selected="false" [attr.aria-expanded]="n.reports.length ? isOpen(n.id) : null">
        <div class="node">
          @if (n.reports.length) {
            <button mat-icon-button type="button" class="toggle" (click)="toggle(n.id)" [attr.aria-label]="'people.orgChart.toggle' | transloco">
              <mat-icon>{{ isOpen(n.id) ? 'expand_more' : 'chevron_right' }}</mat-icon>
            </button>
          } @else {
            <span class="toggle"></span>
          }
          <span class="avatar" aria-hidden="true">{{ initialsOf(n.full_name) }}</span>
          <a class="name" [routerLink]="['/people', n.id]">{{ n.full_name }}</a>
          <span class="muted">{{ n.position?.name }}@if (n.department) { · {{ n.department.name }}}</span>
          @if (n.reports_count) {
            <span class="count">{{ n.reports_count }}</span>
          }
        </div>
        @if (n.reports.length && isOpen(n.id)) {
          <ul role="group">
            @for (child of n.reports; track child.id) {
              <ng-container *ngTemplateOutlet="node; context: { $implicit: child }" />
            }
          </ul>
        }
      </li>
    </ng-template>
  `,
  styles: `
    .tree-panel { padding: 0.5rem 1rem; overflow-x: auto; }
    .tree, .tree ul { list-style: none; margin: 0; padding: 0; }
    .tree ul { margin-left: 1.25rem; padding-left: 1rem; border-left: 1px dashed var(--app-border); }
    .node { display: flex; align-items: center; gap: 0.5rem; min-height: 2.75rem; white-space: nowrap; }
    .toggle { width: 40px; flex: none; }
    .avatar {
      display: inline-grid; place-items: center; width: 2rem; height: 2rem; border-radius: 50%; font-size: 0.8rem; flex: none;
      background: var(--mat-sys-secondary-container); color: var(--mat-sys-on-secondary-container);
    }
    .name { color: inherit; font-weight: 500; text-decoration: none; }
    .name:hover { color: var(--mat-sys-primary); }
    .count {
      font-size: 0.75rem; padding: 0 0.4rem; border-radius: 999px; background: var(--mat-sys-surface-container-high);
    }
  `,
})
export class OrgChartPage {
  /** ?root=<employee id> (withComponentInputBinding). */
  readonly root = input<string | undefined>(undefined);

  private readonly api = inject(PeopleService);
  protected readonly nodes = signal<OrgNode[]>([]);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  protected readonly term = signal('');
  protected readonly mine = signal(false);
  protected readonly open = signal(new Set<number>());
  protected readonly view = computed(() => filterTree(this.nodes(), this.term()));
  protected readonly total = computed(() => countNodes(this.nodes()));

  constructor() {
    effect(() => {
      this.mine();
      this.root();
      this.load();
    });
    // Search results open the branches that lead to a match.
    effect(() => {
      const extra = this.view().open;
      if (extra.size > 0) {
        this.open.update((s) => new Set([...s, ...extra]));
      }
    });
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    const root = this.root();
    this.api.orgChart({ mine: this.mine(), root_id: root ? Number(root) : undefined }).subscribe({
      next: (nodes) => {
        this.nodes.set(nodes);
        this.open.set(expandedToDepth(nodes, 1));
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  protected isOpen(id: number): boolean {
    return this.open().has(id);
  }

  protected toggle(id: number): void {
    this.open.update((s) => {
      const next = new Set(s);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }

  protected expandAll(): void {
    this.open.set(expandedToDepth(this.nodes(), Number.MAX_SAFE_INTEGER));
  }

  protected collapseAll(): void {
    this.open.set(new Set());
  }

  protected initialsOf(name: string): string {
    return initials(name);
  }
}
