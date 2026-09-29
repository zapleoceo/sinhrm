import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, booleanAttribute, forwardRef, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { MatAutocompleteModule, MatAutocompleteSelectedEvent } from '@angular/material/autocomplete';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable, Subject, catchError, debounceTime, distinctUntilChanged, map, of, startWith, switchMap } from 'rxjs';
import { initials } from '../org-tree';
import { PersonOption, PickerScope } from '../people.model';
import { PeopleService } from '../people.service';

/** Minimum query length the server accepts (GET /api/people/search). */
export const PICKER_MIN_CHARS = 2;
/** Pause after the last keystroke before searching. */
export const PICKER_DEBOUNCE_MS = 250;

export type PickerValue = number | number[] | null;
type SearchState = { status: 'idle' | 'short' | 'loading' | 'error' } | { status: 'done'; rows: PersonOption[] };

/** Placeholder row for a saved id the caller may no longer see (terminated, other scope): keeps the value intact. */
export function unknownPerson(id: number): PersonOption {
  return { id, full_name: `#${id}`, position: null, department: null, avatar_url: null };
}

/** "Analyst · Sales" — the secondary line of an option. */
export function personSubtitle(p: PersonOption): string {
  return [p.position, p.department].filter((v): v is string => !!v).join(' · ');
}

/**
 * Pick a person by name instead of typing an id. Material autocomplete; single mode (value = id | null) or
 * multi mode with chips (value = id[]). Saved ids are resolved to names on init through the same API (/lookup),
 * so old links and records keep working. scope: 'employees' (directory), 'subordinates' (my team), 'users' (HR).
 */
