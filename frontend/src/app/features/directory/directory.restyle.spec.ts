import { Type } from '@angular/core';
import { DirectoryPage } from './directory.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Directory restyle', () => {
  it('panel frame comes from the global .panel; notices use 1.5px lines; status is a pill', () => {
    const style = css(DirectoryPage);
    expect(style).toContain('var(--app-border-w)');
    expect(style).not.toMatch(/1px solid/);
    expect(style).not.toContain('--mat-chip-label-text-color');
    expect(css(DirectoryPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
