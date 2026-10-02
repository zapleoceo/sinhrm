import { HttpErrorResponse } from '@angular/common/http';
import { apiErrorCode, apiErrorKey, apiErrorStatus } from './api-error';

describe('apiErrorKey', () => {
  it('prefers a known business code, then the status, then a generic key', () => {
    const codes = ['case_closed'] as const;
    expect(apiErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'case_closed' } }), 'desk', codes)).toBe('desk.errors.case_closed');
    expect(apiErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'other' } }), 'desk', codes)).toBe('common.error');
    expect(apiErrorKey(new HttpErrorResponse({ status: 403 }), 'desk', codes)).toBe('desk.errors.forbidden');
    expect(apiErrorKey(new HttpErrorResponse({ status: 404 }), 'desk', codes)).toBe('desk.errors.not_found');
    expect(apiErrorKey(new HttpErrorResponse({ status: 422, error: {} }), 'desk', codes)).toBe('desk.errors.validation');
    expect(apiErrorKey(new HttpErrorResponse({ status: 429 }), 'desk', codes)).toBe('desk.errors.rate_limited');
    expect(apiErrorKey(new Error('x'), 'desk', codes)).toBe('common.error');
  });

  it('maps only the listed statuses and falls back to the given key', () => {
    const opts = { statuses: [403, 422] as const, fallback: 'scripts.errors.generic' };
    expect(apiErrorKey(new HttpErrorResponse({ status: 403 }), 'scripts', [], opts)).toBe('scripts.errors.forbidden');
    expect(apiErrorKey(new HttpErrorResponse({ status: 422 }), 'scripts', [], opts)).toBe('scripts.errors.validation');
    expect(apiErrorKey(new HttpErrorResponse({ status: 404 }), 'scripts', [], opts)).toBe('scripts.errors.generic');
    expect(apiErrorKey(new HttpErrorResponse({ status: 429 }), 'scripts', [], opts)).toBe('scripts.errors.generic');
    expect(apiErrorKey(null, 'scripts', [], opts)).toBe('scripts.errors.generic');
  });

  it('with no statuses maps only business codes', () => {
    const opts = { statuses: [], fallback: 'users.errors.generic' };
    expect(apiErrorKey(new HttpErrorResponse({ status: 422, error: { code: 'last_superadmin' } }), 'users', ['last_superadmin'], opts)).toBe('users.errors.last_superadmin');
    expect(apiErrorKey(new HttpErrorResponse({ status: 403 }), 'users', ['last_superadmin'], opts)).toBe('users.errors.generic');
  });

  it('a known code wins over the status, an unknown code does not block the status', () => {
    expect(apiErrorKey(new HttpErrorResponse({ status: 403, error: { code: 'not_in_audience' } }), 'pulse', ['not_in_audience'])).toBe('pulse.errors.not_in_audience');
    expect(apiErrorKey(new HttpErrorResponse({ status: 403, error: { code: 'nope' } }), 'pulse', ['not_in_audience'])).toBe('pulse.errors.forbidden');
  });
});

describe('apiErrorCode / apiErrorStatus', () => {
  it('reads the business code and status of HTTP errors only', () => {
    expect(apiErrorCode(new HttpErrorResponse({ status: 422, error: { code: 'x' } }))).toBe('x');
    expect(apiErrorCode(new HttpErrorResponse({ status: 422, error: { code: 5 } }))).toBeNull();
    expect(apiErrorCode(new HttpErrorResponse({ status: 500, error: null }))).toBeNull();
    expect(apiErrorCode(new Error('x'))).toBeNull();
    expect(apiErrorStatus(new HttpErrorResponse({ status: 429 }))).toBe(429);
    expect(apiErrorStatus('ai_timeout')).toBeNull();
  });
});
