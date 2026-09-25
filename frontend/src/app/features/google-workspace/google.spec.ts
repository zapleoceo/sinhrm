import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { connectUrl, isSheetUrl, toIsoWithOffset } from './google.model';
import { GoogleService, googleErrorKey } from './google.service';

describe('google.model', () => {
  it('builds the consent URL for the chosen services', () => {
    expect(connectUrl(['gmail', 'calendar'])).toBe('/api/google/connect?services=gmail,calendar');
  });

  it('accepts only Google spreadsheet links', () => {
    expect(isSheetUrl('https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuvwxyz/edit')).toBe(true);
    expect(isSheetUrl('http://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuvwxyz')).toBe(false);
    expect(isSheetUrl('https://docs.google.com/document/d/abcdefghijklmnopqrstuvwxyz')).toBe(false);
  });

  it('turns local date/time into ISO with the offset of that moment', () => {
    const utcPlus3 = { getTimezoneOffset: () => -180 } as Date;
    const utcMinus430 = { getTimezoneOffset: () => 270 } as Date;
    expect(toIsoWithOffset('2026-10-05', '10:00', utcPlus3)).toBe('2026-10-05T10:00:00+03:00');
    expect(toIsoWithOffset('2026-10-05', '09:15', utcMinus430)).toBe('2026-10-05T09:15:00-04:30');
  });
});

describe('GoogleService', () => {
  let service: GoogleService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(GoogleService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads whether the calendar is connected', () => {
    let connected: boolean | undefined;
    service.calendarConnected().subscribe((v) => (connected = v));
    http.expectOne('/api/google/calendar').flush({ data: { connected: true } });
    expect(connected).toBe(true);
  });

  it('schedules a meeting for the candidate and unwraps the event', () => {
    let link: string | null | undefined;
    const body = { title: 'Interview', start: '2026-10-05T10:00:00+03:00', duration_minutes: 30, type: 'online' as const, invite_candidate: false };
    service.scheduleMeeting(7, body).subscribe((e) => (link = e.meet_link));
    const req = http.expectOne({ method: 'POST', url: '/api/google/candidates/7/meetings' });
    expect(req.request.body).toEqual(body);
    req.flush({ data: { event_id: 'e1', meet_link: 'https://meet.google.com/x', html_link: null } });
    expect(link).toBe('https://meet.google.com/x');
  });

  it('inspects a sheet and saves / reruns imports', () => {
    service.inspectSheet('https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuvwxyz', '').subscribe();
    const inspect = http.expectOne({ method: 'POST', url: '/api/google/sheets/inspect' });
    expect(inspect.request.body).toEqual({ url: 'https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuvwxyz', sheet: '' });
    inspect.flush({ data: { spreadsheet_id: 'x', sheet: '', headers: [], rows: [], suggested: {} } });

    service.saveImport({ url: 'u', mapping: { phone: 1 }, auto_sync: true }).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/google/sheets/imports' }).request.body.mapping).toEqual({ phone: 1 });

    service.runImport(3).subscribe();
    http.expectOne({ method: 'POST', url: '/api/google/sheets/imports/3/run' });
    service.updateImport(3, { auto_sync: false }).subscribe();
    http.expectOne({ method: 'PATCH', url: '/api/google/sheets/imports/3' });
  });

  it('maps API error codes to i18n keys', () => {
    const err = (status: number, code?: string) => new HttpErrorResponse({ status, error: code ? { code } : null });
    expect(googleErrorKey(err(422, 'google_calendar_not_connected'))).toBe('google.errors.google_calendar_not_connected');
    expect(googleErrorKey(err(409, 'reconnect_required'))).toBe('google.errors.reconnect_required');
    expect(googleErrorKey(err(422))).toBe('google.errors.validation');
    expect(googleErrorKey(err(500, 'something'))).toBe('google.errors.generic');
    expect(googleErrorKey(new Error('x'))).toBe('google.errors.generic');
  });
});
