import { Type } from '@angular/core';
import { ArticlePage } from './article.page';
import { KnowledgePage } from './knowledge.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Knowledge restyle', () => {
  it('tag buttons reach 44px on phones; list rows use the track line', () => {
    const style = css(KnowledgePage);
    expect(style).toMatch(/max-width:\s*600px\)\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\{[^{}]*min-height:\s*2\.75rem/);
    expect(style).toContain('var(--app-track)');
    expect(css(KnowledgePage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });

  it('the pressed vote button is marked through button tokens', () => {
    expect(css(ArticlePage)).toContain('--mat-button-outlined-outline-color');
  });
});
