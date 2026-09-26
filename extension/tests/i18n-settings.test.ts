import { describe, expect, it } from 'vitest';
import { dictionaries, pickLang, t } from '../src/i18n';
import { normalizeBaseUrl } from '../src/settings';

describe('i18n', () => {
  it('uk, ru and en dictionaries have identical keys and no empty values', () => {
    const keys = Object.keys(dictionaries.en).sort();
    for (const lang of ['uk', 'ru'] as const) {
      expect(Object.keys(dictionaries[lang]).sort()).toEqual(keys);
    }
    for (const dict of Object.values(dictionaries)) {
      for (const v of Object.values(dict)) expect(v.trim()).not.toBe('');
    }
  });

  it('pickLang falls back to en', () => {
    expect(pickLang('uk')).toBe('uk');
    expect(pickLang('ru-RU')).toBe('ru');
    expect(pickLang('uk_UA')).toBe('uk');
    expect(pickLang('de')).toBe('en');
    expect(pickLang(undefined)).toBe('en');
  });

  it('t() translates by language', () => {
    expect(t('add', 'en')).toBe('Add to SinHRM');
    expect(t('add', 'uk')).toBe('Додати в SinHRM');
  });
});

describe('normalizeBaseUrl', () => {
  it.each([
    ['https://sinhrm.vercel.app/', 'https://sinhrm.vercel.app'],
    ['  https://hrm.example.com///  ', 'https://hrm.example.com'],
    ['https://hrm.example.com/sub/', 'https://hrm.example.com/sub'],
    ['', 'https://sinhrm.vercel.app'],
  ])('%s -> %s', (input, expected) => {
    expect(normalizeBaseUrl(input)).toBe(expected);
  });

  it.each(['http://hrm.example.com', 'ftp://x', 'not a url', 'https://user:pw@hrm.example.com'])('rejects %s', (input) => {
    expect(normalizeBaseUrl(input)).toBeNull();
  });
});
