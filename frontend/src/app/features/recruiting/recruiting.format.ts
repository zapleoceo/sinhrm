import { Application, Stage, TimelineItem } from './recruiting.model';

const MINUTE = 60;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/** Compact duration for the route: 45s → "<1m", 5400s → "1h 30m", 3 days → "3d", 3d 5h → "3d 5h". */
export function formatDuration(seconds: number): string {
  if (seconds < MINUTE) {
    return '<1m';
  }
  if (seconds < HOUR) {
    return `${Math.floor(seconds / MINUTE)}m`;
  }
  if (seconds < DAY) {
    const h = Math.floor(seconds / HOUR);
    const m = Math.floor((seconds % HOUR) / MINUTE);
    return m ? `${h}h ${m}m` : `${h}h`;
  }
  const d = Math.floor(seconds / DAY);
  const h = Math.floor((seconds % DAY) / HOUR);
  return h ? `${d}d ${h}h` : `${d}d`;
}

/** Whole days since an ISO date (0 for today or a missing date). */
export function daysSince(iso: string | null, now: Date = new Date()): number {
  if (!iso) {
    return 0;
  }
  return Math.max(0, Math.floor((now.getTime() - new Date(iso).getTime()) / (DAY * 1000)));
}

/** Kanban columns: every stage of the pipeline in order with its applications. */
export function groupByStage(stages: readonly Stage[], applications: readonly Application[]): { stage: Stage; items: Application[] }[] {
  const sorted = [...stages].sort((a, b) => a.position - b.position);
  return sorted.map((stage) => ({ stage, items: applications.filter((a) => a.stage_id === stage.id) }));
}

/** Month label (YYYY-MM) of a timeline item, used to group the timeline visually. */
export function monthKey(item: TimelineItem): string {
  return item.at.slice(0, 7);
}

/** Applies the backend status rule locally for optimistic board moves. */
export function statusForStage(stage: Stage): Application['status'] {
  if (stage.is_reject) {
    return 'rejected';
  }
  return stage.is_hire ? 'hired' : 'active';
}

/** Default report range: the last 30 days, as YYYY-MM-DD. */
export function lastDays(days: number, now: Date = new Date()): { from: string; to: string } {
  const to = new Date(now);
  const from = new Date(now);
  from.setDate(from.getDate() - (days - 1));
  return { from: isoDate(from), to: isoDate(to) };
}

export function isoDate(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
