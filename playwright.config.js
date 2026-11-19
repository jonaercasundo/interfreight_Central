import { defineConfig, devices } from '@playwright/test'

const port = process.env.IFC_E2E_PORT || '18717'

export default defineConfig({
  testDir: './tests/Browser',
  testMatch: '*.spec.js',
  fullyParallel: false,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: `php artisan serve --host=127.0.0.1 --port=${port}`,
    url: `http://127.0.0.1:${port}/login`,
    reuseExistingServer: false,
    timeout: 60_000,
    env: {
      APP_ENV: 'testing', IFC_E2E: '1', APP_DEBUG: 'false', APP_KEY: process.env.APP_KEY,
      APP_URL: `http://127.0.0.1:${port}`, DB_CONNECTION: 'sqlite', DB_DATABASE: process.env.IFC_E2E_DATABASE,
      CACHE_STORE: 'array', SESSION_DRIVER: 'file', SESSION_FILES: process.env.IFC_E2E_SESSIONS, QUEUE_CONNECTION: 'sync',
    },
  },
})
