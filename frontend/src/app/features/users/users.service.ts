import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { AdminUser, InviteUser, UpdateUser, USER_ERROR_CODES, UsersPage, UsersQuery } from './users.model';
import { apiErrorKey } from '../../core/api/api-error';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';
import { toParams } from '../../core/api/http-params';

const API = '/api/users';

@Injectable({ providedIn: 'root' })
export class UsersService {
  private readonly http = inject(HttpClient);

  list(query: UsersQuery): Observable<UsersPage> {
    return this.http.get<UsersPage>(API, { params: toParams(query) });
  }

  invite(body: InviteUser): Observable<AdminUser> {
    return this.http.post<DataEnvelope<AdminUser>>(API, body).pipe(unwrapData());
  }

  update(id: number, body: UpdateUser): Observable<AdminUser> {
    return this.http.patch<DataEnvelope<AdminUser>>(`${API}/${id}`, body).pipe(unwrapData());
  }
}

/** i18n key for a failed users API call. */
export function userErrorKey(error: unknown): string {
  return apiErrorKey(error, 'users', USER_ERROR_CODES, { statuses: [], fallback: 'users.errors.generic' });
}
