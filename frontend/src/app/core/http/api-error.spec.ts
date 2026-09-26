import { HttpErrorResponse } from '@angular/common/http';
import { apiErrorKey } from './api-error';

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
});
