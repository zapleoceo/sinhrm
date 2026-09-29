#!/usr/bin/env node
// CI job `docs`: code of a backend module / frontend feature or core / the extension changed → a test in the same
// scope was added or changed. Exempt: label `no-tests-needed`, dependabot. Rules: scripts/pr-checks-lib.mjs.
//   node scripts/tests-check.mjs <base-ref>     env: PR_LABELS="a,b" PR_AUTHOR=<login>
import { gitChanges, prContextFromEnv } from './git-diff.mjs';
import { NO_TESTS_LABEL, checkTests } from './pr-checks-lib.mjs';

const changes = gitChanges(process.argv[2] || 'origin/main');
const res = checkTests({ changes, ...prContextFromEnv() });
if (res.ok) {
  console.log(`tests-check: OK (${res.reason}${res.missing.length ? `, без тестов: ${res.missing.join(', ')}` : ''})`);
} else {
  for (const h of res.hints) console.log(`::error::Код изменён, а тестов нет: ${h}`);
  console.log(`\nДобавьте или обновите тест в том же модуле. Для правок без изменения поведения ревьюер может поставить метку ${NO_TESTS_LABEL} (затем Re-run job).`);
  process.exit(1);
}
