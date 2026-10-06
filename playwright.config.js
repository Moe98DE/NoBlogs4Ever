// Browser tests for critical publishing workflows, run against the local stack.
//   make integration seed && npm ci && npx playwright install chromium && npm run test:e2e
// Environment:
//   NBE_BASE_URL            default http://lvh.me:8080
//   NBE_DEMO_PASSWORD_FILE  default secrets/demo_password
//   NBE_MIGRATED_URL        auto-discovered from the stack when unset
//   NBE_CHROMIUM            optional path to an existing Chromium binary
const { defineConfig } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname);
const baseURL = process.env.NBE_BASE_URL || 'http://lvh.me:8080';
process.env.NBE_BASE_URL ||= baseURL;
process.env.NBE_DEMO_PASSWORD_FILE ||= path.join(root, 'secrets', 'demo_password');

// The integration suite creates random tenants, so discover the imported
// fixture post from the running stack unless a URL was supplied.
if (!process.env.NBE_MIGRATED_URL && fs.existsSync(process.env.NBE_DEMO_PASSWORD_FILE)) {
  try {
    const url = execFileSync('docker', [
      'compose', 'run', '--rm', '-T', 'cli', 'wp', 'eval-file',
      '/opt/nbe/tests/fixture-url.php', '--url=http://lvh.me', '--allow-root',
    ], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim().split(/\r?\n/).pop();
    if (url && /^https?:\/\//.test(url)) process.env.NBE_MIGRATED_URL = url;
  } catch {
    // The dependent test fails with a clear message instead.
  }
}

module.exports = defineConfig({
  testDir: './tests/browser',
  timeout: 90000,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    launchOptions: process.env.NBE_CHROMIUM ? { executablePath: process.env.NBE_CHROMIUM } : {},
  },
  reporter: [['list'], ['json', { outputFile: 'test-results/browser.json' }]],
  projects: [
    { name: 'desktop', use: { viewport: { width: 1440, height: 1000 } } },
    { name: 'mobile', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
});
