import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Read the deployed checkout, never the workflow_run runner's GITHUB_SHA (main).
const root = fileURLToPath(new URL('../', import.meta.url));
const sha = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
if (!/^[0-9a-f]{40}$/.test(sha)) throw new Error('Invalid checkout SHA');
const metadata = JSON.stringify({ sha }) + '\n';
for (const path of ['backend/build.json', 'frontend/public/build.json']) {
  const target = new URL(`../${path}`, import.meta.url);
  mkdirSync(new URL('.', target), { recursive: true });
  writeFileSync(target, metadata);
}
console.log(`Build SHA: ${sha}`);
