import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { apiErrorKey } from '../../core/http/api-error';
import { toParams } from '../recruiting/recruiting.service';
import { ArticleQuery, KbArticle, KbCategory, KbVersion, SaveArticle } from './knowledge.model';

/** HTTP client of the Knowledge API (/api/knowledge/*). */
@Injectable({ providedIn: 'root' })
export class KnowledgeService {
  private readonly http = inject(HttpClient);

  categories(): Observable<KbCategory[]> {
    return this.http.get<{ data: KbCategory[] }>('/api/knowledge/categories').pipe(map((r) => r.data));
  }

  createCategory(body: { name: string; emoji?: string | null }): Observable<KbCategory> {
    return this.http.post<{ data: KbCategory }>('/api/knowledge/categories', body).pipe(map((r) => r.data));
  }

  search(query: ArticleQuery): Observable<KbArticle[]> {
    return this.http.get<{ data: KbArticle[] }>('/api/knowledge/articles', { params: toParams({ ...query }) }).pipe(map((r) => r.data));
  }

  get(id: number): Observable<KbArticle> {
    return this.http.get<{ data: KbArticle }>(`/api/knowledge/articles/${id}`).pipe(map((r) => r.data));
  }

  save(id: number | null, body: SaveArticle): Observable<KbArticle> {
    const call = id === null ? this.http.post<{ data: KbArticle }>('/api/knowledge/articles', body) : this.http.patch<{ data: KbArticle }>(`/api/knowledge/articles/${id}`, body);
    return call.pipe(map((r) => r.data));
  }

  versions(id: number): Observable<KbVersion[]> {
    return this.http.get<{ data: KbVersion[] }>(`/api/knowledge/articles/${id}/versions`).pipe(map((r) => r.data));
  }

  vote(id: number, helpful: boolean): Observable<KbArticle> {
    return this.http.post<{ data: KbArticle }>(`/api/knowledge/articles/${id}/vote`, { helpful }).pipe(map((r) => r.data));
  }
}

export function knowledgeErrorKey(error: unknown): string {
  return apiErrorKey(error, 'knowledge', []);
}
