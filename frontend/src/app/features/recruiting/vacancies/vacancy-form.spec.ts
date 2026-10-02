import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, of, throwError } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { safeStorage } from '../../../core/storage/safe-storage';
import { DirectoryService } from '../../directory/directory.service';
import { SaveVacancy, Vacancy, VacancyTemplate, VacancyTextDraft } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { applyMarkdown } from './markdown-field';
import { VacanciesStore } from './vacancies.store';
import { VacancyFormPage } from './vacancy-form.page';

const OPTIONS = {
  employment_types: ['full_time'],
  work_formats: ['office', 'remote', 'hybrid'],
  experience_levels: ['none'],
  education_levels: ['any'],
  languages: ['en', 'de'],
  language_levels: ['A1', 'B2', 'native'],
  countries: ['UA'],
  currencies: ['UAH', 'USD', 'EUR'],
  external_sites: ['work_ua', 'other'],
};

class FakeApi {
  created: SaveVacancy[] = [];
  templates: { name: string; data: SaveVacancy }[] = [];
  create$: Observable<Vacancy> = of({ id: 5 } as Vacancy);
  text$: Observable<VacancyTextDraft> = of({ status: 'done', request_id: 1, text: '- CRM', error: null });
  vacancyOptions = () => of(OPTIONS);
  pipelines = () => of([{ id: 3, name: 'Main', is_default: true, stages: [] }]);
  assignableUsers = () => of([{ id: 9, name: 'Rita' }]);
  vacancyTemplates = () => of<VacancyTemplate[]>([{ id: 4, name: 'Sales', data: { title: 'Sales rep', work_format: 'remote' }, created_at: null }]);
  vacancy = () => of({ id: 7, title: 'Old', branch_id: 1, languages: [], external_postings: [] } as unknown as Vacancy);
  createVacancy = (body: SaveVacancy) => {
    this.created.push(body);
    return this.create$;
  };
  updateVacancy = () => of({ id: 7 } as Vacancy);
  saveVacancyTemplate = (name: string, data: SaveVacancy) => {
    this.templates.push({ name, data });
    return of<VacancyTemplate>({ id: 8, name, data, created_at: null });
  };
  vacancyText = () => this.text$;
  vacancyTextResult = () => this.text$;
}

function setup(params: Record<string, string> = {}, query: Record<string, string> = {}): { page: VacancyFormPage; api: FakeApi; navigate: ReturnType<typeof vi.fn> } {
  const api = new FakeApi();
  const navigate = vi.fn().mockResolvedValue(true);
  TestBed.configureTestingModule({
    imports: [TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      { provide: RecruitingService, useValue: api },
      { provide: DirectoryService, useValue: { active: () => of([{ id: 1, name: 'North', status: 'active' }]) } },
      { provide: AuthService, useValue: { user: signal({ roles: ['recruiter'] }) } },
      { provide: Router, useValue: { navigate } },
      { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap(params), queryParamMap: convertToParamMap(query) } } },
    ],
  });
  const page = TestBed.runInInjectionContext(() => new VacancyFormPage());
  page.ngOnInit();
  return { page, api, navigate };
}

/** Protected members reached in tests. */
interface Internals {
  submit(): void;
  generate(s: string): void;
  saveTemplate(): void;
  addLanguage(v?: { lang: string; level: string }): void;
  removeLanguage(i: number): void;
  fieldErrors(): Record<string, string>;
  error(): string | null;
  aiHints(): Record<string, string>;
  aiBusy(): Record<string, boolean>;
  templateName: { set(v: string | null): void };
  draftRestored(): boolean;
}
const internals = (page: VacancyFormPage): Internals => page as unknown as Internals;

describe('applyMarkdown', () => {
  it('wraps the selection and prefixes list lines', () => {
    expect(applyMarkdown('a bc d', 2, 4, 'bold').text).toBe('a **bc** d');
    expect(applyMarkdown('x', 0, 1, 'italic').text).toBe('*x*');
    expect(applyMarkdown('one\ntwo', 0, 7, 'ol').text).toBe('1. one\n2. two');
    expect(applyMarkdown('- one\n- two', 0, 0, 'ul').text).toBe('- one\n- two');
  });

  it('clears emphasis and list markers (whole text without a selection)', () => {
    expect(applyMarkdown('1. **a**\n- *b*', 0, 0, 'clear').text).toBe('a\nb');
  });
});

