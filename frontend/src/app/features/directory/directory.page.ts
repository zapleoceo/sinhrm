import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTableModule } from '@angular/material/table';
import { MatTabsModule } from '@angular/material/tabs';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { DICTIONARY_TYPES, DIRECTORY_STATUSES, DictionaryItem, DirectoryStatus } from './directory.model';
import { directoryErrorKey } from './directory.service';
import { DirectoryStore } from './directory.store';

const SEARCH_DEBOUNCE_MS = 300;

/**
 * Admin → Dictionaries (superadmin/admin): a tab per dictionary with search, status filter, inline rename,
 * disable/enable and adding an item. Superadmin also runs the Sintegrum import.
 */
@Component({
  selector: 'app-directory-page',
  imports: [
    MatButtonModule,
    MatChipsModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatPaginatorModule,
    MatProgressBarModule,
    MatSelectModule,
    MatTableModule,
    MatTabsModule,
    MatTooltipModule,
    RouterLink,
    TranslocoPipe,
  ],
  providers: [DirectoryStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './directory.page.html',
  styleUrl: './directory.page.scss',
})
export class DirectoryPage implements OnInit {
  protected readonly store = inject(DirectoryStore);
  private readonly auth = inject(AuthService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly search$ = new Subject<string>();

  protected readonly types = DICTIONARY_TYPES;
  protected readonly statuses = DIRECTORY_STATUSES;
  protected readonly canImport = computed(() => this.auth.user()?.roles.includes('superadmin') ?? false);
  protected readonly columns = computed(() =>
    this.store.type() === 'branches' ? ['name', 'city', 'status', 'actions'] : ['name', 'status', 'actions'],
  );
  protected readonly editingId = signal<number | null>(null);
  protected readonly newName = signal('');
  protected readonly creating = signal(false);

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(SEARCH_DEBOUNCE_MS), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.store.patchQuery({ q: q.trim() || undefined }));
    this.store.load();
  }

  protected onTab(index: number): void {
    this.editingId.set(null);
    this.store.selectType(this.types[index]);
  }

  protected onSearch(value: string): void {
    this.search$.next(value);
  }

  protected onStatusFilter(status: DirectoryStatus | undefined): void {
    this.store.patchQuery({ status });
  }

  protected onPage(e: PageEvent): void {
    this.store.setPage(e.pageIndex + 1, e.pageSize);
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

  protected runImport(): void {
    this.store.runImport();
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
