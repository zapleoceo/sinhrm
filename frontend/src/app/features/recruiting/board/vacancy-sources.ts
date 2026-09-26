import { DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, effect, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { VacancySourceRow } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { ChannelIcon } from '../../../core/ui/channel-icon';

/** Tz3 vacancy block "where applicants came from": channel × how added, count and share (collapsible). */
@Component({
  selector: 'app-vacancy-sources',
  imports: [ChannelIcon, DecimalPipe, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <details class="panel">
      <summary>{{ 'recruiting.channels.vacancySources' | transloco: { n: total() } }}</summary>
      <table>
        <thead>
          <tr>
            <th scope="col">{{ 'recruiting.channels.channel' | transloco }}</th>
            <th scope="col">{{ 'recruiting.channels.addedVia' | transloco }}</th>
            <th scope="col" class="num">{{ 'recruiting.channels.count' | transloco }}</th>
            <th scope="col" class="num">{{ 'recruiting.channels.share' | transloco }}</th>
          </tr>
        </thead>
        <tbody>
          @for (r of rows(); track $index) {
            <tr>
              <td>{{ r.name ?? ('recruiting.channels.none' | transloco) }}</td>
              <td>
                @if (r.added_via) {
                  <app-channel-icon [key]="r.added_via" /> {{ 'recruiting.addedVia.' + r.added_via | transloco }}
                } @else {
                  —
                }
              </td>
              <td class="num">{{ r.count }}</td>
              <td class="num">{{ r.share_pct | number: '1.0-1' }}%</td>
            </tr>
          } @empty {
            <tr><td colspan="4" class="muted">{{ 'recruiting.channels.noApplicants' | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </details>
  `,
  styles: `
    details { padding: 0.5rem 1rem; margin-bottom: var(--app-gap); }
    summary { cursor: pointer; font: var(--mat-sys-title-small); }
    table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
    th, td { text-align: left; padding: 0.3rem 0.5rem; border-bottom: 1px solid var(--app-border); font-weight: normal; }
    thead th { color: var(--app-muted); font-size: 0.8rem; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
  `,
})
export class VacancySources {
  readonly vacancyId = input.required<number>();
  private readonly api = inject(RecruitingService);
  protected readonly rows = signal<VacancySourceRow[]>([]);
  protected readonly total = signal(0);

  constructor() {
    effect(() => {
      const id = this.vacancyId();
      this.api.vacancySources(id).subscribe({
        next: (rows) => {
          this.rows.set(rows);
          this.total.set(rows.reduce((sum, r) => sum + r.count, 0));
        },
        error: () => this.rows.set([]),
      });
    });
  }
}