@Component({
  selector: 'app-person-picker',
  imports: [MatAutocompleteModule, MatButtonModule, MatChipsModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressSpinnerModule, TranslocoPipe],
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => PersonPicker), multi: true }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <mat-form-field class="picker" [subscriptSizing]="subscriptSizing()">
      <mat-label>{{ label() | transloco }}</mat-label>
      @if (multiple()) {
        <mat-chip-grid #grid [attr.aria-label]="label() | transloco" [disabled]="disabled()">
          @for (p of selected(); track p.id) {
            <mat-chip-row (removed)="remove(p.id)">
              <span class="chip-av" aria-hidden="true">{{ initialsOf(p) }}</span>{{ p.full_name }}
              <button matChipRemove type="button" [attr.aria-label]="('people.picker.remove' | transloco) + ' ' + p.full_name"><mat-icon>cancel</mat-icon></button>
            </mat-chip-row>
          }
          <input
            #box
            [value]="text()"
            [placeholder]="'people.picker.placeholder' | transloco"
            [matChipInputFor]="grid"
            [matAutocomplete]="auto"
            [required]="required() && selected().length === 0"
            (input)="type(box.value)"
            (keydown.backspace)="backspace(box.value)"
            (blur)="touched()"
            autocomplete="off"
          />
        </mat-chip-grid>
      } @else {
        @if (selected()[0]; as p) {
          <span matPrefix class="av sm" aria-hidden="true">
            @if (p.avatar_url) { <img [src]="p.avatar_url" alt="" /> } @else { {{ initialsOf(p) }} }
          </span>
        } @else {
          <mat-icon matPrefix aria-hidden="true">person_search</mat-icon>
        }
        <input
          #box
          matInput
          [value]="text()"
          [placeholder]="'people.picker.placeholder' | transloco"
          [matAutocomplete]="auto"
          [required]="required()"
          [disabled]="disabled()"
          (input)="type(box.value)"
          (blur)="blurSingle()"
          autocomplete="off"
        />
        @if (selected().length > 0 && !disabled()) {
          <button mat-icon-button matSuffix type="button" (click)="clear()" [attr.aria-label]="'people.picker.clear' | transloco">
            <mat-icon>close</mat-icon>
          </button>
        }
      }
      @if (state().status === 'loading') {
        <mat-spinner matSuffix diameter="18" [attr.aria-label]="'common.loading' | transloco" />
      }
      <mat-autocomplete #auto="matAutocomplete" (optionSelected)="pick($event)" [hideSingleSelectionIndicator]="true" class="person-picker-panel">
        @switch (state().status) {
          @case ('short') {
            <mat-option disabled class="hint">{{ 'people.picker.minChars' | transloco: { n: minChars } }}</mat-option>
          }
          @case ('loading') {
            <mat-option disabled class="hint">{{ 'common.loading' | transloco }}</mat-option>
          }
          @case ('error') {
            <mat-option disabled class="hint err">{{ 'people.picker.error' | transloco }}</mat-option>
          }
        }
        @for (p of rows(); track p.id) {
          <mat-option [value]="p" [disabled]="isSelected(p.id)">
            <span class="opt">
              <span class="av" aria-hidden="true">
                @if (p.avatar_url) { <img [src]="p.avatar_url" alt="" /> } @else { {{ initialsOf(p) }} }
              </span>
              <span class="who">
                <span class="name">{{ p.full_name }}</span>
                @if (subtitleOf(p); as sub) { <span class="sub">{{ sub }}</span> }
              </span>
            </span>
          </mat-option>
        } @empty {
          @if (state().status === 'done') {
            <mat-option disabled class="hint">{{ 'people.picker.empty' | transloco }}</mat-option>
          }
        }
      </mat-autocomplete>
    </mat-form-field>
  `,
  styles: `
    :host { display: block; min-width: 14rem; }
    .picker { width: 100%; }
    .av {
      display: inline-grid; place-items: center; flex: none; width: 2rem; height: 2rem; border-radius: 50%; overflow: hidden;
      background: var(--mat-sys-primary-container); color: var(--mat-sys-on-primary-container); font-size: 0.75rem; font-weight: 600;
    }
    .av img { width: 100%; height: 100%; object-fit: cover; }
    .av.sm { width: 1.5rem; height: 1.5rem; font-size: 0.65rem; margin: 0 0.25rem 0 0.75rem; }
    .chip-av {
      display: inline-grid; place-items: center; width: 1.25rem; height: 1.25rem; margin-right: 0.35rem; border-radius: 50%;
      background: var(--mat-sys-primary-container); color: var(--mat-sys-on-primary-container); font-size: 0.6rem; font-weight: 600;
    }
    .opt { display: flex; align-items: center; gap: 0.75rem; min-width: 0; padding: 0.25rem 0; }
    .who { display: flex; flex-direction: column; min-width: 0; line-height: 1.25; }
    .name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sub { color: var(--mat-sys-on-surface-variant); font-size: 0.8rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .hint { color: var(--mat-sys-on-surface-variant); font-style: italic; }
    .hint.err { color: var(--mat-sys-error); font-style: normal; }
  `,
})
export class PersonPicker implements ControlValueAccessor, OnInit {
  readonly label = input('people.picker.employee');
  readonly scope = input<PickerScope>('employees');
  readonly multiple = input(false, { transform: booleanAttribute });
  readonly required = input(false, { transform: booleanAttribute });
  readonly includeTerminated = input(false, { transform: booleanAttribute });
  readonly subscriptSizing = input<'fixed' | 'dynamic'>('dynamic');

  protected readonly minChars = PICKER_MIN_CHARS;
  protected readonly text = signal('');
  protected readonly selected = signal<PersonOption[]>([]);
  protected readonly state = signal<SearchState>({ status: 'idle' });
  protected readonly rows = signal<PersonOption[]>([]);
  protected readonly disabled = signal(false);

  private readonly api = inject(PeopleService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly queries = new Subject<string>();
  private onChange: (value: PickerValue) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  ngOnInit(): void {
    this.queries
      .pipe(
        map((q) => q.trim()),
        debounceTime(PICKER_DEBOUNCE_MS),
        distinctUntilChanged(),
        switchMap((q) => this.search(q)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((state) => {
        this.state.set(state);
        this.rows.set(state.status === 'done' ? state.rows : []);
      });
  }

  writeValue(value: PickerValue): void {
    const ids = value === null || value === undefined ? [] : Array.isArray(value) ? value : [value];
    const known = new Map(this.selected().map((p) => [p.id, p]));
    this.selected.set(ids.map((id) => known.get(id) ?? unknownPerson(id)));
    this.syncText();
    const missing = ids.filter((id) => !known.has(id));
    if (missing.length > 0) {
      this.api
        .lookupPeople(missing, this.scope())
        .pipe(catchError(() => of<PersonOption[]>([])), takeUntilDestroyed(this.destroyRef))
        .subscribe((found) => {
          const byId = new Map(found.map((p) => [p.id, p]));
          this.selected.update((list) => list.map((p) => byId.get(p.id) ?? p));
          this.syncText();
        });
    }
  }

  registerOnChange(fn: (value: PickerValue) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(disabled: boolean): void {
    this.disabled.set(disabled);
  }

  protected type(value: string): void {
    this.text.set(value);
    this.queries.next(value);
  }

  protected pick(event: MatAutocompleteSelectedEvent): void {
    const person = event.option.value as PersonOption;
    this.selected.set(this.multiple() ? [...this.selected().filter((p) => p.id !== person.id), person] : [person]);
    this.text.set(this.multiple() ? '' : person.full_name);
    this.resetSearch();
    this.emit();
  }

  protected remove(id: number): void {
    this.selected.update((list) => list.filter((p) => p.id !== id));
    this.emit();
  }

  protected clear(): void {
    this.selected.set([]);
    this.text.set('');
    this.resetSearch();
    this.emit();
  }

  /** Multi mode: Backspace in an empty box removes the last chip. */
  protected backspace(value: string): void {
    const last = this.selected().at(-1);
    if (value === '' && last) {
      this.remove(last.id);
    }
  }

  /** Single mode: typing without picking restores the selected name; an emptied box clears the value. */
  protected blurSingle(): void {
    this.touched();
    if (this.text().trim() === '' && this.selected().length > 0) {
      this.clear();
      return;
    }
    this.syncText();
  }

  protected touched(): void {
    this.onTouched();
  }

  protected isSelected(id: number): boolean {
    return this.selected().some((p) => p.id === id);
  }

  protected initialsOf(p: PersonOption): string {
    return p.full_name.startsWith('#') ? '?' : initials(p.full_name);
  }

  protected subtitleOf(p: PersonOption): string {
    return personSubtitle(p);
  }

  private search(q: string): Observable<SearchState> {
    if (q.length === 0) {
      return of({ status: 'idle' });
    }
    if (q.length < PICKER_MIN_CHARS) {
      return of({ status: 'short' });
    }
    return this.api.searchPeople(q, this.scope(), { includeTerminated: this.includeTerminated() }).pipe(
      map((rows): SearchState => ({ status: 'done', rows })),
      catchError(() => of<SearchState>({ status: 'error' })),
      startWith<SearchState>({ status: 'loading' }),
    );
  }

  private resetSearch(): void {
    this.queries.next('');
    this.state.set({ status: 'idle' });
    this.rows.set([]);
  }

  private syncText(): void {
    if (!this.multiple()) {
      this.text.set(this.selected()[0]?.full_name ?? '');
    }
  }

  private emit(): void {
    const ids = this.selected().map((p) => p.id);
    this.onChange(this.multiple() ? ids : (ids[0] ?? null));
  }
}
