import { ASSET_STATUSES, ASSET_STATUS_TONE } from './assets.model';
import { AssetsPage } from './assets.page';
import { css } from '../../../testing/css';

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Assets restyle', () => {
  it('gives every asset status a status-pill tone (colour + marker shape)', () => {
    for (const s of ASSET_STATUSES) {
      expect(PILL_TONES).toContain(ASSET_STATUS_TONE[s]);
    }
  });

  it('the table takes the shared .app-table look (no local copy of table rules), no hex', () => {
    expect(css(AssetsPage)).not.toMatch(/(^|[},])\s*(table|th|td)(\[[^\]]*\])?\s*[,{]/m);
    expect(css(AssetsPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
