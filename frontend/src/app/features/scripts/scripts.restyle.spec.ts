import { Type } from '@angular/core';
import { EvaluationBadge } from './evaluation/evaluation-badge';
import { TasksWidget } from './tasks/tasks-widget';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Scripts restyle', () => {
  it('evaluation chip: band colour on the line and icon, 44px on phones', () => {
    const style = css(EvaluationBadge);
    expect(style).toContain('--band');
    expect(style).toMatch(/max-width:\s*600px\)\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\{[^{}]*min-height:\s*2\.75rem/);
    expect(css(EvaluationBadge)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });

  it('tasks: track dividers, due time in mono, overdue in the AA warn text token', () => {
    const style = css(TasksWidget);
    expect(style).toContain('var(--app-track)');
    expect(style).toContain('var(--app-font-mono)');
    expect(style).toContain('var(--app-warn-text)');
  });
});
