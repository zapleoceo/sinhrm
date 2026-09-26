import { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { faGoogle } from '@fortawesome/free-brands-svg-icons';
import {
  faBook,
  faBriefcase,
  faBullseye,
  faChartColumn,
  faClipboardList,
  faClock,
  faComments,
  faEnvelope,
  faFileSignature,
  faGlobe,
  faHeadset,
  faHouse,
  faKeyboard,
  faListCheck,
  faPlug,
  faRobot,
  faRoute,
  faSquarePollVertical,
  faTable,
  faUmbrellaBeach,
  faUser,
  faUserSecret,
  faWandMagicSparkles,
} from '@fortawesome/free-solid-svg-icons';
import { DocPage } from './docs.model';

/** One block of the "how SinHRM works" map. `slugs`: doc pages it may open, the first visible one wins; none visible → block hidden. */
export interface MapBlock {
  id: string;
  icon: IconDefinition;
  slugs: readonly string[];
}

export interface VisibleBlock extends MapBlock {
  slug: string;
}

export type MapZone = 'sources' | 'recruiting' | 'people' | 'daily' | 'helpers';
export const MAP_ZONES: readonly MapZone[] = ['sources', 'recruiting', 'people', 'daily', 'helpers'];

export const DOCS_MAP: Readonly<Record<MapZone, readonly MapBlock[]>> = {
  sources: [
    { id: 'jobSites', icon: faGlobe, slugs: ['extension', 'mail-agent'] },
    { id: 'mail', icon: faEnvelope, slugs: ['mail-agent', 'recruiting'] },
    { id: 'messengers', icon: faComments, slugs: ['channels'] },
    { id: 'sheets', icon: faTable, slugs: ['recruiting'] },
    { id: 'manual', icon: faKeyboard, slugs: ['recruiting'] },
  ],
  recruiting: [
    { id: 'pipeline', icon: faBriefcase, slugs: ['recruiting'] },
    { id: 'scripts', icon: faListCheck, slugs: ['scripts'] },
    { id: 'aiScreening', icon: faWandMagicSparkles, slugs: ['ai', 'recruiting'] },
    { id: 'hiringRequests', icon: faFileSignature, slugs: ['hiring-requests'] },
  ],
  people: [
    { id: 'employee', icon: faUser, slugs: ['people'] },
    { id: 'onboarding', icon: faRoute, slugs: ['workflows', 'documents'] },
    { id: 'timeoff', icon: faUmbrellaBeach, slugs: ['timeoff'] },
    { id: 'time', icon: faClock, slugs: ['time'] },
    { id: 'perform', icon: faBullseye, slugs: ['perform'] },
    { id: 'pulse', icon: faSquarePollVertical, slugs: ['pulse'] },
    { id: 'desk', icon: faHeadset, slugs: ['desk'] },
    { id: 'safeSpeak', icon: faUserSecret, slugs: ['safe-speak'] },
  ],
  daily: [
    { id: 'tasks', icon: faClipboardList, slugs: ['tasks'] },
    { id: 'overview', icon: faHouse, slugs: ['overview'] },
    { id: 'reports', icon: faChartColumn, slugs: ['reports'] },
    { id: 'knowledge', icon: faBook, slugs: ['knowledge'] },
  ],
  helpers: [
    { id: 'ai', icon: faRobot, slugs: ['ai'] },
    { id: 'google', icon: faGoogle, slugs: ['google-workspace'] },
    { id: 'integrations', icon: faPlug, slugs: ['integrations'] },
  ],
};

/** Blocks the user can open — the same role filter as the list on the left (only already visible doc pages count). */
export function visibleMap(docs: readonly DocPage[]): Record<MapZone, VisibleBlock[]> {
  const have = new Set(docs.map((d) => d.slug));
  const out = {} as Record<MapZone, VisibleBlock[]>;
  for (const zone of MAP_ZONES) {
    out[zone] = DOCS_MAP[zone].flatMap((b) => {
      const slug = b.slugs.find((s) => have.has(s));
      return slug ? [{ ...b, slug }] : [];
    });
  }
  return out;
}
