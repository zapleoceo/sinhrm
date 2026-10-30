// Interaction smoke of critical flows, with assertions on the requests the UI sends (mock API logs mutations).
import { expect, test, type Locator, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { RECORDED_AT, installMock, type Mock } from './harness';
import { boardSteps } from './pages.mjs';
import { runSteps, settle, watchNetwork } from './steps.mjs';
import { E2E_ORIGIN } from './port.mjs';
import type { AssistantStatus } from '../src/app/features/assistant/assistant.model';
import type { LeaveRequest, NewLeaveRequest } from '../src/app/features/timeoff/timeoff.model';

const writes = (mock: Mock) => mock.mutations.filter((m) => m.path !== '/api/sanctum/csrf-cookie');

/** Recorded first page of /api/people: the live-filter flow answers name= queries from it. */
interface PeopleList {
  data: { full_name: string }[];
  meta: Record<string, unknown>;
}
const PEOPLE_PAGE = (JSON.parse(readFileSync(join(__dirname, 'fixtures', 'people.json'), 'utf8')) as Record<string, { body: PeopleList }>)[
  'GET /api/people?page=1&perPage=50'
].body;

async function open(page: Page, context: import('@playwright/test').BrowserContext, path: string, guest = false): Promise<Mock> {
  const mock = await installMock(context, { guest });
  await page.clock.setSystemTime(RECORDED_AT);
  watchNetwork(page);
  await page.goto(path);
  await settle(page);
  return mock;
}

async function expectModulesBadgeOutsideLabel(page: Page, mobile: boolean): Promise<void> {
  const button = page.getByRole('button', { name: 'Модулі', exact: true });
  const badge = button.locator('app-nav-badge.modules-badge .nav-badge');
  const label = button.locator('.modules-label');
  const icon = button.locator('mat-icon');
  await expect(button).toBeVisible();
  await expect(button).toHaveAccessibleName('Модулі');
  await expect(badge).toBeVisible();

  const adjacent = mobile ? icon : label;
  if (mobile) {
    await expect(label).toBeHidden();
    await expect(icon).toBeVisible();
  } else {
    await expect(label).toBeVisible();
  }
  const [adjacentBox, badgeBox] = await Promise.all([adjacent.boundingBox(), badge.boundingBox()]);
  expect(adjacentBox, mobile ? 'the mobile Modules icon has rendered bounds' : 'the desktop Modules label has rendered bounds').not.toBeNull();
  expect(badgeBox, 'the Modules badge has rendered bounds').not.toBeNull();
  const first = adjacentBox!;
  const second = badgeBox!;
  const intersects = first.x < second.x + second.width
    && first.x + first.width > second.x
    && first.y < second.y + second.height
    && first.y + first.height > second.y;
  expect(intersects, mobile ? 'the badge does not overlap the mobile menu icon' : 'the badge does not overlap the desktop Modules label').toBe(false);
}

/** Drags with real mouse moves (CDK drag-drop needs several pointer moves to start and to pick the target). */
async function drag(page: Page, from: Locator, to: Locator): Promise<void> {
  const a = (await from.boundingBox())!;
  await page.mouse.move(a.x + a.width / 2, a.y + 12);
  await page.mouse.down();
  await page.mouse.move(a.x + a.width / 2 + 10, a.y + 22, { steps: 5 });
  const b = (await to.boundingBox())!;
  const y = b.y + Math.min(b.height / 2, 60);
  await page.mouse.move(b.x + b.width / 2, y, { steps: 15 });
  await page.mouse.move(b.x + b.width / 2, y + 4, { steps: 3 });
  await page.mouse.up();
  await settle(page);
}

/** Writes after a drop: CDK reports the drop after its settle animation, so wait for the first one, then a bit more. */
async function settledWrites(page: Page, mock: Mock): Promise<Mock['mutations']> {
  await expect.poll(() => writes(mock).length, { timeout: 5_000 }).toBeGreaterThan(0);
  await settle(page, 500);
  return writes(mock);
}

/**
 * Brings the target lane into view and returns a card of ANOTHER stage lane that is fully on screen next to it
 * (the board scrolls sideways; dragging from off-screen would need CDK auto-scroll).
 */
async function cardNextTo(page: Page, target: Locator): Promise<Locator> {
  await target.scrollIntoViewIfNeeded();
  const vw = page.viewportSize()!.width;
  const cards = page.locator('section.column:not(.own) article.card');
  const tb = (await target.boundingBox())!;
  for (let i = 0; i < (await cards.count()); i++) {
    const c = cards.nth(i);
    const box = await c.boundingBox();
    const inTarget = box && box.x >= tb.x && box.x + box.width <= tb.x + tb.width;
    const cx = box ? box.x + box.width / 2 : -1;
    if (box && !inTarget && cx > 0 && cx < vw && box.y + 30 < page.viewportSize()!.height) return c;
  }
  throw new Error('no card on screen next to the target lane');
}

test.describe('desktop flows', () => {
  test('user menu opens appearance settings', async ({ page, context }, info) => {
    test.skip(info.project.name !== 'desktop-light', 'desktop-light only');
    await open(page, context, '/');
    await page.getByRole('button', { name: 'Меню користувача' }).click();
    await page.getByRole('menuitem', { name: 'Зовнішній вигляд', exact: true }).click();
    await expect.poll(() => new URL(page.url()).pathname).toBe('/settings/appearance');
    await expect.poll(() => new URL(page.url()).searchParams.get('osPrimary')).toBe('1');
    await expect(page.locator('.primary-view').getByRole('heading', { level: 1, name: 'Зовнішній вигляд' })).toBeVisible();
  });

  test('appearance preferences apply immediately and persist across reload', async ({ page, context }, info) => {
    test.skip(info.project.name !== 'desktop-light', 'desktop-light only');
    await open(page, context, '/settings/appearance');
    await expect(page.getByRole('heading', { level: 1, name: 'Зовнішній вигляд' })).toBeVisible();
    const scheme = page.getByTestId('appearance-scheme');
    const wallpaper = page.getByTestId('appearance-wallpaper');
    await expect(scheme).toHaveValue('custom');
    await expect(scheme.locator('option:checked')).toHaveText('Власна');
    await expect(wallpaper).toHaveValue('mist');
    await expect(wallpaper.locator('option:checked')).toHaveText('Туман');

    // Exercise actual Material controls and verify the shared root contract consumed by the app styles.
    await page.getByRole('radio', { name: 'Темна', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await scheme.selectOption('sintegrum');
    await expect(page.locator('html')).toHaveAttribute('data-color-scheme', 'sintegrum');

    await page.getByRole('radio', { name: 'Фіолетовий', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-color-scheme', 'custom');
    await expect(page.locator('html')).toHaveAttribute('data-accent', 'violet');
    await page.getByRole('radio', { name: 'Суцільний', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-interface-style', 'solid');
    await wallpaper.selectOption('dusk');
    await expect(page.locator('html')).toHaveAttribute('data-wallpaper', 'dusk');

    const transparency = page.locator('input[type="range"]');
    await transparency.focus();
    await page.keyboard.press('Home');
    for (let step = 0; step < 30; step++) await page.keyboard.press('ArrowRight');
    await expect(transparency).toHaveValue('30');
    await expect(page.locator('html')).toHaveCSS('--os-glass-opacity', '70%');
    const fastMode = page.getByRole('switch', { name: 'Швидкий режим' });
    await fastMode.click();
    await expect(page.locator('html')).toHaveAttribute('data-performance', 'fast');

    await page.reload();
    await settle(page);
    const root = page.locator('html');
    await expect(root).toHaveAttribute('data-theme', 'dark');
    await expect(root).toHaveAttribute('data-color-scheme', 'custom');
    await expect(root).toHaveAttribute('data-accent', 'violet');
    await expect(root).toHaveAttribute('data-interface-style', 'solid');
    await expect(root).toHaveAttribute('data-wallpaper', 'dusk');
    await expect(root).toHaveAttribute('data-performance', 'fast');
    await expect(root).toHaveCSS('--os-glass-opacity', '70%');
    await expect(page.locator('input[type="range"]')).toHaveValue('30');
  });

  test('custom desktop wallpaper uploads, persists, restores the preset and rejects unsupported files', async ({ page, context }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-light', 'desktop-light only');
    await open(page, context, '/settings/appearance');
    const wallpaperFile = page.getByTestId('appearance-wallpaper-file');
    const pngBase64 = await page.evaluate(() => {
      const canvas = document.createElement('canvas');
      canvas.width = 2;
      canvas.height = 2;
      const context = canvas.getContext('2d')!;
      context.fillStyle = '#626be8';
      context.fillRect(0, 0, 2, 2);
      return canvas.toDataURL('image/png').split(',')[1];
    });
    const png = Buffer.from(pngBase64, 'base64');
    await wallpaperFile.setInputFiles({ name: 'pixel.png', mimeType: 'image/png', buffer: png });
    const preview = page.getByTestId('appearance-wallpaper-preview');
    await expect(preview).toBeVisible();
    await expect(preview).toHaveJSProperty('naturalWidth', 2);
    await page.getByRole('switch', { name: 'Швидкий режим' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-performance', 'fast');
    await expect.poll(() => page.evaluate(() => localStorage.getItem('sinhrm.desktop-wallpaper'))).toMatch(/^data:image\/(?:webp|png|jpeg);base64,/);
    await testInfo.attach('custom-wallpaper-appearance.png', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    await page.goto('/');
    await settle(page);
    const wallpaperCss = () => page.locator('[data-testid="desktop-wallpaper-surface"]').evaluate((element) => getComputedStyle(element).backgroundImage);
    await expect.poll(wallpaperCss).toContain('data:image/');
    await expect(page.locator('.desktop-page')).toHaveClass(/has-user-wallpaper/);
    for (const selector of ['.desktop-heading > div', '.desktop-heading > button', '.widgets-heading', '.add-widgets button']) {
      const alpha = await page.locator(selector).evaluate((element) => {
        const color = getComputedStyle(element).backgroundColor;
        const rgba = color.match(/^rgba\([^,]+,[^,]+,[^,]+,\s*([\d.]+)\)$/);
        return rgba ? Number(rgba[1]) : 1;
      });
      expect(alpha, `${selector} stays opaque over the uploaded wallpaper`).toBe(1);
    }
    await expect(page.locator('html')).toHaveAttribute('data-performance', 'fast');
    await testInfo.attach('custom-wallpaper-desktop.png', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    await page.reload();
    await settle(page);
    await expect.poll(wallpaperCss).toContain('data:image/');

    await page.goto('/settings/appearance');
    await expect(page.getByTestId('appearance-wallpaper-preview')).toBeVisible();
    await page.getByTestId('appearance-wallpaper-remove').click();
    await expect(page.getByTestId('appearance-wallpaper-preview')).toBeHidden();
    await expect.poll(() => page.evaluate(() => localStorage.getItem('sinhrm.desktop-wallpaper'))).toBeNull();

    await wallpaperFile.setInputFiles({ name: 'not-an-image.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>') });
    await expect(page.getByRole('alert')).toContainText('Підтримуються лише зображення JPEG, PNG і WebP.');
    await expect.poll(() => page.evaluate(() => localStorage.getItem('sinhrm.desktop-wallpaper'))).toBeNull();
    await testInfo.attach('custom-wallpaper-appearance-error.png', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
  });

  // eslint-disable-next-line no-empty-pattern -- Playwright fixtures need an object pattern
  test.beforeEach(({}, info) => test.skip(info.project.name !== 'desktop-light', 'desktop-light only'));

  async function personalBoard(page: Page, context: import('@playwright/test').BrowserContext): Promise<Mock> {
    const mock = await open(page, context, '/candidates');
    await runSteps(page, boardSteps);
    await expect(page.locator('section.column').first()).toBeVisible();
    return mock;
  }

  test('routed windows drag and resize inside the workspace without navigating or reloading pages', async ({ page, context }) => {
    const requests: string[] = [];
    page.on('request', (request) => {
      const url = new URL(request.url());
      if (url.pathname === '/api/tasks' || url.pathname === '/api/people') requests.push(url.pathname);
    });
    await open(page, context, '/');
    const dock = page.getByRole('navigation', { name: 'Вікна робочого простору' });
    await dock.getByRole('button', { name: 'Мої задачі', exact: true }).click();
    await expect(page.getByTestId('os-window-os1').getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await dock.getByRole('button', { name: 'Співробітники', exact: true }).click();
    const article = page.getByTestId('os-window-os2');
    await expect(article.getByRole('heading', { level: 1, name: 'Співробітники' })).toBeVisible();
    await settle(page);

    const urlBefore = page.url();
    const requestsBefore = requests.length;
    await page.evaluate(() => {
      const target = window as Window & { __osWindowHistoryWrites?: number };
      target.__osWindowHistoryWrites = 0;
      const push = window.history.pushState.bind(window.history);
      const replace = window.history.replaceState.bind(window.history);
      window.history.pushState = (...args: Parameters<History['pushState']>) => {
        target.__osWindowHistoryWrites = (target.__osWindowHistoryWrites ?? 0) + 1;
        push(...args);
      };
      window.history.replaceState = (...args: Parameters<History['replaceState']>) => {
        target.__osWindowHistoryWrites = (target.__osWindowHistoryWrites ?? 0) + 1;
        replace(...args);
      };
    });
    const layer = page.locator('.window-layer');
    const layerBefore = await layer.boundingBox();
    const before = await article.boundingBox();
    expect(before).not.toBeNull();
    expect(layerBefore).not.toBeNull();
    expect(layerBefore!.y).toBeGreaterThanOrEqual(56);
    expect(layerBefore!.y + layerBefore!.height).toBeLessThanOrEqual(page.viewportSize()!.height - 56);
    const title = await page.getByTestId('window-titlebar-os2').locator('h2').boundingBox();
    expect(title).not.toBeNull();
    await page.mouse.move(title!.x + title!.width / 2, title!.y + title!.height / 2);
    await page.mouse.down();
    await page.mouse.move(title!.x + title!.width / 2 + 24, title!.y + title!.height / 2 + 24, { steps: 4 });
    await page.mouse.up();

    const moved = await article.boundingBox();
    expect(moved).not.toBeNull();
    expect(moved!.x).not.toBe(before!.x);
    expect(moved!.y).not.toBe(before!.y);
    expect(moved!.x).toBeGreaterThanOrEqual(layerBefore!.x);
    expect(moved!.y).toBeGreaterThanOrEqual(layerBefore!.y);
    expect(moved!.x + moved!.width).toBeLessThanOrEqual(layerBefore!.x + layerBefore!.width);
    expect(moved!.y + moved!.height).toBeLessThanOrEqual(layerBefore!.y + layerBefore!.height);

    const resize = await page.getByTestId('window-resize-os2').boundingBox();
    expect(resize).not.toBeNull();
    const resizeBefore = await article.boundingBox();
    await page.mouse.move(resize!.x + resize!.width / 2, resize!.y + resize!.height / 2);
    await page.mouse.down();
    await page.mouse.move(resize!.x + resize!.width / 2 + 48, resize!.y + resize!.height / 2 + 40, { steps: 4 });
    await page.mouse.up();
    const resized = await article.boundingBox();
    expect(resized).not.toBeNull();
    expect(resized!.width).toBeGreaterThan(resizeBefore!.width);
    expect(resized!.height).toBeGreaterThan(resizeBefore!.height);
    expect(resized!.x + resized!.width).toBeLessThanOrEqual(layerBefore!.x + layerBefore!.width);
    expect(resized!.y + resized!.height).toBeLessThanOrEqual(layerBefore!.y + layerBefore!.height);
    const titleAfterResize = await page.getByTestId('window-titlebar-os2').locator('h2').boundingBox();
    expect(titleAfterResize).not.toBeNull();
    await page.mouse.move(titleAfterResize!.x + titleAfterResize!.width / 2, titleAfterResize!.y + titleAfterResize!.height / 2);
    await page.mouse.down();
    await page.mouse.move(titleAfterResize!.x + titleAfterResize!.width / 2 + 4000, titleAfterResize!.y + titleAfterResize!.height / 2 + 4000, { steps: 5 });
    await page.mouse.up();
    const clamped = await article.boundingBox();
    expect(clamped).not.toBeNull();
    expect(clamped!.x + clamped!.width).toBeLessThanOrEqual(layerBefore!.x + layerBefore!.width);
    expect(clamped!.y + clamped!.height).toBeLessThanOrEqual(layerBefore!.y + layerBefore!.height);
    await page.setViewportSize({ width: 800, height: 640 });
    await expect.poll(async () => {
      const currentLayer = await layer.boundingBox();
      const currentWindow = await article.boundingBox();
      return !!currentLayer && !!currentWindow
        && currentWindow.x >= currentLayer.x
        && currentWindow.y >= currentLayer.y
        && currentWindow.x + currentWindow.width <= currentLayer.x + currentLayer.width
        && currentWindow.y + currentWindow.height <= currentLayer.y + currentLayer.height;
    }).toBe(true);
    expect(page.url()).toBe(urlBefore);
    expect(await page.evaluate(() => (window as Window & { __osWindowHistoryWrites?: number }).__osWindowHistoryWrites)).toBe(0);
    expect(requests).toHaveLength(requestsBefore);
    await expect(article.getByRole('heading', { level: 1, name: 'Співробітники' })).toBeVisible();
    const firstWindow = page.getByTestId('os-window-os1');
    await expect(firstWindow.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await page.getByTestId('dock-window-os1').click();
    await expect(firstWindow).toHaveClass(/active/);
    await firstWindow.locator('.window-titlebar').getByRole('button', { name: 'Згорнути вікно', exact: true }).click();
    await expect(firstWindow).toBeHidden();
    const restore = page.getByTestId('dock-window-os1');
    await expect(restore).toBeVisible();
    await restore.click();
    await expect(firstWindow.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await expect.poll(() => page.url()).toContain('os1:window/tasks');
    expect(requests).toHaveLength(requestsBefore);
  });

  test('routed windows stay within the visible safe band while tall reports content scrolls and viewport changes', async ({ page, context }) => {
    await open(page, context, '/reports');
    await page.getByRole('navigation', { name: 'Вікна робочого простору' }).getByRole('button', { name: 'Мої задачі', exact: true }).click();
    const article = page.getByTestId('os-window-os1');
    await expect(article.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);

    const assertSafeBand = async (): Promise<void> => {
      const viewport = page.viewportSize()!;
      const layer = await page.locator('.window-layer').boundingBox();
      const windowBox = await article.boundingBox();
      expect(layer).not.toBeNull();
      expect(windowBox).not.toBeNull();
      expect(layer!.y).toBeGreaterThanOrEqual(56);
      expect(layer!.y + layer!.height).toBeLessThanOrEqual(viewport.height - 56);
      expect(windowBox!.y).toBeGreaterThanOrEqual(layer!.y);
      expect(windowBox!.y + windowBox!.height).toBeLessThanOrEqual(layer!.y + layer!.height);
    };
    await assertSafeBand();
    await page.setViewportSize({ width: 900, height: 640 });
    await expect.poll(async () => {
      const layer = await page.locator('.window-layer').boundingBox();
      return !!layer && layer.height <= 640 - 112;
    }).toBe(true);
    await assertSafeBand();
  });

  test('candidates board: drag to a stage column → exactly one POST /move with stage_id', async ({ page, context }) => {
    const mock = await personalBoard(page, context);
    // Second stage lane («Первинний контакт»): no reject reason needed, so the drop moves at once.
    const target = page.locator('section.column:not(.own)').nth(1);
    await drag(page, await cardNextTo(page, target), target.locator('.cards'));
    const sent = await settledWrites(page, mock);
    expect(sent, JSON.stringify(sent)).toHaveLength(1);
    expect(sent[0].method).toBe('POST');
    expect(sent[0].path).toMatch(/^\/api\/applications\/\d+\/move$/);
    expect(sent[0].body).toEqual(expect.objectContaining({ stage_id: expect.any(Number) }));
  });

  test('candidates board: drag into a personal column → only the filing request', async ({ page, context }) => {
    const mock = await personalBoard(page, context);
    const own = page.locator('section.column.own').first();
    const board = page.locator('.board').first();
    // The own column sits after all stage lanes, off screen: hold the card at the board's right edge so CDK
    // auto-scrolls, then drop on the column once it is in view.
    const card = page.locator('section.column:not(.own) article.card').first();
    await card.scrollIntoViewIfNeeded();
    const a = (await card.boundingBox())!;
    const bb = (await board.boundingBox())!;
    const right = Math.min(bb.x + bb.width, page.viewportSize()!.width);
    await page.mouse.move(a.x + a.width / 2, a.y + 12);
    await page.mouse.down();
    await page.mouse.move(a.x + a.width / 2 + 10, a.y + 22, { steps: 5 });
    await page.mouse.move(right - 6, a.y + 22, { steps: 10 });
    await expect.poll(async () => {
      const o = await own.boundingBox();
      return !!o && o.x + o.width <= right;
    }, { timeout: 15_000 }).toBe(true);
    const o = (await own.locator('.cards').boundingBox())!;
    await page.mouse.move(o.x + o.width / 2, o.y + Math.min(o.height / 2, 60), { steps: 10 });
    await page.mouse.move(o.x + o.width / 2, o.y + Math.min(o.height / 2, 60) + 4, { steps: 3 });
    await page.mouse.up();
    const sent = await settledWrites(page, mock);
    expect(sent, JSON.stringify(sent)).toHaveLength(1);
    expect(sent[0].method).toBe('PUT');
    expect(sent[0].path).toMatch(/^\/api\/applications\/\d+\/personal-column$/);
    expect(sent[0].body).toEqual({ column_id: expect.any(Number) });
  });

  test('vacancy form: a title enables «Створити з ШІ»', async ({ page, context }) => {
    await open(page, context, '/vacancies/create');
    const ai = page.getByRole('button', { name: 'Створити з ШІ' }).first();
    await expect(ai).toBeDisabled();
    await page.getByPlaceholder('Введіть назву вакансії').fill('Менеджер з продажу [ТЕСТ]');
    await expect(ai).toBeEnabled();
  });

  test('employee dialog: opens, shows validation errors, no horizontal scroll, sends nothing', async ({ page, context }) => {
    const mock = await open(page, context, '/people');
    await page.getByRole('button', { name: 'Додати співробітника' }).click();
    const dialog = page.getByRole('dialog', { name: 'Додати співробітника' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Зберегти' }).click();
    await expect(dialog.locator('mat-error').first()).toBeVisible();
    const overflow = await dialog.locator('mat-dialog-content, .mat-mdc-dialog-content').first().evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(overflow, 'horizontal scroll inside the dialog').toBeLessThanOrEqual(0);
    expect(writes(mock).filter((m) => m.method === 'POST')).toEqual([]);
  });

  test('person picker: search after the debounce, the pick shows the name', async ({ page, context }) => {
    await open(page, context, '/perform/one-on-ones');
    const box = page.getByRole('combobox', { name: 'Співробітник' });
    const searched = page.waitForRequest((r) => r.url().includes('/api/people/search'));
    await box.fill('Ко');
    const req = await searched;
    expect(new URL(req.url()).searchParams.get('q')).toBe('Ко');
    const option = page.getByRole('option').filter({ hasNotText: /символ|Завантаження/ }).first();
    await expect(option).toBeVisible();
    // The option label carries the full name (initials and position around it are styling, not checked).
    const label = (await option.getAttribute('aria-label')) ?? (await option.innerText());
    await option.click();
    await expect(box).toHaveValue(/\[ТЕСТ\]/);
    expect(label).toContain(await box.inputValue());
  });

  test('people: a header title sorts asc → desc via the URL, the filter works from the keyboard, «back» restores', async ({ page, context }) => {
    const sent: URLSearchParams[] = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if (u.pathname === '/api/people') sent.push(u.searchParams);
    });
    await open(page, context, '/people?page=2');
    const name = page.getByRole('columnheader', { name: /^ПІБ/ });
    const position = page.getByRole('columnheader', { name: /^Посада/ });
    // Default order of the API (by name) carries the arrow.
    await expect(name).toHaveAttribute('aria-sort', 'ascending');
    await expect(position).toHaveAttribute('aria-sort', 'none');

    // Click on the title: ascending, page back to 1, sort sent to the API; second click: descending.
    await position.getByRole('button', { name: 'Посада', exact: true }).click();
    await expect(position).toHaveAttribute('aria-sort', 'ascending');
    await expect(page).toHaveURL(/[?&]sort=position&dir=asc(&|$)/);
    expect(new URL(page.url()).searchParams.has('page')).toBe(false);
    await expect.poll(() => sent.at(-1)?.get('sort')).toBe('position');
    expect(sent.at(-1)?.get('page')).toBe('1');
    await position.getByRole('button', { name: 'Посада', exact: true }).press('Enter');
    await expect(position).toHaveAttribute('aria-sort', 'descending');
    await expect.poll(() => sent.at(-1)?.get('dir')).toBe('desc');

    // Filter from the keyboard: Enter opens the dialog with the field focused, Esc closes and returns focus.
    const funnel = name.getByRole('button', { name: 'Фільтр стовпця «ПІБ»' });
    await funnel.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Фільтр «ПІБ»' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('searchbox')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(funnel).toBeFocused();

    // Enter in the field applies at once and closes → name= in the URL and the request, the funnel shows the active state.
    await page.keyboard.press('Enter');
    await dialog.getByRole('searchbox').fill('Ко');
    await page.keyboard.press('Enter');
    await expect(dialog).toBeHidden();
    await expect(page).toHaveURL(/[?&]name=%D0%9A%D0%BE/);
    await expect.poll(() => sent.at(-1)?.get('name')).toBe('Ко');
    const active = name.getByRole('button', { name: 'Фільтр стовпця «ПІБ», увімкнено' });
    await expect(active).toBeVisible();

    // «Back» undoes the filter, then the reverse sort.
    await page.goBack();
    await expect(name.getByRole('button', { name: 'Фільтр стовпця «ПІБ»', exact: true })).toBeVisible();
    await page.goBack();
    await expect(position).toHaveAttribute('aria-sort', 'ascending');
    await page.goForward();
    await page.goForward();
    await expect(active).toBeVisible();

    // Clear removes the filter from the URL.
    await active.click();
    await dialog.getByRole('button', { name: 'Очистити' }).click();
    await expect(dialog).toBeHidden();
    await expect.poll(() => new URL(page.url()).searchParams.has('name')).toBe(false);
    await expect(position).toHaveAttribute('aria-sort', 'descending');
  });

  test('people: typing in the «ПІБ» filter narrows the list without Enter — one request after the pause, the field keeps focus', async ({ page, context }) => {
    await open(page, context, '/people');
    // The recorded API answers one list for any query; here it filters by name like the real one (contains, any case).
    const all = PEOPLE_PAGE.data;
    await page.route(
      (u) => u.pathname === '/api/people' && u.searchParams.has('name'),
      (route) => {
        const name = new URL(route.request().url()).searchParams.get('name')!.toLocaleLowerCase();
        const data = all.filter((p) => p.full_name.toLocaleLowerCase().includes(name));
        return route.fulfill({ json: { ...PEOPLE_PAGE, data, meta: { ...PEOPLE_PAGE.meta, total: data.length, last_page: 1 } } });
      },
    );
    const named: string[] = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if (u.pathname === '/api/people' && u.searchParams.has('name')) named.push(u.searchParams.get('name')!);
    });
    const rows = page.locator('table.people tbody tr');
    await expect(rows).toHaveCount(all.length);
    const expected = all.filter((p) => p.full_name.toLocaleLowerCase().includes('коваленко')).length;
    expect(expected).toBeGreaterThan(0);
    expect(expected).toBeLessThan(all.length);

    await page.getByRole('button', { name: 'Фільтр стовпця «ПІБ»' }).click();
    const dialog = page.getByRole('dialog', { name: 'Фільтр «ПІБ»' });
    const field = dialog.getByRole('searchbox', { name: 'Містить (стовпець «ПІБ»)' });
    await expect(field).toBeFocused();
    await expect(dialog.getByRole('button', { name: 'Застосувати' })).toHaveCount(0);
    // fill() puts the word in with one input event, so the count below does not depend on how fast the runner
    // types; the debounce itself (letters within the pause → one write) is covered by the unit tests on fake timers.
    const request = page.waitForRequest((r) => {
      const u = new URL(r.url());
      return u.pathname === '/api/people' && u.searchParams.has('name');
    });
    await field.fill('КОВАЛЕНКО');
    await request; // no Enter: the request goes on its own after the pause

    // The list narrows, the dialog stays open with the caret in the field.
    await expect(rows).toHaveCount(expected);
    await expect(page).toHaveURL(/[?&]name=%D0%9A%D0%9E%D0%92/);
    await settle(page); // network quiet: a second request would have been sent by now
    expect(named).toEqual(['КОВАЛЕНКО']); // one request for the whole word
    await expect(dialog).toBeVisible();
    await expect(field).toBeFocused();
    await expect(field).toHaveValue('КОВАЛЕНКО');
    await expect(dialog.getByRole('status')).toHaveText(`Знайдено: ${expected}`);

    // Esc closes and keeps the filter.
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(rows).toHaveCount(expected);
    await expect(page.getByRole('button', { name: 'Фільтр стовпця «ПІБ», увімкнено' })).toBeFocused();
  });

  test('people (390 px): the header filters do not widen the page, the table scrolls inside its panel', async ({ page, context }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page, context, '/people');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBe(0);
    const funnel = page.getByRole('button', { name: 'Фільтр стовпця «ПІБ»' });
    const box = (await funnel.boundingBox())!;
    expect(box.width).toBeGreaterThanOrEqual(32);
    await funnel.click();
    const dialog = page.getByRole('dialog', { name: 'Фільтр «ПІБ»' });
    const d = (await dialog.boundingBox())!;
    expect(d.x).toBeGreaterThanOrEqual(0);
    expect(d.x + d.width).toBeLessThanOrEqual(390);
  });

  test('assets: page-side sort without a request, the status filter from the keyboard goes to the API, «back» restores', async ({ page, context }) => {
    const sent: URLSearchParams[] = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if (u.pathname === '/api/assets') sent.push(u.searchParams);
    });
    await open(page, context, '/admin/assets');
    const inventory = page.getByRole('columnheader', { name: /^Інв\. номер/ });
    const holder = page.getByRole('columnheader', { name: /^У кого/ });
    const status = page.getByRole('columnheader', { name: /^Статус/ });
    // Default order (by inventory number) carries the arrow.
    await expect(inventory).toHaveAttribute('aria-sort', 'ascending');
    await expect.poll(() => sent.length).toBe(1);

    // Title click: sorted on the page (no request), URL updated; Enter on the title reverses.
    await holder.getByRole('button', { name: 'У кого', exact: true }).click();
    await expect(holder).toHaveAttribute('aria-sort', 'ascending');
    await expect(page).toHaveURL(/[?&]sort=holder&dir=asc(&|$)/);
    await holder.getByRole('button', { name: 'У кого', exact: true }).press('Enter');
    await expect(holder).toHaveAttribute('aria-sort', 'descending');
    const names = await page.locator('table.app-table tbody tr td:nth-child(6)').allInnerTexts();
    const filled = names.map((n) => n.trim()).filter((n) => n !== '—');
    expect(filled).toEqual([...filled].sort((a, b) => b.localeCompare(a, 'uk', { numeric: true, sensitivity: 'base' })));
    expect(names.slice(filled.length).every((n) => n.trim() === '—')).toBe(true); // empty holders last
    expect(sent).toHaveLength(1);

    // Status filter from the keyboard: a server filter — status= in the URL and in the request.
    const funnel = status.getByRole('button', { name: 'Фільтр стовпця «Статус»' });
    await funnel.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Фільтр «Статус»' });
    await expect(dialog).toBeVisible();
    // A choice applies at once (no «apply» button); Esc closes the dialog and keeps it.
    await dialog.getByRole('radio', { name: 'Видано' }).check();
    await expect(page).toHaveURL(/[?&]status=assigned(&|$)/);
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect.poll(() => sent.at(-1)?.get('status')).toBe('assigned');
    await expect(status.getByRole('button', { name: 'Фільтр стовпця «Статус», увімкнено' })).toBeVisible();

    // «Back» drops the filter, the sort stays.
    await page.goBack();
    await expect(status.getByRole('button', { name: 'Фільтр стовпця «Статус»', exact: true })).toBeVisible();
    await expect(holder).toHaveAttribute('aria-sort', 'descending');
  });

  test('desk queue: «open» is the default status in the header, clearing it shows every case, SLA sorts', async ({ page, context }) => {
    const sent: URLSearchParams[] = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if (u.pathname === '/api/desk/cases') sent.push(u.searchParams);
    });
    await open(page, context, '/desk/queue');
    await expect.poll(() => sent.at(-1)?.get('open')).toBe('1');
    const status = page.getByRole('columnheader', { name: /^Статус/ });
    const active = status.getByRole('button', { name: 'Фільтр стовпця «Статус», увімкнено' });
    await expect(active).toBeVisible();
    await active.click();
    const dialog = page.getByRole('dialog', { name: 'Фільтр «Статус»' });
    await expect(dialog.getByRole('radio', { name: 'Відкриті' })).toBeChecked();
    await dialog.getByRole('button', { name: 'Очистити' }).click();
    await expect(page).toHaveURL(/[?&]status=all(&|$)/);
    await expect.poll(() => sent.length).toBe(2);
    expect(sent.at(-1)?.has('open')).toBe(false);
    expect(sent.at(-1)?.has('status')).toBe(false);

    const sla = page.getByRole('columnheader', { name: /^SLA/ });
    await sla.getByRole('button', { name: 'SLA', exact: true }).click();
    await expect(sla).toHaveAttribute('aria-sort', 'ascending');
    await expect(page).toHaveURL(/[?&]sort=sla&dir=asc(&|$)/);
    expect(sent).toHaveLength(2);
  });

  test('people (390 px): a filter of a hidden column stays visible as a chip and is removed from there', async ({ page, context }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page, context, '/people?manager=%D0%9A%D0%BE');
    const chips = page.getByRole('group', { name: 'Активні фільтри' });
    await expect(chips).toContainText('Керівник: Ко');
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBe(0);
    await chips.getByRole('button', { name: 'Прибрати фільтр «Керівник»' }).click();
    await expect.poll(() => new URL(page.url()).searchParams.has('manager')).toBe(false);
    await expect(chips).toBeHidden();
  });

  test('admin users: titles sort via the URL, the role filter sits in its header, the API gets sort/dir and role', async ({ page, context }) => {
    const sent: URLSearchParams[] = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if (u.pathname === '/api/users') sent.push(u.searchParams);
    });
    await open(page, context, '/admin/users');
    const user = page.getByRole('columnheader', { name: 'Користувач', exact: true });
    const lastLogin = page.getByRole('columnheader', { name: 'Останній вхід', exact: true });
    const role = page.getByRole('columnheader', { name: 'Роль', exact: true });
    await expect(user).toHaveAttribute('aria-sort', 'ascending');
    // Roles are many-valued: filter only, no sort.
    await expect(role).not.toHaveAttribute('aria-sort', /.*/);

    await lastLogin.getByRole('button', { name: 'Останній вхід', exact: true }).click();
    await expect(page).toHaveURL(/[?&]sort=last_login&dir=asc(&|$)/);
    await expect.poll(() => sent.at(-1)?.get('sort')).toBe('last_login');
    await lastLogin.getByRole('button', { name: 'Останній вхід', exact: true }).press('Enter');
    await expect(lastLogin).toHaveAttribute('aria-sort', 'descending');
    await expect.poll(() => sent.at(-1)?.get('dir')).toBe('desc');

    await role.getByRole('button', { name: 'Фільтр стовпця «Роль»' }).click();
    const dialog = page.getByRole('dialog', { name: 'Фільтр «Роль»' });
    await dialog.getByRole('radio', { name: 'Спостерігач' }).check();
    await expect(page).toHaveURL(/[?&]role=viewer(&|$)/);
    await expect.poll(() => sent.at(-1)?.get('role')).toBe('viewer');
    expect(sent.at(-1)?.get('page')).toBe('1');
    await expect(role.getByRole('button', { name: 'Фільтр стовпця «Роль», увімкнено' })).toBeVisible();
  });

  test('audit log: newest first by default, the user title sorts, the time column filters a date range', async ({ page, context }) => {
    const sent: URLSearchParams[] = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if (u.pathname === '/api/audit') sent.push(u.searchParams);
    });
    await open(page, context, '/admin/audit');
    const time = page.getByRole('columnheader', { name: 'Час', exact: true });
    const user = page.getByRole('columnheader', { name: 'Користувач', exact: true });
    await expect(time).toHaveAttribute('aria-sort', 'descending');

    await user.getByRole('button', { name: 'Користувач', exact: true }).click();
    await expect(user).toHaveAttribute('aria-sort', 'ascending');
    await expect(time).toHaveAttribute('aria-sort', 'none');
    await expect.poll(() => sent.at(-1)?.get('sort')).toBe('user');

    await time.getByRole('button', { name: 'Фільтр стовпця «Час»' }).click();
    const dialog = page.getByRole('dialog', { name: 'Фільтр «Час»' });
    // The range applies on change after a short pause — no button; both edges go in one URL change.
    await dialog.getByLabel('Від').fill('2026-09-01');
    await dialog.getByLabel('До').fill('2026-09-30');
    await expect.poll(() => new URL(page.url()).searchParams.get('from')).toBe('2026-09-01');
    expect(new URL(page.url()).searchParams.get('to')).toBe('2026-09-30');
    await expect.poll(() => sent.at(-1)?.get('to')).toBe('2026-09-30');
    expect(sent.at(-1)?.get('sort')).toBe('user');
  });

  test('report view: the table has a «Разом» row', async ({ page, context }) => {
    await open(page, context, '/reports/catalog/desk_sla');
    await expect(page.getByRole('row', { name: /^Разом/ })).toBeVisible();
  });

  test('report view: a header title sorts the rows in the browser, «Разом» stays last, the filter narrows and relabels it', async ({ page, context }) => {
    let reportRequests = 0;
    page.on('request', (r) => {
      if (new URL(r.url()).pathname.startsWith('/api/reports/catalog/desk_sla')) reportRequests++;
    });
    await open(page, context, '/reports/catalog/desk_sla');
    const table = page.locator('app-report-table table');
    const firstCells = () => table.locator('tbody tr td:first-child').allInnerTexts();
    const breached = page.getByRole('columnheader', { name: 'SLA порушено, %' });
    await expect(breached).toHaveAttribute('aria-sort', 'none');
    const before = reportRequests;

    // Two clicks: ascending, then descending by the share of breached cases; the API is not asked again.
    await breached.getByRole('button', { name: 'SLA порушено, %', exact: true }).click();
    await expect(page).toHaveURL(/[?&]r_sort=breached_pct&r_dir=asc(&|$)/);
    expect(await firstCells()).toEqual(['Зарплата [ТЕСТ]', 'ІТ та доступи [ТЕСТ]', 'Довідки та документи [ТЕСТ]']);
    await breached.getByRole('button', { name: 'SLA порушено, %', exact: true }).press('Enter');
    await expect(breached).toHaveAttribute('aria-sort', 'descending');
    expect(await firstCells()).toEqual(['Довідки та документи [ТЕСТ]', 'ІТ та доступи [ТЕСТ]', 'Зарплата [ТЕСТ]']);
    expect(reportRequests).toBe(before);
    // «Разом» is the footer, after every body row.
    await expect(table.locator('tfoot tr')).toHaveCount(1);
    await expect(table.locator('tr').last()).toContainText('Разом');

    // Text filter of the category column: the total row now says it covers the whole report.
    await page.getByRole('button', { name: 'Фільтр стовпця «Категорія»' }).click();
    const dialog = page.getByRole('dialog', { name: 'Фільтр «Категорія»' });
    await dialog.getByRole('searchbox').fill('зарплата');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/[?&]r_category=/);
    expect(await firstCells()).toEqual(['Зарплата [ТЕСТ]']);
    await expect(table.locator('tfoot')).toContainText('Разом (усі рядки звіту)');

    // «Back» removes the filter, the sort stays.
    await page.goBack();
    await expect(table.locator('tbody tr')).toHaveCount(3);
    await expect(breached).toHaveAttribute('aria-sort', 'descending');
  });

  test('recruiting reports: each table sorts on its own (prefixed URL), totals stay last', async ({ page, context }) => {
    await open(page, context, '/reports');
    const reasons = page.locator('section', { has: page.getByRole('heading', { name: 'Причини відмов' }) }).locator('table');
    const names = () => reasons.locator('tbody th').allInnerTexts();
    const count = reasons.getByRole('columnheader', { name: 'Кількість' });
    await expect(count).toHaveAttribute('aria-sort', 'descending');
    await count.getByRole('button', { name: 'Кількість', exact: true }).click();
    await expect(page).toHaveURL(/[?&]rej_sort=count&rej_dir=asc(&|$)/);
    expect((await names()).slice(0, 2)).toEqual(['Інше', 'Не прийшов на зустріч']);
    await expect(reasons.locator('tr').last()).toContainText('Разом');
    // The touches table keeps its own default order (by total).
    const touches = page.locator('section', { has: page.getByRole('heading', { name: 'Касання рекрутерів' }) }).locator('table');
    await expect(touches.getByRole('columnheader', { name: 'Усього' })).toHaveAttribute('aria-sort', 'descending');
  });

  test('recruiting reports: the «Рекрутер» filter narrows the touches table while typing, no Enter, no request', async ({ page, context }) => {
    let touchesRequests = 0;
    page.on('request', (r) => {
      if (new URL(r.url()).pathname === '/api/reports/touches') touchesRequests++;
    });
    await open(page, context, '/reports');
    const touches = page.locator('section', { has: page.getByRole('heading', { name: 'Касання рекрутерів' }) }).locator('table');
    const names = () => touches.locator('tbody th').allInnerTexts();
    expect((await names()).length).toBeGreaterThan(1);
    const before = touchesRequests;

    await touches.getByRole('button', { name: 'Фільтр стовпця «Рекрутер»' }).click();
    const dialog = page.getByRole('dialog', { name: 'Фільтр «Рекрутер»' });
    const field = dialog.getByRole('searchbox');
    await field.pressSequentially('лисенко');
    await expect.poll(names).toEqual(['Лисенко Анна [ТЕСТ]']); // contains, any case
    await expect(dialog).toBeVisible();
    await expect(field).toBeFocused();
    await expect(page).toHaveURL(/[?&]tch_recruiter=/);
    expect(touchesRequests).toBe(before); // filtered in the browser
    await expect(touches.locator('tfoot')).toContainText('Разом (усі рядки звіту)');

    // Erasing the text brings every recruiter back, without Enter either.
    await field.fill('');
    await expect.poll(async () => (await names()).length).toBeGreaterThan(1);
    await expect.poll(() => new URL(page.url()).searchParams.has('tch_recruiter')).toBe(false);
  });

  test('recruiting reports (390 px): the header buttons do not widen the page', async ({ page, context }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page, context, '/reports');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBe(0);
  });

  test('user menu: «Працювати як» lists the roles and switching sends PUT /api/auth/active-role', async ({ page, context }) => {
    const mock = await open(page, context, '/');
    await page.getByRole('button', { name: 'Меню користувача' }).click();
    const roles = page.getByRole('menuitemradio');
    await expect(roles).toHaveCount(3); // «Усі ролі» + superadmin + hr_manager of the fixture user
    await roles.last().click();
    await settle(page);
    const sent = writes(mock).filter((m) => m.path === '/api/auth/active-role');
    expect(sent).toHaveLength(1);
    expect(sent[0]).toEqual(expect.objectContaining({ method: 'PUT', body: { role: expect.any(String) } }));
  });

  test('Modules remembers expanded groups and supports keyboard navigation to a routed page', async ({ page, context }) => {
    await open(page, context, '/');
    const modules = page.locator('.modules-toggle');
    const nav = page.getByRole('navigation', { name: 'Головне меню' });
    await expect(modules).toHaveAttribute('aria-expanded', 'false');
    await modules.click();
    await expect(modules).toHaveAttribute('aria-expanded', 'true');
    await expect(nav).toBeVisible();

    const recruiting = nav.getByRole('button', { name: /^Рекрутинг/ });
    await expect(recruiting).toHaveAttribute('aria-expanded', 'false');
    await recruiting.focus();
    await page.keyboard.press('Enter');
    await expect(recruiting).toHaveAttribute('aria-expanded', 'true');

    await page.reload();
    await settle(page);
    await expect(modules).toHaveAttribute('aria-expanded', 'false');
    await modules.click();
    await expect(nav.getByRole('button', { name: /^Рекрутинг/ })).toHaveAttribute('aria-expanded', 'true');

    const tasks = nav.getByRole('link', { name: 'Мої задачі' });
    await tasks.focus();
    await page.keyboard.press('Enter');
    const tasksWindow = page.getByTestId('os-window-os1');
    await expect(tasksWindow).toHaveClass(/open/);
    await expect(tasksWindow.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await expect(modules).toHaveAttribute('aria-expanded', 'false');
  });

  test('Modules closes on Escape, outside click, and navigation', async ({ page, context }) => {
    await open(page, context, '/');
    const modules = page.locator('.modules-toggle');
    const nav = page.getByRole('navigation', { name: 'Головне меню' });
    await modules.click();
    await expect(modules).toHaveAttribute('aria-expanded', 'true');
    await expect(nav).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(modules).toHaveAttribute('aria-expanded', 'false');

    await modules.click();
    await expect(modules).toHaveAttribute('aria-expanded', 'true');
    await page.mouse.click(900, 400);
    await expect(modules).toHaveAttribute('aria-expanded', 'false');

    await modules.click();
    await nav.getByRole('link', { name: 'Мої задачі' }).click();
    const tasksWindow = page.getByTestId('os-window-os1');
    await expect(tasksWindow.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await expect(modules).toHaveAttribute('aria-expanded', 'false');
  });

  test('time off: a new request → exactly one POST /api/timeoff/requests with the type and the dates, the row appears', async ({ page, context }) => {
    const mock = await open(page, context, '/timeoff');
    // Mock mutations answer with the echoed body; the list needs a whole request (type, dates, status), so answer it here.
    const posts: { path: string; body: unknown }[] = [];
    await page.route(`${E2E_ORIGIN}/api/timeoff/requests`, async (route) => {
      if (route.request().method() !== 'POST') return route.fallback();
      const body = route.request().postDataJSON() as NewLeaveRequest;
      posts.push({ path: new URL(route.request().url()).pathname, body });
      const created: LeaveRequest = {
        id: 9001,
        employee: { id: 1, full_name: 'Коваленко Олена [ТЕСТ]' },
        leave_type: { id: body.leave_type_id, name: 'Vacation', color: '#4f7cff' },
        starts_on: body.starts_on,
        ends_on: body.ends_on,
        half_day: body.half_day,
        days: 1,
        comment: body.comment ?? null,
        handover_to: null,
        status: 'pending',
        balance_override: false,
        approver: null,
        decided_at: null,
        decision_comment: null,
        can_decide: false,
        can_cancel: true,
        created_at: null,
      };
      await route.fulfill({ status: 201, json: { data: created } });
    });
    const rows = page.locator('app-requests-list li.row');
    await expect(rows.first()).toBeVisible();
    const before = await rows.count();
    // The form starts on the first active type and on «today» — which is the frozen clock, not the real date.
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Kyiv' }).format(RECORDED_AT);
    await expect(page.getByRole('combobox', { name: 'Тип відсутності' })).toContainText('Vacation');
    await page.getByRole('textbox', { name: 'Коментар' }).fill('Відпустка [ТЕСТ]');
    await page.getByRole('button', { name: 'Надіслати запит' }).click();
    await expect.poll(() => posts.length).toBe(1);
    await settle(page);
    expect(posts).toHaveLength(1);
    expect(posts[0].path).toBe('/api/timeoff/requests');
    expect(posts[0].body).toEqual({ leave_type_id: 1, starts_on: today, ends_on: today, half_day: 'none', comment: 'Відпустка [ТЕСТ]' });
    await expect(rows).toHaveCount(before + 1);
    expect(writes(mock).filter((m) => m.path.startsWith('/api/timeoff'))).toEqual([]); // nothing else went to the API
  });

  test('time off approvals: «Погодити» on a request → exactly one POST …/approve, the row leaves the list', async ({ page, context }) => {
    const mock = await open(page, context, '/timeoff/approvals');
    const rows = page.locator('section', { has: page.getByRole('heading', { name: /^Запити на відсутність/ }) }).locator('li.row');
    await expect(rows.first()).toBeVisible();
    const before = await rows.count();
    expect(before).toBeGreaterThan(1);
    await rows.first().getByRole('button', { name: 'Погодити' }).click();
    await expect(rows).toHaveCount(before - 1);
    await settle(page);
    const sent = writes(mock);
    expect(sent, JSON.stringify(sent)).toHaveLength(1);
    expect(sent[0]).toEqual({ method: 'POST', path: expect.stringMatching(/^\/api\/timeoff\/requests\/\d+\/approve$/), body: { comment: null } });
  });

  test('safe speak: sending a report → one POST with category/subject/body, the code is shown once and never leaves the screen', async ({ page, context }) => {
    const mock = await open(page, context, '/safe-speak');
    const code = 'TEST-AAAA-BBBB-CCCC';
    const urls: string[] = [];
    page.on('request', (r) => urls.push(r.url()));
    await page.route(`${E2E_ORIGIN}/api/safe-speak/public/reports`, async (route) => {
      if (route.request().method() !== 'POST') return route.fallback();
      // The mock logs the mutation itself, so do the same here (the route above would swallow it).
      mock.mutations.push({ method: 'POST', path: new URL(route.request().url()).pathname, body: route.request().postDataJSON() as unknown });
      await route.fulfill({ status: 201, json: { data: { code, report: {} } } });
    });
    const send = page.getByRole('button', { name: 'Надіслати', exact: true });
    await expect(send).toBeDisabled();
    await page.getByRole('combobox', { name: 'Категорія' }).click();
    await page.getByRole('option').first().click();
    await page.getByRole('textbox', { name: 'Тема' }).fill('Тема [ТЕСТ]');
    await page.getByRole('textbox', { name: 'Що сталося' }).fill('Текст повідомлення [ТЕСТ]');
    await expect(send).toBeEnabled();
    await send.click();
    await expect(page.getByTestId('access-code')).toHaveText(code);
    await settle(page);
    const sent = writes(mock);
    expect(sent, JSON.stringify(sent)).toHaveLength(1);
    expect(sent[0]).toEqual({
      method: 'POST',
      path: '/api/safe-speak/public/reports',
      body: { category: expect.any(String), subject: 'Тема [ТЕСТ]', body: 'Текст повідомлення [ТЕСТ]' },
    });
    // The code is not in the address bar, in any request URL, or in the browser's storage.
    expect(page.url()).not.toContain(code);
    expect(urls.filter((u) => u.includes(code))).toEqual([]);
    const stored = await page.evaluate(() => JSON.stringify([{ ...localStorage }, { ...sessionStorage }]));
    expect(stored).not.toContain(code);
    // «Збережено»: the code disappears with the button and the form comes back empty.
    await page.getByRole('button', { name: 'Я зберіг(ла)' }).click();
    await expect(page.getByTestId('access-code')).toHaveCount(0);
    await expect(page.getByRole('textbox', { name: 'Тема' })).toHaveValue('');
  });

  test('login: language switch translates the page', async ({ page, context }) => {
    await open(page, context, '/login', true);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Вхід');
    await page.getByRole('radio', { name: 'en', exact: true }).click();
    await expect(page.getByRole('radio', { name: 'en', exact: true })).toBeChecked();
    await expect(page.getByRole('heading', { level: 1 })).not.toHaveText('Вхід');
  });
});

test.describe('mobile flows', () => {
  // eslint-disable-next-line no-empty-pattern -- Playwright fixtures need an object pattern
  test.beforeEach(({}, info) => test.skip(info.project.name !== 'mobile-light', 'mobile-light only'));

  test('fast mode removes shell blur, filters, transparency, and transitions, including after reload', async ({ page, context }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await open(page, context, '/settings/appearance');
    await page.getByRole('radio', { name: 'Скло', exact: true }).click();
    await page.getByRole('switch', { name: 'Швидкий режим' }).click();

    const root = page.locator('html');
    const assertFastShell = async (): Promise<void> => {
      await expect(root).toHaveAttribute('data-performance', 'fast');
      const surfaceState = await page.evaluate(() => {
        const effects: { selector: string; pseudo: string; filter: string; backdropFilter: string; webkitBackdropFilter: string }[] = [];
        const describe = (element: Element): string => {
          const id = element.id ? `#${element.id}` : '';
          const classes = Array.from(element.classList).slice(0, 2).map((name) => `.${name}`).join('');
          return `${element.tagName.toLowerCase()}${id}${classes}`;
        };
        for (const element of document.querySelectorAll('*')) {
          const base = getComputedStyle(element);
          if (base.display !== 'none' && base.visibility !== 'hidden' && element.getClientRects().length > 0) {
            for (const [pseudo, style] of [['', base], ['::before', getComputedStyle(element, '::before')], ['::after', getComputedStyle(element, '::after')]] as const) {
              if (pseudo && (style.display === 'none' || style.content === 'none' || style.content === 'normal')) continue;
              const filter = style.filter;
              const backdropFilter = style.backdropFilter;
              const webkitBackdropFilter = style.getPropertyValue('-webkit-backdrop-filter');
              // Chromium may leave the unsupported prefixed property empty; only a non-empty
              // non-none value represents an active WebKit backdrop filter.
              if (filter !== 'none' || backdropFilter !== 'none' || (webkitBackdropFilter !== '' && webkitBackdropFilter !== 'none')) {
                effects.push({ selector: describe(element), pseudo, filter, backdropFilter, webkitBackdropFilter });
              }
            }
          }
        }
        const alpha = (color: string): number => {
          const slash = color.match(/\/\s*([\d.]+)\s*\)$/);
          if (slash) return Number(slash[1]);
          const rgba = color.match(/^rgba\([^,]+,[^,]+,[^,]+,\s*([\d.]+)\)$/);
          return rgba ? Number(rgba[1]) : color === 'transparent' ? 0 : 1;
        };
        const shell = ['#app-sidebar', '.topbar'].map((selector) => {
          const element = document.querySelector(selector);
          if (!element) return { selector, missing: true };
          const style = getComputedStyle(element);
          return {
            selector,
            missing: false,
            transitionDuration: style.transitionDuration,
            backgroundColor: style.backgroundColor,
            backgroundAlpha: alpha(style.backgroundColor),
          };
        });
        return { effects, shell };
      });

      expect(surfaceState.effects, 'no rendered element or pseudo-element may retain filter/backdrop-filter in fast mode').toEqual([]);
      for (const surface of surfaceState.shell) {
        expect(surface.missing, `${surface.selector} exists in the authenticated shell`).toBe(false);
        expect(surface.backgroundAlpha, `${surface.selector} background is opaque`).toBe(1);
        expect(surface.transitionDuration, `${surface.selector} transitions are disabled`).toMatch(/^(0s)(,\s*0s)*$/);
      }
    };

    const theme = await root.getAttribute('data-theme');
    await assertFastShell();
    await page.reload();
    await settle(page);
    await expect(root).toHaveAttribute('data-theme', theme ?? 'light');
    await expect(root).toHaveAttribute('data-interface-style', 'glass');
    await assertFastShell();
  });

  test('widget sizes preserve distinct card heights on mobile', async ({ page, context }) => {
    await open(page, context, '/desktop');
    const summary = page.locator('[data-widget="summary"]');
    const tasks = page.locator('[data-widget="tasks"]');
    const today = page.locator('[data-widget="today"]');
    await summary.locator('.size-control button').nth(0).click();
    await tasks.locator('.size-control button').nth(1).click();
    await today.locator('.size-control button').nth(2).click();
    await expect(summary).toHaveClass(/size-s/);
    await expect(tasks).toHaveClass(/size-m/);
    await expect(today).toHaveClass(/size-l/);

    const heights = await page.evaluate(() => Object.fromEntries(
      ['summary', 'tasks', 'today'].map((id) => [id, document.querySelector(`[data-widget="${id}"]`)?.getBoundingClientRect().height ?? 0]),
    ));
    expect(heights['summary']).toBeLessThan(heights['tasks']);
    expect(heights['tasks']).toBeLessThan(heights['today']);
  });

  test('Modules drawer closes on Escape, outside tap, and navigation', async ({ page, context }) => {
    await open(page, context, '/');
    const burger = page.locator('.modules-toggle');
    const nav = page.getByRole('navigation', { name: 'Головне меню' });
    await expect(burger).toHaveAttribute('aria-expanded', 'false');
    await burger.click();
    await expect(burger).toHaveAttribute('aria-expanded', 'true');
    await expect(nav).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(burger).toHaveAttribute('aria-expanded', 'false');
    await burger.click();
    await expect(nav).toBeVisible();
    const panelBox = await nav.boundingBox();
    const viewport = page.viewportSize();
    expect(panelBox, 'the open Modules panel has rendered bounds').not.toBeNull();
    expect(viewport, 'mobile viewport dimensions are available').not.toBeNull();
    const box = panelBox!;
    const size = viewport!;
    const rightX = box.x + box.width + 8;
    const outsideX = rightX < size.width ? rightX : Math.max(0, box.x - 8);
    const outsideY = Math.min(size.height - 1, Math.max(0, box.y + box.height / 2));
    expect(outsideX < box.x || outsideX > box.x + box.width, 'outside click point is beyond the panel bounds').toBe(true);
    await page.mouse.click(outsideX, outsideY);
    await expect(burger).toHaveAttribute('aria-expanded', 'false');
    await burger.click();
    await nav.getByRole('link', { name: 'Мої задачі' }).click();
    await expect(page).toHaveURL(/os1:window\/tasks/);
    await expect(page.getByTestId('os-window-os1').getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await expect(burger).toHaveAttribute('aria-expanded', 'false');
  });

  test('workspace window occupies the mobile safe area without drag or resize controls', async ({ page, context }) => {
    await open(page, context, '/');
    await page.getByRole('navigation', { name: 'Вікна робочого простору' }).getByRole('button', { name: 'Мої задачі', exact: true }).click();
    const article = page.getByTestId('os-window-os1');
    await expect(article.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await expect(page.getByTestId('window-resize-os1')).toHaveCount(0);
    const windowBox = await article.boundingBox();
    const layerBox = await page.locator('.window-layer').boundingBox();
    expect(windowBox).not.toBeNull();
    expect(layerBox).not.toBeNull();
    expect(windowBox!.x).toBeCloseTo(layerBox!.x, 0);
    expect(windowBox!.y).toBeCloseTo(layerBox!.y, 0);
    expect(windowBox!.width).toBeCloseTo(layerBox!.width, 0);
    expect(windowBox!.height).toBeCloseTo(layerBox!.height, 0);
    expect(layerBox!.y).toBeGreaterThanOrEqual(56);
    expect(layerBox!.y + layerBox!.height).toBeLessThanOrEqual(page.viewportSize()!.height - 56);
  });
});

test('Modules badge stays beside its label or icon without overlap', async ({ page, context }, testInfo) => {
  await open(page, context, '/');
  await expectModulesBadgeOutsideLabel(page, testInfo.project.name.startsWith('mobile-'));
});


test('mobile assistant: in-flow launcher opens chat without covering candidate content', async ({ page, context }, info) => {
  test.skip(!info.project.name.startsWith('mobile-'), 'mobile viewports only');
  const mock = await open(page, context, '/candidates');
  // Opening chat reads status once; this synthetic GET belongs only to this flow.
  const status: AssistantStatus = { available: true, reason: null, mcp_url: `${E2E_ORIGIN}/api/mcp` };
  let statusRequests = 0;
  await page.route(`${E2E_ORIGIN}/api/assistant/status`, async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    statusRequests++;
    await route.fulfill({ status: 200, json: { data: status } });
  });
  const launcher = page.locator('.topbar .assistant-launcher');
  await expect(launcher).toHaveAccessibleName('Поговорити зі Стіком');
  await expect(page.locator('.mascot-stage')).toBeHidden();
  await expect(page.locator('.mascot-orb')).toHaveCount(0);
  const header = (await page.locator('.topbar').boundingBox())!;
  const button = (await launcher.boundingBox())!;
  expect(button.width).toBeGreaterThanOrEqual(44);
  expect(button.height).toBeGreaterThanOrEqual(44);
  expect(button.y + button.height).toBeLessThanOrEqual(header.y + header.height);
  const firstCandidate = (await page.locator('app-candidates-page [role=option]').first().boundingBox())!;
  expect(button.y + button.height).toBeLessThan(firstCandidate.y);
  await launcher.click();
  await expect(page.getByRole('dialog', { name: 'Стік', exact: true })).toBeVisible();
  await settle(page);
  expect(statusRequests).toBe(1);
  await expect(page.locator('.mascot-stage')).toBeHidden();
  await page.getByRole('button', { name: 'Закрити чат', exact: true }).click();
  await expect(page.locator('.assistant-panel')).toHaveCount(0);
  await expect(launcher).toBeVisible();
  expect(writes(mock)).toEqual([]);
  expect(mock.missing).toEqual([]);
});

test('desktop: real launcher routes and per-user widgets support add, size, keyboard reorder, drag, and persistence', async ({ page, context }, testInfo) => {
  const mock = await open(page, context, '/desktop');
  await expect(page.getByRole('heading', { name: 'Робочий стіл' })).toBeVisible();
  await expect(page.locator('.app-group[data-group="recruiting"]')).toBeVisible();
  const candidatesShortcut = page.getByTestId('desktop-app-candidates');
  await expect(candidatesShortcut).toBeVisible();
  if (testInfo.project.name === 'desktop-light') {
    await testInfo.attach('os-desktop-reference-desktop-light.png', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
  }
  await candidatesShortcut.click();
  const launchedCandidateWindow = page.getByTestId('os-window-os1');
  await expect(launchedCandidateWindow).toHaveClass(/open/);
  await expect(launchedCandidateWindow.getByRole('heading', { level: 1, name: 'Кандидати' })).toBeVisible();
  await launchedCandidateWindow.locator('.window-control-close').click();
  await expect(launchedCandidateWindow).not.toHaveClass(/open/);
  if (testInfo.project.name.startsWith('desktop-')) {
    const pageWidth = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, content: document.documentElement.scrollWidth }));
    expect(pageWidth.content).toBeLessThanOrEqual(pageWidth.viewport);
  }
  await expect(page.locator('.widget')).toHaveCount(3);
  await expect(page.locator('[data-widget="summary"]')).toContainText('Активні кандидати');
  if (testInfo.project.name.startsWith('desktop-')) {
    const smallWidth = (await page.locator('[data-widget="tasks"]').boundingBox())?.width ?? 0;
    const mediumWidth = (await page.locator('[data-widget="summary"]').boundingBox())?.width ?? 0;
    expect(smallWidth).toBeGreaterThan(0);
    expect(smallWidth).toBeLessThan(mediumWidth);
  }

  await page.getByRole('button', { name: 'Мої погодження', exact: true }).click();
  const approvals = page.locator('[data-widget="approvals"]');
  await expect(approvals).toBeVisible();
  await approvals.getByRole('button', { name: 'Великий розмір для віджета «Мої погодження»' }).click();
  await expect(approvals).toHaveClass(/size-l/);
  if (testInfo.project.name.startsWith('desktop-')) {
    const largeWidth = (await approvals.boundingBox())?.width ?? 0;
    const mediumWidth = (await page.locator('[data-widget="summary"]').boundingBox())?.width ?? 0;
    expect(largeWidth).toBeGreaterThan(mediumWidth);
  }
  await page.getByRole('button', { name: 'Перемістити «Мої погодження» вище' }).click();
  await expect(page.locator('.widget').nth(2)).toHaveAttribute('data-widget', 'approvals');

  const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('sinhrm.desktop.layout.v1.3') ?? 'null') as { widgets: { id: string; size: string }[] });
  expect(saved.widgets).toEqual([
    { id: 'summary', size: 'm' }, { id: 'tasks', size: 's' }, { id: 'approvals', size: 'l' }, { id: 'today', size: 'm' },
  ]);
  await page.reload();
  await settle(page);
  await expect(page.locator('.widget').nth(2)).toHaveAttribute('data-widget', 'approvals');
  await page.getByRole('button', { name: 'Прибрати віджет «Мої погодження»' }).click();
  await expect(page.locator('[data-widget="approvals"]')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Мої погодження', exact: true })).toBeFocused();

  const dragHandle = page.locator('[data-widget="tasks"] .drag-handle');
  const dropTarget = page.locator('[data-widget="summary"] .widget-header');
  await dropTarget.scrollIntoViewIfNeeded();
  await dragHandle.scrollIntoViewIfNeeded();
  await expect(dragHandle).toBeInViewport({ ratio: 1 });
  await expect(dropTarget).toBeInViewport({ ratio: 1 });
  const dragPreparation = await page.evaluate(() => {
    const pointFor = (selector: string): { x: number; y: number; hit: string | null } => {
      const element = document.querySelector(selector);
      if (!element) return { x: 0, y: 0, hit: null };
      const bounds = element.getBoundingClientRect();
      const x = bounds.left + bounds.width / 2;
      const y = bounds.top + 12;
      const hit = document.elementFromPoint(x, y);
      const expected = selector.includes('drag-handle') ? '.drag-handle' : '.widget-header';
      return { x, y, hit: hit?.closest(expected)?.className ?? hit?.className ?? null };
    };
    return {
      scrollY: window.scrollY,
      source: pointFor('[data-widget="tasks"] .drag-handle'),
      target: pointFor('[data-widget="summary"] .widget-header'),
      firstWidget: document.querySelector('.widget')?.getAttribute('data-widget') ?? null,
    };
  });
  expect(dragPreparation.source.hit).toContain('drag-handle');
  expect(dragPreparation.target.hit).toContain('widget-header');
  await drag(page, dragHandle, dropTarget);
  const dragResult = await page.evaluate(() => ({
    scrollY: window.scrollY,
    firstWidget: document.querySelector('.widget')?.getAttribute('data-widget') ?? null,
  }));
  await testInfo.attach('widget-drag-geometry.json', {
    body: Buffer.from(JSON.stringify({ before: dragPreparation, after: dragResult }, null, 2)),
    contentType: 'application/json',
  });
  expect(dragResult.scrollY).toBe(dragPreparation.scrollY);
  await expect(page.locator('.widget').first()).toHaveAttribute('data-widget', 'tasks');
  const reordered = await page.evaluate(() => JSON.parse(localStorage.getItem('sinhrm.desktop.layout.v1.3') ?? 'null') as { widgets: { id: string }[] });
  expect(reordered.widgets.map((widget) => widget.id)).toEqual(['tasks', 'summary', 'today']);

  await page.locator('.modules-toggle').click();
  const modules = page.getByRole('navigation', { name: 'Головне меню' });
  const recruiting = modules.getByRole('button', { name: /^Рекрутинг/ });
  if (await recruiting.getAttribute('aria-expanded') !== 'true') await recruiting.click();
  await modules.getByRole('link', { name: 'Кандидати', exact: true }).click();
  const candidatesWindow = page.getByTestId('os-window-os1');
  await expect(candidatesWindow).toHaveClass(/open/);
  await expect(candidatesWindow.getByRole('heading', { level: 1, name: 'Кандидати' })).toBeVisible();
  expect(mock.missing).toEqual([]);
});

