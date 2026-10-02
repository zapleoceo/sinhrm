import { ReportCatalogPage } from './catalog.page';
import { ReportTable } from './report-table';
import { css } from '../../../testing/css';

describe('Reports restyle', () => {
  it('chart bars are drawn once («trace») and stay static with reduced motion; numbers in mono', () => {
    const style = css(ReportTable);
    expect(style).toMatch(/@keyframes\s+\S*trace/);
    expect(style).toMatch(/prefers-reduced-motion:\s*reduce[^@]*animation:\s*none/);
    expect(style).toContain('var(--app-font-mono)');
    expect(css(ReportTable)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });

  it('catalog links are stations on a line and reach 44px on phones', () => {
    const style = css(ReportCatalogPage);
    expect(style).toMatch(/border-radius:\s*50%/);
    expect(style).toMatch(/max-width:\s*600px\)\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\{[^{}]*min-height:\s*2\.75rem/);
  });
});
