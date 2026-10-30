import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import type { Translation } from '@jsverse/transloco';
import { TranslocoHttpLoader } from './transloco-loader';

describe('TranslocoHttpLoader', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('revalidates the existing locale URL and returns the latest payload', () => {
    const loader = TestBed.inject(TranslocoHttpLoader);
    const refreshed: Translation = {
      appearance: {
        chooseWallpaper: 'Вибрати зображення',
        customWallpaper: 'Власне тло',
      },
    };
    let received: Translation | undefined;

    loader.getTranslation('uk').subscribe((translation) => {
      received = translation;
    });

    const request = http.expectOne({ method: 'GET', url: '/i18n/uk.json' });
    expect(request.request.headers.get('Cache-Control')).toBe('no-cache');
    request.flush(refreshed);

    expect(received).toEqual(refreshed);
    expect(received?.['appearance']).toEqual(refreshed['appearance']);
  });
});
