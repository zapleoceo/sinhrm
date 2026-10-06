import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { CHANNELS, Channel, RejectReasonsRow, SourcesRow } from '../recruiting.model';
import { barWidth, RecruiterTouches, RejectDimRow, RejectView, ReportsStore, recruiterRow, stageRow } from './reports.store';
import { fromIsoDate, toIsoDate } from '../../../core/date/iso-date';
import { ChannelIcon } from '../../../core/ui/channel-icon';
import { ClientColumn, ClientTable, NUMBER_RANGE, TEXT_FILTER, distinctValues, translatedSelect } from '../../../core/ui/table/client-table';
import { ColumnHeader } from '../../../core/ui/table/column-header';
import { TableSortDirective } from '../../../core/ui/table/table-sort.directive';
import { TableUrlState } from '../../../core/ui/table/table-url-state';

/**
 * Manager reports without chart libraries: tables with plain CSS bars. One date range for all four. The three tables
 * sort by a click on a column title and filter next to it (core/ui/table; URL prefixes `tch_`, `src_`, `rej_`); the
 * «Разом» rows are totals of the period and stay at the bottom.
 */
@Component({
  selector: 'app-reports-page',
  imports: [ChannelIcon, MatButtonModule, MatButtonToggleModule,MatDatepickerModule, MatFormFieldModule, MatInputModule, MatProgressBarModule, TranslocoPipe, TableSortDirective, ColumnHeader],
  providers: [ReportsStore, TableUrlState],
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
  private readonly i18n = inject(TranslocoService);
  protected readonly textFilter = TEXT_FILTER;
  protected readonly numberRange = NUMBER_RANGE;
  /** Recruiter × channel: already sorted by the total (API + pivot), so the arrow starts on «Усього». */
  protected readonly touches = new ClientTable<RecruiterTouches>({
    rows: this.store.recruiters,
    prefix: 'tch',
    defaultSort: { key: 'total', dir: 'desc' },
    columns: [
      { key: 'recruiter', value: (r) => r.name, filter: 'text' },
      ...this.channels.map((c): ClientColumn<RecruiterTouches> => ({ key: c, value: (r) => r.byChannel[c] ?? null })),
      { key: 'via', value: (r) => r.viaProduct },
      { key: 'captured', value: (r) => r.captured },
      { key: 'total', value: (r) => r.total, filter: 'number' },
    ],
  });
  private readonly sourceRows = computed(() => this.store.sources()?.rows ?? []);
  protected readonly sourceFilter = translatedSelect(
    () => distinctValues(this.sourceRows(), (r) => r.source),
    (v) => 'recruiting.source.' + v,
  );
  /** Sources: the API orders by the source code, not by a shown column, so no arrow until a click. */
  protected readonly sources = new ClientTable<SourcesRow>({
    rows: this.sourceRows,
    prefix: 'src',
    columns: [
      { key: 'source', value: (r) => this.i18n.translate('recruiting.source.' + r.source), filter: 'select', filterValue: (r) => r.source },
      { key: 'candidates', value: (r) => r.candidates, filter: 'number' },
      { key: 'hired', value: (r) => r.hired, filter: 'number' },
    ],
  });
  private readonly reasonRows = computed(() => this.store.rejectReasons()?.rows ?? []);
  /** Reject reasons: API order is by count, most frequent first. */
  protected readonly reasons = new ClientTable<RejectReasonsRow>({
    rows: this.reasonRows,
    prefix: 'rej',
    defaultSort: { key: 'count', dir: 'desc' },
    columns: [
      { key: 'reason', value: (r) => r.name, filter: 'text' },
      { key: 'count', value: (r) => r.count, filter: 'number' },
    ],
  });
  protected readonly maxReason = computed(() => Math.max(0, ...(this.store.rejectReasons()?.rows ?? []).map((r) => r.count)));
  /** Slice of the reject reasons block: all reasons, reason × stage, reason × recruiter (tz4). */
  protected readonly rejectView = signal<RejectView>('all');
  private readonly stageRows = computed(() => (this.store.rejectReasons()?.by_stage ?? []).map(stageRow));
  private readonly recruiterRows = computed(() => (this.store.rejectReasons()?.by_recruiter ?? []).map(recruiterRow));
  private readonly dimColumns = (): ClientColumn<RejectDimRow>[] => [
    { key: 'reason', value: (r) => r.reason, filter: 'text' },
    { key: 'dim', value: (r) => r.dim ?? this.i18n.translate(r.dimKey ?? ''), filter: 'text' },
    { key: 'count', value: (r) => r.count, filter: 'number' },
  ];
  protected readonly byStage = new ClientTable<RejectDimRow>({ rows: this.stageRows, prefix: 'rjs', defaultSort: { key: 'count', dir: 'desc' }, columns: this.dimColumns() });
  protected readonly byRecruiter = new ClientTable<RejectDimRow>({ rows: this.recruiterRows, prefix: 'rjr', defaultSort: { key: 'count', dir: 'desc' }, columns: this.dimColumns() });
  /** The reason × slice table of the chosen view (null for «all»). */
  protected readonly dim = computed(() => {
    const view = this.rejectView();
    return view === 'stage' ? this.byStage : view === 'recruiter' ? this.byRecruiter : null;
  });
  protected readonly dimSource = computed(() => (this.rejectView() === 'stage' ? this.stageRows() : this.recruiterRows()));
  protected readonly maxDim = computed(() => Math.max(0, ...this.dimSource().map((r) => r.count)));

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
