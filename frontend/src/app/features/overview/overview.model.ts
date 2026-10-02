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
  /** Captions under the funnel; null parts = too little data (not shown). May be absent on older API versions. */
  funnel_insights?: FunnelInsights;
  /** «Маршрут дня»: today's interviews and my tasks. May be absent on older API versions. */
  day_route?: DayRoute;
  /** Notices of other modules (e.g. Google needs reconnecting); may be absent on older API versions. */
  warnings?: DashboardWarning[];
  /** TimeOff block (DashboardSection "timeoff"): who is out today and my pending approvals, in the user's scope. */
  timeoff?: TimeOffDashboard;
  /** HiringRequests block (DashboardSection "hiring"): requests waiting for my decision. */
  hiring?: HiringDashboard;
  /** Time block (DashboardSection "time"): my current week and timesheets waiting for my approval. */
  time?: TimeDashboard;
}

/** backend Overview FunnelInsightsService. */
export interface FunnelInsights {
  period_days: number;
  min_sample: number;
  min_offer_observations: number;
  bottleneck: { from: string; to: string; from_kind: StageKind; to_kind: StageKind; conversion: number; passed: number; decided: number } | null;
  offer_path: { days: number; observations: number } | null;
}

/** backend Overview DayRouteService. */
export interface DayRoute {
  /** The user's day (Y-m-d) in `timezone`; the backend cuts «today» in that zone, not in UTC. */
  date: string;
  /** IANA zone of the day (config app.user_timezone, e.g. Europe/Kyiv); older answers had none → the browser's zone. */
  timezone?: string;
  interviews: number;
  tasks: number;
  items: DayRouteItem[];
}

export interface DayRouteItem {
  kind: 'interview' | 'task';
  id: number;
  at: string;
  end: string | null;
  title: string | null;
  meeting_type?: string | null;
  type?: string;
  candidate: { id: number; name: string } | null;
}

/** A station on the day line: position in % of the visible hours. */
export interface RouteStop extends DayRouteItem {
  left: number;
  time: string;
}

/** The visible hours of the day line: 9:00–19:00, widened to whole hours around the earliest/latest event. */
export interface RouteScale {
  from: number;
  to: number;
  hours: { h: number; left: number }[];
  /** «Now» in % of the line, null when outside the visible hours or not today. */
  now: number | null;
  nowLabel: string;
}

const pad = (n: number): string => String(n).padStart(2, '0');

/** Wall clock of a moment in a time zone (the browser's when none): date 'YYYY-MM-DD', hour and minute. */
export interface WallClock {
  date: string;
  h: number;
  m: number;
}

export function wallClock(d: Date, timeZone?: string): WallClock {
  if (!timeZone) {
    return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, h: d.getHours(), m: d.getMinutes() };
  }
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(d);
  const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find((p) => p.type === type)?.value ?? '00';
  return { date: `${part('year')}-${part('month')}-${part('day')}`, h: Number(part('hour')), m: Number(part('minute')) };
}

/** Hour (with fraction) of an ISO time on the wall clock of the zone. */
function hourOf(iso: string, timeZone?: string): number {
  const w = wallClock(new Date(iso), timeZone);
  return w.h + w.m / 60;
}

/**
 * Visible hours, «now» and its label. Everything is read on the wall clock of `timeZone` — the zone the backend cut the
 * day in (day_route.timezone) — so the line, the stations and «зараз» agree with `date` even when the browser's zone or
 * the UTC day differ (00:00–03:00 in Kyiv is still «yesterday» in UTC).
 */
export function routeScale(items: DayRouteItem[], now: Date, date: string, timeZone?: string): RouteScale {
  const hrs = items.map((i) => hourOf(i.at, timeZone));
  const from = Math.max(0, Math.min(9, Math.floor(Math.min(9, ...hrs))));
  const to = Math.min(24, Math.max(19, Math.ceil(Math.max(19, ...hrs))));
  const span = to - from;
  const pct = (h: number): number => Math.round(((h - from) / span) * 1000) / 10;
  const hours: { h: number; left: number }[] = [];
  const step = span > 12 ? 3 : 2;
  for (let h = from; h <= to; h += step) {
    hours.push({ h, left: pct(h) });
  }
  const w = wallClock(now, timeZone);
  const n = w.h + w.m / 60;
  return {
    from,
    to,
    hours,
    now: w.date === date && n >= from && n <= to ? pct(n) : null,
    nowLabel: `${pad(w.h)}:${pad(w.m)}`,
  };
}

export function routeStops(items: DayRouteItem[], scale: RouteScale, timeZone?: string): RouteStop[] {
  const span = scale.to - scale.from;
  return items.map((i) => {
    const w = wallClock(new Date(i.at), timeZone);
    return { ...i, left: Math.round(((w.h + w.m / 60 - scale.from) / span) * 1000) / 10, time: `${pad(w.h)}:${pad(w.m)}` };
  });
}

/** Segments of the «touches» stacked bar: share of the total, a fixed colour slot by order (legend carries the text). */
export function touchSegments(rows: { channel: Channel; count: number }[]): { channel: Channel; count: number; share: number; slot: number }[] {
  const total = rows.reduce((s, r) => s + r.count, 0);
  return rows.filter((r) => r.count > 0).map((r, i) => ({ ...r, share: total ? Math.round((r.count / total) * 1000) / 10 : 0, slot: i % 7 }));
}

export interface HiringDashboard {
  my_approvals: {
    count: number;
    items: { id: number; title: string; branch: string; headcount: number; step: string | null; overdue: boolean }[];
  };
}

export interface TimeDashboard {
  my_week: { week_start: string; status: 'draft' | 'submitted' | 'approved' | 'rejected'; expected: number; worked: number; missing: number } | null;
  my_approvals: {
    count: number;
    items: { id: number; employee: { id: number; full_name: string }; week_start: string; worked: number; overtime: number }[];
  };
}

export interface TimeOffDashboard {
  out_today: {
    id: number;
    employee: { id: number; full_name: string };
    leave_type: { id: number; name: string; color: string };
    starts_on: string;
    ends_on: string;
    half_day: 'none' | 'start' | 'end';
  }[];
  my_approvals: {
    count: number;
    items: {
      id: number;
      employee: { id: number; full_name: string };
      leave_type: { id: number; name: string; color: string };
      starts_on: string;
      ends_on: string;
      days: number;
    }[];
  };
}

/** backend Overview DashboardNotices item. */
export interface DashboardWarning {
  code: string;
  level: 'warning' | 'error';
  params?: Record<string, string>;
  link?: string;
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

/** «Маршрут дня» ready for the view: the API block plus the visible hours and positioned stations. */
export type DayRouteView = DayRoute & { scale: RouteScale; stops: RouteStop[] };
