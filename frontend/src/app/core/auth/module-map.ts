/**
 * Which backend module (App\Modules\*, key = kebab-case folder name) owns a SPA page.
 * Used by moduleGuard (redirect to "Розділ вимкнено") and the sidebar (hide items). See docs/modules/modules-access.md.
 * Pages not listed here (overview, tasks, docs, users, integrations, dictionaries, status) belong to core modules.
 */
const URL_MODULES: readonly (readonly [prefix: string, module: string])[] = [
  ['/candidates', 'recruiting'],
  ['/vacancies', 'recruiting'],
  ['/inbox', 'recruiting'],
  ['/reports', 'recruiting'],
  ['/settings/extension', 'recruiting'],
  ['/admin/acquisition-channels', 'recruiting'],
  ['/hiring-requests', 'hiring-requests'],
  ['/admin/hiring-requests', 'hiring-requests'],
  ['/admin/scripts', 'scripts'],
  ['/people', 'people'],
  ['/me', 'people'],
  ['/me/documents', 'documents'],
  ['/admin/documents', 'documents'],
  ['/timeoff', 'time-off'],
  ['/admin/timeoff', 'time-off'],
  ['/time', 'time'],
  ['/admin/time', 'time'],
  ['/workflows', 'workflows'],
  ['/admin/workflows', 'workflows'],
  ['/perform', 'perform'],
  ['/admin/perform', 'perform'],
  ['/pulse', 'pulse'],
  ['/admin/pulse', 'pulse'],
  ['/desk', 'desk'],
  ['/safe-speak', 'safe-speak'],
  ['/knowledge', 'knowledge'],
  ['/admin/knowledge', 'knowledge'],
  ['/reports/catalog', 'reports'],
  ['/reports/builder', 'reports'],
  ['/admin/assets', 'assets'],
  ['/admin/mail', 'mail-agent'],
  ['/admin/sheets-import', 'google-workspace'],
];

/** Module of a URL (longest matching prefix wins), or null for pages of core modules. */
export function moduleForUrl(url: string): string | null {
  const path = url.split(/[?#]/)[0];
  let best: string | null = null;
  let bestLen = 0;
  for (const [prefix, module] of URL_MODULES) {
    const hit = path === prefix || path.startsWith(prefix + '/');
    if (hit && prefix.length > bestLen) {
      best = module;
      bestLen = prefix.length;
    }
  }
  return best;
}
