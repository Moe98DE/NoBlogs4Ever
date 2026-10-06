// Two-factor sign-in: enrol an authenticator app (TOTP) through the profile UI, then sign in with it.
const { test, expect } = require('@playwright/test');
const crypto = require('node:crypto');
const { site, password, wp, login: signIn } = require('./helpers');

function base32(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  return Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)));
}

function totp(secret, step = Math.floor(Date.now() / 30000)) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hmac = crypto.createHmac('sha1', base32(secret)).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 0xf;
  return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
}

test('enrol TOTP in the profile and sign in with a one-time code', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'one enrolment run is enough');
  const login = `mfa${Date.now().toString(36)}`;
  wp('user', 'create', login, `${login}@example.invalid`, `--user_pass=${password()}`, '--role=author', '--url=http://garden.lvh.me');
  try {
    await signIn(page, login);
    await page.goto(`${site('garden')}/wp-admin/profile.php`);

    const secret = await page.locator('#two-factor-totp-key').inputValue();
    expect(secret).toMatch(/^[A-Z2-7]{16,}$/);
    const enrolStep = Math.floor(Date.now() / 30000);
    await page.locator('#two-factor-totp-authcode').fill(totp(secret, enrolStep));
    await page.getByRole('button', { name: 'Verify' }).click();
    await expect(page.locator('#two-factor-totp-options').first()).toContainText(/configured|reset|enabled|active/i);

    await page.context().clearCookies();
    await signIn(page, login, site('garden'), false);
    const codeField = page.locator('#authcode');
    await expect(codeField).toBeVisible();
    // A code is accepted only once: wait for the next 30-second step after enrolment.
    while (Math.floor(Date.now() / 30000) <= enrolStep) await page.waitForTimeout(1000);
    await codeField.fill(totp(secret));
    await page.locator('#submit, input[type="submit"]').first().click();
    await expect(page).toHaveURL(/wp-admin/);
  } finally {
    wp('user', 'delete', login, '--yes', '--network');
  }
});
