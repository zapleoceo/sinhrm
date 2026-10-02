import { Type } from '@angular/core';
import { UsersPage } from './users.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Users restyle', () => {
  it('frame comes from the global .panel; status is a pill, not a chip colour override', () => {
    const style = css(UsersPage);
    expect(style).not.toMatch(/1px solid/);
    expect(style).not.toContain('--mat-chip-label-text-color');
    expect(css(UsersPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
