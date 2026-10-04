import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { GoogleConnectPanel } from './google-connect.panel';
import { GoogleService } from './google.service';
import en from '../../../../public/i18n/en.json';

it('shows a denied consent and preserves the contextual card when consuming callback params', () => {
  const calls: unknown[] = [];
  TestBed.configureTestingModule({
    imports: [GoogleConnectPanel, TranslocoTestingModule.forRoot({ langs: { en }, preloadLangs: true, translocoConfig: { availableLangs: ['en'], defaultLang: 'en' } })],
    providers: [
      { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap({ google_error: 'consent_denied', integration: 'google_gmail' }) } } },
      { provide: Router, useValue: { navigate: (...args: unknown[]) => { calls.push(args); return Promise.resolve(true); } } },
      { provide: GoogleService, useValue: { status: () => of({ data: [], meta: { redirect_uri: '', oauth_configured: true } }) } },
    ],
  });
  const fixture = TestBed.createComponent(GoogleConnectPanel);
  fixture.detectChanges();
  expect((fixture.nativeElement as HTMLElement).querySelector('[role=status]')?.textContent).toContain(en.google.errors.consent_denied);
  expect(calls).toEqual([[[], { queryParams: { connected: null, missing: null, google_error: null }, queryParamsHandling: 'merge', replaceUrl: true }]]);
});
