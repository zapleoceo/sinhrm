import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { UserRole } from '../../core/auth/auth.model';
import { DataEnvelope } from '../../core/api/api.model';
import { unwrapData } from '../../core/api/unwrap-data';

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
    return this.http.get<DataEnvelope<ModuleSetting[]>>('/api/modules').pipe(unwrapData());
  }

  save(key: string, enabled: boolean, roles: readonly UserRole[]): Observable<ModuleSetting> {
    return this.http.put<DataEnvelope<ModuleSetting>>(`/api/modules/${key}`, { enabled, roles }).pipe(unwrapData());
  }
}
