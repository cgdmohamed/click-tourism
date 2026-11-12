import { defineConfig, devices } from '@playwright/test'

/**
 * Browser end-to-end tests.
 *
 * Locally, set `E2E_BASE_URL` to run against servers that are already up
 * (for example `http://127.0.0.1:5173`). Without it Playwright starts the
 * Laravel API and the Vite dev server itself, so CI needs no extra service.
 *
 * The suite seeds its own minimum data through the API, so it works against a
 * plain install (`php artisan migrate --seed` with an explicit `DEMO_PASSWORD`)
 * as well as the demo dataset. It never posts journals: the invoice it opens is
 * a draft, which is exactly what the document renderer must support.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173'

export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/support/global-setup.ts',
  outputDir: './test-results',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list'], ['html', { open: 'never' }]],
  timeout: 45_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  // The accessibility sweep loads many pages and runs last: every page load
  // hits `demo/status`, which shares its throttle counter with the 10/min
  // login limit, and the invoice spec needs a real form login.
  projects: [
    { name: 'chromium', testIgnore: /accessibility/, use: { ...devices['Desktop Chrome'] } },
    { name: 'a11y', testMatch: /accessibility/, dependencies: ['chromium'], use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : [
        {
          command: 'php artisan serve --host=127.0.0.1 --port=8000',
          cwd: '../backend',
          url: 'http://127.0.0.1:8000/api/v1/health',
          reuseExistingServer: !process.env.CI,
          timeout: 120_000,
        },
        {
          command: 'npm run dev -- --host 127.0.0.1 --port 5173',
          url: 'http://127.0.0.1:5173',
          reuseExistingServer: !process.env.CI,
          timeout: 120_000,
        },
      ],
})
