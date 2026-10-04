// UI parity harness (docs/guides/ui-parity.md). Runs against the production build with a mock API.
import { defineConfig } from '@playwright/test';
import { E2E_ORIGIN, E2E_PORT } from './port.mjs';

const CI = !!process.env['CI'];
const VISUAL = !!process.env['E2E_VISUAL'];
const desktop = { width: 1440, height: 900 };
const mobile = { width: 390, height: 844 };
const common = { locale: 'uk-UA', timezoneId: 'Europe/Kyiv', reducedMotion: 'reduce' as const, baseURL: E2E_ORIGIN };

export default defineConfig({
  testDir: '.',
  testMatch: VISUAL ? ['visual.pw.ts'] : ['parity.pw.ts', 'flows.pw.ts', 'assistant-context.pw.ts'],
  outputDir: '.out/results',
  snapshotPathTemplate: '.visual/{projectName}/{arg}{ext}',
  fullyParallel: true,
  workers: 4,
  retries: 0,
  timeout: 45_000,
  expect: { timeout: 7_000, toHaveScreenshot: { maxDiffPixelRatio: 0.002, animations: 'disabled' } },
  forbidOnly: CI,
  reporter: CI ? [['list'], ['html', { open: 'never', outputFolder: '.out/report' }]] : [['list'], ['html', { open: 'never', outputFolder: '.out/report' }]],
  globalTeardown: './axe-teardown.ts',
  use: { ...common, actionTimeout: 10_000, trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  webServer: { command: `node e2e/serve.mjs --port=${E2E_PORT}`, cwd: '..', url: `${E2E_ORIGIN}/login`, reuseExistingServer: !CI, timeout: 30_000 },
  projects: [
    { name: 'desktop-light', use: { viewport: desktop, colorScheme: 'light' } },
    { name: 'desktop-dark', use: { viewport: desktop, colorScheme: 'dark' } },
    { name: 'mobile-light', use: { viewport: mobile, colorScheme: 'light' } },
    { name: 'mobile-dark', use: { viewport: mobile, colorScheme: 'dark' } },
  ],
});
