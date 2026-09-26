import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ExtensionTokenStatus } from './extension.model';

const API = '/api/me/extension-token';

/** The browser extension token of the signed-in user: status, issue (revokes the previous one), revoke. */
@Injectable({ providedIn: 'root' })
export class ExtensionService {
  private readonly http = inject(HttpClient);

  status(): Observable<ExtensionTokenStatus> {
    return this.http.get<{ data: ExtensionTokenStatus }>(API).pipe(map((r) => r.data));
  }

  issue(): Observable<ExtensionTokenStatus> {
    return this.http.post<{ data: ExtensionTokenStatus }>(API, {}).pipe(map((r) => r.data));
  }

  revoke(): Observable<void> {
    return this.http.delete<void>(API);
  }
}
