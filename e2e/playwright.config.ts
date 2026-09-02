import { defineConfig, devices } from '@playwright/test';
import { URLS } from './helpers/config';

/**
 * 3 アプリを 1 ランナーで検証する。projects ごとに baseURL を切り替え、
 * fullstack / laminas はブラウザ（page）、api は HTTP（request）で叩く。
 *
 * 対象は compose up で起動した実環境（実 MySQL）。URL は環境変数で上書きできる:
 *   E2E_FS_URL / E2E_LAMINAS_URL / E2E_API_URL
 * ただし対象はローカル（localhost / 127.0.0.1 / ::1）に限る。解決とガードは helpers/config.ts。
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    // 対象 URL の allowlist ガード（.claude/rules/testing.md）。壊れても他のテストは
    // 緑のままになるため明示的に検証する。ブラウザも起動中のアプリも要らないので先頭に置く。
    {
      name: 'guard',
      testDir: './tests/guard',
    },
    {
      name: 'fullstack',
      testDir: './tests/fullstack',
      use: { ...devices['Desktop Chrome'], baseURL: URLS.fullstack },
    },
    {
      name: 'laminas',
      testDir: './tests/laminas',
      use: { ...devices['Desktop Chrome'], baseURL: URLS.laminas },
    },
    {
      name: 'api',
      testDir: './tests/api',
      use: { baseURL: URLS.api },
    },
  ],
});
