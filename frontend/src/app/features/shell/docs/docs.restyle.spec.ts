import { Type } from '@angular/core';
import { DocsOverview } from './docs-overview';
import { DocsPage } from './docs.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Help (docs) restyle', () => {
  it('TOC: current page = brand rail, links reach 44px on phones, group titles not faded', () => {
    const style = css(DocsPage);
    expect(style).toMatch(/max-width:\s*600px\)\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\{[^{}]*padding:\s*0?\.7rem/);
    expect(style).not.toMatch(/opacity/);
    expect(css(DocsPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });

  it('map: zone sleepers in stage colours, focus ring token', () => {
    const style = css(DocsOverview);
    expect(style).toContain('var(--app-focus-ring)');
    expect(style).toContain('var(--app-stage-screen)');
  });
});
