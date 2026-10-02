import { TestBed } from '@angular/core/testing';
import { provideNativeDateAdapter } from '@angular/material/core';
import { of } from 'rxjs';
import { clickTitle, column, header, openTablePage } from '../../../testing/table-page';
import { Survey, Wave, WaveResults } from './pulse.model';
import { PulseService } from './pulse.service';
import { WaveResultsPage } from './results/wave-results.page';
import { SurveysPage } from './surveys/surveys.page';

const survey: Survey = { id: 1, title: 'eNPS', type: 'custom', description: null, questions: [], lifecycle_trigger: null, active: true, waves_count: 3 };
const wave = (id: number, starts_at: string, status: Wave['status'], responses: number): Wave => ({
  id,
  survey: { id: 1, title: 'eNPS', type: 'custom' },
  parent_wave_id: null,
  schedule: 'once',
  audience: { branch_ids: [], department_ids: [] },
  anonymous: true,
  min_group_size: 5,
  starts_at,
  ends_at: starts_at,
  status,
  lifecycle: false,
  subject_employee_id: null,
  responses_count: responses,
});
// API order: newest start first.
const waves = [wave(3, '2026-09-01', 'open', 12), wave(2, '2026-06-01', 'closed', 40), wave(1, '2026-03-01', 'closed', 7)];

describe('SurveysPage waves table (header sort and filter)', () => {
  async function open(url: string): ReturnType<typeof openTablePage<SurveysPage>> {
    const page = await openTablePage(SurveysPage, url, [
      provideNativeDateAdapter(),
      { provide: PulseService, useValue: { surveys: () => of([survey]), templates: () => of([]), waves: () => of(waves) } },
    ]);
    (page.el.querySelector('nav.list button') as HTMLButtonElement).click();
    await page.settle();
    return page;
  }
  const wavesTable = (el: HTMLElement): HTMLTableElement => el.querySelector('table.waves') as HTMLTableElement;

  it('gets a header row with the API order (newest first) marked; a click sorts by responses', async () => {
    const { el, router, settle } = await open('/');
    const table = wavesTable(el);
    expect(header(table, 'pulse.waves.period').getAttribute('aria-sort')).toBe('descending');
    expect(header(table, 'table.actions').querySelector('button')).toBeNull();
    clickTitle(table, 'pulse.waves.answers');
    await settle();
    expect(router.url).toBe('/?wave_sort=responses&wave_dir=asc');
    // 7 → 12 → 40 responses: the waves of March, September, June.
    expect(column(table, 0).map((t) => t.slice(0, 10))).toEqual(['01.03.2026', '01.09.2026', '01.06.2026']);
  });

  it('address → view: the status filter keeps closed waves only', async () => {
    const { el } = await open('/?wave_status=closed');
    expect(column(wavesTable(el), 2)).toEqual(['pulse.waveStatus.closed', 'pulse.waveStatus.closed']);
  });
});

describe('WaveResultsPage segments table (header sort and filter)', () => {
  const block = { questions: [] };
  const results: WaveResults = {
    wave: { id: 5, survey: { id: 1, title: 'eNPS', type: 'custom' }, anonymous: true, min_group_size: 5, starts_at: '2026-09-01', ends_at: '2026-09-30', status: 'closed' },
    scope: 'all',
    state: 'closed',
    responses: 30,
    suppressed: false,
    questions: [],
    segments: [
      { ...block, segment: 1, name: 'Продажі', responses: 12, suppressed: false },
      { ...block, segment: 2, name: 'Бухгалтерія', responses: null, suppressed: true },
      { ...block, segment: 3, name: 'ІТ', responses: 18, suppressed: false },
    ],
  };

  async function open(url: string): ReturnType<typeof openTablePage<WaveResultsPage>> {
    return openTablePage(WaveResultsPage, url, [{ provide: PulseService, useValue: { results: () => of(results), compare: () => of(null) } }], { id: '5' });
  }
  const segTable = (el: HTMLElement): HTMLTableElement => el.querySelector('table.segments') as HTMLTableElement;

  it('hidden groups sort last in both directions and drop out of a number filter', async () => {
    const { el, settle } = await open('/');
    const table = segTable(el);
    clickTitle(table, 'pulse.results.answers');
    await settle();
    expect(column(table, 0)).toEqual(['Продажі', 'ІТ', 'Бухгалтерія']);
    clickTitle(table, 'pulse.results.answers');
    await settle();
    expect(column(table, 0)).toEqual(['ІТ', 'Продажі', 'Бухгалтерія']);
    TestBed.resetTestingModule();
    const filtered = await open('/?seg_responses_from=0');
    expect(column(segTable(filtered.el), 0)).toEqual(['Продажі', 'ІТ']);
  });

  it('address → view: names sorted by the interface language', async () => {
    const { el } = await open('/?seg_sort=name&seg_dir=asc');
    expect(column(segTable(el), 0)).toEqual(['Бухгалтерія', 'ІТ', 'Продажі']);
  });
});

