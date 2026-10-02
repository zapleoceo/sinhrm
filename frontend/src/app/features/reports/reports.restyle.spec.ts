import { Type } from '@angular/core';
import { ReportCatalogPage } from './catalog.page';
import { ReportTable } from './report-table';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

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
