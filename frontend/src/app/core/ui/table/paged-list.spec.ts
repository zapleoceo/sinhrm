import { TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { PagedList } from './paged-list';

interface Page {
  data: number[];
  meta: { total: number; extra: string };
}

describe('PagedList', () => {
  const create = <T>(): PagedList<T> => TestBed.runInInjectionContext(() => new PagedList<T>());

  it('a page sets items and meta.total; loading is on while the request is in flight', () => {
    const list = create<number>();
    const answer = new Subject<Page>();
    const seen: string[] = [];
    list.load(answer, { next: (page) => seen.push(page.meta.extra) });
    expect(list.loading()).toBe(true);
    answer.next({ data: [1, 2], meta: { total: 40, extra: 'x' } });
    expect(list.items()).toEqual([1, 2]);
    expect(list.total()).toBe(40);
    expect(list.loading()).toBe(false);
    expect(list.failed()).toBe(false);
    expect(seen).toEqual(['x']);
  });

  it('a plain array: total is its length; map turns rows into items', () => {
    const list = create<string>();
    const answer = new Subject<number[]>();
    list.load(answer, { map: (n: number) => `#${n}` });
    answer.next([3, 4, 5]);
    expect(list.items()).toEqual(['#3', '#4', '#5']);
    expect(list.total()).toBe(3);
  });

  it('a newer load cancels the previous request: its late answer never lands', () => {
    const list = create<number>();
    const first = new Subject<number[]>();
    const second = new Subject<number[]>();
    list.load(first);
    list.load(second);
    expect(first.observed).toBe(false);
    first.next([1]);
    expect(list.items()).toEqual([]);
    second.next([2]);
    expect(list.items()).toEqual([2]);
  });

  it('an error sets failed, keeps the rows shown and calls the error hook; the next load clears failed', () => {
    const list = create<number>();
    const ok = new Subject<number[]>();
    list.load(ok);
    ok.next([7]);
    const broken = new Subject<number[]>();
    const errors: unknown[] = [];
    list.load(broken, { error: (e) => errors.push(e) });
    broken.error('boom');
    expect(list.failed()).toBe(true);
    expect(list.loading()).toBe(false);
    expect(list.items()).toEqual([7]);
    expect(errors).toEqual(['boom']);
    list.load(new Subject<number[]>());
    expect(list.failed()).toBe(false);
  });

  it('the request in flight is cancelled with its owner', () => {
    const list = create<number>();
    const answer = new Subject<number[]>();
    list.load(answer);
    TestBed.resetTestingModule();
    expect(answer.observed).toBe(false);
  });
});
