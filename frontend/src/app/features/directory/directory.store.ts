import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { PagedList } from '../../core/ui/table/paged-list';
import { sameQuery } from '../../core/ui/table/table-state';
import { DictionaryItem, DictionaryQuery, DictionaryType, SaveDictionaryItem } from './directory.model';
import { DIRECTORY_PAGE_SIZE, DirectoryView } from './directory.query';
import { DirectoryService, directoryErrorKey } from './directory.service';
import { withMember } from '../../core/ui/with-member';

const DEFAULT_QUERY: DictionaryQuery = { page: 1, perPage: DIRECTORY_PAGE_SIZE };

/**
 * State of the directory page (provided per page): the active dictionary tab, its filters and rows. Tab and query
 * come from the URL (directory.query.ts) through `apply()`; a newer one cancels the request still in flight.
 * Rename and enable/disable are optimistic with rollback; onError gets an i18n key.
 */
@Injectable()
export class DirectoryStore {
  private readonly api = inject(DirectoryService);
  private readonly list = new PagedList<DictionaryItem>();
  private loaded = false;

  readonly type = signal<DictionaryType>('branches');
  readonly query = signal<DictionaryQuery>(DEFAULT_QUERY);
  readonly items = this.list.items;
  readonly total = this.list.total;
  readonly loading = this.list.loading;
  readonly failed = this.list.failed;
  readonly pending = signal<ReadonlySet<number>>(new Set());

  /** New tab / query from the URL: loads unless it is the one already shown; another tab starts with no rows. */
  apply(view: DirectoryView): void {
    if (this.loaded && view.type === this.type() && sameQuery(view.query, this.query())) {
      return;
    }
    if (view.type !== this.type()) {
      this.items.set([]);
    }
    this.type.set(view.type);
    this.query.set(view.query);
    this.load();
  }

  load(): void {
    this.loaded = true;
    this.list.load(this.api.list(this.type(), this.query()));
  }

  rename(item: DictionaryItem, name: string, onError: (key: string) => void): void {
    const trimmed = name.trim();
    if (trimmed === '' || trimmed === item.name) {
      return;
    }
    this.optimistic(item, { name: trimmed }, onError);
  }

  toggleStatus(item: DictionaryItem, onError: (key: string) => void): void {
    this.optimistic(item, { status: item.status === 'active' ? 'disabled' : 'active' }, onError);
  }

  /** Not optimistic: the new row needs its id; the list is reloaded to keep the server order. */
  create(name: string): Observable<DictionaryItem> {
    return this.api.create(this.type(), { name: name.trim() }).pipe(tap(() => this.load()));
  }

  private optimistic(item: DictionaryItem, body: SaveDictionaryItem, onError: (key: string) => void): void {
    const type = this.type();
    const previous = item;
    this.replace({ ...item, ...body } as DictionaryItem);
    this.setPending(item.id, true);
    this.api.update(type, item.id, body).subscribe({
      next: (saved) => {
        this.replace(saved);
        this.setPending(item.id, false);
      },
      error: (e: unknown) => {
        this.replace(previous);
        this.setPending(item.id, false);
        onError(directoryErrorKey(e));
      },
    });
  }

  private replace(item: DictionaryItem): void {
    this.items.update((list) => list.map((i) => (i.id === item.id ? item : i)));
  }

  private setPending(id: number, on: boolean): void {
    this.pending.update((set) => withMember(set, id, on));
  }
}
