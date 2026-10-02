import { Type } from '@angular/core';
import { ASSET_STATUSES, ASSET_STATUS_TONE } from './assets.model';
import { AssetsPage } from './assets.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Assets restyle', () => {
  it('gives every asset status a status-pill tone (colour + marker shape)', () => {
    for (const s of ASSET_STATUSES) {
      expect(PILL_TONES).toContain(ASSET_STATUS_TONE[s]);
    }
  });

  it('table rows are split by the 1.5px track line, no hex', () => {
    expect(css(AssetsPage)).toContain('var(--app-track)');
    expect(css(AssetsPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