test('desktop mobile: widget titles and size actions remain readable at 390px', async ({ page, context }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await open(page, context, '/desktop');
  await page.getByRole('button', { name: 'Мої погодження', exact: true }).click();

  const widget = page.locator('[data-widget="approvals"]');
  const title = widget.locator('.widget-header h3');
  const actions = widget.locator('.widget-actions');
  const sizeGroup = widget.getByRole('group', { name: 'Розмір віджета «Мої погодження»' });
  const expectReadableHeader = async (): Promise<void> => {
    const widgetBounds = await widget.boundingBox();
    const titleBounds = await title.boundingBox();
    const actionBounds = await actions.boundingBox();
    expect(widgetBounds).not.toBeNull();
    expect(titleBounds).not.toBeNull();
    expect(actionBounds).not.toBeNull();
    expect(titleBounds!.width).toBeGreaterThan(100);
    expect(titleBounds!.y + titleBounds!.height).toBeLessThanOrEqual(actionBounds!.y);
    expect(actionBounds!.x + actionBounds!.width).toBeLessThanOrEqual(widgetBounds!.x + widgetBounds!.width + 1);
  };
  await expect(title).toHaveText('Мої погодження');
  await expect(sizeGroup).toBeVisible();
  await expect(sizeGroup.locator('button')).toHaveText(['s', 'm', 'l']);
  await expect(actions.getByRole('button', { name: 'Малий розмір для віджета «Мої погодження»' })).toBeVisible();
  await expectReadableHeader();

  for (const [size, label] of [['s', 'Малий'], ['m', 'Середній'], ['l', 'Великий']] as const) {
    await sizeGroup.getByRole('button', { name: `${label} розмір для віджета «Мої погодження»` }).click();
    await expect(widget).toHaveClass(new RegExp(`size-${size}`));
    await expectReadableHeader();
  }
});

