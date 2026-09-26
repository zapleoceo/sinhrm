import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslocoPipe } from '@jsverse/transloco';
import { TASK_SOURCES, TaskDue, TaskQuery, TaskSource } from '../scripts/scripts.model';
import { TasksWidget } from '../scripts/tasks/tasks-widget';

export type SourceFilter = TaskSource | 'all';
export type DueFilter = TaskDue | 'all';

/** Query of the "Мої задачі" page from its filters. */
export function myTasksQuery(source: SourceFilter, due: DueFilter, done: boolean): TaskQuery {
  const query: TaskQuery = { mine: true };
  if (source !== 'all') {
    query.source = source;
  }
  if (due !== 'all') {
    query.due = due;
  }
  if (done) {
    query.done = true;
  }
  return query;
}

/** "Мої задачі" (/tasks): every task assigned to me — recruiting, workflow steps, documents — with filters. */
@Component({
  selector: 'app-my-tasks-page',
  imports: [MatButtonToggleModule, MatSlideToggleModule, TranslocoPipe, TasksWidget],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'tasks.title' | transloco }}</h1>
        <p class="muted">{{ 'tasks.subtitle' | transloco }}</p>
      </div>
    </header>
    <div class="filters">
      <mat-button-toggle-group [value]="source()" (change)="source.set($event.value)" [attr.aria-label]="'tasks.source' | transloco" hideSingleSelectionIndicator>
        <mat-button-toggle value="all">{{ 'common.all' | transloco }}</mat-button-toggle>
        @for (s of sources; track s) {
          <mat-button-toggle [value]="s">{{ 'tasks.sources.' + s | transloco }}</mat-button-toggle>
        }
      </mat-button-toggle-group>
      <mat-button-toggle-group [value]="due()" (change)="due.set($event.value)" [attr.aria-label]="'tasks.due' | transloco" hideSingleSelectionIndicator>
        <mat-button-toggle value="all">{{ 'tasks.dueAll' | transloco }}</mat-button-toggle>
        <mat-button-toggle value="today">{{ 'tasks.dueToday' | transloco }}</mat-button-toggle>
        <mat-button-toggle value="overdue">{{ 'tasks.dueOverdue' | transloco }}</mat-button-toggle>
      </mat-button-toggle-group>
      <mat-slide-toggle [checked]="done()" (change)="done.set($event.checked)">{{ 'tasks.showDone' | transloco }}</mat-slide-toggle>
    </div>
    <div class="panel list">
      <app-tasks-widget [query]="query()" />
    </div>
  `,
  styles: `
    .list { padding: 0 0.75rem; }
  `,
})
export class MyTasksPage {
  protected readonly sources = TASK_SOURCES;
  protected readonly source = signal<SourceFilter>('all');
  protected readonly due = signal<DueFilter>('all');
  protected readonly done = signal(false);
  protected readonly query = computed(() => myTasksQuery(this.source(), this.due(), this.done()));
}
