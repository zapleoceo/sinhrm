import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { extractFromDocument } from '../src/extract';
import { findTelegram, SUMMARY_MAX } from '../src/extractors/common';

const fixture = (name: string, replace: Record<string, string> = {}): Document => {
  let html = readFileSync(path.join(__dirname, 'fixtures', name), 'utf8');
  for (const [k, v] of Object.entries(replace)) html = html.replace(k, v);
  return new DOMParser().parseFromString(html, 'text/html');
};

const profileOf = (doc: Document, url: string) => {
  const r = extractFromDocument(doc, url);
  if (!r.ok) throw new Error('expected ok');
  return r.profile;
};

describe('linkedin (JSON-LD path)', () => {
  it('prefers JSON-LD Person over og/DOM and strips canonical query/hash', () => {
    const p = profileOf(fixture('linkedin.html'), 'https://www.linkedin.com/in/ivan-testenko/?x=1');
    expect(p).toEqual({
      full_name: 'Ivan Testenko',
      headline: 'Senior Test Engineer',
      location: 'Testograd, Exampleland',
      email: 'ivan.testenko@example.com',
      phone: '+380 00 000 0000',
      telegram: '@ivan_testenko',
      profile_url: 'https://www.linkedin.com/in/ivan-testenko/',
      summary: 'Invented person for unit tests.',
      source_site: 'linkedin',
    });
  });
});

describe('work.ua (og fallback, malformed JSON-LD ignored)', () => {
  it('uses og:title/og:description/og:url and DOM location + mailto/tel', () => {
    const p = profileOf(fixture('workua.html'), 'https://www.work.ua/resumes/1234567/');
    expect(p.full_name).toBe('Olena Prykladna');
    expect(p.headline).toBe('QA Engineer');
    expect(p.summary).toBe('Invented resume summary from og description.');
    expect(p.location).toBe('Primerne');
    expect(p.email).toBe('olena.prykladna@example.com');
    expect(p.phone).toBe('+380000000001');
    expect(p.telegram).toBeUndefined();
    expect(p.profile_url).toBe('https://www.work.ua/resumes/1234567/');
    expect(p.source_site).toBe('work_ua');
  });
});

describe('djinni (DOM fallback + truncation)', () => {
  it('reads DOM selectors and truncates summary to 2000 chars', () => {
    const long = 'Lorem ipsum invented text. '.repeat(200);
    const p = profileOf(
      fixture('djinni.html', { SUMMARY_PLACEHOLDER: long }),
      'https://djinni.co/q/abc123/?ref=list#contacts',
    );
    expect(p.full_name).toBe('Petro Vyhadanyi');
    expect(p.headline).toBe('Backend Developer (Invented)');
    expect(p.location).toBe('Fakeville, Exampleland');
    expect(p.summary.length).toBeLessThanOrEqual(SUMMARY_MAX);
    expect(p.summary.length).toBeGreaterThan(1900);
    expect(p.telegram).toBe('@petro_fake');
    expect(p.profile_url).toBe('https://djinni.co/q/abc123/');
    expect(p.email).toBeUndefined();
    expect(p.phone).toBeUndefined();
  });
});

describe('dou (og name, DOM rest, telegram filtering)', () => {
  it('takes 1-part og:title as name only, headline from DOM', () => {
    const p = profileOf(fixture('dou.html'), 'https://dou.ua/users/maria-testova/');
    expect(p.full_name).toBe('Maria Testova');
    expect(p.headline).toBe('Frontend Developer at Example Corp');
    expect(p.location).toBe('Testopil');
    expect(p.summary).toBe('Invented DOU profile description.');
    expect(p.telegram).toBe('@maria_testova');
    expect(p.source_site).toBe('dou');
  });
});

describe('telegram extraction', () => {
  it.each([
    ['https://t.me/some_user', '@some_user'],
    ['http://telegram.me/Some_User/', '@Some_User'],
    ['https://t.me/joinchat/AAAA', ''],
    ['https://example.com/?u=t.me/x', ''],
  ])('%s -> %s', (href, expected) => {
    const doc = new DOMParser().parseFromString(`<a href="${href}">x</a>`, 'text/html');
    expect(findTelegram(doc)).toBe(expected);
  });
});

describe('unsupported page', () => {
  it('returns unsupported_site', () => {
    const doc = new DOMParser().parseFromString('<h1>x</h1>', 'text/html');
    expect(extractFromDocument(doc, 'https://example.com/in/x')).toEqual({ ok: false, error: 'unsupported_site' });
  });
});

describe('robota.ua (DOM, uncalibrated selectors)', () => {
  it('reads data-id fields, contacts and canonical', () => {
    const p = profileOf(fixture('robotaua.html'), 'https://robota.ua/ua/candidates/12345678#x');
    expect(p.full_name).toBe('Mykola Vyhadanenko');
    expect(p.headline).toBe('Invented Sales Manager');
    expect(p.location).toBe('Testopil');
    expect(p.summary).toBe('Invented experience: 3 years in fictional company.');
    expect(p.email).toBe('mykola.fake@example.com');
    expect(p.phone).toBe('+380000000002');
    expect(p.profile_url).toBe('https://robota.ua/candidates/12345678');
    expect(p.source_site).toBe('robota_ua');
  });

  it('tolerates a sparse page (only h1): other fields empty', () => {
    const doc = new DOMParser().parseFromString('<html><body><h1>Test Person</h1></body></html>', 'text/html');
    const p = profileOf(doc, 'https://robota.ua/cv/abc');
    expect(p.full_name).toBe('Test Person');
    expect(p.headline).toBe('');
    expect(p.location).toBe('');
    expect(p.email).toBeUndefined();
  });
});
