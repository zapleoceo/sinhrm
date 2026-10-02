// Interaction smoke of critical flows, with assertions on the requests the UI sends (mock API logs mutations).
import { expect, test, type Locator, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { RECORDED_AT, installMock, type Mock } from './harness';
import { boardSteps } from './pages.mjs';
import { runSteps, settle, watchNetwork } from './steps.mjs';

const writes = (mock: Mock) => mock.mutations.filter((m) => m.path !== '/sanctum/csrf-cookie');

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
  // eslint-disable-next-line no-empty-pattern -- Playwright fixtures need an object pattern
  test.beforeEach(({}, info) => test.skip(info.project.name !== 'desktop-light', 'desktop-light only'));

  async function personalBoard(page: Page, context: import('@playwright/test').BrowserContext): Promise<Mock> {
    const mock = await open(page, context, '/candidates');
    await runSteps(page, boardSteps);
    await expect(page.locator('section.column').first()).toBeVisible();
    return mock;
  }

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
    await field.pressSequentially('КОВАЛЕНКО');

    // No Enter: the list narrows on its own, the dialog stays open with the caret in the field.
    await expect(rows).toHaveCount(expected);
    await expect(page).toHaveURL(/[?&]name=%D0%9A%D0%9E%D0%92/);
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

  test('sidebar collapses to an icon rail and expands; the state survives a reload', async ({ page, context }) => {
    await open(page, context, '/');
    const sidebar = page.locator('#app-sidebar');
    const nav = page.getByRole('navigation', { name: 'Головне меню' });
    const collapse = page.getByRole('button', { name: 'Згорнути меню' });
    const expand = page.getByRole('button', { name: 'Розгорнути меню' });
    const width = async (): Promise<number> => (await sidebar.boundingBox())!.width;
    const pageOverflow = (): Promise<number> => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    const wide = await width();

    await expect(collapse).toHaveAttribute('aria-expanded', 'true');
    await collapse.click();
    await expect(expand).toHaveAttribute('aria-expanded', 'false');
    await expect.poll(width).toBeLessThanOrEqual(64);
    expect(await pageOverflow(), 'horizontal page scroll on the rail').toBeLessThanOrEqual(0);
    // Items keep their names (labels are visually hidden), a tooltip shows the name next to the icon.
    const tasks = nav.getByRole('link', { name: 'Мої задачі' });
    await tasks.hover();
    await expect(page.locator('.mat-mdc-tooltip').filter({ hasText: 'Мої задачі' })).toBeVisible();
    // The user menu stays reachable from the avatar.
    await expect(page.getByRole('button', { name: 'Меню користувача' })).toBeVisible();

    await page.reload();
    await settle(page);
    await expect(expand).toHaveAttribute('aria-expanded', 'false');
    expect(await width()).toBeLessThanOrEqual(64);

    // Keyboard: the toggle is a button (Enter), the sidebar comes back to its full width.
    await expand.focus();
    await page.keyboard.press('Enter');
    await expect(collapse).toHaveAttribute('aria-expanded', 'true');
    await expect.poll(width).toBe(wide);
    await page.reload();
    await settle(page);
    await expect(collapse).toBeVisible();
  });

  test('auto-hide: the rail opens over the content under the pointer and keyboard focus, folds back after a short delay', async ({ page, context }) => {
    await open(page, context, '/');
    const sidebar = page.locator('#app-sidebar');
    const main = page.locator('main.content');
    const autoHide = page.getByRole('button', { name: 'Автоприховування меню' });
    const width = async (): Promise<number> => (await sidebar.boundingBox())!.width;
    const mainX = async (): Promise<number> => (await main.boundingBox())!.x;
    const away = (): Promise<void> => page.mouse.move(900, 400);

    // Pinned 240px sidebar: logo + both toggles fit in the head (no overflow, buttons inside the sidebar).
    const head = page.locator('.nav-head');
    expect(await head.evaluate((e) => e.scrollWidth - e.clientWidth)).toBeLessThanOrEqual(0);
    const box = (await sidebar.boundingBox())!;
    const btn = (await autoHide.boundingBox())!;
    expect(btn.x + btn.width).toBeLessThanOrEqual(box.x + box.width);

    await expect(autoHide).toHaveAttribute('aria-pressed', 'false');
    await autoHide.click();
    await expect(autoHide).toHaveAttribute('aria-pressed', 'true');
    // Switched on under the pointer: open until the pointer leaves, then a rail; the content keeps its place.
    await expect(page.getByRole('button', { name: 'Згорнути меню' })).toHaveCount(0);
    await away();
    await expect.poll(width).toBeLessThanOrEqual(64);
    const x = await mainX();

    await sidebar.hover({ position: { x: 32, y: 300 } });
    await expect.poll(width).toBeGreaterThan(200);
    expect(await mainX(), 'the content does not move when the sidebar opens').toBe(x);
    // A short overshoot is forgiven: back within the delay, still open.
    await page.mouse.move(400, 300);
    await page.mouse.move(100, 300);
    await page.waitForTimeout(400);
    expect(await width()).toBeGreaterThan(200);
    await away();
    await expect.poll(width).toBeLessThanOrEqual(64);

    // The open user menu keeps the sidebar open while the pointer is over the menu.
    await sidebar.hover({ position: { x: 32, y: 300 } });
    await expect.poll(width).toBeGreaterThan(200);
    await page.getByRole('button', { name: 'Меню користувача' }).click();
    await expect(page.getByRole('menu')).toBeVisible();
    await page.getByRole('menu').hover();
    await away();
    await page.waitForTimeout(400);
    expect(await width()).toBeGreaterThan(200);
    await page.mouse.click(900, 400); // closes the menu
    await expect(page.getByRole('menu')).toHaveCount(0);
    await expect.poll(width).toBeLessThanOrEqual(64);

    // Keyboard: focus inside keeps it open, Esc folds it, focus leaving folds it.
    await autoHide.focus();
    await page.keyboard.press('Tab');
    await expect.poll(width).toBeGreaterThan(200);
    await page.waitForTimeout(400);
    expect(await width()).toBeGreaterThan(200);
    await page.keyboard.press('Escape');
    await expect.poll(width).toBeLessThanOrEqual(64);
    await page.keyboard.press('Tab');
    await expect.poll(width).toBeGreaterThan(200);
    await page.getByRole('button', { name: 'Оновити' }).focus();
    await expect.poll(width).toBeLessThanOrEqual(64);

    // Remembered; switching it off brings back the pinned sidebar.
    await page.reload();
    await settle(page);
    await expect(autoHide).toHaveAttribute('aria-pressed', 'true');
    expect(await width()).toBeLessThanOrEqual(64);
    await autoHide.focus();
    await page.keyboard.press('Enter');
    await expect(autoHide).toHaveAttribute('aria-pressed', 'false');
    await expect(page.getByRole('button', { name: 'Згорнути меню' })).toBeVisible();
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

  test('drawer opens, closes, and closes on navigation', async ({ page, context }) => {
    await open(page, context, '/');
    const burger = page.getByRole('button', { name: 'Головне меню' });
    const nav = page.getByRole('navigation', { name: 'Головне меню' });
    await expect(burger).toHaveAttribute('aria-expanded', 'false');
    await burger.click();
    await expect(burger).toHaveAttribute('aria-expanded', 'true');
    await expect(nav).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(burger).toHaveAttribute('aria-expanded', 'false');
    await burger.click();
    await nav.getByRole('link', { name: 'Мої задачі' }).click();
    await expect(page).toHaveURL(/\/tasks$/);
    await expect(burger).toHaveAttribute('aria-expanded', 'false');
  });
});
