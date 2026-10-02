import { Type } from '@angular/core';
import { GoogleConnectPanel } from './google-connect.panel';
import { SheetsImportPage } from './sheets-import.page';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

describe('Google Workspace restyle', () => {
  it('connect panel states are status pills, not bare coloured text', () => {
    const style = css(GoogleConnectPanel);
    expect(style).not.toMatch(/data-state/);
    expect(style).not.toMatch(/1px solid/);
    expect(css(GoogleConnectPanel)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });

  it('sheets import preview: track row lines and a label header', () => {
    const style = css(SheetsImportPage);
    expect(style).toContain('var(--app-track)');
    expect(style).toContain('var(--mat-sys-label-medium)');
  });
});
