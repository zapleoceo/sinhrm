import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { Dashboard } from './overview.model';

/** HTTP client of the home page (GET /api/dashboard). */
@Injectable({ providedIn: 'root' })
export class OverviewService {
  private readonly http = inject(HttpClient);

  dashboard(): Observable<Dashboard> {
    return this.http.get<{ data: Dashboard }>('/api/dashboard').pipe(map((r) => r.data));
  }
}
