import { Type } from '@angular/core';

/**
 * Compiled component CSS for style-contract tests (restyle C «Маршрут»: theme tokens only, no hex,
 * 1.5px lines, no fades). Test-only helper — excluded from the app build (`tsconfig.app.json`).
 */
export function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}
