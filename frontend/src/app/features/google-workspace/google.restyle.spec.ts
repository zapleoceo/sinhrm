import { GoogleConnectPanel } from './google-connect.panel';
import { SheetsImportPage } from './sheets-import.page';
import { css } from '../../../testing/css';

describe('Google Workspace restyle', () => {
  it('connect panel states are status pills, not bare coloured text', () => {
    const style = css(GoogleConnectPanel);
    expect(style).not.toMatch(/data-state/);
    expect(style).not.toMatch(/1px solid/);
    expect(css(GoogleConnectPanel)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });

  it('panel head comes from the shared service-panel mixin (core/ui/styles), same as the AI panel', () => {
    const style = css(GoogleConnectPanel);
    expect(style).toMatch(/\.panel[^{]*\{[^}]*padding:\s*1rem 1\.25rem/);
    expect(style).toMatch(/\.head[^{]*\{[^}]*flex-wrap:\s*wrap/);
    expect(style).toMatch(/\.notice[^{]*\{[^}]*margin:\s*0\.5rem 0 0/);
  });

  it('sheets import preview: track row lines and a label header', () => {
    const style = css(SheetsImportPage);
    expect(style).toContain('var(--app-track)');
    expect(style).toContain('var(--mat-sys-label-medium)');
  });
});
