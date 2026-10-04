import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, Subject, of, throwError } from 'rxjs';
import { GoogleConnectPanel } from './google-connect.panel';
import { GoogleOAuthState, GoogleStatus } from './google.model';
import { GoogleService } from './google.service';
import en from '../../../../public/i18n/en.json';

const status = (configured: boolean): GoogleStatus => ({ data: [], meta: { redirect_uri: '', oauth_configured: configured } });

function setup(response: Observable<GoogleStatus>, query: Record<string, string> = {}) {
  const calls: unknown[] = [];
  TestBed.configureTestingModule({
    imports: [GoogleConnectPanel, TranslocoTestingModule.forRoot({ langs: { en }, preloadLangs: true, translocoConfig: { availableLangs: ['en'], defaultLang: 'en' } })],
    providers: [
      { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap(query) } } },
      { provide: Router, useValue: { navigate: (...args: unknown[]) => { calls.push(args); return Promise.resolve(true); } } },
      { provide: GoogleService, useValue: { status: () => response } },
    ],
  });
  const fixture = TestBed.createComponent(GoogleConnectPanel);
  const states: GoogleOAuthState[] = [];
  fixture.componentInstance.oauthState.subscribe((value) => states.push(value));
  fixture.detectChanges();
  return { fixture, states, calls, element: fixture.nativeElement as HTMLElement };
}

it('shows denied consent and preserves the contextual card when consuming callback params', () => {
  const { states, calls, element } = setup(of(status(true)), { google_error: 'consent_denied', integration: 'google_gmail' });
  expect(states).toEqual(['ready']);
  expect(element.querySelector('[role=status]')?.textContent).toContain(en.google.errors.consent_denied);
  expect(calls).toEqual([[[], { queryParams: { connected: null, missing: null, google_error: null }, queryParamsHandling: 'merge', replaceUrl: true }]]);
});

it('keeps the native Material action disabled while loading, then enables it after confirmation', () => {
  const response = new Subject<GoogleStatus>();
  const { fixture, states, element } = setup(response);
  const action = element.querySelector<HTMLAnchorElement>('a');
  expect(action?.getAttribute('href')).toBeNull();
  expect(action?.getAttribute('aria-disabled')).toBe('true');
  expect(action?.getAttribute('tabindex')).toBe('-1');
  expect(action?.classList.contains('mat-mdc-button-disabled')).toBe(true);
  expect(element.textContent).toContain(en.google.connect.availability.loading);
  response.next(status(true));
  fixture.detectChanges();
  expect(states).toEqual(['ready']);
  expect(action?.getAttribute('href')).toBe('/api/google/connect?services=gmail,calendar,sheets');
  expect(action?.hasAttribute('disabled')).toBe(false);
  expect(action?.getAttribute('aria-disabled')).toBeNull();
  expect(element.textContent).not.toContain(en.google.connect.availability.loading);
});

for (const state of ['unconfigured', 'error'] as const) {
  it(`explains ${state} and disables navigation using the same Material state as the cards`, () => {
    const { states, element } = setup(state === 'error' ? throwError(() => new Error('synthetic status failure')) : of(status(false)));
    const action = element.querySelector<HTMLAnchorElement>('a');
    expect(states).toEqual([state]);
    expect(action?.getAttribute('href')).toBeNull();
    expect(action?.getAttribute('aria-disabled')).toBe('true');
    expect(action?.classList.contains('mat-mdc-button-disabled')).toBe(true);
    expect(element.textContent).toContain(en.google.connect.availability[state]);
    expect(element.textContent).not.toContain(en.google.connect.testingHint);
  });
}
