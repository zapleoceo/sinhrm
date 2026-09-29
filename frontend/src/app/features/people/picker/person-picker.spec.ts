import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { FormsModule } from '@angular/forms';
import { MatAutocompleteSelectedEvent } from '@angular/material/autocomplete';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { PersonOption } from '../people.model';
import { PeopleService } from '../people.service';
import { PICKER_DEBOUNCE_MS, PersonPicker, PickerValue, dayOf, personSubtitle, unknownPerson } from './person-picker';

const ANNA: PersonOption = { id: 7, full_name: 'Anna Stone', position: 'Analyst', department: 'Sales', avatar_url: null };
const BOHDAN: PersonOption = { id: 9, full_name: 'Bohdan Lee', position: null, department: null, avatar_url: null };
const GONE: PersonOption = { id: 5, full_name: 'Gone Worker', position: null, department: null, avatar_url: null, terminated: true, terminated_at: '2026-01-31' };
const UK = { people: { picker: { terminated: 'Звільнений(а)', terminatedAria: '{{name}}, звільнений(а) {{date}}' } } };

@Component({
  imports: [FormsModule, PersonPicker],
  template: `<app-person-picker [multiple]="multi()" [scope]="'subordinates'" [(ngModel)]="value" />`,
})
class Host {
  readonly multi = signal(false);
  value: PickerValue = null;
}

/** Test seam: the protected handlers the template calls. */
interface PickerInternals {
  type(v: string): void;
  pick(e: MatAutocompleteSelectedEvent): void;
  remove(id: number): void;
  clear(): void;
  backspace(v: string): void;
  text(): string;
  state(): { status: string };
  rows(): PersonOption[];
  selected(): PersonOption[];
}

const selectedEvent = (p: PersonOption): MatAutocompleteSelectedEvent => ({ option: { value: p } }) as unknown as MatAutocompleteSelectedEvent;

describe('person picker helpers', () => {
  it('builds the option subtitle and a placeholder for unresolved ids', () => {
    expect(personSubtitle(ANNA)).toBe('Analyst · Sales');
    expect(personSubtitle(BOHDAN)).toBe('');
    expect(unknownPerson(42).full_name).toBe('#42');
    expect(dayOf('2026-01-31')).toBe('31.01.2026');
  });
});

describe('PeopleService picker endpoints', () => {
  it('searches with scope/limit and looks ids up as ids[]', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const api = TestBed.inject(PeopleService);
    const http = TestBed.inject(HttpTestingController);

    api.searchPeople('an', 'users', { limit: 10, includeTerminated: true }).subscribe((rows) => expect(rows).toEqual([ANNA]));
    http.expectOne('/api/people/search?q=an&scope=users&limit=10&include_terminated=1').flush({ data: [ANNA] });
    api.lookupPeople([7, 9]).subscribe((rows) => expect(rows.length).toBe(1));
    http.expectOne('/api/people/lookup?scope=employees&ids%5B%5D=7&ids%5B%5D=9').flush({ data: [ANNA] });
    http.verify();
  });
});

