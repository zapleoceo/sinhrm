// Focused UI proof of a pending assistant answer rejected after the user's access changes.
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { installMock, RECORDED_AT, recordedStatus, watchErrors } from './harness';
import { E2E_ORIGIN } from './port.mjs';
import { settle, watchNetwork } from './steps.mjs';

const auth = (JSON.parse(readFileSync(join(__dirname, 'fixtures', 'auth.json'), 'utf8')) as Record<string, { body: Record<string, unknown> }>)[
  'GET /api/auth/me'
].body;

const languages = [
  { locale: 'uk', talk: 'Поговорити', menu: 'Меню Стіка', connect: 'Підключити до Claude / MCP', inactive: 'Токена ще немає', error: 'Ваша робоча роль або доступ змінилися. Надішліть запит ще раз.' },
  { locale: 'ru', talk: 'Поговорить', menu: 'Меню Стика', connect: 'Подключить к Claude / MCP', inactive: 'Токена ещё нет', error: 'Ваша рабочая роль или доступ изменились. Отправьте запрос ещё раз.' },
  { locale: 'en', talk: 'Talk', menu: 'Stick menu', connect: 'Connect to Claude / MCP', inactive: 'No token yet', error: 'Your work role or access changed. Send the request again.' },
] as const;

for (const language of languages) {
  test(`assistant pending context changed: ${language.locale} retry guidance`, async ({ page, context }, info) => {
    const mock = await installMock(context);
    await context.addInitScript(() => localStorage.setItem('sinhrm.assistant.enabled', '0'));
    await page.clock.setSystemTime(RECORDED_AT);
    const errors = watchErrors(page, recordedStatus);
    watchNetwork(page);

    await page.route(`${E2E_ORIGIN}/api/auth/me`, async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { ...auth, locale: language.locale } });
    });
    await page.route(`${E2E_ORIGIN}/api/assistant/status`, async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { data: { available: true, reason: null, mcp_url: `${E2E_ORIGIN}/api/mcp` } } });
    });
    let turns = 0;
    let polls = 0;
    let mcpReads = 0;
    await page.route(`${E2E_ORIGIN}/api/assistant/mcp-token`, async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      mcpReads++;
      await route.fulfill({ status: 200, json: { data: { active: false, created_at: null, last_used_at: null, expires_at: null } } });
    });
    await page.route(`${E2E_ORIGIN}/api/assistant/turn`, async (route) => {
      if (route.request().method() !== 'POST') return route.fallback();
      turns++;
      expect(route.request().postDataJSON()).toEqual(expect.objectContaining({
        messages: [{ role: 'user', content: 'How many records can I access?' }],
      }));
      await route.fulfill({ status: 200, json: { data: { state: 'pending', request_id: 777 } } });
    });
    await page.route(`${E2E_ORIGIN}/api/assistant/turns/777`, async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      polls++;
      await route.fulfill({ status: 200, json: { data: { state: 'failed', request_id: 777, error: 'ai_context_changed' } } });
    });

    await page.goto('/');
    await settle(page);
    await expect(page.locator('html')).toHaveAttribute('lang', language.locale);
    await page.locator('.mascot-orb').click();
    await page.getByRole('menuitem', { name: language.talk, exact: true }).click();
    const panel = page.locator('.assistant-panel');
    await expect(panel).toBeVisible();
    await settle(page);

    const capture = async (state: string): Promise<void> => {
      const directory = join(__dirname, '.out', 'screens', info.project.name);
      mkdirSync(directory, { recursive: true });
      const name = `assistant-context-${language.locale}-${state}`;
      const bounds = await panel.boundingBox();
      const viewport = page.viewportSize()!;
      expect(bounds).not.toBeNull();
      expect(bounds!.x).toBeGreaterThanOrEqual(0);
      expect(bounds!.y).toBeGreaterThanOrEqual(0);
      expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(viewport.width);
      expect(bounds!.y + bounds!.height).toBeLessThanOrEqual(viewport.height);
      await page.screenshot({ path: join(directory, `${name}.png`), fullPage: false });
      writeFileSync(join(directory, `${name}.json`), JSON.stringify({
        captureAtISO: new Date().toISOString(), githubSha: process.env['GITHUB_SHA'] ?? null,
        syntheticRecordedAt: RECORDED_AT.toISOString(), scenario: 'pending-context-changed',
        locale: language.locale, project: info.project.name, state, viewport, panel: bounds, fullPage: false,
      }, null, 2) + '\n');
    };

    await capture('empty');
    const input = panel.locator('textarea[name=draft]');
    await input.fill('How many records can I access?');
    await input.press('Enter');
    await expect(panel.getByRole('alert')).toHaveText(language.error);
    await expect(panel.locator('.typing')).toHaveCount(0);
    await expect(input).toBeEditable();
    await settle(page);
    const errorBounds = (await panel.getByRole('alert').boundingBox())!;
    const viewport = page.viewportSize()!;
    expect(errorBounds.x).toBeGreaterThanOrEqual(0);
    expect(errorBounds.x + errorBounds.width).toBeLessThanOrEqual(viewport.width);
    expect(errorBounds.y).toBeGreaterThanOrEqual(0);
    expect(errorBounds.y + errorBounds.height).toBeLessThanOrEqual(viewport.height);
    expect(await panel.getByRole('alert').evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
    await capture('error');

    const axe = await new AxeBuilder({ page }).include('.assistant-panel').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations).toEqual([]);

    await panel.getByRole('button', { name: language.menu, exact: true }).click();
    await page.getByRole('menuitem', { name: language.connect, exact: true }).click();
    await expect(panel.locator('.status')).toHaveText(language.inactive);
    const warning = panel.getByRole('note');
    await warning.scrollIntoViewIfNeeded();
    await expect(warning).toBeInViewport({ ratio: 1 });
    expect(await warning.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
    await settle(page);
    await capture('mcp-privacy');
    const mcpAxe = await new AxeBuilder({ page }).include('.assistant-panel').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(mcpAxe.violations).toEqual([]);
    expect(mcpReads).toBe(1);
    expect(turns).toBe(1);
    expect(polls).toBe(1);
    expect(mock.mutations.filter((mutation) => mutation.path !== '/sanctum/csrf-cookie')).toEqual([]);
    expect(mock.missing).toEqual([]);
    expect(errors).toEqual([]);
  });
}
