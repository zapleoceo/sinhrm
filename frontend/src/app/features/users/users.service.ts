import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { AdminUser, InviteUser, UpdateUser, USER_ERROR_CODES, UsersPage, UsersQuery } from './users.model';

const API = '/api/users';

@Injectable({ providedIn: 'root' })
export class UsersService {
  private readonly http = inject(HttpClient);

  list(query: UsersQuery): Observable<UsersPage> {
    let params = new HttpParams();
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params = params.set(key, String(value));
      }
    }
    return this.http.get<UsersPage>(API, { params });
  }

  invite(body: InviteUser): Observable<AdminUser> {
    return this.http.post<{ data: AdminUser }>(API, body).pipe(map((r) => r.data));
  }

  update(id: number, body: UpdateUser): Observable<AdminUser> {
    return this.http.patch<{ data: AdminUser }>(`${API}/${id}`, body).pipe(map((r) => r.data));
  }
}

/** i18n key for a failed users API call. */
export function userErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string' && (USER_ERROR_CODES as readonly string[]).includes(code)) {
      return `users.errors.${code}`;
    }
  }
  return 'users.errors.generic';
}
