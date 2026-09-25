import { Channel, StageKind } from '../recruiting/recruiting.model';

/** GET /api/dashboard (backend Overview DashboardService). */
export interface Dashboard {
  counts: { active: number; stale: number; unmatched_inbox: number; new_today: number };
  stale_days: number;
  my_tasks: {
    total: number;
    overdue: number;
    items: { id: number; title: string; type: string; due_at: string; candidate: { id: number; name: string } | null }[];
  };
  stale: {
    application_id: number;
    candidate: { id: number; name: string };
    vacancy: { id: number; title: string };
    stage: string;
    last_activity_at: string | null;
    days: number;
  }[];
  funnel: { stage_name: string; stage_kind: StageKind; position: number; count: number }[];
  touches: { days: number; by_channel: { channel: Channel; count: number }[] };
}

/** A counter tile: i18n key, value and where a click leads. */
export interface StatTile {
  key: 'active' | 'stale' | 'unmatched_inbox' | 'new_today';
  value: number;
  link: string;
  icon: string;
  tone: 'neutral' | 'warning';
}

export function statTiles(d: Dashboard): StatTile[] {
  return [
    { key: 'active', value: d.counts.active, link: '/candidates', icon: 'groups', tone: 'neutral' },
    { key: 'stale', value: d.counts.stale, link: '/candidates', icon: 'schedule', tone: d.counts.stale > 0 ? 'warning' : 'neutral' },
    { key: 'unmatched_inbox', value: d.counts.unmatched_inbox, link: '/inbox', icon: 'inbox', tone: d.counts.unmatched_inbox > 0 ? 'warning' : 'neutral' },
    { key: 'new_today', value: d.counts.new_today, link: '/vacancies', icon: 'person_add', tone: 'neutral' },
  ];
}
