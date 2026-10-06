// Shared helpers for the browser suite.
const { expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const base = process.env.NBE_BASE_URL || 'http://lvh.me:8080';
const root = path.resolve(__dirname, '..', '..');

/** Origin of a tenant subdomain, e.g. site('garden') → http://garden.lvh.me:8080 */
function site(slug) {
  const url = new URL(base);
  url.hostname = `${slug}.${url.hostname}`;
  return url.origin;
}

function password() {
  return fs.readFileSync(process.env.NBE_DEMO_PASSWORD_FILE, 'utf8').trim();
}

async function login(page, user = 'alice', origin = site('garden'), expectAdmin = true) {
  await page.goto(`${origin}/wp-login.php`);
  // wp-login.php moves focus after load; wait for it so typing is not split across fields.
  await page.waitForFunction(() => document.activeElement?.id === 'user_login');
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(password());
  await expect(page.locator('#user_login')).toHaveValue(user);
  await page.getByRole('button', { name: 'Log In', exact: true }).click();
  if (expectAdmin) await expect(page).toHaveURL(/wp-admin/);
}

/** Run WP-CLI in the stack (setup/teardown only; assertions belong in the browser). */
function wp(...args) {
  return execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', '--allow-root', '--url=http://lvh.me', ...args],
    { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

/** Dismiss the block editor welcome guide when it appears. */
async function closeWelcomeGuide(page) {
  const dialog = page.getByRole('dialog');
  for (let i = 0; i < 5; i++) {
    const close = dialog.getByRole('button', { name: /close/i });
    if (await close.count() && await close.first().isVisible().catch(() => false)) {
      await close.first().click();
      return;
    }
    await page.waitForTimeout(300);
  }
}

module.exports = { base, site, password, login, wp, closeWelcomeGuide };
