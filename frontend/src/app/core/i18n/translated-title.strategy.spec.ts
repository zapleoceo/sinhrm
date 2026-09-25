import { TestBed } from '@angular/core/testing';
import { Title } from '@angular/platform-browser';
import { RouterStateSnapshot } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { BehaviorSubject, map } from 'rxjs';
import { TranslatedTitleStrategy, formatTitle } from './translated-title.strategy';

describe('formatTitle', () => {
  it('prefixes the product name', () => {
    expect(formatTitle('Кандидати')).toBe('SinHRM · Кандидати');
    expect(formatTitle('  ')).toBe('SinHRM');
    expect(formatTitle(null)).toBe('SinHRM');
  });
});

describe('TranslatedTitleStrategy', () => {
  it('translates the route key and follows the language', () => {
    const lang = new BehaviorSubject('uk');
    const dict: Record<string, Record<string, string>> = { uk: { 'titles.inbox': 'Вхідні' }, en: { 'titles.inbox': 'Inbox' } };
    TestBed.configureTestingModule({
      providers: [
        TranslatedTitleStrategy,
        { provide: TranslocoService, useValue: { selectTranslate: (key: string) => lang.pipe(map((l) => dict[l][key] ?? key)) } },
      ],
    });
    const strategy = TestBed.inject(TranslatedTitleStrategy);
    const title = TestBed.inject(Title);
    const build = vi.spyOn(strategy, 'buildTitle');

    build.mockReturnValue('titles.inbox');
    strategy.updateTitle({} as RouterStateSnapshot);
    expect(title.getTitle()).toBe('SinHRM · Вхідні');
    lang.next('en');
    expect(title.getTitle()).toBe('SinHRM · Inbox');

    build.mockReturnValue(undefined);
    strategy.updateTitle({} as RouterStateSnapshot);
    expect(title.getTitle()).toBe('SinHRM');
  });
});
