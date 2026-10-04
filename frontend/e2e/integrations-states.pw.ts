// Synthetic consent states: inspect available actions, never follow an OAuth link.
import { mkdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { GoogleStatus } from '../src/app/features/google-workspace/google.model';
import type { Integration, IntegrationLog, IntegrationsList } from '../src/app/features/integrations/integrations.model';
import { RECORDED_AT, installMock, recordedStatus, watchErrors } from './harness';
import { layoutOf } from './layout';
import { E2E_HOST } from './port.mjs';
import { settle, watchNetwork } from './steps.mjs';

interface Scenario {
  id: 'connected' | 'reconnect' | 'logs-error';
  integration: Pick<Integration, 'status' | 'last_error' | 'last_checked_at'>;
  google: GoogleStatus;
  logs: { status: number; body: { data?: IntegrationLog[]; message?: string } };
}

const readJson = (file: string): unknown => JSON.parse(readFileSync(join(__dirname, file), 'utf8'));
const scenarios = readJson('fixtures/scenarios/google-integrations.json') as Scenario[];
const original = (readJson('fixtures/integrations.json') as Record<string, { body: IntegrationsList }>)['GET /api/integrations'].body;
const i18n = readJson('../public/i18n/uk.json') as {
  integrations: { google: { scope: string }; check: { reconnect_required: string }; logs: { error: string; messages: { google_connected: string } } };
  common: { retry: string };
};
// Reuse the existing page's known findings; new states may not add axe violations.
const axeBaseline = readJson('axe-baseline.json') as Record<string, Record<string, number>>;

for (const scenario of scenarios) {
  test(`integrations-google-${scenario.id}`, async ({ page, context }, info) => {
    const mock = await installMock(context);
    const forbiddenRequests: string[] = [];
    page.on('request', (request) => {
      const url = new URL(request.url());
      if (url.pathname.startsWith('/api/google/connect') || (/^https?:$/.test(url.protocol) && url.host !== E2E_HOST)) {
        forbiddenRequests.push(request.url());
      }
    });
    const gmailLogs = '/api/integrations/google_gmail/logs';
    const overrides = new Map([
      ['/api/integrations', { status: 200, body: { ...original, data: original.data.map((item) => item.group === 'google' ? { ...item, ...scenario.integration } : item) } }],
      ['/api/google/status', { status: 200, body: scenario.google }],
      [gmailLogs, scenario.logs],
    ] as [string, { status: number; body: unknown }][]);
    // Only these synthetic GET responses override installMock; authentication and network guards remain intact.
    await context.route((url) => url.host === E2E_HOST && overrides.has(url.pathname), async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const response = overrides.get(new URL(route.request().url()).pathname)!;
      await route.fulfill({ status: response.status, json: response.body });
    });
    const errors = watchErrors(page, (path, status) => recordedStatus(path, status)
      || (scenario.id === 'logs-error' && path === gmailLogs && status === 500));
    await page.clock.setSystemTime(RECORDED_AT);
    watchNetwork(page);
    try {
      await page.goto('/admin/integrations?integration=google_gmail');
      await settle(page);
      const cards = page.locator('article.google');
      await expect(cards).toHaveCount(3);
      const actions = cards.locator('.google-connect a');
      for (const action of await actions.all()) {
        await expect(action).toHaveAttribute('href', '/api/google/connect?services=gmail,calendar,sheets');
        await expect(action).not.toHaveAttribute('aria-disabled', 'true');
      }
      await expect(page.locator('app-google-connect-panel a')).toHaveAttribute('href', '/api/google/connect?services=gmail,calendar,sheets');
      await expect(page.locator(`app-google-connect-panel li[data-state="${scenario.id === 'connected' ? 'connected' : 'error'}"]`)).toHaveCount(3);
      const gmail = page.locator('#integration-google_gmail');
      await expect(gmail).toHaveClass(/open/);
      await expect(gmail.locator('.google-connect p')).toHaveText(i18n.integrations.google.scope);
      if (scenario.id !== 'connected') {
        await expect(cards.locator('.result')).toHaveCount(3);
        for (const reason of await cards.locator('.result').all()) {
          await expect(reason).toContainText(i18n.integrations.check.reconnect_required);
        }
      } else {
        await expect(cards.locator('.result')).toHaveCount(0);
      }
      if (scenario.id === 'logs-error') {
        await expect(gmail.locator('.logs')).toContainText(i18n.integrations.logs.error);
        await expect(gmail.locator('.logs button')).toHaveText(i18n.common.retry);
      } else {
        await expect(gmail.locator('.logs')).toContainText(i18n.integrations.logs.messages.google_connected);
      }
      expect(await gmail.innerText()).not.toMatch(/reconnect_required|google_connected|integrations\.logs\.messages\./);
      if (info.project.name.startsWith('desktop')) {
        const positions = await cards.locator('.actions').evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().top));
        expect(Math.max(...positions) - Math.min(...positions), 'Google card controls align across descriptions').toBeLessThanOrEqual(2);
      }
      const layout = await layoutOf(page);
      expect.soft(layout.pageOverflow).toBe(0);
      expect.soft(layout.clipped).toEqual([]);
      const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
      const key = `integrations.${info.project.name.replace('-', '.')}`;
      const known = axeBaseline[key] ?? {};
      const regressions = axe.violations.filter((violation) => violation.nodes.length > (known[violation.id] ?? 0));
      expect.soft(regressions.map((violation) => `${violation.id}: ${violation.nodes.length}`), 'no new axe violations').toEqual([]);
      expect.soft(mock.missing).toEqual([]);
      expect.soft(mock.mutations.filter((request) => request.path !== '/sanctum/csrf-cookie')).toEqual([]);
      expect.soft(errors).toEqual([]);
      expect.soft(forbiddenRequests, 'no OAuth or external requests').toEqual([]);
    } finally {
      // Required evidence in every CI project, even when a state assertion fails.
      const directory = join(__dirname, '.out', 'screens', info.project.name);
      mkdirSync(directory, { recursive: true });
      await page.screenshot({ path: join(directory, `integrations-google-${scenario.id}.png`), fullPage: true });
    }
  });
}
