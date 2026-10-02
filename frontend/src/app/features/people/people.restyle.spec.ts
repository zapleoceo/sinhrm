import { OrgChartControls, OrgChartLegend } from './org-chart/org-chart-controls';
import { OrgChartPage } from './org-chart/org-chart.page';
import { OrgPersonPanel } from './org-chart/org-person-panel';
import { PeoplePage } from './directory/people.page';
import { CompensationTab } from './profile/compensation.tab';
import { EmployeeDialog } from './profile/employee.dialog';
import { ProfilePage } from './profile/profile.page';
import { TerminateDialog } from './profile/terminate.dialog';

/** Restyle C «Маршрут» (docs/architecture/design-direction.md §4.2): people pages draw with tokens, not hex or shadows. */
function stylesOf(cmp: unknown): string {
  // Compiled CSS: drop the encapsulation attributes and quotes so selectors read as written.
  return ((cmp as { ɵcmp: { styles?: string[] } }).ɵcmp.styles ?? [])
    .join('\n')
    .replace(/\[_ng(content|host)-[^\]]*\]/g, '')
    .replace(/%NS%/g, '')
    .replace(/["']/g, '');
}

const COMPONENTS: [string, unknown][] = [
  ['PeoplePage', PeoplePage],
  ['ProfilePage', ProfilePage],
  ['EmployeeDialog', EmployeeDialog],
  ['TerminateDialog', TerminateDialog],
  ['CompensationTab', CompensationTab],
  ['OrgChartPage', OrgChartPage],
  ['OrgPersonPanel', OrgPersonPanel],
  ['OrgChartControls', OrgChartControls],
  ['OrgChartLegend', OrgChartLegend],
];

describe('people restyle (C «Маршрут»)', () => {
  it.each(COMPONENTS)('%s uses colour tokens, no hex colours and no ad-hoc rgb() shadows', (_name, cmp) => {
    const css = stylesOf(cmp);
    expect(css.length).toBeGreaterThan(0);
    expect(css).not.toMatch(/#[0-9a-f]{3,8}\b/i);
    expect(css).not.toMatch(/rgba?\(/i);
  });

  it('terminate dialog: the error text uses the AA --app-bad-text token', () => {
    const css = stylesOf(TerminateDialog);
    expect(css).toMatch(/\.error[^{]*\{[^}]*color:\s*var\(--app-bad-text\)/);
    expect(css).not.toMatch(/color:\s*var\(--app-danger\)/);
  });

  it('draws avatars as station rings and statuses as shape-marker pills', () => {
    for (const cmp of [PeoplePage, ProfilePage]) {
      const css = stylesOf(cmp);
      expect(css).toMatch(/\.avatar[^{]*\{[^}]*border:[^;]*solid/);
      expect(css).toContain("[data-status=terminated]");
      expect(css).toContain('border-style: dashed');
    }
  });

  it('org chart: lines instead of shadows, one «pop», search hit without a pulsing glow', () => {
    const css = stylesOf(OrgChartPage);
    expect(css).toMatch(/@keyframes \S*pop/);
    expect(css).not.toMatch(/@keyframes \S*pulse/);
    expect(css).toContain('prefers-reduced-motion');
    expect(css).toMatch(/\.toggle[^{]*::after[^{]*\{[^}]*inset:\s*-10px/);
  });
});
