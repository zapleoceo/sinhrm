import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { CHANNELS, Channel } from '../recruiting.model';
import { barWidth, ReportsStore } from './reports.store';
import { fromIsoDate, toIsoDate } from '../../../core/date/iso-date';
import { ChannelIcon } from '../../../core/ui/channel-icon';

/** Manager reports without chart libraries: tables with plain CSS bars. One date range for all four. */
@Component({
  selector: 'app-reports-page',
  imports: [ChannelIcon, MatButtonModule, MatDatepickerModule, MatFormFieldModule, MatInputModule, MatProgressBarModule, TranslocoPipe],
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
  /** The vacancy name filter appears only when there are more cards than this. */
  protected readonly filterFrom = 6;
  protected readonly query = signal('');
  protected readonly cards = computed(() => {
    const q = this.query().trim().toLocaleLowerCase();
    const all = this.store.funnelCards();
    return q === '' || all.length <= this.filterFrom ? all : all.filter((c) => c.title.toLocaleLowerCase().includes(q));
  });
  protected readonly maxReason = computed(() => Math.max(0, ...(this.store.rejectReasons()?.rows ?? []).map((r) => r.count)));

  ngOnInit(): void {
    this.store.load();
  }

  protected readonly start = computed(() => fromIsoDate(this.store.range().from));
  protected readonly end = computed(() => fromIsoDate(this.store.range().to));

  protected apply(start: Date | null, end: Date | null): void {
    const [from, to] = [toIsoDate(start), toIsoDate(end)];
    if (from && to && from <= to) {
      this.store.setRange({ from, to });
    }
  }

  protected onQuery(event: Event): void {
    this.query.set((event.target as HTMLInputElement).value);
  }
}
