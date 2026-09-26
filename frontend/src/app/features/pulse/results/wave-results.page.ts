import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, effect, inject, input, signal } from '@angular/core';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { QuestionResult, WaveCompare, WaveResults, deltaTone, enpsAngle, enpsTone, maxOf } from '../pulse.model';
import { PulseService, pulseErrorKey } from '../pulse.service';

/**
 * Results of a wave (/pulse/waves/:id/results): admins — everything with a branch/department breakdown; managers —
 * their department. Groups below the wave's minimum are "hidden to protect anonymity". eNPS gauge in CSS, bars for
 * scales and choices, texts sorted, and the comparison table with the previous wave per question and segment.
 */
@Component({
  selector: 'app-wave-results-page',
  imports: [DatePipe, MatButtonToggleModule, MatIconModule, MatProgressBarModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (error(); as key) {
      <p class="state">{{ key | transloco }}</p>
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
        <mat-button-toggle-group [value]="segment()" (change)="segment.set($event.value)" [attr.aria-label]="'pulse.results.segment' | transloco">
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
                  <div class="value">{{ e.score ?? '—' }}</div>
                </div>
                <p class="split">
                  <span class="pro">{{ 'pulse.results.promoters' | transloco }}: {{ e.promoters }}</span>
                  <span>{{ 'pulse.results.passives' | transloco }}: {{ e.passives }}</span>
                  <span class="det">{{ 'pulse.results.detractors' | transloco }}: {{ e.detractors }}</span>
                </p>
              } @else if (q.distribution) {
                <p class="avg">{{ q.average ?? '—' }}</p>
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
                  <div class="hbar"><span class="lab">{{ o.label }}</span><span class="fill" [style.width.%]="(o.count / maxOpt(q)) * 100"></span><span class="num">{{ o.count }}</span></div>
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
        <table class="panel table">
          <thead>
            <tr><th scope="col">{{ 'pulse.results.segment' | transloco }}</th><th scope="col">{{ 'pulse.results.answers' | transloco }}</th></tr>
          </thead>
          <tbody>
            @for (s of d.segments; track s.segment) {
              <tr>
                <th scope="row">{{ s.name ?? '—' }}</th>
                <td>{{ s.suppressed ? ('pulse.results.hidden' | transloco) : s.responses }}</td>
              </tr>
            }
          </tbody>
        </table>
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
                          <span class="delta" [attr.data-tone]="dTone(cell.delta)">{{ cell.delta === null ? '' : (cell.delta > 0 ? '+' : '') + cell.delta }}</span>
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
    .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .card { padding: 1rem; }
    .card h2 { font: var(--mat-sys-title-small); margin: 0 0 0.5rem; }
    .suppressed { padding: 1rem; }
    .avg { font-size: 2rem; margin: 0; }
    .gauge { position: relative; width: 10rem; height: 5.5rem; margin: 0.5rem auto; overflow: hidden; }
    .arc { position: absolute; inset: 0 0 auto 0; height: 10rem; border-radius: 50%;
      background: conic-gradient(from 270deg, var(--app-danger) 0deg 90deg, var(--app-warning) 90deg 117deg, var(--app-success) 117deg 180deg, transparent 180deg);
      mask: radial-gradient(circle at 50% 50%, transparent 55%, #000 56%); }
    .needle { position: absolute; left: calc(50% - 2px); bottom: 0.5rem; width: 4px; height: 4.3rem; background: var(--mat-sys-on-surface); transform-origin: 50% 100%; border-radius: 2px; }
    .value { position: absolute; bottom: 0; width: 100%; text-align: center; font-weight: 700; font-size: 1.4rem; }
    .gauge[data-tone='danger'] .value { color: var(--app-danger); }
    .gauge[data-tone='success'] .value { color: var(--app-success); }
    .split { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.85rem; }
    .pro { color: var(--app-success); }
    .det { color: var(--app-danger); }
    .dist { display: flex; align-items: flex-end; gap: 0.25rem; height: 6rem; }
    .col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; }
    .colbar { width: 100%; background: var(--mat-sys-primary); border-radius: 3px 3px 0 0; min-height: 1px; }
    .lbl { font-size: 0.75rem; color: var(--app-muted); }
    .hbar { display: grid; grid-template-columns: 7rem 1fr 2rem; gap: 0.5rem; align-items: center; margin: 0.2rem 0; }
    .fill { height: 10px; background: var(--mat-sys-tertiary); border-radius: 3px; min-width: 1px; }
    .texts { margin: 0; padding-left: 1.25rem; max-height: 12rem; overflow: auto; }
    .table { width: 100%; border-collapse: collapse; }
    .table th, .table td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid var(--app-border); }
    .delta { margin-left: 0.35rem; font-weight: 600; }
    .delta[data-tone='up'] { color: var(--app-success); }
    .delta[data-tone='down'] { color: var(--app-danger); }
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

  constructor() {
    effect(() => {
      const id = Number(this.id());
      const segment = this.segment();
      this.api.results(id, segment).subscribe({ next: (d) => this.data.set(d), error: (e: unknown) => this.error.set(pulseErrorKey(e)) });
      this.api.compare(id, segment).subscribe({ next: (c) => this.compare.set(c), error: () => this.compare.set(null) });
    });
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
