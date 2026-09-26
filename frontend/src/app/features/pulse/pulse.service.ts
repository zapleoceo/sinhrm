import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { toParams } from '../recruiting/recruiting.service';
import {
  AnswerValue,
  MoodEntry,
  MoodSettings,
  MoodToday,
  MyWave,
  NewWave,
  PULSE_ERROR_CODES,
  SaveSurvey,
  Survey,
  SurveyTemplate,
  TeamMood,
  Wave,
  WaveCompare,
  WaveResults,
} from './pulse.model';

const API = '/api/pulse';

interface Data<T> {
  data: T;
}

/** HTTP client of the Pulse API (/api/pulse/*). */
@Injectable({ providedIn: 'root' })
export class PulseService {
  private readonly http = inject(HttpClient);

  templates(): Observable<SurveyTemplate[]> {
    return this.get<SurveyTemplate[]>(`${API}/templates`);
  }

  surveys(): Observable<Survey[]> {
    return this.get<Survey[]>(`${API}/surveys`);
  }

  saveSurvey(body: SaveSurvey, id?: number): Observable<Survey> {
    const req = id ? this.http.put<Data<Survey>>(`${API}/surveys/${id}`, body) : this.http.post<Data<Survey>>(`${API}/surveys`, body);
    return req.pipe(map((r) => r.data));
  }

  waves(surveyId: number): Observable<Wave[]> {
    return this.get<Wave[]>(`${API}/surveys/${surveyId}/waves`);
  }

  createWave(surveyId: number, body: NewWave): Observable<Wave> {
    return this.http.post<Data<Wave>>(`${API}/surveys/${surveyId}/waves`, body).pipe(map((r) => r.data));
  }

  closeWave(id: number): Observable<Wave> {
    return this.http.post<Data<Wave>>(`${API}/waves/${id}/close`, {}).pipe(map((r) => r.data));
  }

  myWaves(): Observable<MyWave[]> {
    return this.get<MyWave[]>(`${API}/my/waves`);
  }

  form(id: number): Observable<MyWave> {
    return this.get<MyWave>(`${API}/waves/${id}/form`);
  }

  respond(id: number, answers: Record<string, AnswerValue>): Observable<void> {
    return this.http.post<void>(`${API}/waves/${id}/responses`, { answers });
  }

  results(id: number, segment?: 'branch' | 'department'): Observable<WaveResults> {
    return this.get<WaveResults>(`${API}/waves/${id}/results`, { segment });
  }

  compare(id: number, segment: 'branch' | 'department' = 'department', withWave?: number): Observable<WaveCompare> {
    return this.get<WaveCompare>(`${API}/waves/${id}/compare`, { segment, with: withWave });
  }

  moodToday(): Observable<MoodToday> {
    return this.get<MoodToday>(`${API}/mood/today`);
  }

  checkIn(score: number, comment: string | null): Observable<MoodEntry> {
    return this.http.post<Data<MoodEntry>>(`${API}/mood`, { score, comment }).pipe(map((r) => r.data));
  }

  myMood(days = 30): Observable<MoodEntry[]> {
    return this.get<MoodEntry[]>(`${API}/mood/me`, { days });
  }

  teamMood(query: { weeks?: number; branch_id?: number; department_id?: number } = {}): Observable<TeamMood> {
    return this.get<TeamMood>(`${API}/mood/team`, query);
  }

  moodSettings(): Observable<MoodSettings> {
    return this.get<MoodSettings>(`${API}/mood/settings`);
  }

  saveMoodSettings(body: MoodSettings): Observable<MoodSettings> {
    return this.http.put<Data<MoodSettings>>(`${API}/mood/settings`, body).pipe(map((r) => r.data));
  }

  private get<T>(url: string, query: Record<string, string | number | undefined> = {}): Observable<T> {
    return this.http.get<Data<T>>(url, { params: toParams(query) }).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed Pulse API call. */
export function pulseErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (PULSE_ERROR_CODES as readonly string[]).includes(code)) {
      return `pulse.errors.${code}`;
    }
    if (error.status === 403) {
      return 'pulse.errors.forbidden';
    }
    if (error.status === 404) {
      return 'pulse.errors.not_found';
    }
    if (error.status === 422) {
      return 'pulse.errors.validation';
    }
  }
  return 'common.error';
}
