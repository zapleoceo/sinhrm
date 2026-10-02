import { Type } from '@angular/core';
import { DashboardPage } from './dashboard.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Overview restyle', () => {
  it('KPI tiles: sleeper colour by counter; one pop + one trace, both off with reduced motion', () => {
    const style = css(DashboardPage);
    expect(style).toContain('--sleeper');
    expect(style).toMatch(/@keyframes\s+\S*pop/);
    expect(style).toMatch(/@keyframes\s+\S*trace/);
    expect(style).toMatch(/prefers-reduced-motion:\s*reduce[^@]*animation:\s*none/);
    expect(style).toContain('var(--app-stage-hire)');
    expect(css(DashboardPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
