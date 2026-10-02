import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { apiErrorKey } from '../../core/api/api-error';
import { toParams } from '../../core/api/http-params';
import { ArticleQuery, KbArticle, KbCategory, KbVersion, SaveArticle } from './knowledge.model';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

/** HTTP client of the Knowledge API (/api/knowledge/*). */
@Injectable({ providedIn: 'root' })
export class KnowledgeService {
  private readonly http = inject(HttpClient);

  categories(): Observable<KbCategory[]> {
    return this.http.get<DataEnvelope<KbCategory[]>>('/api/knowledge/categories').pipe(unwrapData());
  }

  createCategory(body: { name: string; emoji?: string | null }): Observable<KbCategory> {
    return this.http.post<DataEnvelope<KbCategory>>('/api/knowledge/categories', body).pipe(unwrapData());
  }

  search(query: ArticleQuery): Observable<KbArticle[]> {
    return this.http.get<DataEnvelope<KbArticle[]>>('/api/knowledge/articles', { params: toParams({ ...query }) }).pipe(unwrapData());
  }

  get(id: number): Observable<KbArticle> {
    return this.http.get<DataEnvelope<KbArticle>>(`/api/knowledge/articles/${id}`).pipe(unwrapData());
  }

  save(id: number | null, body: SaveArticle): Observable<KbArticle> {
    const call = id === null ? this.http.post<DataEnvelope<KbArticle>>('/api/knowledge/articles', body) : this.http.patch<DataEnvelope<KbArticle>>(`/api/knowledge/articles/${id}`, body);
    return call.pipe(unwrapData());
  }

  versions(id: number): Observable<KbVersion[]> {
    return this.http.get<DataEnvelope<KbVersion[]>>(`/api/knowledge/articles/${id}/versions`).pipe(unwrapData());
  }

  vote(id: number, helpful: boolean): Observable<KbArticle> {
    return this.http.post<DataEnvelope<KbArticle>>(`/api/knowledge/articles/${id}/vote`, { helpful }).pipe(unwrapData());
  }
}

export function knowledgeErrorKey(error: unknown): string {
  return apiErrorKey(error, 'knowledge', []);
}
