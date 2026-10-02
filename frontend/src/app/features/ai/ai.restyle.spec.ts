import { Type } from '@angular/core';
import { AiPanel } from './ai-panel';
import { AiPromptDialog } from './ai-prompt.dialog';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('AI restyle', () => {
  it('panel: card line from the theme, period toggle reaches 44px on phones', () => {
    const style = css(AiPanel);
    expect(css(AiPanel)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
    expect(style).not.toMatch(/border:\s*1px/);
    expect(style).toMatch(/max-width:\s*600px\)\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\{[^{}]*--mat-button-toggle-height:\s*44px/);
  });

  it('prompt dialog: prompt text and output in the mono font token', () => {
    expect(css(AiPromptDialog)).toContain('var(--app-font-mono)');
    expect(css(AiPromptDialog)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
