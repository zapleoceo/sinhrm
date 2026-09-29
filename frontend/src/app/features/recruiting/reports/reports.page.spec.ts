import { TestBed } from '@angular/core/testing';
import { provideNativeDateAdapter } from '@angular/material/core';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { FunnelRow, TouchesRow } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { ReportsPage } from './reports.page';
import { funnelCards, pivotTouches, touchesTotal } from './reports.store';

const range = { from: 'a', to: 'b' };

function stage(vacancy: number, title: string, position: number, kind: FunnelRow['stage_kind'], count: number): FunnelRow {
  return { vacancy_id: vacancy, vacancy_title: title, stage_id: vacancy * 10 + position, stage_name: `S${position}`, stage_kind: kind, position, count };
}

function render(rows: FunnelRow[]): { el: HTMLElement; detect: () => void } {
  TestBed.configureTestingModule({
    imports: [ReportsPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      provideNativeDateAdapter(),
      {
        provide: RecruitingService,
        useValue: {
          touchesReport: () => of({ range, rows: [], totals: { total: 0, via_product: 0, captured: 0 } }),
          funnelReport: () => of({ range, rows, totals: { total: 0 } }),
          sourcesReport: () =>
            of({
              range,
              rows: [
                { source: 'work_ua', candidates: 3, hired: 1 },
                { source: 'referral', candidates: 2, hired: 0 },
              ],
              totals: { candidates: 5, hired: 1 },
            }),
          rejectReasonsReport: () => of({ range, rows: [{ reject_reason_id: 1, name: 'R', count: 2 }], totals: { total: 2 } }),
        },
      },
    ],
  });
  const fixture = TestBed.createComponent(ReportsPage);
  fixture.detectChanges();
  return { el: fixture.nativeElement as HTMLElement, detect: () => fixture.detectChanges() };
}

const titles = (el: HTMLElement): string[] => [...el.querySelectorAll('[data-testid="funnel-card"] h3')].map((h) => h.textContent?.trim() ?? '');

describe('ReportsPage funnel cards', () => {
  it('renders one card per vacancy, sorted by active candidates', () => {
    const { el } = render([
      stage(1, 'Small', 1, 'attract', 1),
      stage(1, 'Small', 2, 'hire', 5),
      stage(2, 'Big', 1, 'attract', 4),
      stage(2, 'Big', 2, 'select', 2),
      stage(2, 'Big', 3, 'closed', 3),
    ]);
    expect(titles(el)).toEqual(['Big', 'Small']);
    const big = el.querySelector('[data-testid="funnel-card"]') as HTMLElement;
    expect(big.querySelectorAll('.stages li').length).toBe(3);
    expect(big.querySelector('.bar[data-kind="closed"]')).not.toBeNull();
    // share of the 6 active candidates (current counts, never above 100%); the rejection stage has none
    expect([...big.querySelectorAll('.conv')].map((c) => c.textContent?.trim())).toEqual(['67%', '33%', '']);
  });

  it('shows a compact empty state for a vacancy without candidates', () => {
    const { el } = render([stage(1, 'Empty', 1, 'attract', 0), stage(2, 'Full', 1, 'attract', 2)]);
    const cards = el.querySelectorAll('[data-testid="funnel-card"]');
    expect(cards.length).toBe(2);
    expect(cards[1].querySelector('[data-testid="funnel-empty"]')).not.toBeNull();
    expect(cards[1].querySelector('.stages')).toBeNull();
  });

  it('offers the name filter only for more than six cards and filters client-side', () => {
    const few = render([1, 2, 3].map((i) => stage(i, `V${i}`, 1, 'attract', i)));
    expect(few.el.querySelector('[data-testid="funnel-filter"]')).toBeNull();
    TestBed.resetTestingModule();

    const { el, detect } = render([1, 2, 3, 4, 5, 6, 7].map((i) => stage(i, i === 7 ? 'Java developer' : `Manager ${i}`, 1, 'attract', i)));
    expect(titles(el).length).toBe(7);
    const input = el.querySelector('[data-testid="funnel-filter"]') as HTMLInputElement;
    input.value = 'java';
    input.dispatchEvent(new Event('input'));
    detect();
    expect(titles(el)).toEqual(['Java developer']);
  });

  it('adds a total row to tables with two or more rows only', () => {
    const { el } = render([]);
    const footers = [...el.querySelectorAll('tfoot tr')].map((tr) => [...tr.querySelectorAll('td')].map((td) => td.textContent?.trim()));
    expect(footers).toEqual([['5', '1']]);
  });
});

describe('funnel and touches helpers', () => {
  it('computes days open, subtitle and status from the payload', () => {
    const rows: FunnelRow[] = [
      { ...stage(1, 'V', 1, 'attract', 2), vacancy_status: 'paused', branch_name: 'Kyiv', recruiter_name: 'Olena', opened_at: '2026-09-01' },
    ];
    const [card] = funnelCards(rows, new Date('2026-09-11T12:00:00'));
    expect(card).toEqual(expect.objectContaining({ status: 'paused', subtitle: 'Kyiv · Olena', daysOpen: 10, active: 2 }));
    expect(funnelCards([stage(1, 'V', 1, 'attract', 1)])[0]).toEqual(expect.objectContaining({ status: null, subtitle: null, daysOpen: null }));
  });

  it('sums the recruiter × channel matrix per column', () => {
    const row = (author: string, channel: TouchesRow['channel'], count: number, via: boolean): TouchesRow =>
      ({ author_id: 1, author_name: author, channel, via_product: via, count }) as TouchesRow;
    const total = touchesTotal(pivotTouches({ range, rows: [row('A', 'telegram', 2, true), row('B', 'telegram', 3, false), row('B', 'email', 1, false)], totals: { total: 6, via_product: 2, captured: 4 } }));
    expect(total).toEqual({ name: '', total: 6, viaProduct: 2, captured: 4, byChannel: { telegram: 5, email: 1 } });
  });
});
