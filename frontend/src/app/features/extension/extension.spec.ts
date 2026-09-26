import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { ExtensionPage } from './extension.page';
import { ExtensionService } from './extension.service';

const URL = '/api/me/extension-token';
const ACTIVE = { active: true, created_at: '2026-10-01T10:00:00+00:00', last_used_at: null, expires_at: '2026-12-30T10:00:00+00:00' };
const INACTIVE = { active: false, created_at: null, last_used_at: null, expires_at: null };

describe('ExtensionService', () => {
  let service: ExtensionService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(ExtensionService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the status, issues and revokes the token', () => {
    let active: boolean | undefined;
    service.status().subscribe((s) => (active = s.active));
    http.expectOne({ method: 'GET', url: URL }).flush({ data: ACTIVE });
    expect(active).toBe(true);

    let token: string | undefined;
    service.issue().subscribe((s) => (token = s.token));
    http.expectOne({ method: 'POST', url: URL }).flush({ data: { ...ACTIVE, token: '1|synthetic' } });
    expect(token).toBe('1|synthetic');

    let revoked = false;
    service.revoke().subscribe(() => (revoked = true));
    http.expectOne({ method: 'DELETE', url: URL }).flush(null, { status: 204, statusText: 'No Content' });
    expect(revoked).toBe(true);
  });
});

describe('ExtensionPage', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [
        ExtensionPage,
        TranslocoTestingModule.forRoot({ langs: { en: {} }, translocoConfig: { availableLangs: ['en'], defaultLang: 'en' } }),
      ],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('shows the new token once and drops it after revoke', () => {
    const fixture = TestBed.createComponent(ExtensionPage);
    fixture.detectChanges();
    http.expectOne(URL).flush({ data: INACTIVE });
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('[data-testid="token"]')).toBeNull();
    expect(el.querySelector('[data-testid="revoke"]')).toBeNull();

    el.querySelector<HTMLButtonElement>('[data-testid="issue"]')?.click();
    http.expectOne({ method: 'POST', url: URL }).flush({ data: { ...ACTIVE, token: '7|synthetic-token' } });
    fixture.detectChanges();
    expect(el.querySelector('[data-testid="token"]')?.textContent).toBe('7|synthetic-token');

    el.querySelector<HTMLButtonElement>('[data-testid="revoke"]')?.click();
    http.expectOne({ method: 'DELETE', url: URL }).flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();
    expect(el.querySelector('[data-testid="token"]')).toBeNull();
    expect(el.querySelector('[data-testid="revoke"]')).toBeNull();
  });
});
