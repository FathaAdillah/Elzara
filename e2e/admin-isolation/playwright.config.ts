import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests',
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  reporter: 'list',
  use: { ...devices['Desktop Chrome'], trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  webServer: {
    command: 'node server.mjs',
    url: 'http://localhost:3088/ready',
    reuseExistingServer: false,
    timeout: 120000,
  },
})
