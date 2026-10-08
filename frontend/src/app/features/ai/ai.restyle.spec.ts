import { AiPanel } from './ai-panel';
import { AiPromptDialog } from './ai-prompt.dialog';
import { css } from '../../../testing/css';

describe('AI restyle', () => {
  it('panel: card line from the theme, period toggle reaches 44px on phones', () => {
    const style = css(AiPanel);
    expect(css(AiPanel)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
    expect(style).not.toMatch(/border:\s*1px/);
    expect(style).toMatch(/max-width:\s*600px\)\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\{[^{}]*--mat-button-toggle-height:\s*44px/);
  });

  it('panel head comes from the shared service-panel mixin (core/ui/styles), same as the Google panel', () => {
    const style = css(AiPanel);
    expect(style).toMatch(/\.panel[^{]*\{[^}]*padding:\s*1rem 1\.25rem/);
    expect(style).toMatch(/\.head[^{]*\{[^}]*flex-wrap:\s*wrap/);
    expect(style).toMatch(/\.notice[^{]*\{[^}]*margin:\s*0\.5rem 0 0/);
  });

  it('prompt dialog: prompt text and output in the mono font token', () => {
    expect(css(AiPromptDialog)).toContain('var(--app-font-mono)');
    expect(css(AiPromptDialog)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
