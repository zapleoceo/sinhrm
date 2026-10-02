import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { toParams } from '../../core/api/http-params';
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
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

const API = '/api/pulse';

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
    const req = id ? this.http.put<DataEnvelope<Survey>>(`${API}/surveys/${id}`, body) : this.http.post<DataEnvelope<Survey>>(`${API}/surveys`, body);
    return req.pipe(unwrapData());
  }

  waves(surveyId: number): Observable<Wave[]> {
    return this.get<Wave[]>(`${API}/surveys/${surveyId}/waves`);
  }

  createWave(surveyId: number, body: NewWave): Observable<Wave> {
    return this.http.post<DataEnvelope<Wave>>(`${API}/surveys/${surveyId}/waves`, body).pipe(unwrapData());
  }

  closeWave(id: number): Observable<Wave> {
    return this.http.post<DataEnvelope<Wave>>(`${API}/waves/${id}/close`, {}).pipe(unwrapData());
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
    return this.http.post<DataEnvelope<MoodEntry>>(`${API}/mood`, { score, comment }).pipe(unwrapData());
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
    return this.http.put<DataEnvelope<MoodSettings>>(`${API}/mood/settings`, body).pipe(unwrapData());
  }

  private get<T>(url: string, query: Record<string, string | number | undefined> = {}): Observable<T> {
    return this.http.get<DataEnvelope<T>>(url, { params: toParams(query) }).pipe(unwrapData());
  }
}

/** i18n key for a failed Pulse API call. */
export function pulseErrorKey(error: unknown): string {
  return apiErrorKey(error, 'pulse', PULSE_ERROR_CODES, { statuses: [403, 404, 422] });
}