test('desktop: launcher groups and widget rail stay aligned at 390, 768, 1000, and 1440px', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-light', 'desktop-light visual evidence only');
  const mock = await open(page, context, '/desktop');
  for (const width of [390, 768, 1000, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => resolve())));
    const layout = await page.evaluate(() => {
      const rect = (selector: string): { x: number; y: number; width: number; height: number } | null => {
        const element = document.querySelector(selector);
        if (!element) return null;
        const box = element.getBoundingClientRect();
        return { x: box.x, y: box.y, width: box.width, height: box.height };
      };
      const widget = document.querySelector('.widget[data-widget="tasks"]');
      const header = widget?.querySelector('.widget-header');
      const actions = widget?.querySelector('.widget-actions');
      const headerBox = header?.getBoundingClientRect();
      const actionsBox = actions?.getBoundingClientRect();
      return {
        viewport: document.documentElement.clientWidth,
        page: document.documentElement.scrollWidth,
        workspace: rect('.desktop-workspace'),
        launcher: rect('.launcher'),
        rail: rect('.widgets-section'),
        groupColumns: Number.parseInt(getComputedStyle(document.querySelector('.app-groups')!).columnCount, 10),
        groupWidths: Array.from(document.querySelectorAll('.app-group'), (element) => element.getBoundingClientRect().width),
        headerClientWidth: header?.clientWidth ?? 0,
        headerScrollWidth: header?.scrollWidth ?? 0,
        actionsX: actionsBox?.x ?? -1,
        actionsRight: actionsBox?.right ?? Number.POSITIVE_INFINITY,
        widgetBounds: headerBox ? rect('.widget[data-widget="tasks"]') : null,
      };
    });
    expect(layout.workspace, `${width}px workspace`).not.toBeNull();
    expect(layout.launcher, `${width}px app area`).not.toBeNull();
    expect(layout.rail, `${width}px widget rail`).not.toBeNull();
    if (width > 1100) {
      expect(layout.rail!.x, `${width}px widget rail follows the launcher`).toBeGreaterThanOrEqual(layout.launcher!.x + layout.launcher!.width - 1);
      expect(layout.rail!.y, `${width}px widget rail aligns with launcher`).toBeLessThan(layout.launcher!.y + 1);
    } else {
      expect(layout.rail!.y, `${width}px widget rail follows launcher`).toBeGreaterThan(layout.launcher!.y + layout.launcher!.height - 1);
      expect(layout.launcher!.width, `${width}px launcher uses workspace width`).toBeGreaterThan(width * 0.8);
    }
    expect(layout.groupColumns, `${width}px category columns`).toBe(width < 768 ? 1 : 3);
    expect(Math.min(...layout.groupWidths), `${width}px category cards stay readable`).toBeGreaterThanOrEqual(width > 1100 ? 200 : 180);
    expect(layout.page, `${width}px no document horizontal overflow`).toBeLessThanOrEqual(layout.viewport);
    expect(layout.headerScrollWidth, `${width}px widget controls fit the wrapped header`).toBeLessThanOrEqual(layout.headerClientWidth + 1);
    expect(layout.actionsX, `${width}px widget controls stay inside card`).toBeGreaterThanOrEqual(layout.widgetBounds!.x);
    expect(layout.actionsRight, `${width}px widget controls stay inside card`).toBeLessThanOrEqual(layout.widgetBounds!.x + layout.widgetBounds!.width + 1);
    await testInfo.attach(`os-desktop-reference-${width}.png`, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
  }
  expect(mock.missing).toEqual([]);
});

