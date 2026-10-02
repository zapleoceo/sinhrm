import { Type } from '@angular/core';
import { MailPage } from './mail.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Mail agent restyle', () => {
  it('status tiles carry a top «sleeper» line; warnings use the AA warn text token', () => {
    const style = css(MailPage);
    expect(style).toMatch(/border-top:\s*4px solid/);
    expect(style).toContain('var(--app-warn-text)');
    expect(css(MailPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
