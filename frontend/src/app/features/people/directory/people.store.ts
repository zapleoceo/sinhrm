import { Injectable, inject, signal } from '@angular/core';
import { safeStorage } from '../../../core/storage/safe-storage';
import { Employee, PeopleQuery } from '../people.model';
import { PeopleService } from '../people.service';

export type PeopleView = 'table' | 'cards';
const VIEW_KEY = 'sinhrm.people.view';
const DEFAULT_QUERY: PeopleQuery = { page: 1, perPage: 50 };

/** Directory page state (provided per page). Stale answers after a filter change are dropped. */
@Injectable()
export class PeopleStore {
  private readonly api = inject(PeopleService);
  private seq = 0;

  readonly query = signal<PeopleQuery>(DEFAULT_QUERY);
  readonly items = signal<Employee[]>([]);
  readonly total = signal(0);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly view = signal<PeopleView>(safeStorage.get(VIEW_KEY) === 'cards' ? 'cards' : 'table');

  load(): void {
    const seq = ++this.seq;
    this.loading.set(true);
    this.failed.set(false);
    this.api.list(this.query()).subscribe({
      next: (page) => {
        if (seq !== this.seq) {
          return;
        }
        this.items.set(page.data);
        this.total.set(page.meta.total);
        this.loading.set(false);
      },
      error: () => {
        if (seq === this.seq) {
          this.failed.set(true);
          this.loading.set(false);
        }
      },
    });
  }

  /** Filters reset the page to 1. */
  patchQuery(patch: Partial<PeopleQuery>): void {
    this.query.update((q) => ({ ...q, ...patch, page: 1 }));
    this.load();
  }

  setPage(page: number, perPage: number): void {
    this.query.update((q) => ({ ...q, page, perPage }));
    this.load();
  }

  setView(view: PeopleView): void {
    this.view.set(view);
    safeStorage.set(VIEW_KEY, view);
  }
}
