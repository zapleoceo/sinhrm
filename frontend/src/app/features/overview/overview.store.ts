import { Injectable, computed, inject, signal } from '@angular/core';
import { barWidth } from '../recruiting/reports/reports.store';
import { Dashboard, statTiles } from './overview.model';
import { OverviewService } from './overview.service';

/** Home page state: one request, derived tiles and bar widths. */
@Injectable()
export class OverviewStore {
  private readonly api = inject(OverviewService);

  readonly data = signal<Dashboard | null>(null);
  readonly loading = signal(false);
  readonly failed = signal(false);

  readonly tiles = computed(() => {
    const d = this.data();
    return d ? statTiles(d) : [];
  });
  readonly funnel = computed(() => {
    const rows = this.data()?.funnel ?? [];
    const max = Math.max(0, ...rows.map((r) => r.count));
    return rows.map((r) => ({ ...r, width: barWidth(r.count, max) }));
  });
  readonly touches = computed(() => {
    const rows = this.data()?.touches.by_channel ?? [];
    const max = Math.max(0, ...rows.map((r) => r.count));
    return rows.map((r) => ({ ...r, width: barWidth(r.count, max) }));
  });
  readonly touchesTotal = computed(() => (this.data()?.touches.by_channel ?? []).reduce((sum, r) => sum + r.count, 0));

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.dashboard().subscribe({
      next: (d) => {
        this.data.set(d);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }
}
