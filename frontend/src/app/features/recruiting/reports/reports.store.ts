import { Injectable, computed, inject, signal } from '@angular/core';
import { forkJoin } from 'rxjs';
import { Channel, DateRange, FunnelReport, FunnelRow, StageKind, VacancyStatus, RejectReasonsReport, SourcesReport, TouchesReport } from '../recruiting.model';
import { lastDays } from '../recruiting.format';
import { RecruitingService } from '../recruiting.service';

export interface RecruiterTouches {
  name: string;
  total: number;
  viaProduct: number;
  captured: number;
  byChannel: Partial<Record<Channel, number>>;
}

/** Recruiter × channel matrix from the flat touches rows. */
export function pivotTouches(report: TouchesReport | null): RecruiterTouches[] {
  if (!report) {
    return [];
  }
  const map = new Map<string, RecruiterTouches>();
  for (const row of report.rows) {
    const key = row.author_name ?? '—';
    const item = map.get(key) ?? { name: key, total: 0, viaProduct: 0, captured: 0, byChannel: {} };
    item.total += row.count;
    if (row.via_product) {
      item.viaProduct += row.count;
    } else {
      item.captured += row.count;
    }
    item.byChannel[row.channel] = (item.byChannel[row.channel] ?? 0) + row.count;
    map.set(key, item);
  }
  return [...map.values()].sort((a, b) => b.total - a.total);
}

export interface FunnelStage {
  id: number;
  name: string;
  kind: StageKind;
  count: number;
  /** % of the previous stage (null for the first stage, an empty previous one and the rejections) */
  conversion: number | null;
}

export interface FunnelCard {
  id: number;
  title: string;
  status: VacancyStatus | null;
  /** branch · recruiter */
  subtitle: string | null;
  daysOpen: number | null;
  /** candidates on attract/select stages */
  active: number;
  max: number;
  stages: FunnelStage[];
}

/** One card per vacancy: stages in funnel order, most active vacancies first. */
export function funnelCards(rows: readonly FunnelRow[], today: Date = new Date()): FunnelCard[] {
  const groups = new Map<number, FunnelRow[]>();
  for (const row of rows) {
    groups.set(row.vacancy_id, [...(groups.get(row.vacancy_id) ?? []), row]);
  }
  const cards = [...groups.values()].map((group): FunnelCard => {
    const sorted = [...group].sort((a, b) => a.position - b.position);
    const first = sorted[0];
    const stages = sorted.map((r, i): FunnelStage => {
      const prev = i > 0 ? sorted[i - 1].count : 0;
      return {
        id: r.stage_id,
        name: r.stage_name,
        kind: r.stage_kind,
        count: r.count,
        conversion: r.stage_kind !== 'closed' && prev > 0 ? Math.round((r.count / prev) * 100) : null,
      };
    });
    return {
      id: first.vacancy_id,
      title: first.vacancy_title,
      status: first.vacancy_status ?? null,
      subtitle: [first.branch_name, first.recruiter_name].filter((x) => !!x).join(' · ') || null,
      daysOpen: daysSince(first.opened_at ?? null, today),
      active: stages.filter((s) => s.kind === 'attract' || s.kind === 'select').reduce((sum, s) => sum + s.count, 0),
      max: Math.max(0, ...stages.map((s) => s.count)),
      stages,
    };
  });
  return cards.sort((a, b) => b.active - a.active || a.title.localeCompare(b.title));
}

function daysSince(iso: string | null, today: Date): number | null {
  if (!iso) {
    return null;
  }
  const opened = Date.parse(`${iso}T00:00:00`);
  return Number.isNaN(opened) ? null : Math.max(0, Math.floor((today.getTime() - opened) / 86_400_000));
}

/** Column sums of the recruiter × channel matrix (the «Total» row). */
export function touchesTotal(rows: readonly RecruiterTouches[]): RecruiterTouches {
  const total: RecruiterTouches = { name: '', total: 0, viaProduct: 0, captured: 0, byChannel: {} };
  for (const r of rows) {
    total.total += r.total;
    total.viaProduct += r.viaProduct;
    total.captured += r.captured;
    for (const [channel, n] of Object.entries(r.byChannel) as [Channel, number][]) {
      total.byChannel[channel] = (total.byChannel[channel] ?? 0) + n;
    }
  }
  return total;
}

/** Share of the maximum, for plain CSS bars (0..100). */
export function barWidth(value: number, max: number): number {
  return max > 0 ? Math.round((value / max) * 100) : 0;
}

/** Reports page: one date range drives all four reports. */
@Injectable()
export class ReportsStore {
  private readonly api = inject(RecruitingService);

  readonly range = signal<DateRange>(lastDays(30));
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly touches = signal<TouchesReport | null>(null);
  readonly funnel = signal<FunnelReport | null>(null);
  readonly sources = signal<SourcesReport | null>(null);
  readonly rejectReasons = signal<RejectReasonsReport | null>(null);

  readonly recruiters = computed(() => pivotTouches(this.touches()));
  readonly recruitersTotal = computed(() => touchesTotal(this.recruiters()));
  readonly funnelCards = computed(() => funnelCards(this.funnel()?.rows ?? []));

  setRange(range: DateRange): void {
    this.range.set(range);
    this.load();
  }

  load(): void {
    const range = this.range();
    this.loading.set(true);
    this.failed.set(false);
    forkJoin({
      touches: this.api.touchesReport(range),
      funnel: this.api.funnelReport(range),
      sources: this.api.sourcesReport(range),
      rejectReasons: this.api.rejectReasonsReport(range),
    }).subscribe({
      next: (r) => {
        this.touches.set(r.touches);
        this.funnel.set(r.funnel);
        this.sources.set(r.sources);
        this.rejectReasons.set(r.rejectReasons);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }
}
