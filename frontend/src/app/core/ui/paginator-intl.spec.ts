import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { EnvironmentInjector, createEnvironmentInjector, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatPaginator, MatPaginatorIntl } from '@angular/material/paginator';
import { TRANSLOCO_LOADER, Translation, TranslocoLoader, provideTransloco } from '@jsverse/transloco';
import { Observable, Subject } from 'rxjs';
import { AppLang } from '../auth/auth.model';
import { AuthService } from '../auth/auth.service';
import { LanguageService } from '../i18n/language.service';
import { AppPaginatorIntl, provideAppPaginator } from './paginator-intl';

const LABELS = {
  uk: { itemsPerPage: 'На сторінці:', firstPage: 'Перша сторінка', previousPage: 'Попередня сторінка', nextPage: 'Наступна сторінка', lastPage: 'Остання сторінка', rangeOf: 'із' },
  ru: { itemsPerPage: 'На странице:', firstPage: 'Первая страница', previousPage: 'Предыдущая страница', nextPage: 'Следующая страница', lastPage: 'Последняя страница', rangeOf: 'из' },
  en: { itemsPerPage: 'Items per page:', firstPage: 'First page', previousPage: 'Previous page', nextPage: 'Next page', lastPage: 'Last page', rangeOf: 'of' },
};

class DeferredLoader implements TranslocoLoader {
  readonly requests = new Map<string, Subject<Translation>>();

  getTranslation(lang: string): Observable<Translation> {
    const response = new Subject<Translation>();
    this.requests.set(lang, response);
    return response;
  }

  reply(lang: AppLang): void {
    const response = this.requests.get(lang);
    if (!response) throw new Error(`Dictionary ${lang} was not requested`);
    response.next({ common: { paginator: LABELS[lang] } });
    response.complete();
  }
}

function setup(): { intl: MatPaginatorIntl; language: LanguageService; loader: DeferredLoader } {
  const loader = new DeferredLoader();
  TestBed.configureTestingModule({
    imports: [MatPaginator],
    providers: [
      provideHttpClient(), provideHttpClientTesting(),
      provideTransloco({ config: { availableLangs: ['uk', 'ru', 'en'], defaultLang: 'uk', fallbackLang: 'uk', reRenderOnLangChange: true, prodMode: true } }),
      { provide: TRANSLOCO_LOADER, useValue: loader },
      { provide: AuthService, useValue: { user: signal(null), isLoggedIn: () => false } },
      provideAppPaginator(),
    ],
  });
  const intl = TestBed.inject(MatPaginatorIntl);
  const language = TestBed.inject(LanguageService);
  language.init();
  return { intl, language, loader };
}

function expectLabels(intl: MatPaginatorIntl, lang: AppLang): void {
  const labels = LABELS[lang];
  expect(intl.itemsPerPageLabel).toBe(labels.itemsPerPage);
  expect(intl.firstPageLabel).toBe(labels.firstPage);
  expect(intl.previousPageLabel).toBe(labels.previousPage);
  expect(intl.nextPageLabel).toBe(labels.nextPage);
  expect(intl.lastPageLabel).toBe(labels.lastPage);
  expect(intl.getRangeLabel(0, 30, 140)).toBe(`1–30 ${labels.rangeOf} 140`);
}

describe('AppPaginatorIntl', () => {
  beforeEach(() => localStorage.clear());

  it.each(['uk', 'ru', 'en'] as const)('provides every Material paginator label and range in %s', async (lang) => {
    const { intl, language, loader } = setup();
    expect(intl).toBeInstanceOf(AppPaginatorIntl);
    expect(TestBed.inject(MatPaginatorIntl)).toBe(intl);
    await language.use(lang);
    loader.reply(lang);
    expectLabels(intl, lang);
  });

  it.each([
    { page: 0, size: 30, total: 0, expected: '0 із 0' },
    { page: 0, size: 0, total: 140, expected: '0 із 140' },
    { page: 0, size: 30, total: -1, expected: '0 із 0' },
    { page: 0, size: 30, total: 140, expected: '1–30 із 140' },
    { page: 1, size: 30, total: 140, expected: '31–60 із 140' },
    { page: 4, size: 30, total: 140, expected: '121–140 із 140' },
    { page: 1, size: 30, total: 60, expected: '31–60 із 60' },
    { page: -1, size: 30, total: 140, expected: '1–30 із 140' },
    { page: 4, size: 30, total: 20, expected: '1–20 із 20' },
  ])('formats page $page / size $size / total $total safely', ({ page, size, total, expected }) => {
    const { intl, loader } = setup();
    loader.reply('uk');
    expect(intl.getRangeLabel(page, size, total)).toBe(expected);
  });

  it('waits for language loading and ignores a late dictionary from an abandoned switch', async () => {
    const { intl, language, loader } = setup();
    const changes = vi.fn();
    intl.changes.subscribe(changes);
    loader.reply('uk');
    expectLabels(intl, 'uk');
    await language.use('ru');
    expectLabels(intl, 'uk');
    await language.use('en');
    loader.reply('ru');
    expectLabels(intl, 'uk');
    loader.reply('en');
    expectLabels(intl, 'en');
    expect(changes).toHaveBeenCalledTimes(2);
    await language.use('uk');
    expectLabels(intl, 'uk');
    expect(changes).toHaveBeenCalledTimes(3);
  });

  it('updates the rendered paginator range and all four accessible navigation labels at runtime', async () => {
    const { language, loader } = setup();
    const fixture = TestBed.createComponent(MatPaginator);
    fixture.componentRef.setInput('length', 95);
    fixture.componentRef.setInput('pageSize', 30);
    fixture.componentRef.setInput('showFirstLastButtons', true);
    fixture.detectChanges();
    loader.reply('uk');
    await fixture.whenStable();
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const labels = (): (string | null)[] => Array.from(element.querySelectorAll('button')).map((button) => button.getAttribute('aria-label'));
    expect(labels()).toEqual([LABELS.uk.firstPage, LABELS.uk.previousPage, LABELS.uk.nextPage, LABELS.uk.lastPage]);
    expect(element.querySelector('.mat-mdc-paginator-range-label')?.textContent?.trim()).toBe('1–30 із 95');
    expect(element.querySelector('.mat-mdc-paginator-page-size-label')?.textContent?.trim()).toBe(LABELS.uk.itemsPerPage);
    await language.use('ru');
    loader.reply('ru');
    await fixture.whenStable();
    fixture.detectChanges();
    expect(labels()).toEqual([LABELS.ru.firstPage, LABELS.ru.previousPage, LABELS.ru.nextPage, LABELS.ru.lastPage]);
    expect(element.querySelector('.mat-mdc-paginator-range-label')?.textContent?.trim()).toBe('1–30 из 95');
  });

  it('unsubscribes on injector destruction while the surviving provider still follows the language', async () => {
    const { intl, language, loader } = setup();
    const child = createEnvironmentInjector([provideAppPaginator()], TestBed.inject(EnvironmentInjector));
    const destroyedIntl = child.get(MatPaginatorIntl);
    loader.reply('uk');
    const changes = vi.fn();
    destroyedIntl.changes.subscribe(changes);
    child.destroy();
    await language.use('ru');
    loader.reply('ru');
    expectLabels(intl, 'ru');
    expectLabels(destroyedIntl, 'uk');
    expect(changes).not.toHaveBeenCalled();
  });
});
