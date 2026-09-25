import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { DictionaryItem, DictionaryQuery, DictionaryType, ImportReport, SaveDictionaryItem } from './directory.model';
import { DirectoryService, directoryErrorKey } from './directory.service';

const DEFAULT_QUERY: DictionaryQuery = { page: 1, perPage: 50 };

/**
 * State of the directory page (provided per page): the active dictionary tab, its filters and rows,
 * and the Sintegrum import. Rename and enable/disable are optimistic with rollback; onError gets an i18n key.
 */
@Injectable()
export class DirectoryStore {
  private readonly api = inject(DirectoryService);

  readonly type = signal<DictionaryType>('branches');
  readonly query = signal<DictionaryQuery>(DEFAULT_QUERY);
  readonly items = signal<DictionaryItem[]>([]);
  readonly total = signal(0);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly pending = signal<ReadonlySet<number>>(new Set());

  readonly importing = signal(false);
  readonly importReport = signal<ImportReport | null>(null);
  /** i18n key of the last import failure. */
  readonly importError = signal<string | null>(null);

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    const type = this.type();
    this.api.list(type, this.query()).subscribe({
      next: (page) => {
        if (type !== this.type()) {
          return; // a stale answer after a tab switch
        }
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

  selectType(type: DictionaryType): void {
    this.type.set(type);
    this.items.set([]);
    this.query.set(DEFAULT_QUERY);
    this.load();
  }

  /** Filters reset the page to 1. */
  patchQuery(patch: Partial<DictionaryQuery>): void {
    this.query.update((q) => ({ ...q, ...patch, page: 1 }));
    this.load();
  }

  setPage(page: number, perPage: number): void {
    this.query.update((q) => ({ ...q, page, perPage }));
    this.load();
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

  runImport(): void {
    this.importing.set(true);
    this.importError.set(null);
    this.importReport.set(null);
    this.api.import().subscribe({
      next: (report) => {
        this.importReport.set(report);
        this.importing.set(false);
        this.load();
      },
      error: (e: unknown) => {
        this.importError.set(directoryErrorKey(e));
        this.importing.set(false);
      },
    });
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
    this.pending.update((set) => {
      const next = new Set(set);
      if (on) {
        next.add(id);
      } else {
        next.delete(id);
      }
      return next;
    });
  }
}
