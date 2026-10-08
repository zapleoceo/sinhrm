import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTableModule } from '@angular/material/table';
import { MatTabsModule } from '@angular/material/tabs';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslocoPipe } from '@jsverse/transloco';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { ColumnFilter, FilterValue, TableSort, idToFilter } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { DICTIONARY_TYPES, DIRECTORY_STATUSES, DictionaryItem } from './directory.model';
import { directoryViewFromParams } from './directory.query';
import { DirectoryService, directoryErrorKey } from './directory.service';
import { DirectoryStore } from './directory.store';
import { NotifyService } from '../../core/ui/notify.service';

/** API order without ?sort (by name, A→Z): the name column carries the arrow. */
const DEFAULT_SORT: TableSort = { key: 'name', dir: 'asc' };

/**
 * Admin → Dictionaries (superadmin/admin): a tab per dictionary, inline rename, disable/enable and adding an item.
 * Column headers sort and filter (core/ui/table): name, city (branches), status; tab and state live in the URL.
 */
@Component({
  selector: 'app-directory-page',
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatPaginatorModule,
    MatProgressBarModule,
    MatTableModule,
    MatTabsModule,
    MatTooltipModule,
    TableSortDirective,
    ColumnHeader,
    TranslocoPipe,
  ],
  providers: [DirectoryStore, TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './directory.page.html',
  styleUrl: './directory.page.scss',
})
export class DirectoryPage implements OnInit {
  protected readonly store = inject(DirectoryStore);
  private readonly notify = inject(NotifyService);
  private readonly url = inject(TableUrlState);
  /** Select value of an optional id of the query (none → «all»). */
  protected readonly idValue = idToFilter;
  private readonly directory = inject(DirectoryService);
  private readonly cities = signal<DictionaryItem[]>([]);

  protected readonly types = DICTIONARY_TYPES;
  protected readonly textFilter: ColumnFilter = { type: 'text' };
  protected readonly statusFilter: ColumnFilter = {
    type: 'select',
    options: DIRECTORY_STATUSES.map((s) => ({ value: s, label: `directory.statuses.${s}`, i18n: true })),
  };
  protected readonly cityFilter = computed<ColumnFilter>(() => ({
    type: 'select',
    options: this.cities().map((c) => ({ value: String(c.id), label: c.name })),
  }));
  /** Shown sort: the URL one, or the API default. */
  protected readonly sort = computed<TableSort>(() => {
    const q = this.store.query();
    return q.sort ? { key: q.sort, dir: q.dir ?? 'asc' } : DEFAULT_SORT;
  });
  protected readonly columns = computed(() =>
    this.store.type() === 'branches' ? ['name', 'city', 'status', 'actions'] : ['name', 'status', 'actions'],
  );
  protected readonly editingId = signal<number | null>(null);
  protected readonly newName = signal('');
  protected readonly creating = signal(false);

  ngOnInit(): void {
    this.url.watch(directoryViewFromParams, (view) => this.store.apply(view));
    this.directory.active('cities').subscribe({ next: (list) => this.cities.set(list), error: () => this.cities.set([]) });
  }

  /** Another tab starts clean: its own filters and sort (city exists on branches only). */
  protected onTab(index: number): void {
    this.editingId.set(null);
    this.url.update({ tab: this.types[index], q: null, status: null, city_id: null, sort: null, dir: null });
  }

  protected onPage(e: PageEvent): void {
    this.url.setPage(e);
  }

  protected onSort(sort: TableSort | null): void {
    this.url.setSort(sort);
  }

  /** Header filters: text / chosen value; cleared → removed from the URL (and the page goes back to 1). */
  protected setFilter(name: 'q' | 'status' | 'city_id', value: FilterValue): void {
    this.url.setFilter(name, value);
  }

  protected startEdit(item: DictionaryItem): void {
    this.editingId.set(item.id);
  }

  protected cancelEdit(): void {
    this.editingId.set(null);
  }

  protected saveEdit(item: DictionaryItem, name: string): void {
    this.editingId.set(null);
    this.store.rename(item, name, (key) => this.toast(key));
  }

  protected toggle(item: DictionaryItem): void {
    this.store.toggleStatus(item, (key) => this.toast(key));
  }

  protected add(): void {
    const name = this.newName().trim();
    if (name === '') {
      return;
    }
    this.creating.set(true);
    this.store.create(name).subscribe({
      next: () => {
        this.newName.set('');
        this.creating.set(false);
        this.toast('directory.created');
      },
      error: (e: unknown) => {
        this.creating.set(false);
        this.toast(directoryErrorKey(e));
      },
    });
  }

  private toast(key: string): void {
    this.notify.show(key, { duration: 3000 });
  }
}
