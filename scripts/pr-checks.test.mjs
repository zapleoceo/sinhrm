// node --test scripts/pr-checks.test.mjs — pass/fail matrix for `docs-check` and `tests-check` (pure logic, no git).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkDocs, checkTests, codeScope, hasSubstantiveAddition, requiredModuleDocs, testScope } from './pr-checks-lib.mjs';
import { gitChanges, parseNameStatus, prContextFromEnv } from './git-diff.mjs';

const M = (path) => ({ status: 'M', path });
const A = (path) => ({ status: 'A', path });
const D = (path) => ({ status: 'D', path });
const always = () => true;
const never = () => false;

test('requiredModuleDocs: модуль, фича, core, extension; kebab-case и синоним timeoff; тесты не в счёт', () => {
  assert.deepEqual(
    requiredModuleDocs([
      M('backend/app/Modules/GoogleWorkspace/Services/X.php'),
      M('backend/app/Modules/TimeOff/Http/Y.php'),
      M('backend/app/Modules/HiringRequests/Z.php'),
      M('frontend/src/app/features/mail-agent/a.ts'),
      M('frontend/src/app/core/auth/b.ts'),
      M('extension/src/popup.ts'),
      M('extension/package-lock.json'),
      M('backend/tests/Feature/People/OnlyTest.php'),
      M('frontend/src/app/features/people/x.spec.ts'),
      M('extension/tests/api.test.ts'),
    ]),
    [
      'docs/modules/core.md',
      'docs/modules/extension.md',
      'docs/modules/google-workspace.md',
      'docs/modules/hiring-requests.md',
      'docs/modules/mail-agent.md',
      'docs/modules/timeoff.md',
    ],
  );
});

const svc = M('backend/app/Modules/People/Services/EmployeeService.php');
const docsMatrix = [
  ['код + содержательная доc-правка', [svc, M('docs/modules/people.md')], always, true],
  ['код без доки', [svc], always, false],
  ['код + дока изменена только пробелами', [svc, M('docs/modules/people.md')], never, false],
  ['код + удалена дока', [svc, D('docs/modules/people.md')], always, false],
  ['только тесты и docs/', [M('backend/tests/Feature/People/X.php'), M('docs/guides/development.md')], always, true],
  ['миграция без доки', [M('backend/database/migrations/2026_09_29_x.php')], always, false],
  ['миграция + содержательная дока architecture', [M('backend/database/migrations/2026_09_29_x.php'), M('docs/architecture/overview.md')], always, true],
  ['миграция + дока только пробелами', [M('backend/database/migrations/2026_09_29_x.php'), M('docs/architecture/overview.md')], never, false],
  ['routes + worklog-фрагмент (не считается докой)', [M('backend/routes/api.php'), A('docs/worklog.d/2026-09-29-x.md')], always, false],
  ['без кода модулей', [M('scripts/x.mjs'), M('.github/workflows/ci.yml')], never, true],
];
for (const [name, changes, isSubstantive, ok] of docsMatrix) {
  test(`docs: ${name} → ${ok ? 'PASS' : 'FAIL'}`, () => assert.equal(checkDocs({ changes, isSubstantive }).ok, ok));
}

test('docs: причина — missing / weak / crossCutting', () => {
  assert.deepEqual(checkDocs({ changes: [svc], isSubstantive: always }).missing, ['docs/modules/people.md']);
  assert.deepEqual(checkDocs({ changes: [svc, M('docs/modules/people.md')], isSubstantive: never }).weak, ['docs/modules/people.md']);
  assert.equal(checkDocs({ changes: [M('backend/config/x.php')], isSubstantive: always }).crossCutting, true);
});

test('hasSubstantiveAddition: пробел, точка и короткая строка — нет; содержательная строка — да', () => {
  assert.equal(hasSubstantiveAddition('+++ b/x.md\n@@ -1 +1 @@\n+ \n'), false);
  assert.equal(hasSubstantiveAddition('+.\n+коротко\n-удалено очень длинная строка про модуль\n'), false);
  assert.equal(hasSubstantiveAddition('@@ -1 +1 @@\n+| GET /api/people/search | поиск по имени |\n'), true);
  assert.equal(hasSubstantiveAddition(''), false);
});

