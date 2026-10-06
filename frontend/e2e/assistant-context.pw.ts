// Browser UI proofs with deterministic assistant/API fixtures; no live provider or full-stack claim.
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type BrowserContext, type Locator, type Page, type TestInfo } from '@playwright/test';
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
    const mobileLauncher = page.locator('.assistant-launcher');
    if (page.viewportSize()!.width < 768 && await mobileLauncher.count()) {
      // The panel has a compact mobile launcher as well as the mascot menu.
      await mobileLauncher.click();
    } else {
      await page.locator('.mascot-orb').click();
      await page.getByRole('menuitem', { name: language.talk, exact: true }).click();
    }
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

interface BrowserTurn {
  messages: { role: string; content?: string | null; tool_call_id?: string; tool_calls?: { id: string }[] }[];
  page: { path: string };
}
interface TurnFixture { data: Record<string, unknown>; check?: (request: BrowserTurn) => void }
const answer = (content: string): Record<string, unknown> => ({ state: 'done', request_id: 1, assistant: { role: 'assistant', content } });
const clientCall = (id: string, name: string, args: Record<string, unknown>): Record<string, unknown> => ({
  state: 'done', request_id: 1,
  assistant: { role: 'assistant', content: null, tool_calls: [{ id, type: 'function', function: { name, arguments: JSON.stringify(args) } }] },
  client_calls: [{ id, name, arguments: args }],
});
function lastTool(request: BrowserTurn, id: string): Record<string, unknown> {
  const message = request.messages.at(-1);
  expect(message).toEqual(expect.objectContaining({ role: 'tool', tool_call_id: id }));
  return JSON.parse(message!.content!) as Record<string, unknown>;
}
const copy = {
  uk: { count: 'Доступно 7 кандидатів у тестовому наборі.', opened: 'Відкрив список кандидатів.', proposal: 'Додати тестову нотатку кандидату', written: 'Тестову нотатку додано.', history: 'Попередня нотатка вже додана; повторно нічого не змінюю.', declined: 'Дію скасовано. Нових змін немає.', refusal: 'Ця операція недоступна. Можу допомогти з дозволеними даними.', forbidden: 'Немає доступу до цих даних. Спробуйте інший дозволений запит.' },
  ru: { count: 'Доступно 7 кандидатов в тестовом наборе.', opened: 'Открыл список кандидатов.', proposal: 'Добавить тестовую заметку кандидату', written: 'Тестовая заметка добавлена.', history: 'Предыдущая заметка уже добавлена; повторно ничего не меняю.', declined: 'Действие отменено. Новых изменений нет.', refusal: 'Эта операция недоступна. Могу помочь с разрешёнными данными.', forbidden: 'Нет доступа к этим данным. Попробуйте другой разрешённый запрос.' },
  en: { count: 'There are 7 accessible candidates in the synthetic dataset.', opened: 'Opened the candidates list.', proposal: 'Add a synthetic candidate note', written: 'Synthetic note added.', history: 'The previous note is already added; I am making no further changes.', declined: 'Action cancelled. No new changes.', refusal: 'That operation is unavailable. I can help with permitted data.', forbidden: 'You do not have access to this data. Try another permitted request.' },
} as const;

