// After `npm run e2e:update`: merges the axe counts written by each test (.out/axe/*.json) into axe-baseline.json.
import { existsSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

export const AXE_BASELINE = join(__dirname, 'axe-baseline.json');
export const AXE_OUT = join(__dirname, '.out', 'axe');

export default function axeTeardown(): void {
  if (!process.env['E2E_UPDATE'] || !existsSync(AXE_OUT)) return;
  const baseline = existsSync(AXE_BASELINE) ? (JSON.parse(readFileSync(AXE_BASELINE, 'utf8')) as Record<string, Record<string, number>>) : {};
  for (const f of readdirSync(AXE_OUT)) {
    baseline[f.replace(/\.json$/, '')] = JSON.parse(readFileSync(join(AXE_OUT, f), 'utf8')) as Record<string, number>;
  }
  const sorted = Object.fromEntries(Object.entries(baseline).sort(([a], [b]) => a.localeCompare(b)));
  writeFileSync(AXE_BASELINE, JSON.stringify(sorted, null, 1) + '\n');
  rmSync(AXE_OUT, { recursive: true, force: true });
}
