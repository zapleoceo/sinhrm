import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { trustedLink } from '../src/settings';

describe('manifest least privilege', () => {
  const manifest = JSON.parse(readFileSync(path.join(__dirname, '..', 'static', 'manifest.json'), 'utf8')) as {
    host_permissions: string[];
    permissions: string[];
    content_scripts?: unknown;
    externally_connectable?: unknown;
  };

  it('has standing host access only to the SinHRM API (job sites go through activeTab on click)', () => {
    expect(manifest.host_permissions).toEqual(['https://sinhrm.vercel.app/*']);
    expect(manifest.permissions).toEqual(['activeTab', 'scripting', 'storage']);
    expect(manifest.content_scripts).toBeUndefined();
    expect(manifest.externally_connectable).toBeUndefined();
  });
});

describe('trustedLink', () => {
  const base = 'https://sinhrm.vercel.app';

  it('keeps links to the configured origin', () => {
    expect(trustedLink('https://sinhrm.vercel.app/candidates/1', base)).toBe('https://sinhrm.vercel.app/candidates/1');
    expect(trustedLink('/candidates/2', base)).toBe('https://sinhrm.vercel.app/candidates/2');
  });

  it.each([
    'https://evil.example/candidates/1',
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'http://sinhrm.vercel.app/candidates/1',
    '//evil.example/x',
    undefined,
    '',
  ])('drops %s', (link) => {
    expect(trustedLink(link, base)).toBeNull();
  });
});
