import { Injectable, computed, inject, signal } from '@angular/core';
import { Task, TaskQuery } from '../scripts.model';
import { ScriptsService, scriptsErrorKey } from '../scripts.service';

/** Tasks of one widget (dashboard: mine due today; candidate card: open tasks of the candidate). */
@Injectable()
export class TasksStore {
  private readonly api = inject(ScriptsService);
  private seq = 0;

  readonly query = signal<TaskQuery>({});
  readonly items = signal<Task[]>([]);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly pending = signal<ReadonlySet<number>>(new Set());
  readonly overdue = computed(() => this.items().filter((t) => t.is_overdue && !t.done_at).length);

  load(query: TaskQuery): void {
    const seq = ++this.seq;
    this.query.set(query);
    this.loading.set(true);
    this.failed.set(false);
    this.api.tasks(query).subscribe({
      next: (list) => {
        if (seq === this.seq) {
          this.items.set(list);
          this.loading.set(false);
        }
      },
      error: () => {
        if (seq === this.seq) {
          this.failed.set(true);
          this.loading.set(false);
        }
      },
    });
  }

  /** Optimistic: the row is marked at once and restored when the API refuses. */
  toggleDone(task: Task, onError: (key: string) => void): void {
    const done = task.done_at === null;
    const optimistic: Task = { ...task, done_at: done ? new Date().toISOString() : null };
    this.replace(optimistic);
    this.pending.update((s) => new Set(s).add(task.id));
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
    this.pending.update((s) => {
      const next = new Set(s);
      next.delete(id);
      return next;
    });
  }
}
