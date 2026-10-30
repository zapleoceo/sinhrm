import { expect, test } from '@playwright/test';
import { RECORDED_AT, installMock } from './harness';
import { settle, watchNetwork } from './steps.mjs';

test.describe('OS routed windows', () => {
  // eslint-disable-next-line no-empty-pattern -- Playwright fixtures need an object pattern
  test.beforeEach(({}, info) => test.skip(info.project.name !== 'desktop-light', 'desktop-light only'));

  test('opens real Tasks and People pages, preserves minimized routes, and follows browser history', async ({ page, context }) => {
    const mock = await installMock(context);
    await page.clock.setSystemTime(RECORDED_AT);
    watchNetwork(page);
    await page.goto('/overview');
    await settle(page);

    const tasksUrl = page.url();
    const dock = page.getByRole('navigation', { name: 'Вікна робочого простору' });
    await dock.getByRole('button', { name: 'Мої задачі', exact: true }).click();
    await expect.poll(() => new URL(page.url()).pathname).toBe('/tasks');
    await expect(new URL(page.url()).searchParams.has('osActive')).toBe(false);
    const tasksWindow = page.getByTestId('os-window-os1');
    await expect(tasksWindow).toBeVisible();
    await expect(tasksWindow).toHaveClass(/active/);
    await expect(tasksWindow.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();

    const oneWindowUrl = page.url();
    await dock.getByRole('button', { name: 'Співробітники', exact: true }).click();
    await expect.poll(() => new URL(page.url()).pathname).toBe('/people');
    const peopleWindow = page.getByTestId('os-window-os2');
    await expect(peopleWindow).toBeVisible();
    await expect(peopleWindow).toHaveClass(/active/);
    await expect(peopleWindow.getByRole('heading', { level: 1, name: /Співробітники/ })).toBeVisible();
    await expect(page.locator('article.os-window.open')).toHaveCount(2);

    await page.getByTestId('dock-window-os1').click();
    await expect(tasksWindow).toHaveClass(/active/);
    await tasksWindow.locator('.window-titlebar').getByRole('button', { name: 'Згорнути вікно', exact: true }).click();
    await expect(tasksWindow).toHaveClass(/minimized/);
    await expect(tasksWindow).toBeHidden();
    await expect(tasksWindow.locator('app-os-window-host router-outlet + *')).toHaveCount(1);
    await page.getByTestId('dock-window-os1').click();
    await expect(tasksWindow).not.toHaveClass(/minimized/);
    await expect(tasksWindow.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    const twoWindowUrl = page.url();
    expect(new URL(twoWindowUrl).pathname).toBe('/tasks');
    await expect(tasksWindow).toHaveClass(/active/);

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
    await expect(page.locator('article.os-window.open')).toHaveCount(1);

    await page.goForward();
    await expect(page).toHaveURL(twoWindowUrl);
    await expect(page.locator('article.os-window.open')).toHaveCount(2);
    await expect(tasksWindow).toHaveClass(/active/);
    await page.getByTestId('dock-window-os2').click();
    await expect(peopleWindow).toHaveClass(/active/);
    await expect.poll(() => new URL(page.url()).pathname).toBe('/people');

    const search = peopleWindow.getByRole('searchbox', { name: 'Імʼя, e-mail або телефон' });
    await search.fill('Бондаренко');
    await expect.poll(() => new URL(page.url()).searchParams.get('q')).toBe('Бондаренко');
    const firstFilterEntry = await page.evaluate(() => ({
      url: `${location.pathname}${location.search}`,
      state: structuredClone(history.state),
    }));
    await search.fill('Євген');
    await expect.poll(() => new URL(page.url()).searchParams.get('q')).toBe('Євген');
    const secondFilterEntry = await page.evaluate(() => ({
      url: `${location.pathname}${location.search}`,
      state: structuredClone(history.state),
    }));

    // The text filter replaces the current entry; seed two snapshots captured from actual app navigations
    // so this exercises real browser popstate restoration for same-path query-only entries.
    const historyLengthBeforeQueryFixture = await page.evaluate(() => history.length);
    await page.evaluate(({ first, second }) => {
      history.replaceState(first.state, '', first.url);
      history.pushState(second.state, '', second.url);
    }, { first: firstFilterEntry, second: secondFilterEntry });
    await expect.poll(() => page.evaluate(() => history.length)).toBe(historyLengthBeforeQueryFixture + 1);
    await page.goBack();
    await expect.poll(() => new URL(page.url()).searchParams.get('q')).toBe('Бондаренко');
    await expect(search).toHaveValue('Бондаренко');
    await expect(page.locator('article.os-window.open')).toHaveCount(2);
    await expect(peopleWindow).toHaveClass(/active/);
    await page.goForward();
    await expect.poll(() => new URL(page.url()).searchParams.get('q')).toBe('Євген');
    await expect(search).toHaveValue('Євген');
    await expect(page.locator('article.os-window.open')).toHaveCount(2);

    const historyLengthBeforeBackgroundClose = await page.evaluate(() => history.length);
    await tasksWindow.locator('.window-titlebar').getByRole('button', { name: 'Закрити вікно', exact: true }).click();
    await expect(page.locator('article.os-window.open')).toHaveCount(1);
    await expect.poll(() => page.evaluate(() => history.length)).toBe(historyLengthBeforeBackgroundClose);
    await dock.getByRole('button', { name: 'Мої задачі', exact: true }).click();
    await expect(page.locator('article.os-window.open')).toHaveCount(2);

    // Relative links inside the real People outlet keep the dynamic route in that same slot.
    await page.getByTestId('dock-window-os2').click();
    await expect(peopleWindow).toHaveClass(/active/);
    const person = peopleWindow.getByRole('link', { name: /Бондаренко Євген/ }).first();
    await person.click();
    await expect.poll(() => new URL(page.url()).pathname).toBe('/people/70');
    await expect(peopleWindow).toContainText('Бондаренко Євген');
    await expect.poll(() => mock.requests).toContain('GET /api/people/70');
    expect(mock.missing).toEqual([]);

    await peopleWindow.locator('.window-titlebar').focus();
    await page.keyboard.press('ArrowRight');
    await page.getByTestId('window-resize-os2').focus();
    await page.keyboard.press('ArrowRight');
    const savedGeometry = await peopleWindow.evaluate((element) => ({ left: element.style.left, width: element.style.width }));
    const savedTasksGeometry = await tasksWindow.evaluate((element) => ({ left: element.style.left, width: element.style.width }));

    await tasksWindow.locator('.window-titlebar').getByRole('button', { name: 'Згорнути вікно', exact: true }).click();
    await expect(tasksWindow).toHaveClass(/minimized/);
    await peopleWindow.locator('.window-titlebar').getByRole('button', { name: 'Розгорнути вікно', exact: true }).click();
    await expect(peopleWindow).toHaveClass(/maximized/);

    await page.reload();
    await settle(page);
    await expect.poll(() => new URL(page.url()).pathname).toBe('/people/70');
    await expect(page.locator('article.os-window.open')).toHaveCount(2);
    await expect(tasksWindow).toHaveClass(/minimized/);
    await expect(peopleWindow).toHaveClass(/maximized/);
    await expect(page.getByTestId('os-window-os2')).toHaveClass(/active/);
    await page.getByTestId('dock-window-os1').click();
    await expect(tasksWindow).not.toHaveClass(/minimized/);
    await peopleWindow.locator('.window-titlebar').getByRole('button', { name: 'Розгорнути вікно', exact: true }).click();
    await expect(peopleWindow).not.toHaveClass(/maximized/);
    await expect(peopleWindow).toHaveCSS('left', savedGeometry.left);
    await expect(peopleWindow).toHaveCSS('width', savedGeometry.width);
    await expect(tasksWindow).toHaveCSS('left', savedTasksGeometry.left);
    await expect(tasksWindow).toHaveCSS('width', savedTasksGeometry.width);
  });

  test('a clean link opened in a separate tab does not inherit the source tab workspace', async ({ page, context }) => {
    await installMock(context);
    await page.clock.setSystemTime(RECORDED_AT);
    await page.goto('/tasks');
    await settle(page);
    await page.getByRole('navigation', { name: 'Вікна робочого простору' })
      .getByRole('button', { name: 'Мої задачі', exact: true }).click();
    await expect(page.locator('article.os-window.open')).toHaveCount(1);
    const cleanUrl = page.url();

    const sharedTab = await context.newPage();
    await sharedTab.goto(cleanUrl);
    await settle(sharedTab);

    await expect.poll(() => new URL(sharedTab.url()).pathname).toBe('/tasks');
    await expect(sharedTab.locator('article.os-window.open')).toHaveCount(0);
    await expect(sharedTab.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await sharedTab.close();
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
    const dock = page.getByRole('navigation', { name: 'Вікна робочого простору' });
    await dock.getByRole('button', { name: 'Мої задачі', exact: true }).click();
    const tasks = page.getByTestId('os-window-os1');
    await expect(tasks.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
    await dock.getByRole('button', { name: 'Співробітники', exact: true }).click();
    const people = page.getByTestId('os-window-os2');
    await expect(people.getByRole('heading', { level: 1, name: /Співробітники/ })).toBeVisible();
    await settle(page);

    const mountedBefore = await page.locator('app-os-window-host router-outlet + *').count();
    expect(mountedBefore).toBe(2);
    const apiRequestOffset = apiRequests.length;
    const startedAt = await page.evaluate(() => performance.now());
    for (let round = 0; round < 3; round++) {
      await page.getByTestId('dock-window-os1').click();
      await expect(tasks).toHaveClass(/active/);
      await tasks.locator('.window-titlebar').getByRole('button', { name: 'Згорнути вікно', exact: true }).click();
      await expect(tasks).toHaveClass(/minimized/);
      await expect(tasks).toBeHidden();
      await page.getByTestId('dock-window-os1').click();
      await expect(tasks).toHaveClass(/active/);
      await expect(tasks.getByRole('heading', { level: 1, name: 'Мої задачі' })).toBeVisible();
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

});

test.describe('OS routed windows on mobile', () => {
  test('mobile keeps inactive windows out of keyboard focus while primary and restore controls stay usable', async ({ page, context }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile-light', 'mobile-light only');
    await installMock(context);
    watchNetwork(page);
    for (const width of [390, 375]) {
      await page.setViewportSize({ width, height: 844 });
      await page.clock.setSystemTime(RECORDED_AT);
      await page.goto('/tasks');
      await settle(page);
      const dock = page.getByRole('navigation', { name: 'Вікна робочого простору' });
      await dock.getByRole('button', { name: 'Мої задачі', exact: true }).click();
      await dock.getByRole('button', { name: 'Співробітники', exact: true }).click();

      const inactive = page.locator('article.os-window.window-os1.mobile-inactive');
      await expect(inactive).toHaveAttribute('aria-hidden', 'true');
      await expect(inactive).toHaveAttribute('inert', '');
      await expect(inactive).toBeHidden();
      for (let index = 0; index < 16; index++) {
        await page.keyboard.press('Tab');
        expect(await inactive.evaluate((element) => element.contains(document.activeElement))).toBe(false);
      }

      await page.locator('.dock-home').click();
      await expect(page.locator('.primary-view')).toBeVisible();
      await expect(page.locator('article.os-window.open.mobile-inactive')).toHaveCount(2);
      const peopleWindow = page.getByTestId('os-window-os2');
      await page.getByTestId('dock-window-os2').click();
      await expect(peopleWindow).not.toHaveClass(/mobile-inactive/);

      await peopleWindow.locator('.window-titlebar').getByRole('button', { name: 'Згорнути вікно', exact: true }).click();
      await expect(peopleWindow).toHaveClass(/minimized/);
      await expect(peopleWindow).toBeHidden();
      const restore = page.getByTestId('dock-window-os2');
      await expect(restore).toBeVisible();
      await restore.click();
      await expect(peopleWindow).not.toHaveClass(/minimized/);
      await expect(peopleWindow.getByRole('heading', { level: 1, name: /Співробітники/ })).toBeVisible();
    }
  });
});
