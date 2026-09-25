import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../auth/auth.service';
import { CurrentUser } from '../auth/auth.model';
import { LANG_STORAGE_KEY, LanguageService } from './language.service';

describe('LanguageService', () => {
  let http: HttpTestingController;
  const active: string[] = [];
  const user = signal<CurrentUser | null>(null);
  const setLocale = vi.fn();

  function setup(): LanguageService {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: TranslocoService, useValue: { setActiveLang: (l: string) => active.push(l) } },
        { provide: AuthService, useValue: { user, isLoggedIn: () => user() !== null, setLocale } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    return TestBed.inject(LanguageService);
  }

  beforeEach(() => {
    active.length = 0;
    user.set(null);
    setLocale.mockReset();
    localStorage.clear();
  });

  it('defaults to uk for a new guest', () => {
    const lang = setup();
    lang.init();
    expect(lang.current()).toBe('uk');
    expect(active).toEqual(['uk']);
  });

  it('guest choice goes to localStorage', async () => {
    const lang = setup();
    await lang.use('en');
    expect(localStorage.getItem(LANG_STORAGE_KEY)).toBe('en');
    http.expectNone('/api/auth/me/locale');
  });

  it('signed-in user uses profile locale and saves via API', async () => {
    user.set({ id: 1, name: 'U', email: 'u@example.com', avatar_url: null, locale: 'ru', roles: [], status: 'active' });
    const lang = setup();
    lang.init();
    expect(lang.current()).toBe('ru');

    const saving = lang.use('en');
    http.expectOne({ method: 'PATCH', url: '/api/auth/me/locale' }).flush({});
    await saving;
    expect(setLocale).toHaveBeenCalledWith('en');
  });

  it('rolls back when the API rejects the change', async () => {
    user.set({ id: 1, name: 'U', email: 'u@example.com', avatar_url: null, locale: 'ru', roles: [], status: 'active' });
    const lang = setup();
    lang.init();

    const saving = lang.use('en');
    http.expectOne('/api/auth/me/locale').flush(null, { status: 500, statusText: 'Error' });
    await saving;
    expect(lang.current()).toBe('ru');
  });
});
