// ESLint of the UI parity harness (Node + Playwright), separate from the Angular app config (`ng lint` covers src/ only).
import eslint from '@eslint/js';
import { defineConfig } from 'eslint/config';
import tseslint from 'typescript-eslint';

const names = ['process', 'console', 'setTimeout', 'URL', 'URLSearchParams', 'Buffer', '__dirname', '__filename', 'document', 'window', 'getComputedStyle', 'requestAnimationFrame', 'fetch', 'HTMLElement', 'Element'];
const globals = { node: Object.fromEntries(names.map((n) => [n, 'readonly'])), browser: {} };

export default defineConfig([
  { ignores: ['.out/**', '.visual/**'] },
  {
    files: ['**/*.ts', '**/*.mjs'],
    extends: [eslint.configs.recommended, tseslint.configs.recommended],
    languageOptions: { globals: { ...globals.node, ...globals.browser } },
  },
  { files: ['**/*.mjs'], rules: { '@typescript-eslint/no-explicit-any': 'off' } },
]);
