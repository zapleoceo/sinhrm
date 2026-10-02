import { ArticlePage } from './article.page';
import { KnowledgePage } from './knowledge.page';
import { css } from '../../../testing/css';

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
