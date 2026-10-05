// UI parity: every page and state of e2e/pages.mjs keeps its inventory, loads without errors, does not overflow,
// and does not get more axe violations than the committed baseline. docs/guides/ui-parity.md explains each check.
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { AXE_BASELINE, AXE_OUT } from './axe-teardown';
import { RECORDED_AT, installMock, recordedStatus, watchErrors } from './harness';
import { inventoryOf } from './inventory';
import { layoutOf } from './layout';
import { PAGES } from './pages.mjs';
import { runSteps, settle, watchNetwork } from './steps.mjs';

const UPDATE = !!process.env['E2E_UPDATE'];
const SCREENSHOTS = !!process.env['E2E_SCREENSHOTS'];
const SNAPSHOTS = join(__dirname, '__snapshots__');
const allowlist = JSON.parse(readFileSync(join(__dirname, 'layout-allowlist.json'), 'utf8')) as {
  pageOverflow: string[];
  clipped: Record<string, string[]>;
};
// Material's tooltip puts aria-describedby on a mat-button-toggle host (role=presentation) only on some loads:
// a real but timing-dependent finding, so it is left out of the counts (it would make the baseline flaky).
const NONDETERMINISTIC = (rule: string, html: string): boolean => rule === 'aria-prohibited-attr' && html.startsWith('<mat-button-toggle');
const axeBaseline =existsSync(AXE_BASELINE) ? (JSON.parse(readFileSync(AXE_BASELINE, 'utf8')) as Record<string, Record<string, number>>) : {};

async function expectUsersTableScrollable(page: Page, width: number, theme: string): Promise<void> {
  const metrics = await page.locator('.table-scroll').evaluate((element) => {
    const scroller = element as HTMLElement;
    const overflowX = getComputedStyle(scroller).overflowX;
    const initialScrollLeft = scroller.scrollLeft;
    scroller.scrollLeft = scroller.scrollWidth;
    const maxScrollLeft = scroller.scrollLeft;
    scroller.scrollLeft = initialScrollLeft;
    return { overflowX, scrollWidth: scroller.scrollWidth, clientWidth: scroller.clientWidth, maxScrollLeft };
  });
  expect.soft(metrics.overflowX, `Users table horizontal scroller at ${width}px (${theme})`).toBe('auto');
  expect.soft(metrics.scrollWidth, `Users table content width at ${width}px (${theme})`).toBeGreaterThan(metrics.clientWidth);
  expect.soft(metrics.maxScrollLeft, `Users table columns reachable at ${width}px (${theme})`).toBeGreaterThan(0);
}

async function expectUsersControlsReachable(page: Page, theme: string): Promise<void> {
  const scroller = page.locator('.table-scroll');
  const action = page.locator('.table-scroll td.actions button').first();
  const initialScrollLeft = await scroller.evaluate((element) => (element as HTMLElement).scrollLeft);
  try {
    await scroller.evaluate((element) => {
      const viewport = element as HTMLElement;
      viewport.scrollLeft = viewport.scrollWidth;
    });
    await expect.soft(action, `rightmost user action is reachable (${theme})`).toBeInViewport({ ratio: 1 });

    for (const [selector, label] of [['.role mat-select', 'role'], ['.branches mat-select:not([disabled])', 'branch']] as const) {
      const control = page.locator(selector).first();
      await control.scrollIntoViewIfNeeded();
      await control.click();
      const listbox = page.getByRole('listbox');
      await expect.soft(listbox, `${label} options open outside the contained scroller (${theme})`).toBeVisible();
      const bounds = await listbox.boundingBox();
      expect.soft(bounds?.x ?? -1, `${label} options stay inside the viewport (${theme})`).toBeGreaterThanOrEqual(0);
      expect.soft((bounds?.x ?? 0) + (bounds?.width ?? 0), `${label} options stay inside the viewport (${theme})`).toBeLessThanOrEqual(page.viewportSize()?.width ?? 0);
      const layout = await layoutOf(page);
      expect.soft(layout.pageOverflow, `${label} options do not cause page overflow (${theme})`).toBe(0);
      await page.keyboard.press('Escape');
    }
  } finally {
    await page.keyboard.press('Escape');
    await scroller.evaluate((element, left) => {
      (element as HTMLElement).scrollLeft = left;
    }, initialScrollLeft);
  }
}

