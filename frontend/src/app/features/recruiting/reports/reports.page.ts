import { ChangeDetectionStrategy, Component, OnInit, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { CHANNELS, Channel } from '../recruiting.model';
import { barWidth, ReportsStore } from './reports.store';

/** Manager reports without chart libraries: tables with plain CSS bars. One date range for all four. */
@Component({
  selector: 'app-reports-page',
  imports: [MatButtonModule, MatFormFieldModule, MatInputModule, MatProgressBarModule, TranslocoPipe],
  providers: [ReportsStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './reports.page.html',
  styleUrl: './reports.page.scss',
})
export class ReportsPage implements OnInit {
  protected readonly store = inject(ReportsStore);
  protected readonly channels: readonly Channel[] = CHANNELS.filter((c) => c !== 'system');
  protected readonly bar = barWidth;
  protected readonly maxRecruiter = computed(() => Math.max(0, ...this.store.recruiters().map((r) => r.total)));
  protected readonly maxSource = computed(() => Math.max(0, ...(this.store.sources()?.rows ?? []).map((r) => r.candidates)));
  protected readonly maxReason = computed(() => Math.max(0, ...(this.store.rejectReasons()?.rows ?? []).map((r) => r.count)));

  ngOnInit(): void {
    this.store.load();
  }

  protected apply(from: string, to: string): void {
    if (from && to && from <= to) {
      this.store.setRange({ from, to });
    }
  }

  protected maxOf(rows: readonly { count: number }[]): number {
    return Math.max(0, ...rows.map((r) => r.count));
  }
}
