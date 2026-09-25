import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, OnInit, computed, inject, output, signal, viewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Subject, catchError, debounceTime, distinctUntilChanged, forkJoin, map, of, switchMap } from 'rxjs';
import { RecruitingService } from '../recruiting.service';

export interface PaletteItem {
  kind: 'nav' | 'candidate' | 'vacancy';
  icon: string;
  /** i18n key for nav items, plain text otherwise. */
  label: string;
  hint?: string;
  route: (string | number)[];
}

/** Static navigation entries shown with an empty query and filtered by it. */
export const NAV_ITEMS: readonly PaletteItem[] = [
  { kind: 'nav', icon: 'person_search', label: 'shell.nav.candidates', route: ['/candidates'] },
  { kind: 'nav', icon: 'work', label: 'shell.nav.vacancies', route: ['/vacancies'] },
  { kind: 'nav', icon: 'inbox', label: 'shell.nav.inbox', route: ['/inbox'] },
  { kind: 'nav', icon: 'bar_chart', label: 'shell.nav.reports', route: ['/reports'] },
];

/** Keeps the highlighted index inside [0, length). */
export function wrapIndex(index: number, length: number): number {
  return length === 0 ? 0 : (index + length) % length;
}

/**
 * Cmd/Ctrl+K palette (rendered in a CDK overlay by CommandPaletteService): jump to a candidate or vacancy by name /
 * phone / e-mail, or to a section. ↑/↓ move, Enter opens, Esc closes.
 */
@Component({
  selector: 'app-command-palette',
  imports: [MatIconModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="palette" role="dialog" aria-modal="true" [attr.aria-label]="'palette.title' | transloco">
      <input
        #input
        class="query"
        type="search"
        role="combobox"
        aria-controls="palette-list"
        [attr.aria-expanded]="items().length > 0"
        [attr.aria-activedescendant]="items().length ? 'palette-item-' + active() : null"
        [placeholder]="'palette.placeholder' | transloco"
        (input)="query$.next(input.value)"
        (keydown)="onKey($event)"
      />
      <ul id="palette-list" role="listbox" class="list">
        @for (item of items(); track item.kind + item.route.join('/'); let i = $index) {
          <li
            [id]="'palette-item-' + i"
            role="option"
            [attr.aria-selected]="i === active()"
            [class.active]="i === active()"
            (mouseenter)="active.set(i)"
            (mousedown)="$event.preventDefault(); choose(item)"
          >
            <mat-icon>{{ item.icon }}</mat-icon>
            <span class="label">{{ item.kind === 'nav' ? (item.label | transloco) : item.label }}</span>
            @if (item.hint) {
              <span class="hint">{{ item.hint }}</span>
            }
          </li>
        } @empty {
          <li class="empty">{{ 'palette.nothing' | transloco }}</li>
        }
      </ul>
      <p class="keys">{{ 'palette.keys' | transloco }}</p>
    </div>
  `,
  styles: `
    .palette {
      width: min(36rem, 92vw); background: var(--mat-sys-surface-container-high); color: var(--mat-sys-on-surface);
      border-radius: var(--app-radius); box-shadow: var(--mat-sys-level3); overflow: hidden;
    }
    .query {
      width: 100%; box-sizing: border-box; border: 0; border-bottom: 1px solid var(--app-border); outline: none;
      padding: 1rem 1.25rem; font: var(--mat-sys-body-large); background: transparent; color: inherit;
    }
    .list { list-style: none; margin: 0; padding: 0.25rem; max-height: 50vh; overflow-y: auto; }
    li { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 8px; cursor: pointer; }
    li.active { background: var(--mat-sys-secondary-container); color: var(--mat-sys-on-secondary-container); }
    .label { flex: 1; }
    .hint, .keys, .empty { color: var(--app-muted); font-size: 0.8rem; }
    .keys { margin: 0; padding: 0.5rem 1rem; border-top: 1px solid var(--app-border); }
  `,
})
export class CommandPalette implements OnInit {
  readonly chosen = output<PaletteItem>();
  readonly closed = output<void>();

  private readonly api = inject(RecruitingService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly i18n = inject(TranslocoService);
  private readonly input = viewChild.required<ElementRef<HTMLInputElement>>('input');

  protected readonly query$ = new Subject<string>();
  protected readonly query = signal('');
  protected readonly found = signal<PaletteItem[]>([]);
  protected readonly active = signal(0);
  protected readonly items = computed(() => {
    const q = this.query().trim().toLowerCase();
    const nav = NAV_ITEMS.filter((n) => q === '' || this.i18n.translate(n.label).toLowerCase().includes(q) || n.route[0].toString().includes(q));
    return [...this.found(), ...nav];
  });

  ngOnInit(): void {
    queueMicrotask(() => this.input().nativeElement.focus());
    this.query$
      .pipe(
        debounceTime(200),
        distinctUntilChanged(),
        switchMap((q) => {
          this.query.set(q);
          this.active.set(0);
          const term = q.trim();
          if (term.length < 2) {
            return of([] as PaletteItem[]);
          }
          return forkJoin({
            candidates: this.api.candidates({ q: term, perPage: 6 }),
            vacancies: this.api.vacancies({ q: term, perPage: 4 }),
          }).pipe(
            map(({ candidates, vacancies }) => [
              ...candidates.data.map(
                (c): PaletteItem => ({
                  kind: 'candidate',
                  icon: 'person',
                  label: c.full_name,
                  hint: c.phone ?? c.email ?? undefined,
                  route: ['/candidates', c.id],
                }),
              ),
              ...vacancies.data.map(
                (v): PaletteItem => ({ kind: 'vacancy', icon: 'work', label: v.title, hint: v.branch?.name, route: ['/vacancies', v.id] }),
              ),
            ]),
            catchError(() => of([] as PaletteItem[])),
          );
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((items) => this.found.set(items));
  }

  protected onKey(e: KeyboardEvent): void {
    const count = this.items().length;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      this.active.update((i) => wrapIndex(i + (e.key === 'ArrowDown' ? 1 : -1), count));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const item = this.items()[this.active()];
      if (item) {
        this.choose(item);
      }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      this.closed.emit();
    }
  }

  protected choose(item: PaletteItem): void {
    this.chosen.emit(item);
  }
}
