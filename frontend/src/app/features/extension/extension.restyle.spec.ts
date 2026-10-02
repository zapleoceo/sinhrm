import { Type } from '@angular/core';
import { ExtensionPage } from './extension.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Extension restyle', () => {
  it('panels and the one-time token box use the 1.5px line, the token is mono', () => {
    const style = css(ExtensionPage);
    expect(style).not.toMatch(/1px (solid|dashed)/);
    expect(style).toContain('var(--app-font-mono)');
    expect(css(ExtensionPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
