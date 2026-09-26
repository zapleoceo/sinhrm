import { describe, expect, it } from 'vitest';
import { detectSite } from '../src/detect';

describe('detectSite', () => {
  it.each([
    ['https://www.linkedin.com/in/ivan-testenko/', 'linkedin'],
    ['https://www.linkedin.com/in/ivan-testenko?trk=x', 'linkedin'],
    ['https://www.work.ua/resumes/1234567/', 'work_ua'],
    ['https://www.work.ua/ru/resumes/1234567/', 'work_ua'],
    ['https://djinni.co/q/abc123/', 'djinni'],
    ['https://djinni.co/q/abc123/some-slug/', 'djinni'],
    ['https://dou.ua/users/maria-testova/', 'dou'],
    ['https://www.dou.ua/users/maria-testova/', 'dou'],
  ])('%s -> %s', (url, site) => {
    expect(detectSite(url)).toBe(site);
  });

  it.each([
    'https://www.linkedin.com/feed/',
    'https://www.linkedin.com/in/x/details/experience/',
    'http://www.linkedin.com/in/x/',
    'https://www.work.ua/jobs/123/',
    'https://djinni.co/jobs/',
    'https://dou.ua/lenta/',
    'https://evil.example.com/in/x',
    'not a url',
  ])('%s -> null', (url) => {
    expect(detectSite(url)).toBeNull();
  });
});
