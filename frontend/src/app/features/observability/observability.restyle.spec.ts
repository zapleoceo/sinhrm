import { Type } from '@angular/core';
import { ErrorsPage } from './errors.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Errors page restyle', () => {
  it('resolved groups: dashed line + neutral rail instead of a fade; count is a mono badge', () => {
    const style = css(ErrorsPage);
    expect(style).not.toMatch(/opacity/);
    expect(style).toMatch(/border-style:\s*dashed/);
    expect(style).toContain('var(--app-font-mono)');
    expect(css(ErrorsPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
