import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HealthService } from './health.service';
import { HealthReport } from './health.model';

describe('HealthService', () => {
  let service: HealthService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(HealthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('returns the backend report', () => {
    let result: HealthReport | undefined;
    service.check().subscribe((r) => (result = r));
    http.expectOne('/api/health').flush({ version: '1', ok: true, checks: { database: { ok: true } } });
    expect(result?.ok).toBe(true);
  });

  it('maps a failure to an unreachable report', () => {
    let result: HealthReport | undefined;
    service.check().subscribe((r) => (result = r));
    http.expectOne('/api/health').flush('down', { status: 503, statusText: 'Service Unavailable' });
    expect(result?.ok).toBe(false);
    expect(result?.checks['api'].detail).toBe('unreachable');
  });
});
