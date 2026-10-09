import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, Subject, of, throwError } from 'rxjs';
import { DictionaryItem, DictionaryPage, DictionaryQuery, DictionaryType } from './directory.model';
import { DirectoryService } from './directory.service';
import { DirectoryStore } from './directory.store';

const item = (id: number, name: string, status: DictionaryItem['status'] = 'active'): DictionaryItem => ({
  id,
  name,
  status,
  created_at: null,
  updated_at: null,
});

const page = (data: DictionaryItem[]): DictionaryPage => ({
  data,
  meta: { current_page: 1, per_page: 50, total: data.length, last_page: 1 },
});

class FakeApi {
  calls: { type: DictionaryType; query: DictionaryQuery }[] = [];
  list$: Observable<DictionaryPage> = of(page([]));
  update$ = new Subject<DictionaryItem>();
  list = (type: DictionaryType, query: DictionaryQuery) => {
    this.calls.push({ type, query });
    return this.list$;
  };
  update = () => this.update$;
  create = (_type: DictionaryType, body: { name?: string }) => of(item(99, body.name ?? ''));
}

describe('DirectoryStore', () => {
  let store: DirectoryStore;
  let api: FakeApi;

  beforeEach(() => {
    api = new FakeApi();
    TestBed.configureTestingModule({ providers: [DirectoryStore, { provide: DirectoryService, useValue: api }] });
    store = TestBed.inject(DirectoryStore);
  });

  it('loads the tab and query from the URL; another tab starts with no rows; the same view is not reloaded', () => {
    api.list$ = of(page([item(1, 'A')]));
    store.apply({ type: 'branches', query: { q: 'x', page: 1, perPage: 50 } });
    expect(store.items().length).toBe(1);
    expect(store.total()).toBe(1);
    expect(api.calls.at(-1)).toEqual({ type: 'branches', query: { q: 'x', page: 1, perPage: 50 } });

    store.apply({ type: 'branches', query: { q: 'x', page: 1, perPage: 50 } });
    expect(api.calls).toHaveLength(1);

    api.list$ = new Subject<DictionaryPage>();
    store.apply({ type: 'positions', query: { page: 1, perPage: 50, sort: 'status', dir: 'desc' } });
    expect(store.items()).toEqual([]);
    expect(store.type()).toBe('positions');
    expect(api.calls.at(-1)).toEqual({ type: 'positions', query: { page: 1, perPage: 50, sort: 'status', dir: 'desc' } });
  });

  it('a newer view cancels the request still in flight', () => {
    const first = new Subject<DictionaryPage>();
    api.list$ = first;
    store.apply({ type: 'branches', query: { page: 1, perPage: 50 } });
    expect(first.observed).toBe(true);
    api.list$ = of(page([item(2, 'B')]));
    store.apply({ type: 'cities', query: { page: 1, perPage: 50 } });
    expect(first.observed).toBe(false);
    expect(store.items().map((i) => i.name)).toEqual(['B']);
  });

  it('flags a load error; the rows already shown stay, and the next load clears the flag', () => {
    api.list$ = of(page([item(1, 'A')]));
    store.load();
    api.list$ = throwError(() => new Error('down'));
    store.load();
    expect(store.failed()).toBe(true);
    expect(store.loading()).toBe(false);
    expect(store.items().map((i) => i.name)).toEqual(['A']);
    api.list$ = new Subject<DictionaryPage>();
    store.load();
    expect(store.failed()).toBe(false);
    expect(store.loading()).toBe(true);
  });

  it('renames optimistically and keeps the server answer', () => {
    api.list$ = of(page([item(1, 'Old')]));
    store.load();

    store.rename(store.items()[0], '  New  ', () => undefined);
    expect(store.items()[0].name).toBe('New');
    expect(store.pending().has(1)).toBe(true);

    api.update$.next(item(1, 'New'));
    expect(store.pending().size).toBe(0);
  });

  it('ignores an empty or unchanged name', () => {
    api.list$ = of(page([item(1, 'Same')]));
    store.load();
    store.rename(store.items()[0], 'Same', () => undefined);
    store.rename(store.items()[0], '   ', () => undefined);
    expect(store.pending().size).toBe(0);
  });

  it('rolls a status toggle back and reports the error key', () => {
    api.list$ = of(page([item(1, 'A')]));
    store.load();
    let reported = '';

    store.toggleStatus(store.items()[0], (key) => (reported = key));
    expect(store.items()[0].status).toBe('disabled');
    api.update$.error(new HttpErrorResponse({ status: 500 }));

    expect(store.items()[0].status).toBe('active');
    expect(reported).toBe('directory.errors.generic');
  });

  it('create reloads the list', () => {
    const before = api.calls.length;
    store.create(' New ').subscribe();
    expect(api.calls.length).toBe(before + 1);
  });

});
