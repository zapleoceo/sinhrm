import { Injectable, inject, signal } from '@angular/core';
import { safeStorage } from '../../../core/storage/safe-storage';
import { LatestRequest } from '../../../core/ui/table/latest-request';
import { Employee, PeopleQuery } from '../people.model';
import { PeopleService } from '../people.service';
import { PEOPLE_PAGE_SIZE, sameQuery } from './people.query';

export type PeopleView = 'table' | 'cards';
const VIEW_KEY = 'sinhrm.people.view';
const DEFAULT_QUERY: PeopleQuery = { page: 1, perPage: PEOPLE_PAGE_SIZE };

/**
 * Directory page state (provided per page). The query comes from the URL (people.query.ts): the page calls
 * `apply()` on every URL change. A newer query cancels the request still in flight (LatestRequest), so an old
 * answer never lands over the new filters.
 */
@Injectable()
export class PeopleStore {
  private readonly api = inject(PeopleService);
  private readonly request = new LatestRequest();
  private loaded = false;

  readonly query = signal<PeopleQuery>(DEFAULT_QUERY);
  readonly items = signal<Employee[]>([]);
  readonly total = signal(0);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly view = signal<PeopleView>(safeStorage.get(VIEW_KEY) === 'cards' ? 'cards' : 'table');

  /** New query from the URL: loads unless it is the same as the one already shown. */
  apply(query: PeopleQuery): void {
    if (this.loaded && sameQuery(query, this.query())) {
      return;
    }
    this.query.set(query);
    this.load();
  }

  load(): void {
    this.loaded = true;
    this.loading.set(true);
    this.failed.set(false);
    this.request.run(this.api.list(this.query()), {
      next: (page) => {
        this.items.set(page.data);
        this.total.set(page.meta.total);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  setView(view: PeopleView): void {
    this.view.set(view);
    safeStorage.set(VIEW_KEY, view);
  }
}
