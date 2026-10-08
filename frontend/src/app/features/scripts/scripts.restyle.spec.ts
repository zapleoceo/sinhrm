import { ScriptEditorPage } from './editor/script-editor.page';
import { EvaluationBadge } from './evaluation/evaluation-badge';
import { TasksWidget } from './tasks/tasks-widget';
import { css } from '../../../testing/css';

describe('Scripts restyle', () => {
  it('editor cards come from the shared sortable-items mixin; cards outside the list keep their gap', () => {
    const style = css(ScriptEditorPage);
    expect(style).toMatch(/\.handle[^{]*\{[^}]*cursor:\s*grab/);
    expect(style).toMatch(/\.cdk-drag-placeholder[^{]*\{[^}]*dashed/);
    expect(style).toMatch(/\.item[^{,]*\{[^}]*margin-bottom:\s*0\.75rem/);
    expect(style).not.toMatch(/\.small[^{]*\{/); // the global .small utility (styles.scss) is used instead
  });

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
