import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { MySurveysPage } from './my/my-surveys.page';
import { MyWave } from './pulse.model';

async function render(waves: MyWave[]): Promise<HTMLElement> {
  TestBed.configureTestingModule({
    imports: [
      MySurveysPage,
      TranslocoTestingModule.forRoot({
        langs: { uk: { pulse: { my: { empty: 'Немає відкритих опитувань', done: 'Відповідь надіслано', answer: 'Відповісти' } } } },
        translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' },
      }),
    ],
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
  });
  const fixture = TestBed.createComponent(MySurveysPage);
  fixture.detectChanges();
  TestBed.inject(HttpTestingController).expectOne('/api/pulse/my/waves').flush({ data: waves });
  fixture.detectChanges();
  await fixture.whenStable();
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

const wave = (id: number, responded: boolean): MyWave => ({ id, title: `Опитування ${id}`, type: 'engagement', anonymous: true, ends_at: '2026-10-20', responded });

describe('MySurveysPage', () => {
  it('a failed load stops the progress bar and shows the empty state', async () => {
    TestBed.configureTestingModule({
      imports: [MySurveysPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });
    const fixture = TestBed.createComponent(MySurveysPage);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('mat-progress-bar')).not.toBeNull();
    TestBed.inject(HttpTestingController).expectOne('/api/pulse/my/waves').flush({}, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();
    expect(el.querySelector('mat-progress-bar')).toBeNull();
    expect(el.querySelector('ul.list > li.app-empty')?.textContent?.trim()).toBe('pulse.my.empty');
  });

  it('shows the shared empty state (dashed branch) when nothing is open', async () => {
    const el = await render([]);
    const empty = el.querySelector('ul.list > li.app-empty');
    expect(empty?.textContent?.trim()).toBe('Немає відкритих опитувань');
  });

  it('marks answered surveys with a «good» pill and keeps the answer link for the rest', async () => {
    const el = await render([wave(1, true), wave(2, false)]);
    const rows = Array.from(el.querySelectorAll('ul.list > li.panel'));
    expect(rows.map((r) => r.classList.contains('answered'))).toEqual([true, false]);
    const pill = rows[0].querySelector('.app-pill');
    expect(pill?.getAttribute('data-tone')).toBe('good');
    expect(pill?.textContent?.trim()).toBe('Відповідь надіслано');
    expect(rows[1].querySelector('a')?.getAttribute('href')).toBe('/pulse/waves/2');
  });
});
