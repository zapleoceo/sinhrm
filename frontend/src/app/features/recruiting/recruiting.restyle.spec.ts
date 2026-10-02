import { CandidateCard } from './card/candidate-card';
import { ScreeningPanel } from './card/screening-panel';
import { CandidateDialog } from './candidates/candidate.dialog';
import { CandidatesPage } from './candidates/candidates.page';
import { InboxResolveDialog } from './inbox/inbox-resolve.dialog';
import { InboxPage } from './inbox/inbox.page';
import { ReportsPage } from './reports/reports.page';
import { MarkdownField } from './vacancies/markdown-field';
import { VacanciesPage } from './vacancies/vacancies.page';
import { VacancyFormPage } from './vacancies/vacancy-form.page';

/** Restyle C «Маршрут» (docs/architecture/design-direction.md §4.2): recruiting pages draw with tokens, not hex or shadows. */
function stylesOf(cmp: unknown): string {
  // Compiled CSS: drop the encapsulation attributes and quotes so selectors read as written.
  return ((cmp as { ɵcmp: { styles?: string[] } }).ɵcmp.styles ?? [])
    .join('\n')
    .replace(/\[_ng(content|host)-[^\]]*\]/g, '')
    .replace(/%NS%/g, '')
    .replace(/["']/g, '');
}

const COMPONENTS: [string, unknown][] = [
  ['CandidatesPage', CandidatesPage],
  ['CandidateCard', CandidateCard],
  ['ScreeningPanel', ScreeningPanel],
  ['CandidateDialog', CandidateDialog],
  ['VacanciesPage', VacanciesPage],
  ['VacancyFormPage', VacancyFormPage],
  ['MarkdownField', MarkdownField],
  ['InboxPage', InboxPage],
  ['InboxResolveDialog', InboxResolveDialog],
  ['ReportsPage', ReportsPage],
];

describe('recruiting restyle (C «Маршрут»)', () => {
  it.each(COMPONENTS)('%s uses colour tokens, no hex colours and no ad-hoc rgb() shadows', (_name, cmp) => {
    const css = stylesOf(cmp);
    expect(css.length).toBeGreaterThan(0);
    expect(css).not.toMatch(/#[0-9a-f]{3,8}\b/i);
    expect(css).not.toMatch(/rgba?\(/i);
  });

  it('candidate card draws the route as stations coloured by stage type (closed = square)', () => {
    const css = stylesOf(CandidateCard);
    for (const kind of ['select', 'hire', 'closed']) {
      expect(css).toContain(`[data-kind=${kind}]`);
    }
    expect(css).toContain('--app-stage-closed');
    expect(css).toMatch(/\[data-kind=closed\][^{]*::before[^{]*\{[^}]*border-radius:\s*2px/);
  });

  it('list rows are at least 44px tall for touch', () => {
    expect(stylesOf(CandidatesPage)).toMatch(/min-height:\s*44px/);
    expect(stylesOf(VacanciesPage)).toMatch(/min-height:\s*52px/);
  });

  it('statuses carry a shape marker, not colour alone', () => {
    expect(stylesOf(ReportsPage)).toMatch(/\.chip\[data-status=paused\]::before[^{]*\{[^}]*rotate:\s*45deg/);
    expect(stylesOf(CandidateCard)).toMatch(/\.status\[data-status=rejected\]::before[^{]*\{[^}]*border-radius:\s*1px/);
  });
});
