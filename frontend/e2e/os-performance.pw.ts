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
      const runningControl = page.getByTestId(`dock-window-${module.slot}`);

      let startedAt = await page.evaluate(() => performance.now());
      await runningControl.click();
      await expect(window).toHaveClass(/active/);
      timings.push({ round, slot: module.slot, operation: 'focus', elapsedMs: await page.evaluate((started) => performance.now() - started, startedAt) });

      startedAt = await page.evaluate(() => performance.now());
      await window.locator('.window-titlebar').getByRole('button', { name: 'Згорнути вікно', exact: true }).click();
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
  await page.getByTestId('dock-window-os1').click();
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

  await page.keyboard.press('Escape');
  await expect(page.locator('#app-sidebar')).toBeHidden();
  await page.locator('.topbar .os-user-launcher').click();
  await page.getByRole('menuitem', { name: 'Зовнішній вигляд' }).click();
  await page.getByRole('switch', { name: 'Швидкий режим' }).click();
  await page.getByRole('switch', { name: 'Зменшити рух' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-performance', 'standard');
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'reduced');
  await page.locator('.topbar-brand').click();
  await expect(page.locator('.desktop-page')).toBeVisible();
  await page.getByTestId('dock-window-os1').click();
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

test('rendered desktop materials follow glass transparency, palette, solid, and fast preferences', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-light', 'desktop-light only; captures the appearance matrix once');

  await page.emulateMedia({ reducedMotion: 'no-preference' });
  const mock = await installMock(context);
  await page.clock.setSystemTime(RECORDED_AT);
  watchNetwork(page);
  await page.goto('/');
  await settle(page);
  await page.getByRole('navigation', { name: 'Вікна робочого простору' })
    .getByRole('button', { name: 'Мої задачі', exact: true }).click();

  const root = page.locator('html');
  const window = page.getByTestId('os-window-os1');
  await expect(window).toHaveClass(/open/);
  await expect(window.locator('.window-titlebar')).toBeVisible();
  await expect(page.locator('.desktop-page .widget-header').first()).toBeVisible();
  const slider = page.getByTestId('appearance-transparency');
  const openAppearance = async (): Promise<void> => {
    await page.locator('.topbar .os-user-launcher').click();
    await page.getByRole('menuitem', { name: 'Зовнішній вигляд', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Зовнішній вигляд' })).toBeVisible();
  };
  const returnToDesktop = async (): Promise<void> => {
    await page.locator('.topbar-brand').click();
    await expect(page.locator('.desktop-page')).toBeVisible();
    await page.getByTestId('dock-window-os1').click();
    await expect(window).toHaveClass(/active/);
  };
  const setTransparency = async (value: number): Promise<void> => {
    await slider.focus();
    await page.keyboard.press('Home');
    for (let step = 0; step < value; step++) await page.keyboard.press('ArrowRight');
    await expect(slider).toHaveValue(String(value));
  };

  type Surface = {
    readonly id: string;
    readonly missing: boolean;
    readonly backgroundAlpha: number;
    readonly backgroundColor: string;
    readonly backdropFilter: string;
    readonly boxShadow: string;
    readonly backgroundImage: string;
  };
  const captureMaterials = async (label: string, expectedAlpha: number): Promise<void> => {
    const state = await page.evaluate(() => {
      const alpha = (color: string): number => {
        const modern = color.match(/\/\s*([\d.]+)\s*\)$/);
        if (modern) return Number(modern[1]);
        const legacy = color.match(/^rgba\([^,]+,[^,]+,[^,]+,\s*([\d.]+)\)$/);
        return legacy ? Number(legacy[1]) : color === 'transparent' ? 0 : 1;
      };
      const selectors = [
        ['window-frame', '.os-window.window-os1.open'],
        ['window-titlebar', '.os-window.window-os1.open .window-titlebar'],
        ['window-body', '.os-window.window-os1.open .window-body'],
        ['widget-frame', '.desktop-page .widget'],
        ['widget-header', '.desktop-page .widget .widget-header'],
        ['widget-content', '.desktop-page .widget .widget-content'],
      ] as const;
      const surfaces: Surface[] = selectors.map(([id, selector]) => {
        const element = document.querySelector(selector);
        if (!element) return { id, missing: true, backgroundAlpha: Number.NaN, backgroundColor: '', backdropFilter: '', boxShadow: '', backgroundImage: '' };
        const style = getComputedStyle(element);
        return {
          id,
          missing: false,
          backgroundAlpha: alpha(style.backgroundColor),
          backgroundColor: style.backgroundColor,
          backdropFilter: style.backdropFilter,
          boxShadow: style.boxShadow,
          backgroundImage: style.backgroundImage,
        };
      });
      return {
        appearance: {
          theme: document.documentElement.dataset['theme'],
          colorScheme: document.documentElement.dataset['colorScheme'],
          interfaceStyle: document.documentElement.dataset['interfaceStyle'],
          performance: document.documentElement.dataset['performance'],
          glassOpacity: getComputedStyle(document.documentElement).getPropertyValue('--os-glass-opacity').trim(),
        },
        surfaces,
      };
    });

    expect(state.surfaces.filter((surface) => surface.missing), `${label}: rendered surfaces`).toEqual([]);
    const surfaces = state.surfaces as Surface[];
    const glassStandard = state.appearance.interfaceStyle === 'glass' && state.appearance.performance === 'standard';
    for (const id of ['window-titlebar', 'widget-header']) {
      const surface = surfaces.find((candidate) => candidate.id === id)!;
      expect(surface.backgroundAlpha, `${label}: ${id} background alpha`).toBeCloseTo(expectedAlpha, 2);
    }
    for (const id of ['window-frame', 'widget-frame']) {
      const surface = surfaces.find((candidate) => candidate.id === id)!;
      expect(surface.backgroundAlpha, `${label}: ${id} background alpha`).toBe(glassStandard ? 0 : 1);
    }
    for (const id of ['window-body', 'widget-content']) {
      const surface = surfaces.find((candidate) => candidate.id === id)!;
      expect(surface.backgroundAlpha, `${label}: ${id} remains opaque`).toBe(1);
    }

    if (glassStandard) {
      expect(surfaces.find((surface) => surface.id === 'window-titlebar')!.backdropFilter).toContain('blur');
      expect(surfaces.find((surface) => surface.id === 'widget-header')!.backdropFilter).toContain('blur');
      expect(surfaces.find((surface) => surface.id === 'window-frame')!.boxShadow).not.toBe('none');
      expect(surfaces.find((surface) => surface.id === 'window-titlebar')!.backgroundImage).not.toBe('none');
    } else {
      for (const id of ['window-titlebar', 'widget-header']) {
        expect(surfaces.find((surface) => surface.id === id)!.backdropFilter, `${label}: ${id} blur`).toBe('none');
      }
      expect(surfaces.find((surface) => surface.id === 'window-frame')!.boxShadow, `${label}: frame shadow`).toBe('none');
      expect(surfaces.find((surface) => surface.id === 'window-titlebar')!.backgroundImage, `${label}: reflection`).toBe('none');
    }

    await testInfo.attach(`${label}.json`, { body: JSON.stringify(state, null, 2), contentType: 'application/json' });
    await testInfo.attach(`${label}.png`, { body: await page.screenshot({ fullPage: false }), contentType: 'image/png' });
  };

  await expect(root).toHaveAttribute('data-theme', 'light');
  await expect(root).toHaveAttribute('data-interface-style', 'glass');
  await expect(root).toHaveAttribute('data-performance', 'standard');
  await captureMaterials('os-material-glass-default-light', 0.82);

  await openAppearance();
  await expect(slider).toHaveValue('18');
  await setTransparency(0);
  await expect(root).toHaveCSS('--os-glass-opacity', '100%');
  await returnToDesktop();
  await captureMaterials('os-material-glass-transparency-0', 1);

  await openAppearance();
  await setTransparency(40);
  await expect(root).toHaveCSS('--os-glass-opacity', '60%');
  await returnToDesktop();
  await captureMaterials('os-material-glass-transparency-40', 0.6);

  await openAppearance();
  await setTransparency(18);
  await page.getByRole('radio', { name: 'Темна', exact: true }).click();
  await expect(root).toHaveAttribute('data-theme', 'dark');
  await returnToDesktop();
  await captureMaterials('os-material-glass-default-dark', 0.82);

  await openAppearance();
  await page.getByTestId('appearance-scheme').selectOption('sintegrum');
  await expect(root).toHaveAttribute('data-color-scheme', 'sintegrum');
  await expect(root).toHaveAttribute('data-theme', 'dark');
  await returnToDesktop();
  await captureMaterials('os-material-glass-reference-palette', 0.82);

  await openAppearance();
  await page.getByRole('radio', { name: 'Суцільний', exact: true }).click();
  await expect(root).toHaveAttribute('data-interface-style', 'solid');
  await returnToDesktop();
  await captureMaterials('os-material-solid-reference-palette', 1);

  await openAppearance();
  await page.getByRole('radio', { name: 'Скло', exact: true }).click();
  await page.getByRole('switch', { name: 'Швидкий режим' }).click();
  await expect(root).toHaveAttribute('data-performance', 'fast');
  await returnToDesktop();
  await captureMaterials('os-material-fast-reference-palette', 1);
  expect(mock.missing).toEqual([]);
});
