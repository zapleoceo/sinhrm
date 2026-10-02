import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { SafeSpeakPage } from './report.page';

const HINT = 'Code shown once after sending; lost it, send a new report.';

async function setup(): Promise<{ el: HTMLElement; detect: () => Promise<void> }> {
  TestBed.configureTestingModule({
    imports: [
      SafeSpeakPage,
      TranslocoTestingModule.forRoot({ langs: { uk: { safeSpeak: { codeHint: HINT } } }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } }),
    ],
    providers: [provideHttpClient(), provideHttpClientTesting()],
  });
  const fixture = TestBed.createComponent(SafeSpeakPage);
  const detect = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };
  await detect();
  return { el: fixture.nativeElement as HTMLElement, detect };
}

describe('SafeSpeakPage', () => {
  it('explains the access code above the code field on the "I have a code" tab', async () => {
    const { el, detect } = await setup();
    expect(el.querySelector('[data-testid="code-hint"]')).toBeNull();

    (el.querySelectorAll('mat-button-toggle button')[1] as HTMLButtonElement).click();
    await detect();

    const hint = el.querySelector('[data-testid="code-hint"]');
    expect(hint?.textContent?.trim()).toBe(HINT);
    expect(hint?.nextElementSibling?.tagName.toLowerCase()).toBe('mat-form-field');
  });
});
