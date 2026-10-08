import { HttpErrorResponse } from '@angular/common/http';
import { VacancyFormValue, displayName, serverFieldErrors, templateData, vacancyBody } from './vacancy-form.body';

const value = (patch: Partial<VacancyFormValue> = {}): VacancyFormValue => ({
  title: '  Бухгалтер ',
  recruiter_id: null,
  hiring_manager_id: 7,
  status: 'open',
  category_id: null,
  pipeline_id: 3,
  branch_id: 1,
  department_id: null,
  description: '  ',
  requirements: 'Excel',
  responsibilities: '',
  additional_info: '',
  employment_type: null,
  work_format: null,
  country: null,
  city_id: null,
  experience_level: null,
  education_level: null,
  salary_min: null,
  salary_max: null,
  salary_currency: 'UAH',
  salary_visible: false,
  languages: [{ lang: 'en', level: 'B1' }, { lang: '', level: 'B1' }],
  published: false,
  public_description: '',
  external_postings: [{ site: 'work_ua', url: ' ', date: '2026-10-01' }],
  ...patch,
});

describe('vacancyBody', () => {
  it('trims the title, turns empty texts into null and drops language rows without a language', () => {
    const body = vacancyBody(value(), { canWrite: true, isNew: true });
    expect(body.title).toBe('Бухгалтер');
    expect(body.description).toBeNull();
    expect(body.requirements).toBe('Excel');
    expect(body.languages).toEqual([{ lang: 'en', level: 'B1' }]);
    expect(body.external_postings).toEqual([{ site: 'work_ua', url: null, date: '2026-10-01' }]);
    expect(body).not.toHaveProperty('recruiter_id');
  });

  it('sends the hiring manager only for recruiting writers and the pipeline only for a new vacancy', () => {
    expect(vacancyBody(value(), { canWrite: true, isNew: true })).toMatchObject({ hiring_manager_id: 7, pipeline_id: 3 });
    const edit = vacancyBody(value({ recruiter_id: 4 }), { canWrite: false, isNew: false });
    expect(edit).not.toHaveProperty('hiring_manager_id');
    expect(edit).not.toHaveProperty('pipeline_id');
    expect(edit.recruiter_id).toBe(4);
  });
});

describe('templateData', () => {
  it('keeps the content and drops branch, people, status, publication and pipeline', () => {
    const body = vacancyBody(value({ recruiter_id: 4, published: true }), { canWrite: true, isNew: true });
    const data = templateData(body);
    for (const key of ['status', 'branch_id', 'recruiter_id', 'hiring_manager_id', 'published', 'pipeline_id']) {
      expect(data).not.toHaveProperty(key);
    }
    expect(data.requirements).toBe('Excel');
    expect(body.status).toBe('open'); // the body itself is not changed
  });
});

describe('serverFieldErrors', () => {
  it('maps 422 field errors to i18n keys; the salary range has its own message', () => {
    const e = new HttpErrorResponse({ status: 422, error: { errors: { salary_max: ['salary_range'], 'languages.0.level': ['in'] } } });
    expect(serverFieldErrors(e)).toEqual({ salary_max: 'recruiting.form.errors.salaryRange', 'languages.0.level': 'recruiting.form.errors.invalid' });
  });

  it('other answers are not field errors', () => {
    expect(serverFieldErrors(new HttpErrorResponse({ status: 403 }))).toBeNull();
    expect(serverFieldErrors(new Error('x'))).toBeNull();
    expect(serverFieldErrors(new HttpErrorResponse({ status: 422, error: null }))).toEqual({});
  });
});

describe('displayName', () => {
  it('names a language in the UI language and falls back to the code', () => {
    expect(displayName('language', 'en', 'en')).toBe('English');
    expect(displayName('region', '??', 'en')).toBe('??');
  });
});
