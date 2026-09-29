// node --test scripts/worklog.test.mjs — pass/fail matrix for the `worklog` CI check and the assembler.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildRows, checkPr, historyRows, journalMarkdown, parseFragment } from './worklog-lib.mjs';

const OK = '---\ndate: 2026-09-29\narea: CI\n---\nЖурнал работ из фрагментов — [development.md](guides/development.md)\n';
const F = 'docs/worklog.d/2026-09-29-worklog-fragments.md';
const code = { status: 'M', path: 'backend/app/Modules/People/Foo.php' };

const matrix = [
  ['код + новый фрагмент', { changes: [code, { status: 'A', path: F }], fragments: { [F]: OK } }, true, 'fragment'],
  ['код без фрагмента', { changes: [code] }, false, 'missing'],
  ['код + метка no-worklog', { changes: [code], labels: ['preview', 'no-worklog'] }, true, 'label'],
  ['код от dependabot[bot]', { changes: [code], author: 'dependabot[bot]' }, true, 'dependabot'],
  ['только docs/', { changes: [{ status: 'M', path: 'docs/modules/ai.md' }] }, true, 'docs-or-ci-only'],
  ['только .github/', { changes: [{ status: 'M', path: '.github/workflows/ci.yml' }] }, true, 'docs-or-ci-only'],
  ['docs/ + scripts/', { changes: [{ status: 'M', path: 'docs/a.md' }, { status: 'M', path: 'scripts/x.sh' }] }, false, 'missing'],
  ['код + изменён (не добавлен) фрагмент', { changes: [code, { status: 'M', path: F }], fragments: { [F]: OK } }, false, 'missing'],
  ['код + только README фрагментов', { changes: [code, { status: 'A', path: 'docs/worklog.d/README.md' }] }, false, 'missing'],
  ['фрагмент без date', { changes: [code, { status: 'A', path: F }], fragments: { [F]: '---\narea: CI\n---\nтекст\n' } }, false, 'invalid-fragment'],
  ['фрагмент с date 2026-13-40', { changes: [{ status: 'A', path: F }], fragments: { [F]: OK.replace('2026-09-29', '2026-13-40') } }, false, 'invalid-fragment'],
  ['фрагмент с пустым текстом', { changes: [{ status: 'A', path: F }], fragments: { [F]: '---\ndate: 2026-09-29\narea: CI\n---\n\n' } }, false, 'invalid-fragment'],
  ['фрагмент из 4 строк', { changes: [{ status: 'A', path: F }], fragments: { [F]: `${OK}a\nb\nc\n` } }, false, 'invalid-fragment'],
  ['фрагмент без front matter', { changes: [{ status: 'A', path: F }], fragments: { [F]: 'просто текст\n' } }, false, 'invalid-fragment'],
  ['имя файла Fix_Bug.md', { changes: [{ status: 'A', path: 'docs/worklog.d/Fix_Bug.md' }], fragments: { 'docs/worklog.d/Fix_Bug.md': OK } }, false, 'invalid-fragment'],
  ['невалидный фрагмент + no-worklog', { changes: [{ status: 'A', path: F }], labels: ['no-worklog'], fragments: { [F]: 'x' } }, false, 'invalid-fragment'],
];

for (const [name, input, ok, reason] of matrix) {
  test(`check: ${name} → ${ok ? 'PASS' : 'FAIL'} (${reason})`, () => {
    const res = checkPr(input);
    assert.equal(res.ok, ok);
    assert.equal(res.reason, reason);
  });
}

test('parse: комментарии и кавычки в front matter, pr с #', () => {
  const f = parseFragment('2026-09-29-x.md', '---\ndate: "2026-09-29"  # дата\narea: People\npr: #92\n---\nтекст\n');
  assert.deepEqual(f.errors, []);
  assert.equal(f.meta.pr, '#92');
});

test('build: новые сверху, PR из front matter или git, экранирование |', () => {
  const rows = buildRows([
    { name: '2026-09-27-a.md', content: '---\ndate: 2026-09-27\narea: A\n---\nстарое\n' },
    { name: '2026-09-29-b.md', content: '---\ndate: 2026-09-29\narea: B\npr: 5\n---\nx | y\nвторая строка\n' },
    { name: '2026-09-28-c.md', content: '---\ndate: 2026-09-28\narea: C\n---\nсреднее\n', pr: '7' },
  ]);
  assert.deepEqual(rows, [
    '| 2026-09-29 | B: x \\| y вторая строка | #5 |',
    '| 2026-09-28 | C: среднее | #7 |',
    '| 2026-09-27 | A: старое | — |',
  ]);
});

test('journal: фрагменты сверху, затем статичная история «Ранее»; невалидный фрагмент — ошибка', () => {
  const doc = [
    '# Журнал', '## Блокеры', '| 2026-01-01 | не история | — |', '',
    '### Ранее', '| Дата | Что | PR |', '|---|---|---|', '| 2026-09-26 | Фаза 0 | #1 |', '',
  ].join('\n');
  assert.deepEqual(historyRows(doc), ['| 2026-09-26 | Фаза 0 | #1 |']);
  const md = journalMarkdown([{ name: '2026-09-29-b.md', content: OK }], doc);
  assert.deepEqual(md.split('\n').slice(2), [
    '| 2026-09-29 | CI: Журнал работ из фрагментов — [development.md](guides/development.md) | — |',
    '| 2026-09-26 | Фаза 0 | #1 |',
  ]);
  assert.throws(() => journalMarkdown([{ name: 'bad.md', content: 'x' }], doc));
});

