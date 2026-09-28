/** Route groups with their own quips (assistant.quips.<group>.<n> in i18n). */
export type QuipGroup =
  | 'overview'
  | 'recruiting'
  | 'people'
  | 'timeoff'
  | 'perform'
  | 'knowledge'
  | 'desk'
  | 'reports'
  | 'admin'
  | 'generic';

/** How many quips each group has in every language (quips.spec.ts checks the i18n files). */
export const QUIP_COUNTS: Readonly<Record<QuipGroup, number>> = {
  overview: 3,
  recruiting: 4,
  people: 3,
  timeoff: 3,
  perform: 3,
  knowledge: 3,
  desk: 3,
  reports: 3,
  admin: 3,
  generic: 8,
};
export const GREETING_COUNT = 4;

/** First path segment → group; longest match wins for nested areas. */
const PREFIXES: readonly [string, QuipGroup][] = [
  ['/reports/catalog', 'reports'],
  ['/reports/builder', 'reports'],
  ['/admin', 'admin'],
  ['/status', 'admin'],
  ['/candidates', 'recruiting'],
  ['/vacancies', 'recruiting'],
  ['/inbox', 'recruiting'],
  ['/hiring-requests', 'recruiting'],
  ['/reports', 'recruiting'],
  ['/people', 'people'],
  ['/me', 'people'],
  ['/workflows', 'people'],
  ['/time', 'timeoff'],
  ['/timeoff', 'timeoff'],
  ['/perform', 'perform'],
  ['/pulse', 'perform'],
  ['/knowledge', 'knowledge'],
  ['/docs', 'knowledge'],
  ['/desk', 'desk'],
  ['/safe-speak', 'desk'],
  ['/tasks', 'overview'],
];

export function quipGroupForUrl(url: string): QuipGroup {
  const path = url.split(/[?#]/)[0];
  if (path === '/' || path === '') {
    return 'overview';
  }
  let best: [string, QuipGroup] | null = null;
  for (const entry of PREFIXES) {
    const [prefix] = entry;
    if ((path === prefix || path.startsWith(`${prefix}/`)) && (!best || prefix.length > best[0].length)) {
      best = entry;
    }
  }
  return best ? best[1] : 'generic';
}

/** i18n key of a random quip: half the time a route quip, otherwise a generic one. */
export function pickQuip(url: string, rng: () => number): string {
  const routeGroup = quipGroupForUrl(url);
  const group: QuipGroup = routeGroup !== 'generic' && rng() < 0.6 ? routeGroup : 'generic';
  return `assistant.quips.${group}.${Math.floor(rng() * QUIP_COUNTS[group])}`;
}

export function pickGreeting(rng: () => number): string {
  return `assistant.greetings.${Math.floor(rng() * GREETING_COUNT)}`;
}