test('desktop mobile: Dock scroll stays inside its own viewport', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile-light', 'mobile-light interaction check only');
  const mock = await open(page, context, '/');
  await expect(page.getByRole('heading', { name: 'Робочий стіл' })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  const dock = page.locator('.os-dock');
  let expectedWindows = 0;
  for (const group of ['personal', 'people', 'services', 'recruiting']) {
    await dock.locator(`.dock-app[data-group="${group}"]`).click();
    expectedWindows++;
    await expect(dock.locator('.dock-window')).toHaveCount(expectedWindows);
  }
  const dimensions = await dock.evaluate((element) => {
    const box = element.getBoundingClientRect();
    return { x: box.x, right: box.right, clientWidth: element.clientWidth, scrollWidth: element.scrollWidth };
  });
  expect(dimensions.x).toBeGreaterThanOrEqual(0);
  expect(dimensions.right).toBeLessThanOrEqual(390);
  expect(dimensions.scrollWidth).toBeGreaterThan(dimensions.clientWidth);
  await testInfo.attach('os-desktop-reference-mobile-dock.png', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
  expect(mock.missing).toEqual([]);
});

test('desktop S widget: title stays readable beside its wrapped controls at a wide viewport', async ({ page, context }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await open(page, context, '/desktop');

  const widget = page.locator('[data-widget="tasks"]');
  const title = widget.locator('.widget-header h3');
  const actions = widget.locator('.widget-actions');
  await expect(title).toHaveText('Мої задачі');
  const widgetBounds = await widget.boundingBox();
  const titleBounds = await title.boundingBox();
  const actionBounds = await actions.boundingBox();
  expect(widgetBounds).not.toBeNull();
  expect(titleBounds).not.toBeNull();
  expect(actionBounds).not.toBeNull();
  expect(titleBounds!.width).toBeGreaterThan(70);
  expect(titleBounds!.y + titleBounds!.height).toBeLessThanOrEqual(actionBounds!.y);
  expect(actionBounds!.x + actionBounds!.width).toBeLessThanOrEqual(widgetBounds!.x + widgetBounds!.width + 1);
});
