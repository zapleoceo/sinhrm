import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { RespondPage } from './respond.page';

async function renderWithError(status: number): Promise<HTMLElement> {
  TestBed.configureTestingModule({
    imports: [
      RespondPage,
      TranslocoTestingModule.forRoot({
        langs: { uk: { pulse: { errors: { not_found: 'Опитування не знайдено' }, my: { title: 'Мої опитування' } } } },
        translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' },
      }),
    ],
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
  });
  const fixture = TestBed.createComponent(RespondPage);
  fixture.componentRef.setInput('id', '7');
  fixture.detectChanges();
  TestBed.inject(HttpTestingController).expectOne('/api/pulse/waves/7/form').flush({ message: 'x' }, { status, statusText: 'Error' });
  fixture.detectChanges();
  await fixture.whenStable();
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('RespondPage', () => {
  it('announces «cannot answer» as an alert (data-tone=bad) and keeps the way back to my surveys', async () => {
    const el = await renderWithError(404);
    const state = el.querySelector('.state.end[data-tone="bad"]');
    expect(state?.getAttribute('role')).toBe('alert');
    expect(state?.querySelector('p')?.textContent?.trim()).toBe('Опитування не знайдено');
    expect(state?.querySelector('a')?.getAttribute('href')).toBe('/pulse');
  });
});
