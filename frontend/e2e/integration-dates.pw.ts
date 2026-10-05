// Synthetic read-only locale/timezone evidence; never follow OAuth or change a user's timezone.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import type { CurrentUser, AppLang } from '../src/app/core/auth/auth.model';
import type { IntegrationLog, IntegrationsList } from '../src/app/features/integrations/integrations.model';
import { RECORDED_AT, installMock, recordedStatus, watchErrors } from './harness';
import { E2E_HOST } from './port.mjs';
import { settle, watchNetwork } from './steps.mjs';

const read = (file: string): unknown => JSON.parse(readFileSync(join(__dirname, file), 'utf8'));
const auth = (read('fixtures/auth.json') as Record<string, { body: CurrentUser }>)['GET /api/auth/me'].body;
const original = (read('fixtures/integrations.json') as Record<string, { body: IntegrationsList }>)['GET /api/integrations'].body;
const instants = ['2026-10-01T22:05:00Z', '2026-01-15T12:05:00Z', '2026-07-15T12:05:00Z'];
const languages = ['uk', 'ru', 'en'] as const;
const calendarDates: Record<AppLang, string[]> = {
  uk: ['01.10.26', '15.01.26', '15.07.26'],
  ru: ['01.10.2026', '15.01.2026', '15.07.2026'],
  en: ['01/10/2026', '15/01/2026', '15/07/2026'],
};
const zones = [
  { id: 'UTC', times: ['22:05', '12:05', '12:05'] },
  { id: 'Europe/Kyiv', times: ['01:05', '14:05', '15:05'] },
  { id: 'America/Los_Angeles', times: ['15:05', '04:05', '05:05'] },
];

for (const zone of zones) {
  test.describe(zone.id, () => {
    // Browser language deliberately disagrees with uk/ru UI language. Timezone is fixture-only.
    test.use({ timezoneId: zone.id, locale: 'en-US' });
    for (const lang of languages) {
      test(`integration timestamps ${lang}`, async ({ page, context }, info) => {
        const mock = await installMock(context);
        const errors = watchErrors(page, recordedStatus);
        const unexpectedRequests: string[] = [];
        page.on('request', (request) => {
          const url = new URL(request.url());
          if ((/^https?:$/.test(url.protocol) && url.host !== E2E_HOST) || url.pathname.startsWith('/api/google/connect')) {
            unexpectedRequests.push(request.url());
          }
        });
        const expected = calendarDates[lang].map((date, index) => {
          const day = zone.id === 'Europe/Kyiv' && index === 0 ? date.replace(/^01/, '02') : date;
          return `${day}, ${zone.times[index]}`;
        });
        const logs: IntegrationLog[] = instants.map((created_at, index) => ({
          id: index + 1, level: 'warning', message: 'checked', created_at, context: {},
        }));
        logs.push(
          { id: 4, level: 'warning', message: 'checked', created_at: null, context: {} },
          { id: 5, level: 'warning', message: 'checked', created_at: 'not-a-timestamp', context: {} },
        );
        const overrides = new Map<string, unknown>([
          ['/api/auth/me', { ...auth, locale: lang }],
          ['/api/integrations', { ...original, data: original.data.map((item) => item.key === 'google_gmail'
            ? { ...item, status: 'error', last_error: 'reconnect_required', last_checked_at: instants[0], fields: [] } : item) }],
          ['/api/integrations/google_gmail/logs', { data: logs }],
        ]);
        await context.route((url) => url.host === E2E_HOST && overrides.has(url.pathname), async (route) => {
          if (route.request().method() !== 'GET') return route.fallback();
          await route.fulfill({ status: 200, json: overrides.get(new URL(route.request().url()).pathname) });
        });
        await page.clock.setSystemTime(RECORDED_AT);
        watchNetwork(page);
        await page.goto('/admin/integrations');
        await settle(page);
        await expect(page.locator('html')).toHaveAttribute('lang', lang);
        expect(await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone)).toBe(zone.id);
        const card = page.locator('app-integration-card').filter({ has: page.getByRole('heading', { name: 'Gmail', exact: true }) });
        await expect(card).toHaveCount(1);
        await card.locator('button[aria-expanded]').click();
        await settle(page);
        await expect(card.locator('.result .muted')).toHaveText(`· ${expected[0]}`);
        await expect(card.locator('.logs li .muted')).toHaveText([...expected, '', '']);
        await expect(card.locator('.logs')).not.toContainText(/AM|PM/);
        await expect(card.locator('.logs')).not.toContainText('Invalid Date');
        expect(mock.missing).toEqual([]);
        expect(mock.mutations).toEqual([]);
        expect(errors).toEqual([]);
        expect(unexpectedRequests).toEqual([]);
        if (zone.id === 'Europe/Kyiv') {
          await card.scrollIntoViewIfNeeded();
          const bounds = await card.boundingBox();
          const viewport = page.viewportSize()!;
          expect(bounds).not.toBeNull();
          expect(bounds!.x).toBeGreaterThanOrEqual(0);
          expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(viewport.width);
          const directory = join(__dirname, '.out', 'screens', info.project.name);
          mkdirSync(directory, { recursive: true });
          const path = join(directory, `integration-dates-${lang}.png`);
          await page.screenshot({ path, fullPage: false });
          writeFileSync(path.replace(/\.png$/, '.json'), JSON.stringify({
            githubSha: process.env['GITHUB_SHA'] ?? null, project: info.project.name, locale: lang,
            timezone: zone.id, viewport, fullPage: false, syntheticInstants: instants,
            expected, cardBounds: bounds, capturedAt: new Date().toISOString(),
          }, null, 2) + '\n');
        }
      });
    }
  });
}
