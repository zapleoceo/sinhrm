import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of, throwError } from 'rxjs';
import { NotifyService } from '../../core/ui/notify.service';
import { PrivacySettingsPage } from './privacy-settings.page';
import { PrivacyService } from './privacy.service';

/**
 * Retention rule of personal data (rejected candidates anonymized N months later): off = null, on = 1..120 months.
 * A term outside the range cannot be saved; switching the rule off sends null, not the last number.
 */
function render(months: number | null) {
  const api = { settings: vi.fn(() => of({ retention_rejected_months: months })), saveSettings: vi.fn(() => of({ retention_rejected_months: months })) };
  const notify = { show: vi.fn() };
  TestBed.configureTestingModule({
    imports: [PrivacySettingsPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      { provide: PrivacyService, useValue: api },
      { provide: NotifyService, useValue: notify },
    ],
  });
  const fixture = TestBed.createComponent(PrivacySettingsPage);
  fixture.detectChanges();
  const page = fixture.componentInstance as unknown as { enabled: { set(v: boolean): void }; months: { set(v: number): void } };
  const el = fixture.nativeElement as HTMLElement;
  const button = (): HTMLButtonElement => el.querySelector('button[mat-flat-button]') as HTMLButtonElement;
  return { fixture, el, page, api, notify, button };
}

describe('PrivacySettingsPage', () => {
  it('the rule off: no term field; saving sends null', () => {
    const { el, api, button } = render(null);
    expect(el.querySelector('input[type="number"]')).toBeNull();
    button().click();
    expect(api.saveSettings).toHaveBeenCalledWith({ retention_rejected_months: null });
  });

  it('the rule on: the saved term is shown and sent back', () => {
    const { el, api, button } = render(24);
    expect(el.querySelector('input[type="number"]')).not.toBeNull();
    button().click();
    expect(api.saveSettings).toHaveBeenCalledWith({ retention_rejected_months: 24 });
  });

  it('switching the rule off sends null even after a term was typed', () => {
    const { fixture, page, api, button } = render(24);
    page.months.set(6);
    page.enabled.set(false);
    fixture.detectChanges();
    button().click();
    expect(api.saveSettings).toHaveBeenCalledWith({ retention_rejected_months: null });
  });

  it('a term outside 1..120 months cannot be saved', () => {
    const { fixture, page, button } = render(24);
    for (const months of [0, 121]) {
      page.months.set(months);
      fixture.detectChanges();
      expect(button().disabled).toBe(true);
    }
    page.months.set(120);
    fixture.detectChanges();
    expect(button().disabled).toBe(false);
  });

  it('a failed save says so and frees the button', () => {
    const { fixture, api, notify, button } = render(24);
    api.saveSettings.mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 500 })));
    button().click();
    fixture.detectChanges();
    expect(notify.show).toHaveBeenCalledWith('common.error');
    expect(button().disabled).toBe(false);
  });
});
