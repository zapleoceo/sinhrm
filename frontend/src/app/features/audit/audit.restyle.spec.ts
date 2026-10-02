import { Type } from '@angular/core';
import { AuditHistory } from './audit-history';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Audit restyle', () => {
  it('history reads as a feed on one route line (track + station rings)', () => {
    const style = css(AuditHistory);
    expect(style).toContain('var(--app-track)');
    expect(style).toMatch(/border-radius:\s*50%/);
    expect(style).toContain('var(--app-font-mono)');
    expect(css(AuditHistory)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