for (const p of PAGES) {
  for (const state of [{ id: '', steps: [], only: undefined }, ...(p.states ?? [])]) {
    const name = state.id ? `${p.id}--${state.id}` : p.id;
    test(state.only ? `${name} (${state.only})` : name, async ({ page, context }, info) => {
      const [viewport, theme] = info.project.name.split('-') as ['desktop' | 'mobile', 'light' | 'dark'];
      test.skip(!!state.only && state.only !== viewport, `${name} exists on ${state.only} only`);
      const key = `${name}.${viewport}`;
      const mock = await installMock(context, { guest: p.guest });
      await page.clock.setSystemTime(RECORDED_AT);
      const errors = watchErrors(page, recordedStatus);

      watchNetwork(page);
      await page.goto(p.path);
      await settle(page);
      await runSteps(page, state.steps);

      // (a) inventory — identical across themes, so only the light run writes it.
      const inventory = await inventoryOf(page, p.volatile);
      const file = join(SNAPSHOTS, `${key}.json`);
      if (UPDATE && theme === 'light') {
        mkdirSync(SNAPSHOTS, { recursive: true });
        writeFileSync(file, JSON.stringify({ path: p.path, state: state.id || null, ...inventory }, null, 1) + '\n');
      } else {
        expect(existsSync(file), `no snapshot ${key}.json — run npm run e2e:update and review the diff`).toBe(true);
        const saved = JSON.parse(readFileSync(file, 'utf8')) as Record<string, unknown>;
        delete saved['path'];
        delete saved['state'];
        expect.soft(inventory, `inventory of ${key} changed (lost/renamed/hidden element?)`).toEqual(saved);
      }

      // (b) no missing fixtures, console errors, page errors, failed requests.
      expect.soft(mock.missing, 'GET without a fixture — re-record (npm run e2e:record)').toEqual([]);
      if (p.id === 'integrations') {
        expect(mock.requests).toContain('GET /api/integrations/itstep-directory/status');
        const googleActions = page.locator('a[role="link"]').filter({ hasText: 'Підключити Google' });
        await expect(googleActions).toHaveCount(4);
        expect(await googleActions.evaluateAll((links) => links.map((link) => ({
          href: link.getAttribute('href'),
          ariaDisabled: link.getAttribute('aria-disabled'),
          disabled: link.hasAttribute('disabled'),
        })))).toEqual(Array.from({ length: 4 }, () => ({ href: null, ariaDisabled: 'true', disabled: true })));
        if (viewport === 'mobile') await expect(page.getByRole('button', { name: 'Поговорити зі Стіком' })).toBeVisible();
        if (state.id === 'synthetic-directory-preview') {
          expect(mock.requests).toContain('GET /api/integrations/itstep-directory/synthetic-preview');
          await expect(page.getByText('Синтетичні дані — не відповідь Itstep')).toBeVisible();
        }
      }
      expect.soft(errors, 'console / page / request errors').toEqual([]);

      // (c) layout — run all pages in light theme; Users additionally checks both themes and a narrower phone width.
      if (theme === 'light' || key === 'users.mobile' || key === 'users.desktop') {
        const layout = await layoutOf(page);
        if ((theme === 'light' && !allowlist.pageOverflow.includes(key)) || key === 'users.mobile' || key === 'users.desktop') {
          expect.soft(layout.pageOverflow, `horizontal page scroll on ${key} (${theme})`).toBe(0);
        }
        if (theme === 'light') {
          const allowed = allowlist.clipped[key] ?? [];
          expect.soft(layout.clipped.filter((c) => !allowed.some((a) => c.includes(a))), `clipped elements on ${key}`).toEqual([]);
        }

        if (key === 'users.mobile') {
          await expectUsersTableScrollable(page, 390, theme);
          await expectUsersControlsReachable(page, theme);
          const originalViewport = page.viewportSize();
          if (!originalViewport) throw new Error('Users mobile viewport is required for the 375px regression check');
          try {
            await page.setViewportSize({ ...originalViewport, width: 375 });
            await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => resolve())));
            const narrowLayout = await layoutOf(page);
            expect.soft(narrowLayout.pageOverflow, `horizontal page scroll on users.mobile at 375px (${theme})`).toBe(0);
            await expectUsersTableScrollable(page, 375, theme);
            await expectUsersControlsReachable(page, `${theme}, 375px`);
            expect.soft(await inventoryOf(page, p.volatile), `inventory of users.mobile at 375px (${theme})`).toEqual(inventory);
            if (SCREENSHOTS) {
              await page.screenshot({ path: join(__dirname, '.out', 'screens', info.project.name, 'users-375.png'), fullPage: true });
            }
          } finally {
            await page.setViewportSize(originalViewport);
          }
        }
      }

      // (d) axe WCAG A/AA: per-rule node counts must not grow.
      const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']);
      for (const css of (p.volatile ?? '').split(',').map((s) => s.trim()).filter(Boolean)) builder.exclude(css);
      const axe = await builder.analyze();
      const counts: Record<string, number> = Object.fromEntries(
        axe.violations
          .map((v) => [v.id, v.nodes.filter((n) => !NONDETERMINISTIC(v.id, n.html)).length] as const)
          .filter(([, n]) => n > 0)
          .sort(([a], [b]) => a.localeCompare(b)),
      );
      const axeKey = `${key}.${theme}`;
      if (UPDATE) {
        mkdirSync(AXE_OUT, { recursive: true });
        writeFileSync(join(AXE_OUT, `${axeKey}.json`), JSON.stringify(counts));
      } else {
        const base = axeBaseline[axeKey] ?? {};
        const worse = Object.entries(counts).filter(([rule, n]) => n > (base[rule] ?? 0)).map(([rule, n]) => `${rule}: ${base[rule] ?? 0} → ${n}`);
        expect.soft(worse, `new axe violations on ${axeKey}`).toEqual([]);
      }

      // (f) screenshots for reviewers (CI artifact), never compared here.
      if (SCREENSHOTS) await page.screenshot({ path: join(__dirname, '.out', 'screens', info.project.name, `${name}.png`), fullPage: true });
    });
  }
}
