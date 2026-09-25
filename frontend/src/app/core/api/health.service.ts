import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, of } from 'rxjs';
import { HealthReport } from './health.model';

@Injectable({ providedIn: 'root' })
export class HealthService {
  private readonly http = inject(HttpClient);

  /** Emits the backend report; a network/5xx failure is mapped to an "unreachable" report. */
  check(): Observable<HealthReport> {
    return this.http
      .get<HealthReport>('/api/health')
      .pipe(catchError(() => of({ version: 'unknown', ok: false, checks: { api: { ok: false, detail: 'unreachable' } } })));
  }
}
