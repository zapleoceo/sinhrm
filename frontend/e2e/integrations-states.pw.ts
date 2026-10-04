// Synthetic consent states: inspect available actions, never follow an OAuth link.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
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
  integrations: { status: Record<Integration['status'], string>; google: { scope: string }; check: { reconnect_required: string }; logs: { error: string; messages: { google_connected: string } } };
  common: { retry: string };
};
// Reuse the existing page's known findings; new states may not add axe violations.
const axeBaseline = readJson('axe-baseline.json') as Record<string, Record<string, number>>;

/** Integration-only evidence: normal scrolling, sticky header and in-flow launcher in the real viewport. */
async function captureMobileViewport(page: Page, region: Locator, directory: string, name: string, scenario: string): Promise<void> {
  const topbar = page.locator('.topbar');
  const initialHeader = await topbar.boundingBox();
  expect(initialHeader).not.toBeNull();
  await region.evaluate((element, headerHeight) => window.scrollTo(0, window.scrollY + element.getBoundingClientRect().top - headerHeight - 12), initialHeader!.height);
  await settle(page);
  const header = await topbar.boundingBox();
  const content = await region.boundingBox();
  const launcher = await page.locator('.topbar .assistant-launcher').boundingBox();
  const viewport = page.viewportSize();
  expect(header).not.toBeNull();
  expect(content).not.toBeNull();
  expect(launcher).not.toBeNull();
  expect(viewport).not.toBeNull();
  expect(content!.y).toBeGreaterThanOrEqual(header!.y + header!.height);
  expect(content!.y + content!.height).toBeLessThanOrEqual(viewport!.height);
  expect(content!.x).toBeGreaterThanOrEqual(0);
  expect(content!.x + content!.width).toBeLessThanOrEqual(viewport!.width);
  expect(launcher!.width).toBeGreaterThanOrEqual(44);
  expect(launcher!.height).toBeGreaterThanOrEqual(44);
  expect(launcher!.x).toBeGreaterThanOrEqual(header!.x);
  expect(launcher!.y).toBeGreaterThanOrEqual(header!.y);
  expect(launcher!.x + launcher!.width).toBeLessThanOrEqual(header!.x + header!.width);
  expect(launcher!.y + launcher!.height).toBeLessThanOrEqual(header!.y + header!.height);
  await expect(page.locator('.mascot-stage')).toBeHidden();
  await expect(page.locator('.mascot-orb')).toHaveCount(0);
  const accounts = await region.locator('.services li').evaluateAll((nodes) => nodes.map((node) => {
    const { x, y, width, height } = node.getBoundingClientRect();
    return { x, y, width, height };
  }));
  for (const account of accounts) {
    expect(account.y).toBeGreaterThanOrEqual(header!.y + header!.height);
    expect(account.y + account.height).toBeLessThanOrEqual(viewport!.height);
  }
  mkdirSync(directory, { recursive: true });
  await page.screenshot({ path: join(directory, `${name}.png`), fullPage: false });
  writeFileSync(join(directory, `${name}.json`), JSON.stringify({ scenario, url: page.url(), viewport, recordedAt: RECORDED_AT.toISOString(), scrollY: await page.evaluate(() => window.scrollY), header, content, launcher, accounts }, null, 2) + '\n');
}

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
      // Deep links retain the complete focused header below the mobile sticky bar.
      const focusedHeader = await gmail.locator('header.head').boundingBox();
      expect(focusedHeader).not.toBeNull();
      expect(focusedHeader!.y).toBeGreaterThanOrEqual(0);
      if (info.project.name.startsWith('mobile')) {
        const topbar = await page.locator('.topbar').boundingBox();
        expect(topbar).not.toBeNull();
        expect(focusedHeader!.y).toBeGreaterThanOrEqual(topbar!.y + topbar!.height);
      }
      for (const mode of await cards.locator('.status mat-select').all()) {
        await expect(mode).toHaveText(i18n.integrations.status[scenario.integration.status]);
      }

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
      // Exactly four extra evidence images across the selected mobile theme/state pairs.
      if ((info.project.name === 'mobile-light' && scenario.id === 'connected') || (info.project.name === 'mobile-dark' && scenario.id === 'reconnect')) {
        const theme = info.project.name === 'mobile-light' ? 'light' : 'dark';
        const directory = join(__dirname, '.out', 'screens', info.project.name);
        const scroll = await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY }));
        const panel = page.locator('app-google-connect-panel .panel');
        await expect(panel.locator('.services li')).toHaveCount(3);
        await captureMobileViewport(page, panel, directory, `integration-mobile-${theme}-google-viewport`, scenario.id);
        await captureMobileViewport(page, gmail.locator('.logs'), directory, `integration-mobile-${theme}-logs-viewport`, scenario.id);
        await page.evaluate(({ x, y }) => window.scrollTo(x, y), scroll);
        await settle(page);
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
      const writes = mock.mutations.filter((request) => request.path !== '/sanctum/csrf-cookie');
      // The intentional 500 is reported once by serverErrorInterceptor; no business/OAuth writes are allowed.
      expect.soft(writes).toEqual(scenario.id === 'logs-error' ? [{
        method: 'POST', path: '/api/errors/client',
        body: { kind: 'HTTP 500', message: `GET ${gmailLogs}`, location: gmailLogs, route: '/admin/integrations' },
      }] : []);
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
