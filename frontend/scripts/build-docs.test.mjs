// npm run test:docs — checks the docs bundler: plain-language extraction, sanitization, role tags, real docs build.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildIndex, safeHref, userSections } from './build-docs.mjs';

test('keeps only plain-language sections', () => {
  const md = '# T\n## Что это и зачем\nA\n### Детали\nB\n## Как пользоваться\nC\n## Как устроено\nSECRET\n### Анонимность — простыми словами\nD\n## Ещё\nE';
  const out = userSections(md);
  assert.match(out, /A[\s\S]*B[\s\S]*C/);
  assert.match(out, /D/);
  assert.doesNotMatch(out, /SECRET|E$/);
});

test('whitelists links', () => {
  assert.equal(safeHref('javascript:alert(1)', 'modules'), null);
  assert.equal(safeHref('data:text/html,x', 'modules'), null);
  assert.equal(safeHref('//evil.example', 'modules'), null);
  assert.equal(safeHref('https://example.com', 'modules'), 'https://example.com');
  assert.equal(safeHref('pulse.md#x', 'modules'), '/docs/pulse#x');
  assert.equal(safeHref('../adr/0001-hosting-and-stack.md', 'modules'), 'https://github.com/zapleoceo/sinhrm/blob/main/docs/adr/0001-hosting-and-stack.md');
  assert.equal(safeHref('../../backend/x.php', 'modules'), null);
});

test('builds the real docs: sanitized, tagged, no internals', () => {
  const docs = buildIndex();
  assert.ok(docs.length >= 20);
  for (const d of docs) {
    assert.ok(['all', 'admin'].includes(d.audience), d.slug);
    assert.doesNotMatch(d.html, /<script|<iframe|\son\w+=|javascript:/i, d.slug);
    if (d.group !== 'guides') assert.doesNotMatch(d.html, /<h2[^>]*>Как устроено/, d.slug);
  }
  assert.equal(docs.find((d) => d.slug === 'pulse')?.audience, 'all');
  assert.equal(docs.find((d) => d.slug === 'integrations')?.audience, 'admin');
  assert.ok(docs.filter((d) => d.group === 'guides').every((d) => d.audience === 'admin'));
});
