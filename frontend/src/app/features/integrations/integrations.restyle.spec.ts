import { IntegrationCard } from './integration-card';
import { INTEGRATION_STATUS_TONE, IntegrationStatus } from './integrations.model';
import { IntegrationsPage } from './integrations.page';
import { css } from '../../../testing/css';

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Integrations restyle', () => {
  it('gives every integration status a status-pill tone (colour + marker shape)', () => {
    for (const s of (['off', 'demo', 'connected', 'error'] as const satisfies readonly IntegrationStatus[])) {
      expect(PILL_TONES).toContain(INTEGRATION_STATUS_TONE[s]);
    }
  });

  it('connected, error and off differ by marker shape, not only by colour', () => {
    expect(INTEGRATION_STATUS_TONE.connected).toBe('good');
    expect(INTEGRATION_STATUS_TONE.error).toBe('bad');
    expect(INTEGRATION_STATUS_TONE.off).toBe('neutral');
  });

  it('cards and the AI banner: card fill + 1.5px line, the banner state also on a 4px rail; no chip colour overrides', () => {
    const card = css(IntegrationCard);
    expect(card).toContain('var(--app-border-w)');
    expect(card).not.toContain('--mat-chip-label-text-color');
    expect(css(IntegrationsPage)).toMatch(/border-left-width:\s*4px/);
    expect(css(IntegrationCard)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