const testsMatrix = [
  ['сервис + тест того же модуля', [svc, A('backend/tests/Unit/People/EmployeeServiceTest.php')], {}, true, 'tests'],
  ['сервис без теста', [svc], {}, false, 'missing'],
  ['сервис + тест другого модуля', [svc, M('backend/tests/Feature/Reports/X.php')], {}, false, 'missing'],
  ['сервис без теста + метка', [svc], { labels: ['no-tests-needed'] }, true, 'label'],
  ['сервис без теста, автор dependabot', [svc], { author: 'dependabot[bot]' }, true, 'dependabot'],
  ['только модель/провайдер/контракт', [M('backend/app/Modules/People/Models/X.php'), M('backend/app/Modules/People/Providers/P.php'), M('backend/app/Modules/People/Contracts/C.php')], {}, true, 'no-code'],
  ['routes.php модуля без теста', [M('backend/app/Modules/People/routes.php')], {}, false, 'missing'],
  ['удалён сервис', [D('backend/app/Modules/People/Services/Old.php')], {}, true, 'no-code'],
  ['тест удалён вместе с правкой сервиса', [svc, D('backend/tests/Unit/People/X.php')], {}, false, 'missing'],
  ['фича фронта + spec той же фичи', [M('frontend/src/app/features/people/a.service.ts'), M('frontend/src/app/features/people/a.service.spec.ts')], {}, true, 'tests'],
  ['фича фронта без spec', [M('frontend/src/app/features/people/a.service.ts')], {}, false, 'missing'],
  ['фича фронта + spec другой фичи', [M('frontend/src/app/features/people/a.ts'), M('frontend/src/app/features/reports/b.spec.ts')], {}, false, 'missing'],
  ['модель/маршруты/типы фронта', [M('frontend/src/app/features/people/people.model.ts'), M('frontend/src/app/features/people/people.routes.ts')], {}, true, 'no-code'],
  ['core фронта без spec', [M('frontend/src/app/core/auth/auth.service.ts')], {}, false, 'missing'],
  ['расширение + тест', [M('extension/src/api.ts'), M('extension/tests/api.test.ts')], {}, true, 'tests'],
  ['расширение без теста', [M('extension/src/popup.ts')], {}, false, 'missing'],
  ['расширение: только types.ts', [M('extension/src/types.ts')], {}, true, 'no-code'],
  ['html/scss фронта без .ts', [M('frontend/src/app/features/people/a.html')], {}, true, 'no-code'],
  ['несколько модулей, тест одного', [svc, M('backend/app/Modules/Reports/Services/R.php'), M('backend/tests/Feature/People/X.php')], {}, false, 'missing'],
];
for (const [name, changes, ctx, ok, reason] of testsMatrix) {
  test(`tests: ${name} → ${ok ? 'PASS' : 'FAIL'} (${reason})`, () => {
    const res = checkTests({ changes, ...ctx });
    assert.equal(res.ok, ok);
    assert.equal(res.reason, reason);
  });
}

test('tests: подсказка называет нужную папку тестов', () => {
  const res = checkTests({ changes: [svc, M('frontend/src/app/core/a.ts'), M('extension/src/api.ts')] });
  assert.deepEqual(res.hints, [
    'backend:People → backend/tests/{Feature,Unit}/People/',
    'extension → extension/tests/',
    'frontend:core → frontend/src/app/core/**/*.spec.ts',
  ]);
});

test('codeScope / testScope', () => {
  assert.equal(codeScope('backend/app/Modules/Time/Services/T.php'), 'backend:Time');
  assert.equal(codeScope('backend/app/Modules/Time/Models/T.php'), null);
  assert.equal(codeScope('backend/app/Modules/Time/routes.php'), 'backend:Time');
  assert.equal(codeScope('README.md'), null);
  assert.equal(testScope('backend/tests/Unit/Time/TTest.php'), 'backend:Time');
  assert.equal(testScope('backend/tests/Support/Helper.php'), null);
  assert.equal(testScope('frontend/src/app/features/time/t.spec.ts'), 'frontend:features/time');
});

test('git-diff: parseNameStatus, gitChanges и prContextFromEnv без реального git', () => {
  assert.deepEqual(parseNameStatus('M\0docs/а б.md\0A\0x\ty.php\0'), [
    { status: 'M', path: 'docs/а б.md' },
    { status: 'A', path: 'x\ty.php' },
  ]);
  let args;
  const out = gitChanges('origin/main', (cmd, a) => ((args = a), 'M\0a.md\0'));
  assert.deepEqual(out, [{ status: 'M', path: 'a.md' }]);
  assert.ok(args.includes('-z') && args.includes('origin/main...HEAD') && args.includes('core.quotepath=false'));
  assert.deepEqual(prContextFromEnv({ PR_LABELS: 'a, no-tests-needed,', PR_AUTHOR: 'x' }), { labels: ['a', 'no-tests-needed'], author: 'x' });
});
