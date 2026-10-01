// Page inventory of the UI parity harness: which routes (and which dialogs/menus on them) are snapshotted.
// Shared by the tests (e2e/parity.spec.ts) and the fixture recorder (e2e/record-fixtures.mjs).
//
// page:  { id, path, guest?, ready?, states?, only? }
//   id     — file name part of the snapshot (__snapshots__/<id>.<viewport>.json)
//   path   — route (fixture ids come from the demo data: 1 = first row)
//   guest  — no session (GET /api/auth/me answers 401)
//   ready  — text that must be visible before the page counts as loaded (besides network idle)
//   states — dialogs/menus opened by `steps`; each state is its own snapshot (<id>--<state>.<viewport>.json)
//   only   — 'desktop' | 'mobile': the state exists on one viewport only
// step:  { click: Target } | { fill: Target & { value } } | { press: key } | { check: Target } | { waitFor: Target }
//   Target = { role, name, exact?, nth? } (accessible role + name, like getByRole) or { text } or { css }

/** @typedef {{ role?: string, name?: string, exact?: boolean, nth?: number, text?: string, css?: string }} Target */
/** @typedef {{ click?: Target, fill?: Target & { value: string }, press?: string, check?: Target, waitFor?: Target }} Step */
/** @typedef {{ id: string, steps: Step[], only?: 'desktop' | 'mobile' }} State */
/** @typedef {{ id: string, path: string, guest?: boolean, ready?: string, states?: State[] }} Page */

// Personal board of vacancy 1 (the recorder gives it one own column «Мої [ТЕСТ]»).
export const boardSteps = [
  { click: { role: 'radio', name: 'Дошка' } },
  { click: { role: 'combobox', name: 'Вакансія' } },
  { click: { role: 'option', name: 'Менеджер з продажу [ТЕСТ]' } },
];
const drawer = { click: { role: 'button', name: 'Головне меню' } };
const userMenu = { click: { role: 'button', name: 'Меню користувача' } };
const navGroups = ['Рекрутинг', 'Люди', 'Продуктивність', 'Сервіси', 'Адміністрування'].map((name) => ({ click: { role: 'button', name } }));
/** @param {string} name @returns {State} */
const tab = (name) => ({ id: 'tab-' + name, only: 'desktop', steps: [{ click: { role: 'tab', name } }] });

/** @type {Page[]} */
export const PAGES = [
  {
    id: 'login',
    path: '/login',
    guest: true,
    states: [{ id: 'lang-en', steps: [{ click: { role: 'radio', name: 'en', exact: true } }] }],
  },
  {
    id: 'overview',
    path: '/',
    states: [
      { id: 'user-menu', only: 'desktop', steps: [userMenu] },
      { id: 'user-menu', only: 'mobile', steps: [drawer, userMenu] },
      { id: 'nav-expanded', only: 'desktop', steps: navGroups },
      { id: 'drawer', only: 'mobile', steps: [drawer, ...navGroups] },
    ],
  },
  { id: 'tasks', path: '/tasks' },
  { id: 'candidates', path: '/candidates', states: [{ id: 'board', steps: boardSteps }] },
  { id: 'candidate', path: '/candidates/1' },
  { id: 'vacancies', path: '/vacancies' },
  {
    id: 'vacancy-board',
    path: '/vacancies/1',
    states: [
      { id: 'move-menu', steps: [{ click: { role: 'button', name: 'Перемістити в…' } }] },
      { id: 'reject-dialog', steps: [{ click: { role: 'button', name: 'Перемістити в…' } }, { click: { role: 'menuitem', name: 'Відмова' } }] },
    ],
  },
  { id: 'vacancy-create', path: '/vacancies/create' },
  { id: 'vacancy-edit', path: '/vacancies/1/edit' },
  { id: 'inbox', path: '/inbox' },
  { id: 'hiring-requests', path: '/hiring-requests' },
  { id: 'hiring-request', path: '/hiring-requests/1' },
  { id: 'hiring-new', path: '/hiring-requests/new' },
  { id: 'reports', path: '/reports' },
  { id: 'report-catalog', path: '/reports/catalog' },
  { id: 'report-view', path: '/reports/catalog/desk_sla' },
  {
    id: 'people',
    path: '/people',
    states: [
      { id: 'cards', steps: [{ click: { role: 'radio', nth: 1 } }] },
      { id: 'employee-dialog', steps: [{ click: { role: 'button', name: 'Додати співробітника' } }] },
    ],
  },
  {
    id: 'person',
    path: '/people/1',
    states: ['Робота', 'Компенсація', 'Запити на зміни', 'Документи', 'Відсутності', 'Продуктивність', 'Воркфлоу', 'Активи'].map(tab),
  },
  { id: 'org-chart', path: '/people/org-chart' },
  { id: 'timeoff', path: '/timeoff' },
  { id: 'timeoff-calendar', path: '/timeoff/calendar' },
  { id: 'timeoff-approvals', path: '/timeoff/approvals' },
  { id: 'time', path: '/time' },
  { id: 'time-approvals', path: '/time/approvals' },
  { id: 'time-team', path: '/time/team' },
  { id: 'documents', path: '/me/documents' },
  { id: 'workflow-runs', path: '/workflows/runs' },
  { id: 'workflow-templates', path: '/admin/workflows' },
  {
    id: 'one-on-ones',
    path: '/perform/one-on-ones',
    states: [
      {
        id: 'person-picker',
        steps: [{ fill: { role: 'combobox', name: 'Співробітник', value: 'Ко' } }, { waitFor: { role: 'option', nth: 0 } }],
      },
    ],
  },
  { id: 'objectives', path: '/perform/objectives' },
  { id: 'feedback', path: '/perform/feedback' },
  { id: 'reviews', path: '/perform/reviews' },
  { id: 'pulse', path: '/pulse' },
  { id: 'pulse-mood', path: '/pulse/mood' },
  { id: 'desk', path: '/desk' },
  { id: 'desk-case', path: '/desk/cases/1' },
  { id: 'desk-queue', path: '/desk/queue' },
  { id: 'safe-speak', path: '/safe-speak' },
  { id: 'knowledge', path: '/knowledge' },
  { id: 'article', path: '/knowledge/1' },
  { id: 'assets', path: '/admin/assets' },
  { id: 'users', path: '/admin/users' },
  { id: 'modules', path: '/admin/modules' },
  { id: 'integrations', path: '/admin/integrations' },
  { id: 'audit', path: '/admin/audit' },
  { id: 'errors', path: '/admin/errors' },
  { id: 'privacy', path: '/admin/privacy' },
  { id: 'docs', path: '/docs' },
  { id: 'me', path: '/me' },
];

/** Fixed "now" of every test run = the moment the fixtures were recorded (dates in the data are relative to it). */
export const CLOCK_FILE = 'fixtures/meta.json';
