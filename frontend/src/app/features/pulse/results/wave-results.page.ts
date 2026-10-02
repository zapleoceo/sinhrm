import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, signal } from '@angular/core';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { ClientTable, NUMBER_RANGE, TEXT_FILTER } from '../../../core/ui/table/client-table';
import { ColumnHeader } from '../../../core/ui/table/column-header';
import { TableSortDirective } from '../../../core/ui/table/table-sort.directive';
import { TableUrlState } from '../../../core/ui/table/table-url-state';
import { QuestionResult, WaveCompare, WaveResults, deltaTone, enpsAngle, enpsTone, maxOf } from '../pulse.model';
import { PulseService, pulseErrorKey } from '../pulse.service';

/**
 * Results of a wave (/pulse/waves/:id/results): admins — everything with a branch/department breakdown; managers —
 * their department. Groups below the wave's minimum are "hidden to protect anonymity". eNPS gauge in CSS, bars for
 * scales and choices, texts sorted, and the comparison table with the previous wave per question and segment.
 */
@Component({
  selector: 'app-wave-results-page',
  imports: [DatePipe, MatButtonToggleModule, MatIconModule, MatProgressBarModule, TranslocoPipe, TableSortDirective, ColumnHeader],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (error(); as key) {
      <p class="state" role="alert">{{ key | transloco }}</p>
    }
    @if (data(); as d) {
      <header class="page-head">
        <div>
          <h1>{{ d.wave.survey.title }}</h1>
          <p class="muted">
            {{ d.wave.starts_at | date: 'dd.MM.yyyy' }} — {{ d.wave.ends_at | date: 'dd.MM.yyyy' }} · {{ 'pulse.waveStatus.' + d.wave.status | transloco }}
            @if (d.wave.anonymous) { · <mat-icon inline>visibility_off</mat-icon> {{ 'pulse.waves.anonymous' | transloco }} }
            @if (d.scope === 'department') { · {{ 'pulse.results.myDepartment' | transloco }} }
          </p>
        </div>
        <mat-button-toggle-group [value]="segment()" (change)="changeSegment($event.value)" [attr.aria-label]="'pulse.results.segment' | transloco">
          <mat-button-toggle value="department">{{ 'pulse.results.byDepartment' | transloco }}</mat-button-toggle>
          <mat-button-toggle value="branch">{{ 'pulse.results.byBranch' | transloco }}</mat-button-toggle>
        </mat-button-toggle-group>
      </header>

      @if (d.participation; as p) {
        <p class="panel suppressed">
          <mat-icon inline>hourglass_top</mat-icon> {{ 'pulse.results.notClosed' | transloco }}
          {{ 'pulse.results.participation' | transloco: { bucket: p.responded_bucket, percent: p.responded_percent ?? '—' } }}
        </p>
      } @else if (d.suppressed) {
        <p class="panel suppressed"><mat-icon inline>shield</mat-icon> {{ 'pulse.results.suppressed' | transloco: { n: d.wave.min_group_size } }}</p>
      } @else {
        <p class="muted">{{ 'pulse.results.responses' | transloco: { n: d.responses } }}</p>
        <div class="cards">
          @for (q of d.questions; track q.id) {
            <section class="panel card">
              <h2>{{ q.text }}</h2>
              @if (q.suppressed) {
                <p class="muted"><mat-icon inline>shield</mat-icon> {{ 'pulse.results.hidden' | transloco }}</p>
              }
              @if (q.enps; as e) {
                <div class="gauge" [attr.data-tone]="tone(e.score)" role="img" [attr.aria-label]="'eNPS ' + (e.score ?? '—')">
                  <div class="arc"></div>
                  <div class="needle" [style.transform]="'rotate(' + (angle(e.score) - 90) + 'deg)'"></div>
                  <div class="value mono">{{ e.score ?? '—' }}</div>
                </div>
                <p class="split">
                  <span class="pro">{{ 'pulse.results.promoters' | transloco }}: {{ e.promoters }}</span>
                  <span>{{ 'pulse.results.passives' | transloco }}: {{ e.passives }}</span>
                  <span class="det">{{ 'pulse.results.detractors' | transloco }}: {{ e.detractors }}</span>
                </p>
              } @else if (q.distribution) {
                <p class="avg mono">{{ q.average ?? '—' }}</p>
              }
              @if (q.distribution) {
                <div class="dist">
                  @for (entry of entries(q); track entry[0]) {
                    <div class="col">
                      <span class="colbar" [style.height.%]="(entry[1] / maxDist(q)) * 100"></span>
                      <span class="lbl">{{ entry[0] }}</span>
                    </div>
                  }
                </div>
              }
              @if (q.options) {
                @for (o of q.options; track o.label) {
                  <div class="hbar"><span class="lab">{{ o.label }}</span><span class="fill" [style.width.%]="(o.count / maxOpt(q)) * 100"></span><span class="num mono">{{ o.count }}</span></div>
                }
              }
              @if (q.texts) {
                <ul class="texts">
                  @for (t of q.texts; track $index) {
                    <li>{{ t }}</li>
                  }
                </ul>
              }
            </section>
          }
        </div>
      }

      @if (d.segments?.length) {
        <h2>{{ (segment() === 'branch' ? 'pulse.results.byBranch' : 'pulse.results.byDepartment') | transloco }}</h2>
        <!-- Segments: sort and filter in the headers (core/ui/table), URL seg_sort / seg_<column>. Hidden groups have no
             number: they sort last and drop out of a number filter, so nothing about their size leaks. -->
        <div class="panel">
          <table class="app-table segments" [appTableSort]="segTable.sort()" [appTableSortCount]="segTable.rows().length" (appTableSortChange)="segTable.setSort($event)">
            <thead>
              <tr>
                <th scope="col" app-column-header key="name" [label]="'pulse.results.segment' | transloco"
                  [filter]="textFilter" [filterValue]="segTable.filterValue('name')" (filterChange)="segTable.setFilter('name', $event)"></th>
                <th scope="col" app-column-header key="responses" [label]="'pulse.results.answers' | transloco"
                  [filter]="numberRange" [filterValue]="segTable.filterValue('responses')" (filterChange)="segTable.setFilter('responses', $event)"></th>
              </tr>
            </thead>
            <tbody>
              @for (s of segTable.rows(); track s.segment) {
                <tr>
                  <th scope="row">{{ s.name ?? '—' }}</th>
                  <td>{{ s.suppressed ? ('pulse.results.hidden' | transloco) : s.responses }}</td>
                </tr>
              } @empty {
                <tr><td colspan="2" class="muted">{{ 'table.noMatches' | transloco }}</td></tr>
              }
            </tbody>
          </table>
        </div>
      }

      @if (compare(); as c) {
        @if (c.rows) {
        <h2>{{ 'pulse.results.compare' | transloco }}</h2>
        @if (!c.previous) {
          <p class="muted">{{ 'pulse.results.noPrevious' | transloco }}</p>
        } @else {
          <p class="muted">{{ c.previous.starts_at | date: 'dd.MM.yyyy' }} → {{ c.current?.starts_at | date: 'dd.MM.yyyy' }}</p>
          <div class="panel">
            <table class="table">
              <thead>
                <tr>
                  <th scope="col">{{ 'pulse.results.segment' | transloco }}</th>
                  @for (q of c.questions; track q.id) {
                    <th scope="col">{{ q.text }}</th>
                  }
                </tr>
              </thead>
              <tbody>
                @for (r of c.rows; track r.segment ?? -1) {
                  <tr>
                    <th scope="row">
                      {{ r.name ?? (r.segment === null && $first ? ('pulse.results.all' | transloco) : '—') }}
                      @if (r.hidden_reason === 'anonymity') {
                        <br /><small class="muted">{{ 'pulse.results.diffHidden' | transloco }}</small>
                      }
                    </th>
                    @for (cell of r.questions; track cell.id) {
                      <td>
                        @if (cell.current === null && cell.previous === null) {
                          <span class="muted">{{ 'pulse.results.hidden' | transloco }}</span>
                        } @else {
                          {{ cell.previous ?? '—' }} → {{ cell.current ?? '—' }}
                          <span class="delta mono" [attr.data-tone]="dTone(cell.delta)">{{ cell.delta === null ? '' : (cell.delta > 0 ? '+' : '') + cell.delta }}</span>
                        }
                      </td>
                    }
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
        }
      }
    } @else if (!error()) {
      <mat-progress-bar mode="indeterminate" />
    }
  `,
  styles: `
    .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(18rem, 100%), 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .card { padding: 1.25rem; }
    .card h2 { font: var(--mat-sys-title-small); margin: 0 0 0.75rem; overflow-wrap: anywhere; }
    .suppressed { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; padding: 1rem 1.25rem; border-left: 3px solid var(--app-accent); }
    .avg { font-size: 2.25rem; font-weight: 600; line-height: 1.1; margin: 0; }
    .gauge { position: relative; width: 10rem; height: 5.5rem; margin: 0.5rem auto; overflow: hidden; }
    .arc { position: absolute; inset: 0 0 auto 0; height: 10rem; border-radius: 50%;
      background: conic-gradient(from 270deg, var(--app-danger) 0deg 90deg, var(--app-warning) 90deg 117deg, var(--app-success) 117deg 180deg, transparent 180deg);
      mask: radial-gradient(circle at 50% 50%, transparent 58%, black 59%); }
    .needle { position: absolute; left: calc(50% - 2px); bottom: 0.5rem; width: 4px; height: 4.3rem; background: var(--mat-sys-on-surface); transform-origin: 50% 100%; border-radius: 2px; }
    .value { position: absolute; bottom: 0; width: 100%; text-align: center; font-weight: 600; font-size: 1.4rem; }
    .gauge[data-tone='danger'] .value { color: var(--app-bad-text); }
    .gauge[data-tone='success'] .value { color: var(--app-good-text); }
    .split { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.25rem 0.75rem; font: var(--mat-sys-body-small); }
    .pro { color: var(--app-good-text); }
    .det { color: var(--app-bad-text); }
    .dist { display: flex; align-items: flex-end; gap: 0.25rem; height: 6rem; border-bottom: var(--app-border-w) solid var(--app-border); }
    .col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; }
    .colbar { width: 100%; background: var(--mat-sys-primary); border-radius: 4px 4px 1px 1px; min-height: 1px; }
    .lbl { font: 500 0.6875rem var(--app-font-mono); color: var(--app-muted); }
    /* Choice bars: a teal fill (the length carries the number, the count is printed too). */
    .hbar { display: grid; grid-template-columns: minmax(0, 7rem) 1fr 2rem; gap: 0.5rem; align-items: center; margin: 0.3rem 0; }
    .hbar .lab { overflow-wrap: anywhere; font: var(--mat-sys-body-small); }
    .fill { height: 8px; background: var(--app-accent); border-radius: 4px; min-width: 2px; }
    .hbar .num { text-align: right; }
    .texts { margin: 0; padding-left: 1.25rem; max-height: 12rem; overflow: auto; display: flex; flex-direction: column; gap: 0.25rem; }
    .table { width: 100%; border-collapse: collapse; }
    .table th, .table td { text-align: left; padding: 0.6rem 0.875rem; border-bottom: var(--app-border-w) solid var(--app-track); }
    .table thead th { font: var(--mat-sys-label-medium); font-weight: 700; color: var(--app-muted); border-bottom-color: var(--app-border); }
    .table tbody tr:last-child > * { border-bottom: 0; }
    .segments tbody th { font: inherit; color: inherit; white-space: normal; padding: 0.6rem 1rem; border-bottom: var(--app-border-w) solid var(--app-track); }
    .segments tbody tr:last-child > * { border-bottom: 0; }
    .delta { margin-left: 0.35rem; font-weight: 600; }
    .delta[data-tone='up'] { color: var(--app-good-text); }
    .delta[data-tone='down'] { color: var(--app-bad-text); }
  `,
})
export class WaveResultsPage {
  /** Route param :id. */
  readonly id = input.required<string>();
  private readonly api = inject(PulseService);
  protected readonly data = signal<WaveResults | null>(null);
  protected readonly compare = signal<WaveCompare | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly segment = signal<'department' | 'branch'>('department');
  protected readonly angle = enpsAngle;
  protected readonly tone = enpsTone;
  protected readonly dTone = deltaTone;
  protected readonly textFilter = TEXT_FILTER;
  protected readonly numberRange = NUMBER_RANGE;
  private readonly segments = computed(() => this.data()?.segments ?? []);
  protected readonly segTable = new ClientTable({
    rows: this.segments,
    prefix: 'seg',
    columns: [
      { key: 'name', value: (s) => s.name, filter: 'text' },
      { key: 'responses', value: (s) => (s.suppressed ? null : s.responses), filter: 'number' },
    ],
  });

  constructor() {
    effect(() => {
      const id = Number(this.id());
      const segment = this.segment();
      this.api.results(id, segment).subscribe({ next: (d) => this.data.set(d), error: (e: unknown) => this.error.set(pulseErrorKey(e)) });
      this.api.compare(id, segment).subscribe({ next: (c) => this.compare.set(c), error: () => this.compare.set(null) });
    });
  }

  /** Another grouping means other rows (branches vs departments): old seg_* filters would hide them, so they go; the sort stays. */
  protected changeSegment(segment: 'department' | 'branch'): void {
    this.segment.set(segment);
    this.segTable.clearFilters();
  }

  protected entries(q: QuestionResult): [string, number][] {
    return Object.entries(q.distribution ?? {});
  }

  protected maxDist(q: QuestionResult): number {
    return maxOf(Object.values(q.distribution ?? {}));
  }

  protected maxOpt(q: QuestionResult): number {
    return maxOf((q.options ?? []).map((o) => o.count));
  }
}