async function openChat(page: Page, talk: string): Promise<Locator> {
  const panel = page.locator('.assistant-panel');
  if (!(await panel.isVisible())) {
    const launcher = page.locator('.assistant-launcher');
    if (page.viewportSize()!.width < 768 && await launcher.count()) await launcher.click();
    else {
      await page.locator('.mascot-orb').click();
      await page.getByRole('menuitem', { name: talk, exact: true }).click();
    }
  }
  await expect(panel).toBeVisible();
  await settle(page);
  return panel;
}
async function interactionHarness(page: Page, context: BrowserContext, language: typeof languages[number], fixtures: TurnFixture[], allowCandidateForbidden = false) {
  const mock = await installMock(context);
  await context.addInitScript(() => localStorage.setItem('sinhrm.assistant.enabled', '0'));
  await page.clock.setSystemTime(RECORDED_AT);
  const errors = watchErrors(page, (path, status) => (allowCandidateForbidden && path === '/api/candidates' && status === 403) || recordedStatus(path, status));
  watchNetwork(page);
  await page.route(`${E2E_ORIGIN}/api/auth/me`, async (route) => {
    expect(route.request().method()).toBe('GET');
    await route.fulfill({ status: 200, json: { ...auth, locale: language.locale } });
  });
  await page.route(`${E2E_ORIGIN}/api/assistant/status`, async (route) => {
    expect(route.request().method()).toBe('GET');
    await route.fulfill({ status: 200, json: { data: { available: true, reason: null, mcp_url: `${E2E_ORIGIN}/api/mcp` } } });
  });
  const requests: BrowserTurn[] = [];
  await page.route(`${E2E_ORIGIN}/api/assistant/turn`, async (route) => {
    expect(route.request().method()).toBe('POST');
    const request = route.request().postDataJSON() as BrowserTurn;
    requests.push(request);
    const fixture = fixtures.shift();
    expect(fixture, 'unexpected assistant turn: deterministic fixture queue exhausted').toBeDefined();
    fixture!.check?.(request);
    await route.fulfill({ status: 200, json: { data: fixture!.data } });
  });
  await page.goto('/');
  await settle(page);
  const panel = await openChat(page, language.talk);
  const send = async (text: string): Promise<void> => {
    const input = panel.locator('textarea[name=draft]');
    await expect(panel.locator('.typing')).toHaveCount(0);
    await input.fill(text);
    await input.press('Enter');
  };
  const rendered = (text: string) => expect(panel.locator('.msg:not(.user) .bubble').filter({ hasText: text }).last()).toHaveText(text);
  return { mock, errors, requests, panel, send, rendered };
}
async function captureInteraction(page: Page, panel: Locator, info: TestInfo, locale: string, state: string): Promise<void> {
  const confirmation = panel.locator('.assistant-confirm');
  const target = await confirmation.count() ? confirmation : panel.locator('.msg:not(.user) .bubble').last();
  await target.scrollIntoViewIfNeeded();
  await expect(target).toBeInViewport({ ratio: 1 });
  expect(await target.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
  await settle(page);
  const directory = join(__dirname, '.out', 'screens', info.project.name);
  mkdirSync(directory, { recursive: true });
  const name = `assistant-interaction-${locale}-${state}`;
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
    syntheticRecordedAt: RECORDED_AT.toISOString(), scenario: 'deterministic-browser-tools',
    locale, project: info.project.name, state, viewport, panel: bounds, fullPage: false,
  }, null, 2) + '\n');
}

