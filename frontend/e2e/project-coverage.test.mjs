import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const PROJECTS = ['desktop-light', 'desktop-dark', 'mobile-light', 'mobile-dark'];
const FRONTEND = dirname(dirname(fileURLToPath(import.meta.url)));
const require = createRequire(import.meta.url);
const PLAYWRIGHT_CLI = require.resolve('@playwright/test/cli');

function collectTests(suites, tests = [], parentTitles = []) {
  for (const suite of suites ?? []) {
    const titles = suite.title ? [...parentTitles, suite.title] : parentTitles;
    for (const spec of suite.specs ?? []) {
      for (const projectTest of spec.tests ?? []) {
        tests.push({
          id: `${spec.file}:${spec.line}:${spec.column}:${[...titles, spec.title].join(' > ')}`,
          project: projectTest.projectName,
        });
      }
    }
    collectTests(suite.suites, tests, titles);
  }
  return tests;
}

function listTests(project) {
  const args = [PLAYWRIGHT_CLI, 'test', '-c', 'e2e/playwright.config.ts', '--list', '--reporter=json'];
  if (project) args.push(`--project=${project}`);
  const result = spawnSync(process.execPath, args, {
    cwd: FRONTEND,
    encoding: 'utf8',
    maxBuffer: 24 * 1024 * 1024,
  });
  assert.equal(result.status, 0, `Playwright test listing failed${project ? ` for ${project}` : ''}: ${result.stderr}`);
  const report = JSON.parse(result.stdout);
  return collectTests(report.suites);
}

test('four project selections exactly cover the unfiltered Playwright test identities', () => {
  const unfiltered = listTests();
  assert.ok(unfiltered.length > 0, 'the unfiltered Playwright listing must not be empty');
  const configuredProjects = [...new Set(unfiltered.map((entry) => entry.project))].sort();
  assert.deepEqual(configuredProjects, [...PROJECTS].sort(), 'the configured project matrix must remain the complete four-project grid');

  const expected = unfiltered.map((entry) => `${entry.project}\u0000${entry.id}`).sort();
  assert.equal(new Set(expected).size, expected.length, 'the unfiltered listing must not duplicate a test identity within a project');

  const selected = [];
  for (const project of PROJECTS) {
    const entries = listTests(project);
    assert.ok(entries.length > 0, `${project} selection must not be empty`);
    assert.ok(entries.every((entry) => entry.project === project), `${project} selection must contain only that project`);
    const identities = entries.map((entry) => `${entry.project}\u0000${entry.id}`);
    assert.equal(new Set(identities).size, identities.length, `${project} selection must not duplicate test identities`);
    selected.push(...identities);
  }

  assert.equal(new Set(selected).size, selected.length, 'project selections must not overlap or duplicate test identities');
  assert.deepEqual(selected.sort(), expected, 'the selected project union must equal the unfiltered test listing');
});
