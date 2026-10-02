import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { DataEnvelope } from '../../../core/api/api.model';
import { unwrapData } from '../../../core/api/unwrap-data';

/** Public fields of a published vacancy (GET /api/public/vacancies[/{slug}]). */
export interface PublicVacancy {
  slug: string;
  title: string;
  branch?: string | null;
  position?: string | null;
  opened_at?: string | null;
  description?: string | null;
  city?: string | null;
  employment_type?: string | null;
  work_format?: string | null;
  /** Present only when the recruiter ticked «Показувати кандидатам» (salary_visible). */
  salary?: { min: number | null; max: number | null; currency: string } | null;
  /** Markdown sections rendered to HTML on the server (raw HTML escaped); bound via [innerHTML], i.e. Angular-sanitized. */
  requirements_html?: string | null;
  responsibilities_html?: string | null;
  additional_info_html?: string | null;
}

/** HTTP client of the public career pages (/api/public/vacancies*, no login). */
@Injectable({ providedIn: 'root' })
export class PublicCareersService {
  private readonly http = inject(HttpClient);

  vacancies(): Observable<PublicVacancy[]> {
    return this.http.get<DataEnvelope<PublicVacancy[]>>('/api/public/vacancies').pipe(unwrapData());
  }

  vacancy(slug: string): Observable<PublicVacancy> {
    return this.http.get<DataEnvelope<PublicVacancy>>(`/api/public/vacancies/${encodeURIComponent(slug)}`).pipe(unwrapData());
  }

  /** Multipart application (name, email, phone, message, honeypot `website`, consent, optional `cv`). */
  apply(slug: string, body: FormData): Observable<unknown> {
    return this.http.post(`/api/public/vacancies/${encodeURIComponent(slug)}/apply`, body);
  }
}
