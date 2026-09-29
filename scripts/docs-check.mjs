#!/usr/bin/env node
// CI job `docs`: module code changed → its docs/modules/<module>.md changed for real (not a whitespace edit);
// migrations/routes/config/bootstrap changed → some docs/ page changed for real. Rules: scripts/pr-checks-lib.mjs.
//   node scripts/docs-check.mjs <base-ref>
import { gitChanges, gitDiffIgnoringWhitespace } from './git-diff.mjs';
import { MIN_DOC_LINE_CHARS, checkDocs, hasSubstantiveAddition, requiredModuleDocs } from './pr-checks-lib.mjs';

const base = process.argv[2] || 'origin/main';
const changes = gitChanges(base);
const cache = new Map();
const isSubstantive = (path) => {
  if (!cache.has(path)) cache.set(path, hasSubstantiveAddition(gitDiffIgnoringWhitespace(base, path)));
  return cache.get(path);
};

const res = checkDocs({ changes, isSubstantive });
if (res.ok) {
  const docs = requiredModuleDocs(changes);
  console.log(`docs-check: OK (${docs.length ? docs.join(', ') : 'нет изменённых модулей'})`);
} else {
  for (const d of res.missing) console.log(`::error::Код модуля изменён, а ${d} нет (или страницы не существует). Обновите документацию.`);
  for (const d of res.weak) {
    console.log(`::error::${d} изменён формально: нужна хотя бы одна добавленная строка не короче ${MIN_DOC_LINE_CHARS} букв и цифр (пробелы, пустые строки, точки и разделители таблиц не считаются).`);
  }
  if (res.crossCutting) {
    console.log('::error::Изменены миграции/routes/config/bootstrap — обновите содержательно любую страницу в docs/modules, architecture, guides или adr.');
  }
  process.exit(1);
}
