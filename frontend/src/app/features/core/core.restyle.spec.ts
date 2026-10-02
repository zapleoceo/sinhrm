import { ModuleOffPage } from './module-off.page';
import { ModulesPage } from './modules.page';
import { StatusPage } from './status.page';
import { css } from '../../../testing/css';

describe('Core pages restyle', () => {
  it('modules matrix: track row lines, muted label header, no hex', () => {
    const style = css(ModulesPage);
    expect(style).toContain('var(--app-track)');
    expect(style).toContain('var(--mat-sys-label-medium)');
    expect(css(ModulesPage)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });

  it('page titles use the headline tracking token; the off icon is muted, not faded', () => {
    for (const page of [ModulesPage, StatusPage, ModuleOffPage]) {
      expect(css(page)).toContain('var(--mat-sys-headline-small-tracking)');
    }
    expect(css(ModuleOffPage)).not.toMatch(/opacity/);
  });
});
