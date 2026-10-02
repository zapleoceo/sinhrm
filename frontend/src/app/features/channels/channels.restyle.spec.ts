import { Type } from '@angular/core';
import { ChannelPanel } from './channel-panel';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Channels restyle', () => {
  it('channel panel separator is the 1.5px track line', () => {
    const style = css(ChannelPanel);
    expect(style).toContain('var(--app-track)');
    expect(style).not.toMatch(/1px solid/);
  });
});
