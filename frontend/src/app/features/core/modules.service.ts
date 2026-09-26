import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { UserRole } from '../../core/auth/auth.model';

/** One row of GET /api/modules (superadmin). */
export interface ModuleSetting {
  key: string;
  name_key: string;
  icon: string;
  group: string;
  core: boolean;
  enabled: boolean;
  roles: UserRole[];
  default_roles: UserRole[];
}

/** Admin → Модулі: company-wide on/off + roles per module (docs/modules/modules-access.md). */
@Injectable({ providedIn: 'root' })
export class ModulesService {
  private readonly http = inject(HttpClient);

  list(): Observable<ModuleSetting[]> {
    return this.http.get<{ data: ModuleSetting[] }>('/api/modules').pipe(map((r) => r.data));
  }

  save(key: string, enabled: boolean, roles: readonly UserRole[]): Observable<ModuleSetting> {
    return this.http.put<{ data: ModuleSetting }>(`/api/modules/${key}`, { enabled, roles }).pipe(map((r) => r.data));
  }
}
