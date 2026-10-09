import { HttpErrorResponse } from '@angular/common/http';
import { of, throwError } from 'rxjs';
import { openTablePage } from '../../../../testing/table-page';
import { QuestionResult, WaveCompare, WaveResults } from '../pulse.model';
import { PulseService } from '../pulse.service';
import { WaveResultsPage } from './wave-results.page';

/**
 * Anonymity of wave results in the UI: whatever the API hides (a group below the minimum, a wave still open) the page
 * shows as «hidden» — never a number, a text answer or a breakdown. The API is the gate; these tests keep the page
 * from turning a hidden group back into a figure.
 */
const wave: WaveResults['wave'] = {
  id: 5,
  survey: { id: 1, title: 'Залученість', type: 'engagement' },
  anonymous: true,
  min_group_size: 5,
  starts_at: '2026-09-01',
  ends_at: '2026-09-30',
  status: 'closed',
};
const scale = (id: string, extra: Partial<QuestionResult> = {}): QuestionResult => ({
  id,
  type: 'scale5',
  text: `Питання ${id}`,
  answered: 12,
  average: 4.2,
  distribution: { '1': 0, '2': 1, '3': 2, '4': 4, '5': 5 },
  ...extra,
});
const results = (extra: Partial<WaveResults> = {}): WaveResults => ({
  wave,
  scope: 'all',
  state: 'closed',
  responses: 12,
  suppressed: false,
  questions: [scale('q1'), { id: 'q2', type: 'text', text: 'Що покращити?', answered: 3, texts: ['Більше кави'] }],
  ...extra,
});

async function open(data: WaveResults | HttpErrorResponse, compare: WaveCompare | null = null): Promise<HTMLElement> {
  const api = {
    results: () => (data instanceof HttpErrorResponse ? throwError(() => data) : of(data)),
    compare: () => of(compare),
  };
  const { el } = await openTablePage(WaveResultsPage, '/', [{ provide: PulseService, useValue: api }], { id: '5' });
  return el;
}

const text = (el: Element | null): string => el?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

describe('WaveResultsPage — anonymity', () => {
  it('shows the scores, the distribution and the text answers of a group above the minimum', async () => {
    const el = await open(results());
    expect(el.querySelectorAll('section.card').length).toBe(2);
    expect(text(el.querySelector('.avg'))).toBe('4.2');
    expect(el.querySelectorAll('.dist .col').length).toBe(5);
    expect([...el.querySelectorAll('ul.texts li')].map(text)).toEqual(['Більше кави']);
    expect(el.textContent).toContain('pulse.results.responses');
    expect(el.querySelector('.panel.suppressed')).toBeNull();
  });

  it('a whole wave below the minimum: only the «hidden» notice — no cards, no averages, no texts, no answer count', async () => {
    // Even if a suppressed answer carried stray data, the page must not draw it.
    const el = await open(results({ suppressed: true, responses: null }));
    expect(text(el.querySelector('.panel.suppressed'))).toContain('pulse.results.suppressed');
    expect(el.querySelector('.cards')).toBeNull();
    expect(el.querySelector('.avg')).toBeNull();
    expect(el.querySelector('ul.texts')).toBeNull();
    expect(el.textContent).not.toContain('Більше кави');
    expect(el.textContent).not.toContain('pulse.results.responses');
  });

  it('a wave that is not closed yet: only the coarse participation, no scores and no texts', async () => {
    const el = await open(results({ state: 'open', participation: { responded_bucket: '10-20', responded_percent: null } }));
    expect(text(el.querySelector('.panel.suppressed'))).toContain('pulse.results.notClosed');
    expect(el.querySelector('.cards')).toBeNull();
    expect(el.textContent).not.toContain('Більше кави');
  });

  it('a question hidden on its own says so', async () => {
    const el = await open(results({ questions: [scale('q1', { suppressed: true, average: null, distribution: undefined })] }));
    const card = el.querySelector('section.card');
    expect(text(card)).toContain('pulse.results.hidden');
    expect(card?.querySelector('.avg')).toBeNull();
  });

  it('a hidden segment shows «hidden» instead of its number of answers, even if a number came along', async () => {
    const el = await open(
      results({
        segments: [
          { segment: 1, name: 'Продажі', responses: 9, suppressed: false, questions: [] },
          { segment: 2, name: 'Бухгалтерія', responses: 3, suppressed: true, questions: [] },
        ],
      }),
    );
    const rows = [...el.querySelectorAll('table.segments tbody tr')].map((tr) => [...tr.children].map(text));
    expect(rows).toEqual([
      ['Продажі', '9'],
      ['Бухгалтерія', 'pulse.results.hidden'],
    ]);
  });

  it('comparison: a row hidden for anonymity says why, a cell without both values says «hidden», not «— → —»', async () => {
    const compare: WaveCompare = {
      scope: 'all',
      state: 'closed',
      segment: 'department',
      current: { id: 5, starts_at: '2026-09-01' },
      previous: { id: 4, starts_at: '2026-06-01' },
      questions: [{ id: 'q1', type: 'scale5', text: 'Питання q1' }],
      rows: [
        { segment: null, name: null, questions: [{ id: 'q1', current: 4.2, previous: 3.9, delta: 0.3 }] },
        { segment: 2, name: 'Бухгалтерія', hidden_reason: 'anonymity', questions: [{ id: 'q1', current: null, previous: null, delta: null }] },
      ],
    };
    const el = await open(results(), compare);
    const rows = [...el.querySelectorAll('table.table tbody tr')];
    expect(text(rows[0])).toContain('3.9 → 4.2');
    expect(text(rows[0].querySelector('.delta'))).toBe('+0.3');
    expect(text(rows[1].querySelector('th'))).toContain('pulse.results.diffHidden');
    expect(text(rows[1].querySelector('td'))).toBe('pulse.results.hidden');
  });

  it('a refusal of the API (403: not a manager of this department) shows the error, not an empty report', async () => {
    const el = await open(new HttpErrorResponse({ status: 403, error: { message: 'Forbidden' } }));
    expect(el.querySelector('[role="alert"]')).not.toBeNull();
    expect(el.querySelector('.cards')).toBeNull();
    expect(el.querySelector('mat-progress-bar')).toBeNull();
  });
});
