// npm run e2e / e2e:update / e2e:visual — sets the mode env and runs Playwright with the harness config.
//   --update  rewrite inventory snapshots (__snapshots__) and axe-baseline.json from this run (review the diff!)
//   --visual  local pixel diff (e2e/visual.pw.ts, baselines in e2e/.visual/, not committed)
// Any other argument goes to Playwright (e.g. a test name filter or --project=desktop-light).
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

const args = process.argv.slice(2);
const env = { ...process.env };
if (args.includes('--update')) env.E2E_UPDATE = '1';
if (args.includes('--visual')) env.E2E_VISUAL = '1';
const rest = args.filter((a) => a !== '--update' && a !== '--visual');
const cli = createRequire(import.meta.url).resolve('@playwright/test/cli');
const r = spawnSync(process.execPath, [cli, 'test', '-c', 'e2e/playwright.config.ts', ...rest], { stdio: 'inherit', env });
process.exit(r.status ?? 1);
