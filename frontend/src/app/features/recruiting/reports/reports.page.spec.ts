import { TestBed } from '@angular/core/testing';
import { provideNativeDateAdapter } from '@angular/material/core';
import { Router, provideRouter } from '@angular/router';
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
      provideRouter([]),
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

describe('ReportsPage tables: header sort and filter (core/ui/table)', () => {
  async function open(url: string): Promise<{ el: HTMLElement; router: Router; detect: () => Promise<void> }> {
    TestBed.configureTestingModule({
      imports: [ReportsPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([]),
        provideNativeDateAdapter(),
        {
          provide: RecruitingService,
          useValue: {
            touchesReport: () =>
              of({
                range,
                rows: [
                  { author_id: 1, author_name: 'Ann', channel: 'telegram', via_product: true, count: 2 },
                  { author_id: 2, author_name: 'Bob', channel: 'email', via_product: false, count: 5 },
                ],
                totals: { total: 7, via_product: 2, captured: 5 },
              }),
            funnelReport: () => of({ range, rows: [], totals: { total: 0 } }),
            sourcesReport: () => of({ range, rows: [{ source: 'work_ua', candidates: 3, hired: 1 }, { source: 'referral', candidates: 2, hired: 0 }], totals: { candidates: 5, hired: 1 } }),
            rejectReasonsReport: () =>
              of({
                range,
                rows: [
                  { reject_reason_id: 1, name: 'Salary', count: 4 },
                  { reject_reason_id: 2, name: 'Accepted another offer', count: 1 },
                ],
                totals: { total: 5 },
              }),
          },
        },
      ],
    });
    const router = TestBed.inject(Router);
    await router.navigateByUrl(url);
    const fixture = TestBed.createComponent(ReportsPage);
    fixture.detectChanges();
    const detect = async (): Promise<void> => {
      await fixture.whenStable();
      fixture.detectChanges();
    };
    return { el: fixture.nativeElement as HTMLElement, router, detect };
  }

  const table = (el: HTMLElement, index: number): HTMLTableElement => el.querySelectorAll('table')[index] as HTMLTableElement;
  const rowNames = (t: HTMLTableElement): string[] => [...t.querySelectorAll('tbody th')].map((th) => th.textContent?.trim() ?? '');

  it('default arrows follow the API order: touches by total, reasons by count; sources have none', async () => {
    const { el } = await open('/');
    const sorted = (t: HTMLTableElement): string[] => [...t.querySelectorAll('thead th[aria-sort]:not([aria-sort="none"])')].map((th) => th.getAttribute('aria-label') ?? '');
    expect(sorted(table(el, 0))).toEqual(['recruiting.reports.total']);
    expect(sorted(table(el, 1))).toEqual([]);
    expect(sorted(table(el, 2))).toEqual(['recruiting.reports.count']);
    expect(rowNames(table(el, 0))).toEqual(['Bob', 'Ann']);
    // Channel headers keep their icon inside the sort button.
    expect(table(el, 0).querySelector('thead th button.title app-channel-icon')).not.toBeNull();
  });

  it('a click sorts one table only (prefixed URL) and the «Разом» row stays last', async () => {
    const { el, router, detect } = await open('/');
    const reasons = table(el, 2);
    const countTitle = reasons.querySelectorAll('thead th')[1].querySelector('button.title') as HTMLButtonElement;
    countTitle.click(); // count desc (default) → asc
    await detect();
    expect(router.url).toBe('/?rej_sort=count&rej_dir=asc');
    expect(rowNames(reasons)).toEqual(['Accepted another offer', 'Salary']);
    expect(reasons.querySelector('tfoot th')?.textContent?.trim()).toBe('recruiting.reports.grandTotal');
    expect(rowNames(table(el, 0))).toEqual(['Bob', 'Ann']);
  });

  it('address → view: recruiter filter and source sort come from the URL', async () => {
    const { el } = await open('/?tch_recruiter=an&src_sort=candidates&src_dir=asc');
    expect(rowNames(table(el, 0))).toEqual(['Ann']);
    expect([...table(el, 1).querySelectorAll('tbody td.barcell .num')].map((n) => n.textContent?.trim())).toEqual(['2', '3']);
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
