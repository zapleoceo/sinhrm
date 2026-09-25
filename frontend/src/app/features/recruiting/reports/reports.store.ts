import { Injectable, computed, inject, signal } from '@angular/core';
import { forkJoin } from 'rxjs';
import { Channel, DateRange, FunnelReport, RejectReasonsReport, SourcesReport, TouchesReport } from '../recruiting.model';
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
  readonly funnelByVacancy = computed(() => {
    const rows = this.funnel()?.rows ?? [];
    const groups = new Map<number, { title: string; rows: typeof rows }>();
    for (const row of rows) {
      const g = groups.get(row.vacancy_id) ?? { title: row.vacancy_title, rows: [] };
      g.rows.push(row);
      groups.set(row.vacancy_id, g);
    }
    return [...groups.values()];
  });

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
