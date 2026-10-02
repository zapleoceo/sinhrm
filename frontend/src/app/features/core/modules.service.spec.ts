import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ModuleSetting, ModulesService } from './modules.service';

describe('ModulesService', () => {
  it('unwraps { data } of the list and of a saved module', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const api = TestBed.inject(ModulesService);
    const http = TestBed.inject(HttpTestingController);
    const row = { key: 'pulse', enabled: false, roles: ['recruiter'] } as unknown as ModuleSetting;

    let list: ModuleSetting[] | undefined;
    api.list().subscribe((r) => (list = r));
    http.expectOne({ method: 'GET', url: '/api/modules' }).flush({ data: [row] });
    expect(list).toEqual([row]);

    let saved: ModuleSetting | undefined;
    api.save('pulse', false, ['recruiter']).subscribe((r) => (saved = r));
    const put = http.expectOne({ method: 'PUT', url: '/api/modules/pulse' });
    expect(put.request.body).toEqual({ enabled: false, roles: ['recruiter'] });
    put.flush({ data: row });
    expect(saved).toEqual(row);
    http.verify();
  });
});
