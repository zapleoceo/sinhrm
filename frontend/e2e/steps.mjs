// Runs the declarative steps of e2e/pages.mjs on a Playwright page (shared by the tests and the recorder).

/** @param {import('@playwright/test').Page} page @param {import('./pages.mjs').Target} t */
export function locate(page, t) {
  let loc;
  if (t.css) loc = page.locator(t.css);
  else if (t.text) loc = page.getByText(t.text, { exact: t.exact ?? false });
  else loc = page.getByRole(/** @type {any} */ (t.role), { name: t.name, exact: t.exact ?? false });
  return t.nth === undefined ? loc.first() : loc.nth(t.nth);
}

/** @param {import('@playwright/test').Page} page @param {import('./pages.mjs').Step[]} steps */
export async function runSteps(page, steps) {
  for (const s of steps) {
    if (s.click) await locate(page, s.click).click();
    else if (s.fill) await locate(page, s.fill).fill(s.fill.value);
    else if (s.check) await locate(page, s.check).check();
    else if (s.press) await page.keyboard.press(s.press);
    else if (s.waitFor) await locate(page, s.waitFor).waitFor({ state: 'visible' });
    // Longer quiet window after a click: tab bodies and dialogs start their requests after their open animation.
    await settle(page, 700);
  }
}

/** @type {WeakMap<import('@playwright/test').Page, { inflight: number, last: number }>} */
const net = new WeakMap();

/** Counts in-flight requests of a page (waitForLoadState('networkidle') only covers the first load). */
function track(page) {
  let n = net.get(page);
  if (n) return n;
  n = { inflight: 0, last: Date.now() };
  const t = n;
  page.on('request', () => {
    t.inflight++;
    t.last = Date.now();
  });
  const done = () => {
    t.inflight = Math.max(0, t.inflight - 1);
    t.last = Date.now();
  };
  page.on('requestfinished', done);
  page.on('requestfailed', done);
  net.set(page, n);
  return n;
}

/** Call before page.goto: starts counting requests so settle() can wait for them. */
export function watchNetwork(page) {
  track(page);
}

/**
 * Quiet for 300 ms (no request in flight, none started) + two animation frames: the page has rendered what the
 * answers brought. Debounced inputs (person picker, 250 ms) start their request inside that window.
 */
export async function settle(page, quietMs = 300) {
  const n = track(page);
  await page.waitForLoadState('load');
  const deadline = Date.now() + 15_000;
  while (Date.now() < deadline && (n.inflight > 0 || Date.now() - n.last < quietMs)) await page.waitForTimeout(50);
  await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r(undefined)))));
}
