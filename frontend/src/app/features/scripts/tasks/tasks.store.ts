import { Injectable, computed, inject, signal } from '@angular/core';
import { Task, TaskQuery } from '../scripts.model';
import { ScriptsService, scriptsErrorKey } from '../scripts.service';
import { PagedList } from '../../../core/ui/table/paged-list';
import { withMember } from '../../../core/ui/with-member';

/** Tasks of one widget (dashboard: mine due today; candidate card: open tasks of the candidate). */
@Injectable()
export class TasksStore {
  private readonly api = inject(ScriptsService);
  /** A newer query cancels the request still in flight. */
  private readonly list = new PagedList<Task>();

  readonly query = signal<TaskQuery>({});
  readonly items = this.list.items;
  readonly loading = this.list.loading;
  readonly failed = this.list.failed;
  readonly pending = signal<ReadonlySet<number>>(new Set());
  readonly overdue = computed(() => this.items().filter((t) => t.is_overdue && !t.done_at).length);

  load(query: TaskQuery): void {
    this.query.set(query);
    this.list.load(this.api.tasks(query));
  }

  /** Optimistic: the row is marked at once and restored when the API refuses. */
  toggleDone(task: Task, onError: (key: string) => void): void {
    const done = task.done_at === null;
    const optimistic: Task = { ...task, done_at: done ? new Date().toISOString() : null };
    this.replace(optimistic);
    this.pending.update((s) => withMember(s, task.id, true));
    this.api.setTaskDone(task.id, done).subscribe({
      next: (saved) => {
        this.replace(saved);
        this.release(task.id);
      },
      error: (e: unknown) => {
        this.replace(task);
        this.release(task.id);
        onError(scriptsErrorKey(e));
      },
    });
  }

  private replace(task: Task): void {
    this.items.update((list) => list.map((t) => (t.id === task.id ? task : t)));
  }

  private release(id: number): void {
    this.pending.update((s) => withMember(s, id, false));
  }
}
