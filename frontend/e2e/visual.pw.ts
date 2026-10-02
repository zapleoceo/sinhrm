// Opt-in local pixel diff (npm run e2e:visual) for PRs that claim to be pixel-neutral. Not run in CI:
// a restyle changes pixels by design. Baselines live in e2e/.visual/ (git-ignored):
//   on main:       npm run e2e:visual -- --update-snapshots
//   on the branch: npm run e2e:visual        → diffs in e2e/.out/report
import { expect, test } from '@playwright/test';
import { RECORDED_AT, installMock } from './harness';
import { PAGES } from './pages.mjs';
import { settle, watchNetwork } from './steps.mjs';

for (const p of PAGES) {
  test(p.id, async ({ page, context }) => {
    await installMock(context, { guest: p.guest });
    await page.clock.setSystemTime(RECORDED_AT);
    watchNetwork(page);
    await page.goto(p.path);
    await settle(page);
    await expect(page).toHaveScreenshot(`${p.id}.png`, { fullPage: true });
  });
}