describe('VacancyFormPage', () => {
  afterEach(() => {
    safeStorage.remove('sinhrm.vacancy-draft.new');
  });

  it('preselects the default pipeline and validates before sending', () => {
    const { page, api } = setup();
    expect(page.form.controls.pipeline_id.value).toBe(3);

    internals(page).submit();
    expect(api.created).toEqual([]);
    expect(internals(page).error()).toBe('recruiting.form.fixErrors');
  });

  it('sends language rows and the full body, then leaves without asking', () => {
    const { page, api, navigate } = setup();
    page.form.patchValue({ title: '  Tutor ', branch_id: 1, salary_min: 100, salary_max: 200, work_format: 'remote' });
    internals(page).addLanguage({ lang: 'en', level: 'B2' });
    internals(page).addLanguage();
    internals(page).removeLanguage(1);
    internals(page).submit();

    expect(api.created[0]).toMatchObject({
      title: 'Tutor',
      branch_id: 1,
      pipeline_id: 3,
      work_format: 'remote',
      salary_currency: 'UAH',
      languages: [{ lang: 'en', level: 'B2' }],
      requirements: null,
    });
    expect(navigate).toHaveBeenCalledWith(['/vacancies', 5]);
    expect(page.canLeave()).toBe(true);
  });

  it('maps 422 errors to fields', () => {
    const { page, api } = setup();
    api.create$ = throwError(
      () => new HttpErrorResponse({ status: 422, error: { errors: { salary_max: ['salary_range'], 'languages.0.level': ['bad'] } } }),
    );
    page.form.patchValue({ title: 'X', branch_id: 1 });
    internals(page).addLanguage({ lang: 'en', level: 'B2' });
    internals(page).submit();

    expect(internals(page).fieldErrors()).toEqual({
      salary_max: 'recruiting.form.errors.salaryRange',
      'languages.0.level': 'recruiting.form.errors.invalid',
    });
    expect(page.form.controls.salary_max.hasError('server')).toBe(true);
    expect(page.form.controls.languages.at(0).controls.level.hasError('server')).toBe(true);
  });

  it('saves a template without branch, people, status or publication', () => {
    const { page, api } = setup();
    page.form.patchValue({ title: 'Sales', branch_id: 1, published: true, work_format: 'office' });
    internals(page).templateName.set('Base');
    internals(page).saveTemplate();

    expect(api.templates[0].name).toBe('Base');
    expect(api.templates[0].data.work_format).toBe('office');
    for (const key of ['branch_id', 'status', 'published', 'recruiter_id', 'pipeline_id']) {
      expect(key in api.templates[0].data).toBe(false);
    }
  });

  it('prefills from a template given in the URL', () => {
    const { page } = setup({}, { template: '4' });
    expect(page.form.controls.title.value).toBe('Sales rep');
    expect(page.form.controls.work_format.value).toBe('remote');
  });

  it('AI button fills the section or shows why AI did not help', () => {
    const { page, api } = setup();
    page.form.patchValue({ title: 'Tutor' });
    internals(page).generate('requirements');
    expect(page.form.controls.requirements.value).toBe('- CRM');
    expect(internals(page).aiBusy()['requirements']).toBe(false);

    api.text$ = throwError(() => new HttpErrorResponse({ status: 422, error: { code: 'ai_disabled' } }));
    internals(page).generate('description');
    expect(internals(page).aiHints()['description']).toBe('ai.errors.ai_disabled');
  });

  it('guards unsaved edits and restores the autosaved draft', () => {
    const first = setup();
    expect(first.page.canLeave()).toBe(true);
    first.page.form.controls.title.setValue('Draft title');
    first.page.form.markAsDirty();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    expect(first.page.canLeave()).toBe(false);
    confirm.mockRestore();

    safeStorage.set('sinhrm.vacancy-draft.new', JSON.stringify(first.page.body()));
    TestBed.resetTestingModule();
    const second = setup();
    expect(second.page.form.controls.title.value).toBe('Draft title');
    expect(internals(second.page).draftRestored()).toBe(true);
  });
});

describe('VacanciesStore active count', () => {
  it('keeps the active count from the list meta', () => {
    TestBed.configureTestingModule({
      providers: [
        VacanciesStore,
        {
          provide: RecruitingService,
          useValue: { vacancies: () => of({ data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1, active_count: 3 } }) },
        },
      ],
    });
    const store = TestBed.inject(VacanciesStore);
    store.patchQuery({ active: true, status: undefined });
    expect(store.activeCount()).toBe(3);
    expect(store.query().active).toBe(true);
  });
});
