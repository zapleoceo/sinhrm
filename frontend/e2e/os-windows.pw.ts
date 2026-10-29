import { expect, test } from '@playwright/test';
import { RECORDED_AT, installMock } from './harness';
import { settle, watchNetwork } from './steps.mjs';

test.describe('OS routed windows', () => {
  // eslint-disable-next-line no-empty-pattern -- Playwright fixtures need an object pattern
  test.beforeEach(({}, info) => test.skip(info.project.name !== 'desktop-light', 'desktop-light only'));

  test('opens real Tasks and People pages, preserves minimized routes, and follows browser history', async ({ page, context }) => {
    await installMock(context);
    await page.clock.setSystemTime(RECORDED_AT);
    watchNetwork(page);
    await page.goto('/tasks');
    await settle(page);

    const tasksUrl = page.url();
    await page.getByRole('button', { name: 'Відкрити задачі у вікні' }).click();
    await expect(page).toHaveURL(/os1:window\/tasks/);
    const tasksWindow = page.locator('article.os-window.window-os1.open');
    await expect(tasksWindow).toBeVisible();
    await expect(tasksWindow.getByRole('heading', { name: 'Мої задачі' })).toBeVisible();

    const oneWindowUrl = page.url();
    await page.getByRole('button', { name: 'Відкрити співробітників у вікні' }).click();
    await expect(page).toHaveURL(/os2:window\/people/);
    const peopleWindow = page.locator('article.os-window.window-os2.open');
    await expect(peopleWindow).toBeVisible();
    await expect(peopleWindow.getByRole('heading', { name: /Співробітники/ })).toBeVisible();
    await expect(page.locator('article.os-window.open')).toHaveCount(2);

    await tasksWindow.getByRole('button', { name: 'Згорнути вікно' }).click();
    await expect(tasksWindow).toHaveClass(/minimized/);
    await expect(tasksWindow.locator('app-os-window-host router-outlet + *')).toHaveCount(1);
    await tasksWindow.getByRole('button', { name: 'Відновити вікно' }).click();
    await expect(tasksWindow).not.toHaveClass(/minimized/);

    await page.goBack();
    await expect(page).toHaveURL(oneWindowUrl);
    await expect(page.locator('article.os-window.window-os1.open')).toBeVisible();
    await expect(page.locator('article.os-window.window-os2.open')).toHaveCount(0);

    await page.goBack();
    await expect(page).toHaveURL(tasksUrl);
    await expect(page.locator('article.os-window.open')).toHaveCount(0);

    await page.goForward();
    await expect(page).toHaveURL(oneWindowUrl);
    await expect(page.locator('article.os-window.window-os1.open')).toBeVisible();

    await page.goForward();
    await expect(page).toHaveURL(/os2:window\/people/);
    await expect(page.locator('article.os-window.open')).toHaveCount(2);
  });

  test('repeated window controls do not reload APIs or remount routed views', async ({ page, context }, testInfo) => {
    const apiRequests: string[] = [];
    page.on('request', (request) => {
      const url = new URL(request.url());
      if (url.pathname.startsWith('/api/')) apiRequests.push(`${request.method()} ${url.pathname}${url.search}`);
    });
    await installMock(context);
    await page.clock.setSystemTime(RECORDED_AT);
    watchNetwork(page);
    await page.goto('/tasks');
    await settle(page);
    await page.getByRole('button', { name: 'Відкрити задачі у вікні' }).click();
    const tasks = page.locator('article.os-window.window-os1.open');
    await expect(tasks.getByRole('heading', { name: 'Мої задачі' })).toBeVisible();
    await page.getByRole('button', { name: 'Відкрити співробітників у вікні' }).click();
    const people = page.locator('article.os-window.window-os2.open');
    await expect(people.getByRole('heading', { name: /Співробітники/ })).toBeVisible();
    await settle(page);

    const mountedBefore = await page.locator('app-os-window-host router-outlet + *').count();
    expect(mountedBefore).toBe(2);
    const apiRequestOffset = apiRequests.length;
    const startedAt = await page.evaluate(() => performance.now());
    for (let round = 0; round < 3; round++) {
      await tasks.locator('.window-titlebar').click();
      await expect(tasks).toHaveClass(/active/);
      await tasks.getByRole('button', { name: 'Згорнути вікно' }).click();
      await expect(tasks).toHaveClass(/minimized/);
      await tasks.getByRole('button', { name: 'Відновити вікно' }).click();
      await expect(tasks).toHaveClass(/active/);
    }
    const controlDurationMs = await page.evaluate((start) => performance.now() - start, startedAt);
    const controlApiRequests = apiRequests.slice(apiRequestOffset);
    expect(controlApiRequests).toEqual([]);
    expect(await page.locator('app-os-window-host router-outlet + *').count()).toBe(mountedBefore);

    const browserSample = await page.evaluate(async () => {
      const frameIntervalsMs: number[] = [];
      let previous = performance.now();
      await new Promise<void>((resolve) => {
        const sample = (now: number): void => {
          frameIntervalsMs.push(now - previous);
          previous = now;
          if (frameIntervalsMs.length === 30) resolve();
          else requestAnimationFrame(sample);
        };
        requestAnimationFrame(sample);
      });
      const resources = performance.getEntriesByType('resource')
        .map((entry) => new URL(entry.name).pathname.split('/').at(-1) ?? '')
        .filter((name) => /^(main|chunk|styles|polyfills)-/.test(name));
      const memory = (performance as Performance & { memory?: { usedJSHeapSize: number; totalJSHeapSize: number } }).memory;
      return {
        userAgent: navigator.userAgent,
        viewport: { width: innerWidth, height: innerHeight, devicePixelRatio },
        buildContext: { route: location.pathname, rootTheme: document.documentElement.dataset['theme'], assets: resources },
        frameIntervalsMs,
        heapBytes: memory ? { used: memory.usedJSHeapSize, total: memory.totalJSHeapSize } : null,
      };
    });
    await testInfo.attach('os-window-control-performance.json', {
      body: JSON.stringify({
        controls: { rounds: 3, elapsedMs: controlDurationMs, apiRequestCount: controlApiRequests.length, mountedViews: mountedBefore },
        browserSample,
      }, null, 2),
      contentType: 'application/json',
    });
  });

  test('mobile keeps inactive windows out of keyboard focus while primary and restore controls stay usable', async ({ page, context }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile-light', 'mobile-light only');
    await installMock(context);
    watchNetwork(page);
    for (const width of [390, 375]) {
      await page.setViewportSize({ width, height: 844 });
      await page.clock.setSystemTime(RECORDED_AT);
      await page.goto('/tasks');
      await settle(page);
      await page.getByRole('button', { name: 'Відкрити задачі у вікні' }).click();
      await page.getByRole('button', { name: 'Відкрити співробітників у вікні' }).click();

      const inactive = page.locator('article.os-window.window-os1.mobile-inactive');
      await expect(inactive).toHaveAttribute('aria-hidden', 'true');
      await expect(inactive).toHaveAttribute('inert', '');
      await expect(inactive).toBeHidden();
      for (let index = 0; index < 16; index++) {
        await page.keyboard.press('Tab');
        expect(await inactive.evaluate((element) => element.contains(document.activeElement))).toBe(false);
      }

      await page.getByRole('button', { name: 'Показати основну сторінку' }).click();
      await expect(page.locator('.primary-view')).toBeVisible();
      await expect(page.locator('article.os-window.open.mobile-inactive')).toHaveCount(2);
      await page.getByRole('button', { name: 'Відкрити співробітників у вікні' }).click();
      await expect(page.locator('article.os-window.window-os2.open')).not.toHaveClass(/mobile-inactive/);

      await page.locator('article.os-window.window-os2.open').getByRole('button', { name: 'Згорнути вікно' }).click();
      const minimized = page.locator('article.os-window.window-os2.minimized');
      await expect(minimized.getByRole('button', { name: 'Відновити вікно' })).toBeVisible();
    }
  });
});
