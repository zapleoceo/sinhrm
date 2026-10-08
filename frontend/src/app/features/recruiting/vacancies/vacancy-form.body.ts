import { HttpErrorResponse } from '@angular/common/http';
import { SaveVacancy, VacancyStatus } from '../recruiting.model';

/** Raw value of the vacancy form (`form.getRawValue()`): text controls are strings, the rest nullable. */
export interface VacancyFormValue {
  title: string;
  recruiter_id: number | null;
  hiring_manager_id: number | null;
  status: VacancyStatus;
  category_id: number | null;
  pipeline_id: number | null;
  branch_id: number | null;
  department_id: number | null;
  description: string;
  requirements: string;
  responsibilities: string;
  additional_info: string;
  employment_type: string | null;
  work_format: string | null;
  country: string | null;
  city_id: number | null;
  experience_level: string | null;
  education_level: string | null;
  salary_min: number | null;
  salary_max: number | null;
  salary_currency: string;
  salary_visible: boolean;
  languages: { lang: string; level: string }[];
  published: boolean;
  public_description: string;
  external_postings: { site: string; url: string; date: string }[];
}

/**
 * Request body from the form (empty strings → null; the API validates everything again). Mirrors the API rules:
 * only recruiting writers send the hiring manager (`canWrite`), the pipeline goes only with a new vacancy (`isNew`),
 * a language row without a language is dropped.
 */
export function vacancyBody(v: VacancyFormValue, opts: { canWrite: boolean; isNew: boolean }): SaveVacancy {
  const text = (s: string): string | null => s.trim() || null;
  return {
    title: v.title.trim(),
    branch_id: v.branch_id ?? undefined,
    ...(v.recruiter_id !== null ? { recruiter_id: v.recruiter_id } : {}),
    ...(opts.canWrite ? { hiring_manager_id: v.hiring_manager_id } : {}),
    ...(opts.isNew && v.pipeline_id !== null ? { pipeline_id: v.pipeline_id } : {}),
    status: v.status,
    category_id: v.category_id,
    department_id: v.department_id,
    description: text(v.description),
    requirements: text(v.requirements),
    responsibilities: text(v.responsibilities),
    additional_info: text(v.additional_info),
    employment_type: v.employment_type,
    work_format: v.work_format,
    country: v.country,
    city_id: v.city_id,
    experience_level: v.experience_level,
    education_level: v.education_level,
    salary_min: v.salary_min,
    salary_max: v.salary_max,
    salary_currency: v.salary_currency,
    salary_visible: v.salary_visible,
    languages: v.languages.filter((l) => l.lang !== ''),
    published: v.published,
    public_description: text(v.public_description),
    external_postings: v.external_postings.map((p) => ({ site: p.site, url: text(p.url), date: text(p.date) })),
  };
}

/** A template keeps the content only: no branch, people, status, publication or pipeline. */
export function templateData(body: SaveVacancy): SaveVacancy {
  const data: SaveVacancy = { ...body };
  delete data.status;
  delete data.branch_id;
  delete data.recruiter_id;
  delete data.hiring_manager_id;
  delete data.published;
  delete data.pipeline_id;
  return data;
}

/** 422 of the API → field path (as in the API: `languages.0.level`) → i18n key; anything else → null. */
export function serverFieldErrors(e: unknown): Record<string, string> | null {
  if (!(e instanceof HttpErrorResponse) || e.status !== 422) {
    return null;
  }
  const errors = (e.error as { errors?: Record<string, string[]> } | null)?.errors ?? {};
  return Object.fromEntries(
    Object.entries(errors).map(([path, messages]) => [
      path,
      messages.includes('salary_range') ? 'recruiting.form.errors.salaryRange' : 'recruiting.form.errors.invalid',
    ]),
  );
}

/** Language / country name in the UI language (Intl), the code itself when the browser cannot name it. */
export function displayName(type: 'language' | 'region', code: string, locale: string): string {
  try {
    return new Intl.DisplayNames([locale], { type }).of(code) ?? code;
  } catch {
    return code;
  }
}
