import { expect, test } from '@playwright/test';
import { RECORDED_AT, installMock } from './harness';
import { settle, watchNetwork } from './steps.mjs';

type ModuleLaunch = {
  readonly slot: 'os1' | 'os2' | 'os3' | 'os4';
  readonly label: string;
};

type TimedOperation = {
  readonly round: number;
  readonly slot: ModuleLaunch['slot'];
  readonly operation: 'open' | 'focus' | 'minimize' | 'restore';
  readonly elapsedMs: number;
};

const MODULES: readonly ModuleLaunch[] = [
  { slot: 'os1', label: 'Мої задачі' },
  { slot: 'os2', label: 'Співробітники' },
  { slot: 'os3', label: 'Мої звернення' },
  { slot: 'os4', label: 'Звіти' },
];

test('four routed Dock windows keep their pages through controls and report browser performance samples', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-light', 'desktop-light only; mobile coverage remains in the existing OS flows');

  const apiRequests: { method: string; path: string }[] = [];
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) {
      apiRequests.push({ method: request.method(), path: url.pathname });
    }
  });
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  const mock = await installMock(context);
  await page.clock.setSystemTime(RECORDED_AT);
  watchNetwork(page);
  await page.goto('/');
  await settle(page);
  await expect(page.locator('html')).toHaveAttribute('data-interface-style', 'glass');
  await expect(page.locator('html')).toHaveAttribute('data-performance', 'standard');
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'normal');
  const glassFilters = await page.evaluate(() => ['.topbar', '.os-dock', '.desktop-page .widget-header'].map((selector) => {
    const element = document.querySelector(selector);
    return element ? getComputedStyle(element).backdropFilter : '';
  }));
  expect(glassFilters).toHaveLength(3);
  for (const filter of glassFilters) expect(filter).toContain('blur');

  const dock = page.getByRole('navigation', { name: 'Вікна робочого простору' });
  const timings: TimedOperation[] = [];
  for (const module of MODULES) {
    const startedAt = await page.evaluate(() => performance.now());
    await dock.getByRole('button', { name: module.label, exact: true }).click();
    const window = page.getByTestId(`os-window-${module.slot}`);
    await expect(window).toHaveClass(/open/);
    await expect(window.locator('app-os-window-host router-outlet + *')).toHaveCount(1);
    await expect(window.locator('.window-titlebar h2')).toBeVisible();
    await settle(page, 500);
    timings.push({
      round: 0,
      slot: module.slot,
      operation: 'open',
      elapsedMs: await page.evaluate((started) => performance.now() - started, startedAt),
    });
  }

  const mountedBefore = await page.locator('app-os-window-host router-outlet + *').count();
  expect(mountedBefore).toBe(4);
  for (const module of MODULES) {
    await expect(page.getByTestId(`os-window-${module.slot}`)).toBeVisible();
  }
  await testInfo.attach('os-four-module-windows.png', {
    body: await page.screenshot({ fullPage: false }),
    contentType: 'image/png',
  });

  const apiRequestOffset = apiRequests.length;
  const frameSamplePromise = page.evaluate(() => new Promise<number[]>((resolve) => {
    const intervalsMs: number[] = [];
    let previous = performance.now();
    const sample = (now: number): void => {
      intervalsMs.push(now - previous);
      previous = now;
      if (intervalsMs.length === 180) resolve(intervalsMs);
      else requestAnimationFrame(sample);
    };
    requestAnimationFrame(sample);
  }));

  for (let round = 1; round <= 2; round++) {
    for (const module of MODULES) {
      const window = page.getByTestId(`os-window-${module.slot}`);
      const appButton = dock.getByRole('button', { name: module.label, exact: true });

      let startedAt = await page.evaluate(() => performance.now());
      await appButton.click();
      await expect(window).toHaveClass(/active/);
      timings.push({ round, slot: module.slot, operation: 'focus', elapsedMs: await page.evaluate((started) => performance.now() - started, startedAt) });

      startedAt = await page.evaluate(() => performance.now());
      await window.getByRole('button', { name: 'Згорнути вікно' }).click();
      await expect(window).toHaveClass(/minimized/);
      timings.push({ round, slot: module.slot, operation: 'minimize', elapsedMs: await page.evaluate((started) => performance.now() - started, startedAt) });

      startedAt = await page.evaluate(() => performance.now());
      await page.getByTestId(`dock-window-${module.slot}`).click();
      await expect(window).not.toHaveClass(/minimized/);
      await expect(window).toHaveClass(/active/);
      timings.push({ round, slot: module.slot, operation: 'restore', elapsedMs: await page.evaluate((started) => performance.now() - started, startedAt) });
    }
  }
  await settle(page, 500);
  const controlRequests = apiRequests.slice(apiRequestOffset);
  expect(controlRequests).toEqual([]);
  expect(await page.locator('app-os-window-host router-outlet + *').count()).toBe(mountedBefore);
  for (const module of MODULES) {
    await expect(page.getByTestId(`os-window-${module.slot}`)).toHaveClass(/open/);
    await expect(page.getByTestId(`os-window-${module.slot}`).locator('app-os-window-host router-outlet + *')).toHaveCount(1);
  }

  const browserSample = await page.evaluate(async ({ frameIntervalsMs, apiRequestCount, recordedAt }) => {
    const memory = (performance as Performance & { memory?: { usedJSHeapSize: number; totalJSHeapSize: number } }).memory;
    return {
      userAgent: navigator.userAgent,
      viewport: { width: innerWidth, height: innerHeight, devicePixelRatio },
      fixtureContext: {
        route: location.pathname,
        recordedAt,
        apiRequestCount,
      },
      appearance: {
        colorScheme: document.documentElement.dataset['theme'],
        interfaceStyle: document.documentElement.dataset['interfaceStyle'],
        performance: document.documentElement.dataset['performance'],
        motion: document.documentElement.dataset['motion'],
      },
      frameSample: { intervalsMs: frameIntervalsMs, count: frameIntervalsMs.length },
      heapBytes: memory ? { used: memory.usedJSHeapSize, total: memory.totalJSHeapSize } : null,
    };
  }, { frameIntervalsMs: await frameSamplePromise, apiRequestCount: apiRequests.length, recordedAt: RECORDED_AT.toISOString() });

  await testInfo.attach('os-four-module-performance.json', {
    body: JSON.stringify({
      scenario: 'Four live routed modules launched from the OS Dock; two focus/minimize/restore cycles per window.',
      modules: MODULES.map(({ slot }) => slot),
      timings,
      controlOperations: {
        apiRequests: controlRequests.length,
        mountedViewsBefore: mountedBefore,
        mountedViewsAfter: await page.locator('app-os-window-host router-outlet + *').count(),
      },
      browserSample: {
        ...browserSample,
        fixtureContext: {
          ...browserSample.fixtureContext,
          mockedApi: true,
          fixtureRequestCount: mock.requests.length,
          unmatchedFixtureCount: mock.missing.length,
        },
      },
    }, null, 2),
    contentType: 'application/json',
  });
  expect(mock.missing).toEqual([]);

  await page.locator('.topbar .os-user-launcher').click();
  await page.getByRole('menuitem', { name: 'Зовнішній вигляд' }).click();
  await expect(page.getByRole('heading', { name: 'Зовнішній вигляд' })).toBeVisible();
  await expect(page.getByRole('switch', { name: 'Швидкий режим' })).toHaveAttribute('aria-checked', 'false');
  await page.getByRole('switch', { name: 'Швидкий режим' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-performance', 'fast');

  await page.locator('.topbar-brand').click();
  await expect(page.locator('.desktop-page')).toBeVisible();
  await dock.getByRole('button', { name: MODULES[0].label, exact: true }).click();
  await page.locator('.modules-toggle').click();
  await expect(page.locator('#app-sidebar.open')).toBeVisible();
  for (const surface of [page.locator('.topbar'), page.locator('#app-sidebar'), page.locator('.os-dock'), page.locator('.os-window.active .window-titlebar'), page.locator('.desktop-page .widget-header').first()]) {
    await expect(surface).toBeVisible();
  }
  const fastChrome = await page.evaluate(() => {
    const selectors = ['.topbar', '#app-sidebar', '.os-dock', '.os-window.active .window-titlebar', '.desktop-page .widget-header'];
    return selectors.map((selector) => {
      const element = document.querySelector(selector);
      if (!element) return { selector, missing: true, filter: '', transitionDuration: '' };
      const style = getComputedStyle(element);
      return {
        selector,
        missing: false,
        filter: style.filter,
        backdropFilter: style.backdropFilter,
        webkitBackdropFilter: style.getPropertyValue('-webkit-backdrop-filter'),
        transitionDuration: style.transitionDuration,
      };
    });
  });
  for (const surface of fastChrome) {
    expect(surface.missing, `${surface.selector} is rendered in fast mode`).toBe(false);
    expect(surface.filter).toBe('none');
    expect(surface.backdropFilter).toBe('none');
    expect(surface.webkitBackdropFilter === '' || surface.webkitBackdropFilter === 'none').toBe(true);
    expect(surface.transitionDuration.split(',').every((value) => Number.parseFloat(value) === 0)).toBe(true);
  }

  await page.locator('.modules-toggle').click();
  await page.locator('.topbar .os-user-launcher').click();
  await page.getByRole('menuitem', { name: 'Зовнішній вигляд' }).click();
  await page.getByRole('switch', { name: 'Швидкий режим' }).click();
  await page.getByRole('switch', { name: 'Зменшити рух' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-performance', 'standard');
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'reduced');
  await page.locator('.topbar-brand').click();
  await expect(page.locator('.desktop-page')).toBeVisible();
  await dock.getByRole('button', { name: MODULES[0].label, exact: true }).click();
  await page.locator('.modules-toggle').click();
  for (const surface of [page.locator('.topbar'), page.locator('#app-sidebar'), page.locator('.os-dock'), page.locator('.os-window.active .window-titlebar'), page.locator('.desktop-page .widget-header').first()]) {
    await expect(surface).toBeVisible();
  }
  const reducedMotionChrome = await page.evaluate(() => {
    const selectors = ['.topbar', '#app-sidebar', '.os-dock', '.os-window.active .window-titlebar', '.desktop-page .widget-header'];
    return selectors.map((selector) => {
      const element = document.querySelector(selector);
      if (!element) return { selector, missing: true, transitionDuration: '' };
      return { selector, missing: false, transitionDuration: getComputedStyle(element).transitionDuration };
    });
  });
  for (const surface of reducedMotionChrome) {
    expect(surface.missing, `${surface.selector} is rendered with reduced motion`).toBe(false);
    expect(surface.transitionDuration.split(',').every((value) => Number.parseFloat(value) === 0)).toBe(true);
  }
});
