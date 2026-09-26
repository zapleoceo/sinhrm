import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, shareReplay } from 'rxjs';
import { DocPage } from './docs.model';

/** Loads the bundled docs index (static asset, not the API) once per session. */
@Injectable({ providedIn: 'root' })
export class DocsService {
  private readonly http = inject(HttpClient);
  private cache$: Observable<DocPage[]> | null = null;

  all(): Observable<DocPage[]> {
    this.cache$ ??= this.http.get<DocPage[]>('/docs/index.json').pipe(shareReplay(1));
    return this.cache$;
  }
}
