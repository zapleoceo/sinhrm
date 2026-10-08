import { Injectable, inject, signal } from '@angular/core';
import { safeStorage } from '../../../core/storage/safe-storage';
import { PagedList } from '../../../core/ui/table/paged-list';
import { Employee, PeopleQuery } from '../people.model';
import { PeopleService } from '../people.service';
import { PEOPLE_PAGE_SIZE, sameQuery } from './people.query';

export type PeopleView = 'table' | 'cards';
const VIEW_KEY = 'sinhrm.people.view';
const DEFAULT_QUERY: PeopleQuery = { page: 1, perPage: PEOPLE_PAGE_SIZE };

/**
 * Directory page state (provided per page). The query comes from the URL (people.query.ts): the page calls
 * `apply()` on every URL change. A newer query cancels the request still in flight (PagedList), so an old
 * answer never lands over the new filters.
 */
@Injectable()
export class PeopleStore {
  private readonly api = inject(PeopleService);
  private readonly list = new PagedList<Employee>();
  private loaded = false;

  readonly query = signal<PeopleQuery>(DEFAULT_QUERY);
  readonly items = this.list.items;
  readonly total = this.list.total;
  readonly loading = this.list.loading;
  readonly failed = this.list.failed;
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
    this.list.load(this.api.list(this.query()));
  }

  setView(view: PeopleView): void {
    this.view.set(view);
    safeStorage.set(VIEW_KEY, view);
  }
}
