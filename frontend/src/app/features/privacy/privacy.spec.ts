import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, HttpHeaders, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { PrivacyService, canManagePrivacy, privacyErrorKey } from './privacy.service';

describe('PrivacyService', () => {
  let api: PrivacyService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(PrivacyService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('calls the privacy endpoints', () => {
    api.erase('candidate', 5, 'Written request').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/privacy/candidate/5/erase' }).request.body).toEqual({ reason: 'Written request', confirm: true });

    let months: number | null | undefined;
    api.settings().subscribe((s) => (months = s.retention_rejected_months));
    http.expectOne({ method: 'GET', url: '/api/privacy/settings' }).flush({ data: { retention_rejected_months: null } });
    expect(months).toBeNull();

    api.saveSettings({ retention_rejected_months: 12 }).subscribe();
    expect(http.expectOne({ method: 'PUT', url: '/api/privacy/settings' }).request.body).toEqual({ retention_rejected_months: 12 });
  });

  it('downloads the export as a file', () => {
    const create = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:x');
    const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);

    api.download('employee', 3, 'html').subscribe();
    const req = http.expectOne((r) => r.url === '/api/privacy/employee/3/export' && r.params.get('format') === 'html');
    req.flush(new Blob(['<html></html>']), { headers: new HttpHeaders({ 'Content-Disposition': 'attachment; filename="personal-data-employee-3.html"' }) });

    expect(click).toHaveBeenCalledTimes(1);
    expect(revoke).toHaveBeenCalledWith('blob:x');
    create.mockRestore();
    revoke.mockRestore();
    click.mockRestore();
  });

  it('allows only superadmin and admin and maps blocker codes', () => {
    expect(canManagePrivacy(['admin'])).toBe(true);
    expect(canManagePrivacy(['superadmin'])).toBe(true);
    expect(canManagePrivacy(['hr_manager', 'recruiter', 'viewer', 'employee'])).toBe(false);
    expect(privacyErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'hired' } }))).toBe('privacy.errors.hired');
    expect(privacyErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'not_terminated' } }))).toBe('privacy.errors.not_terminated');
    expect(privacyErrorKey(new HttpErrorResponse({ status: 500 }))).toBe('common.error');
    expect(privacyErrorKey(new HttpErrorResponse({ status: 404 }))).toBe('privacy.errors.not_found');
  });
});
