// node --test scripts/docs-links-check.test.mjs — dead relative links in the repository Markdown (pure logic, no git).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { anchorsOf, baseDir, brokenLinks, checkRepo, extractLinks, inScope, slugify, stripCode } from './docs-links-check.mjs';

/** In-memory repository: { path: content }; folders are derived from the paths. */
function memFs(files) {
  const dirs = new Set();
  for (const f of Object.keys(files)) for (let d = f.split('/').slice(0, -1); d.length; d.pop()) dirs.add(d.join('/'));
  return {
    tracked: Object.keys(files),
    exists: (p) => p in files || dirs.has(p),
    isDir: (p) => dirs.has(p),
    read: (p) => files[p],
  };
}

const repo = {
  'docs/README.md': '# Документация\n',
  'docs/guides/deploy.md': '# Деплой\n## Заморозка Vercel\n## Шаги (2026-10-03)\n## Шаги (2026-10-03)\n### `ci.yml` и «Protect main»\n',
  'docs/modules/core.md': '# Ядро\n## Заголовок таблицы: сортировка и фильтр\n## Счётчики в меню (`Contracts/NavBadgeProvider`)\n',
  'docs/adr/0011-mysql-only.md': '# ADR 0011\n',
  'scripts/x.mjs': '',
};

test('slugify: кириллица, пунктуация, код и ссылки как на GitHub', () => {
  assert.equal(slugify('Заморозка Vercel'), 'заморозка-vercel');
  assert.equal(slugify('Заголовок таблицы: сортировка и фильтр'), 'заголовок-таблицы-сортировка-и-фильтр');
  assert.equal(slugify('Счётчики в меню (Contracts/NavBadgeProvider)'), 'счётчики-в-меню-contractsnavbadgeprovider');
  assert.equal(slugify('Выбор человека (person picker, 2026-09-29)'), 'выбор-человека-person-picker-2026-09-29');
  assert.equal(slugify('См. [ADR](../adr/0011.md) — «MySQL»'), 'см-adr--mysql');
  assert.equal(slugify('snake_case и ёлка'), 'snake_case-и-ёлка');
});

test('anchorsOf: дубликаты получают -1, код в заголовке без обратных кавычек, блоки кода пропущены', () => {
  const a = anchorsOf(repo['docs/guides/deploy.md'] + '```\n# не заголовок\n```\n');
  assert.ok(a.has('заморозка-vercel'));
  assert.ok(a.has('шаги-2026-10-03'));
  assert.ok(a.has('шаги-2026-10-03-1'));
  assert.ok(a.has('ciyml-и-protect-main'));
  assert.ok(!a.has('не-заголовок'));
});

test('stripCode / extractLinks: ссылки в коде не считаются, строки сохраняются, title и <...> поддержаны', () => {
  const md = 'a [x](one.md)\n`[y](code.md)`\n```\n[z](fence.md)\n```\n[w](<two words.md> "t") ![i](img.png)';
  assert.equal(stripCode(md).split('\n').length, md.split('\n').length);
  assert.deepEqual(extractLinks(md), [
    { line: 1, target: 'one.md' },
    { line: 6, target: 'two words.md' },
    { line: 6, target: 'img.png' },
  ]);
});

test('inScope и baseDir: корневые файлы, docs/**; фрагменты журнала — относительно docs/', () => {
  assert.ok(inScope('README.md') && inScope('CLAUDE.md') && inScope('AGENTS.md') && inScope('docs/guides/deploy.md'));
  assert.ok(!inScope('frontend/README.md') && !inScope('docs/assets/a.png'));
  assert.equal(baseDir('docs/worklog.d/2026-10-08-x.md'), 'docs');
  assert.equal(baseDir('docs/worklog.d/README.md'), 'docs/worklog.d');
  assert.equal(baseDir('docs/guides/deploy.md'), 'docs/guides');
});

const fs = memFs(repo);
const linkMatrix = [
  ['файл существует', 'docs/guides/x.md', '[d](deploy.md)', 0],
  ['путь вверх в другой раздел', 'docs/guides/x.md', '[c](../modules/core.md)', 0],
  ['папка', 'docs/README.md', '[adr](adr/)', 0],
  ['файл вне docs', 'docs/guides/x.md', '[s](../../scripts/x.mjs)', 0],
  ['якорь с кириллицей', 'docs/guides/x.md', '[a](../modules/core.md#заголовок-таблицы-сортировка-и-фильтр)', 0],
  ['якорь в URL-кодировке', 'docs/guides/x.md', '[a](deploy.md#%D0%B7%D0%B0%D0%BC%D0%BE%D1%80%D0%BE%D0%B7%D0%BA%D0%B0-vercel)', 0],
  ['якорь в том же файле', 'docs/guides/deploy.md', '[a](#заморозка-vercel)', 0],
  ['внешние и почта', 'docs/README.md', '[g](https://github.com) [h](http://x) [m](mailto:a@b.c)', 0],
  ['фрагмент журнала относительно docs/', 'docs/worklog.d/2026-10-08-x.md', '[c](modules/core.md)', 0],
  ['нет файла', 'docs/guides/x.md', '[n](nope.md)', 1],
  ['фрагмент журнала с ../ — мёртвая', 'docs/worklog.d/2026-10-08-x.md', '[c](../guides/deploy.md)', 1],
  ['нет якоря', 'docs/guides/x.md', '[a](deploy.md#нет-такого)', 1],
  ['нет якоря в том же файле', 'docs/guides/deploy.md', '[a](#нет)', 1],
  ['за пределы репозитория', 'README.md', '[o](../other/repo.md)', 1],
];
for (const [name, path, md, n] of linkMatrix) {
  test(`brokenLinks: ${name} → ${n ? 'FAIL' : 'PASS'}`, () => {
    const files = memFs({ ...repo, [path]: (repo[path] ?? '') + md });
    assert.equal(brokenLinks(path, files.read(path), files).length, n);
  });
}

test('brokenLinks: причина и номер строки', () => {
  assert.deepEqual(brokenLinks('docs/guides/x.md', '# T\n\n[n](nope.md)', fs), [{ line: 3, target: 'nope.md', reason: 'файл не найден' }]);
  assert.equal(brokenLinks('docs/guides/x.md', '[a](deploy.md#нет)', fs)[0].reason, 'нет заголовка с таким якорем');
});

test('checkRepo: только файлы в области проверки', () => {
  const broken = checkRepo(memFs({ ...repo, 'docs/guides/bad.md': '[n](nope.md)', 'frontend/README.md': '[n](nope.md)' }));
  assert.deepEqual(broken.map((b) => b.path), ['docs/guides/bad.md']);
});