for (const language of languages) {
  const text = copy[language.locale];
  const translation = (JSON.parse(readFileSync(join(__dirname, '..', 'public', 'i18n', `${language.locale}.json`), 'utf8')) as {
    assistant: { confirm: { run: string; cancel: string }; errors: { ai_provider: string } };
  }).assistant;
  test(`assistant browser count, SPA navigation, confirmed write and history: ${language.locale}`, async ({ page, context }, info) => {
    const write = clientCall('write-once', 'api_write', { method: 'POST', path: 'candidates/3/notes', body: { text: 'Synthetic assistant note' }, summary: text.proposal });
    const fixtures: TurnFixture[] = [
      { data: clientCall('count', 'api_get', { path: 'candidates', query: { assistant_count: 1 } }) },
      { data: answer(text.count), check: (request) => expect(lastTool(request, 'count')).toEqual({ status: 200, data: { data: [], meta: { total: 7 } } }) },
      { data: clientCall('open', 'open_page', { path: '/candidates' }) },
      { data: answer(text.opened), check: (request) => { expect(lastTool(request, 'open')).toEqual({ opened: true }); expect(request.page.path).toBe('/candidates'); } },
      { data: write },
      { data: answer(text.written), check: (request) => expect(lastTool(request, 'write-once')['status']).toBe(201) },
      { data: answer(text.history), check: (request) => {
        expect(request.messages.some((message) => message.tool_calls?.some((call) => call.id === 'write-once'))).toBe(true);
        expect(request.messages.some((message) => message.tool_call_id === 'write-once')).toBe(true);
      } },
      { data: write }, // Even the same call ID returned afresh must ask for a fresh confirmation.
      { data: answer(text.declined), check: (request) => expect(lastTool(request, 'write-once')['declined']).toBe(true) },
    ];
    let countReads = 0;
    let writes = 0;
    await page.route(`${E2E_ORIGIN}/api/candidates?assistant_count=1`, async (route) => {
      expect(route.request().method()).toBe('GET');
      countReads++;
      await route.fulfill({ status: 200, json: { data: [], meta: { total: 7 } } });
    });
    await page.route(`${E2E_ORIGIN}/api/candidates/3/notes`, async (route) => {
      expect(route.request().method()).toBe('POST');
      expect(route.request().postDataJSON()).toEqual({ text: 'Synthetic assistant note' });
      writes++;
      await route.fulfill({ status: 201, json: { data: { id: 901 } } });
    });
    const ui = await interactionHarness(page, context, language, fixtures);
    await ui.send('Count accessible candidates');
    await ui.rendered(text.count);
    expect(countReads).toBe(1);
    await captureInteraction(page, ui.panel, info, language.locale, 'useful');
    await page.locator('html').evaluate((element) => element.setAttribute('data-assistant-router-proof', 'same-document'));
    await ui.send('Open candidates');
    await expect(page).toHaveURL(`${E2E_ORIGIN}/candidates`);
    await ui.rendered(text.opened);
    await expect(page.locator('html')).toHaveAttribute('data-assistant-router-proof', 'same-document');
    await ui.send('Add a synthetic note');
    const confirmation = ui.panel.locator('.assistant-confirm');
    await expect(confirmation).toContainText(text.proposal);
    await settle(page);
    expect(ui.requests).toHaveLength(5);
    expect(writes).toBe(0);
    await captureInteraction(page, ui.panel, info, language.locale, 'confirm');
    const axe = await new AxeBuilder({ page }).include('.assistant-panel').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations).toEqual([]);
    await confirmation.getByRole('button', { name: translation.confirm.run, exact: true }).click();
    await ui.rendered(text.written);
    expect(writes).toBe(1);
    await expect(confirmation).toHaveCount(0);
    await ui.send('Review the previous action without changing anything');
    await ui.rendered(text.history);
    expect(writes).toBe(1);
    await page.reload();
    await settle(page);
    await openChat(page, language.talk);
    await ui.rendered(text.history);
    expect(ui.requests).toHaveLength(7);
    expect(writes).toBe(1);
    await ui.send('Propose that note again');
    await expect(confirmation).toContainText(text.proposal);
    await settle(page);
    expect(writes).toBe(1);
    expect(ui.requests).toHaveLength(8);
    await confirmation.getByRole('button', { name: translation.confirm.cancel, exact: true }).click();
    await ui.rendered(text.declined);
    expect(writes).toBe(1);
    await captureInteraction(page, ui.panel, info, language.locale, 'declined');
    expect(ui.requests).toHaveLength(9);
    expect(fixtures).toHaveLength(0);
    expect(ui.mock.mutations.filter((mutation) => mutation.path !== '/sanctum/csrf-cookie')).toEqual([]);
    expect(ui.mock.missing).toEqual([]);
    expect(ui.errors).toEqual([]);
  });

  test(`assistant browser forbidden tools, failure and explicit retry: ${language.locale}`, async ({ page, context }, info) => {
    const fixtures: TurnFixture[] = [
      { data: clientCall('forbidden', 'api_get', { path: 'ops/migrate' }) },
      { data: answer(text.refusal), check: (request) => expect(lastTool(request, 'forbidden')).toEqual({ error: 'forbidden_path' }) },
      { data: { state: 'failed', request_id: 2, error: 'ai_provider_http_500' } },
      { data: clientCall('denied', 'api_get', { path: 'candidates', query: { assistant_retry: 1 } }) },
      { data: answer(text.forbidden), check: (request) => expect(lastTool(request, 'denied')).toEqual({ status: 403, error: 'Synthetic access denied' }) },
      { data: clientCall('retry', 'api_get', { path: 'candidates', query: { assistant_retry: 1 } }) },
      { data: answer(text.count), check: (request) => expect(lastTool(request, 'retry')['status']).toBe(200) },
    ];
    let forbiddenRequests = 0;
    let reads = 0;
    await page.route(`${E2E_ORIGIN}/api/ops/**`, async (route) => { forbiddenRequests++; await route.fulfill({ status: 500, json: { message: 'Forbidden tool unexpectedly reached network' } }); });
    await page.route(`${E2E_ORIGIN}/api/candidates?assistant_retry=1`, async (route) => {
      expect(route.request().method()).toBe('GET');
      reads++;
      await route.fulfill(reads === 1
        ? { status: 403, json: { message: 'Synthetic access denied' } }
        : { status: 200, json: { data: [], meta: { total: 7 } } });
    });
    const ui = await interactionHarness(page, context, language, fixtures, true);
    await ui.send('Run the forbidden operation');
    await ui.rendered(text.refusal);
    expect(forbiddenRequests).toBe(0);
    await captureInteraction(page, ui.panel, info, language.locale, 'refusal');
    await ui.send('Try an allowed request');
    await expect(ui.panel.getByRole('alert')).toHaveText(translation.errors.ai_provider);
    await expect(ui.panel.locator('.typing')).toHaveCount(0);
    await expect(ui.panel.locator('textarea[name=draft]')).toBeEditable();
    await ui.send('Retry the allowed request');
    await ui.rendered(text.forbidden);
    await expect(ui.panel.getByRole('alert')).toHaveCount(0);
    expect(reads).toBe(1);
    await ui.send('Retry with the updated permitted data');
    await ui.rendered(text.count);
    expect(reads).toBe(2);
    expect(forbiddenRequests).toBe(0);
    expect(ui.requests).toHaveLength(7);
    expect(fixtures).toHaveLength(0);
    expect(ui.mock.mutations.filter((mutation) => mutation.path !== '/sanctum/csrf-cookie')).toEqual([]);
    expect(ui.mock.missing).toEqual([]);
    expect(ui.errors).toEqual([]);
  });
}