describe('PersonPicker', () => {
  let http: HttpTestingController;

  const setup = (multi = false, value: PickerValue = null) => {
    TestBed.configureTestingModule({
      imports: [Host, TranslocoTestingModule.forRoot({ langs: { uk: UK }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(Host);
    fixture.componentInstance.multi.set(multi);
    fixture.componentInstance.value = value;
    fixture.detectChanges();
    return { fixture, host: fixture.componentInstance };
  };
  const pickerOf = (fixture: ReturnType<typeof setup>['fixture']): PickerInternals =>
    fixture.debugElement.children[0].componentInstance as unknown as PickerInternals;

  beforeEach(() => vi.useFakeTimers());
  afterEach(() => {
    http.verify();
    vi.useRealTimers();
  });

  it('marks a terminated person: muted chip, badge with the date, accessible name', async () => {
    const { fixture } = setup(true, [5, 7]);
    await vi.runAllTimersAsync();
    http.expectOne((r) => r.url === '/api/people/lookup').flush({ data: [GONE, ANNA] });
    fixture.detectChanges();
    const chips = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('mat-chip-row'));
    expect(chips.map((c) => c.classList.contains('gone'))).toEqual([true, false]);
    expect(chips[0].querySelector('.gone-badge')?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Звільнений(а) · 31.01.2026');
    expect(chips[0].getAttribute('aria-label')).toBe('Gone Worker, звільнений(а) 31.01.2026');
    expect(chips[1].querySelector('.gone-badge')).toBeNull();
    expect(chips[1].hasAttribute('aria-label')).toBe(false);
  });

  it('single mode shows the terminated badge next to the selected name', async () => {
    const { fixture } = setup(false, 5);
    await vi.runAllTimersAsync();
    http.expectOne((r) => r.url === '/api/people/lookup').flush({ data: [GONE] });
    fixture.detectChanges();
    const badge = (fixture.nativeElement as HTMLElement).querySelector('.suffix-badge');
    expect(badge?.getAttribute('aria-label')).toBe('Gone Worker, звільнений(а) 31.01.2026');
    expect(badge?.textContent).toContain('Звільнений(а)');
  });

  it('projects the prefix icon and suffixes into the form-field slots, not into the input row', async () => {
    const { fixture } = setup(false, 5);
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.mat-mdc-form-field-icon-prefix mat-icon')?.textContent).toBe('person_search');
    expect(el.querySelector('.mat-mdc-form-field-infix mat-icon')).toBeNull();
    await vi.runAllTimersAsync();
    http.expectOne((r) => r.url === '/api/people/lookup').flush({ data: [GONE] });
    fixture.detectChanges();
    expect(el.querySelector('.mat-mdc-form-field-icon-prefix .av')).not.toBeNull();
    expect(el.querySelector('.mat-mdc-form-field-text-suffix .suffix-badge')).not.toBeNull();
    expect(el.querySelector('.mat-mdc-form-field-icon-suffix button')).not.toBeNull();
    expect(el.querySelector('.mat-mdc-form-field-infix')?.querySelector('mat-icon, button, .av, .suffix-badge')).toBeNull();
  });

  it('shows the saved person by name (id → name through /lookup)', async () => {
    const { fixture } = setup(false, 7);
    await vi.runAllTimersAsync();
    http.expectOne((r) => r.url === '/api/people/lookup' && r.params.getAll('ids[]')?.join() === '7').flush({ data: [ANNA] });
    expect(pickerOf(fixture).text()).toBe('Anna Stone');
  });

  it('keeps an unresolved saved id as a #id placeholder instead of dropping it', async () => {
    const { fixture, host } = setup(false, 404);
    await vi.runAllTimersAsync();
    http.expectOne((r) => r.url === '/api/people/lookup').flush({ data: [] });
    expect(pickerOf(fixture).text()).toBe('#404');
    expect(host.value).toBe(404);
  });

  it('waits for 2 characters and the debounce, then searches in the given scope', async () => {
    const { fixture } = setup();
    const picker = pickerOf(fixture);
    picker.type('a');
    await vi.advanceTimersByTimeAsync(PICKER_DEBOUNCE_MS);
    http.expectNone((r) => r.url === '/api/people/search');
    expect(picker.state().status).toBe('short');

    picker.type('an');
    await vi.advanceTimersByTimeAsync(PICKER_DEBOUNCE_MS - 1);
    http.expectNone((r) => r.url === '/api/people/search');
    await vi.advanceTimersByTimeAsync(1);
    expect(picker.state().status).toBe('loading');
    const req = http.expectOne((r) => r.url === '/api/people/search');
    expect(req.request.params.get('q')).toBe('an');
    expect(req.request.params.get('scope')).toBe('subordinates');
    req.flush({ data: [ANNA, BOHDAN] });
    expect(picker.rows().map((p) => p.id)).toEqual([7, 9]);
  });

  it('shows the empty and error states', async () => {
    const { fixture } = setup();
    const picker = pickerOf(fixture);
    picker.type('zz');
    await vi.advanceTimersByTimeAsync(PICKER_DEBOUNCE_MS);
    http.expectOne((r) => r.url === '/api/people/search').flush({ data: [] });
    expect(picker.state().status).toBe('done');
    expect(picker.rows()).toEqual([]);

    picker.type('zzz');
    await vi.advanceTimersByTimeAsync(PICKER_DEBOUNCE_MS);
    http.expectOne((r) => r.url === '/api/people/search').flush({}, { status: 500, statusText: 'Server Error' });
    expect(picker.state().status).toBe('error');
  });

  it('single mode: picking writes the id, clear writes null', async () => {
    const { fixture, host } = setup();
    await vi.runAllTimersAsync();
    const picker = pickerOf(fixture);
    picker.pick(selectedEvent(ANNA));
    await vi.runAllTimersAsync();
    expect(host.value).toBe(7);
    expect(picker.text()).toBe('Anna Stone');
    picker.clear();
    expect(host.value).toBeNull();
    expect(picker.text()).toBe('');
  });

  it('multi mode: chips add ids, remove and Backspace take them away', async () => {
    const { fixture, host } = setup(true, []);
    await vi.runAllTimersAsync();
    const picker = pickerOf(fixture);
    picker.pick(selectedEvent(ANNA));
    picker.pick(selectedEvent(BOHDAN));
    picker.pick(selectedEvent(ANNA));
    await vi.runAllTimersAsync();
    expect(host.value).toEqual([9, 7]);
    picker.remove(9);
    expect(host.value).toEqual([7]);
    picker.backspace('');
    expect(host.value).toEqual([]);
  });
});
