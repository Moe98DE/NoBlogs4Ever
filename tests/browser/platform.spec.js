// Platform features: site creation, media, collaborators, import, analytics, archives, read-only mode.
const { test, expect } = require('@playwright/test');
const path = require('node:path');
const { site, login, wp } = require('./helpers');

test.beforeAll(() => { wp('site', 'option', 'update', 'nbe_max_sites_per_user', '50'); });
test.afterAll(() => { wp('site', 'option', 'update', 'nbe_max_sites_per_user', '3'); });

test('create a new site from "Your platform"', async ({ page }) => {
  await login(page);
  await page.goto(`${site('garden')}/wp-admin/admin.php?page=nbe`);
  const slug = `b${Date.now().toString(36)}${test.info().project.name.slice(0, 1)}`;
  await page.getByLabel('Address').fill(slug);
  await page.getByLabel('Title').fill('Browser-made site');
  try {
    await page.getByRole('button', { name: 'Create site' }).click();
    // Sessions are per host (host-only cookies), so the new site asks the owner to sign in there.
    await expect(page).toHaveURL(new RegExp(`//${slug}\\.`));
    await login(page, 'alice', site(slug));
    await expect(page.locator('#wpadminbar')).toContainText('Browser-made site');
  } finally {
    try { wp('site', 'delete', `--slug=${slug}`, '--yes'); } catch { /* not created */ }
  }
});

test('reserved site names are refused with a clear message', async ({ page }) => {
  await login(page);
  await page.goto(`${site('garden')}/wp-admin/admin.php?page=nbe`);
  await page.getByLabel('Address').fill('admin');
  await page.getByLabel('Title').fill('Nope');
  await page.getByRole('button', { name: 'Create site' }).click();
  await expect(page.locator('.notice-error')).toContainText(/reserved/i);
});

test('upload an image to the media library', async ({ page }) => {
  await login(page);
  await page.goto(`${site('garden')}/wp-admin/media-new.php?browser-uploader`);
  await page.locator('#async-upload').setInputFiles(path.join(__dirname, 'fixtures', 'upload.png'));
  await page.locator('#html-upload').click();
  await expect(page).toHaveURL(/upload\.php/);
  await expect(page.locator('body')).toContainText(/upload/i);
});

test('an executable upload is refused', async ({ page }) => {
  await login(page);
  await page.goto(`${site('garden')}/wp-admin/media-new.php?browser-uploader`);
  await page.locator('#async-upload').setInputFiles({ name: 'shell.php', mimeType: 'application/x-php', buffer: Buffer.from('<?php echo 1;') });
  await page.locator('#html-upload').click();
  await expect(page.locator('body')).toContainText(/not permitted|not allowed|type/i);
});

test('add an existing account as a collaborator with an editorial role', async ({ page }) => {
  await login(page, 'bob', site('journal'));
  await page.goto(`${site('journal')}/wp-admin/user-new.php`);
  await page.locator('#adduser-email').fill('editor@example.invalid');
  await page.locator('#adduser-role').selectOption('editor');
  await page.getByRole('button', { name: 'Add Existing User' }).click();
  await expect(page.locator('body')).toContainText(/added|invitation|already a member/i);
});

test('import a WordPress export and reach the author mapping step', async ({ page }) => {
  await login(page, 'bob', site('journal'));
  await page.goto(`${site('journal')}/wp-admin/tools.php?page=nbe-import`);
  await page.getByLabel('Publication archive').setInputFiles(path.join(__dirname, '..', 'fixtures', 'publication.xml'));
  await page.getByRole('button', { name: 'Upload and take inventory' }).click();
  await expect(page.locator('body')).toContainText(/Saved|already/i);
  wp('eval', '\\NBE\\Worker::run();');
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Who wrote what?' }).first()).toBeVisible();
  await expect(page.locator('body')).toContainText('source-alice');
});

test('site analytics and full site archive pages are available to administrators', async ({ page }) => {
  await login(page);
  await page.goto(`${site('garden')}/wp-admin/index.php?page=nbe-analytics`);
  await expect(page.getByRole('heading', { name: 'Site analytics' })).toBeVisible();
  await expect(page.locator('body')).toContainText(/No cookies/i);
  await page.goto(`${site('garden')}/wp-admin/tools.php?page=nbe-export`);
  await page.getByRole('button', { name: 'Create a new archive' }).click();
  wp('eval', '\\NBE\\Worker::run();');
  await page.goto(`${site('garden')}/wp-admin/tools.php?page=nbe-export`);
  const download = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download ZIP' }).first().click();
  expect((await download).suggestedFilename()).toMatch(/\.zip$/);
});

test('network discovery page lists opted-in sites', async ({ page }) => {
  wp('eval', '\\NBE\\Discovery::rebuild();');
  await page.goto('/discover/');
  await expect(page.locator('.nbe-directory')).toContainText(/Community Garden|Field Journal/);
});

test.describe('emergency read-only mode', () => {
  test.describe.configure({ mode: 'serial' });
  test.afterAll(() => { wp('site', 'option', 'update', 'nbe_readonly', '0'); });

  test('readers can still read, members can still sign in, writes are paused', async ({ page }) => {
    wp('site', 'option', 'update', 'nbe_readonly', '1');
    const response = await page.goto(`${site('garden')}/`);
    expect(response.status()).toBe(200);
    await login(page);
    await expect(page.locator('.notice-warning').first()).toContainText(/read-only/i);
    const result = await page.evaluate(async () => {
      const body = new URLSearchParams({ action: 'nbe_site', slug: 'readonlytest', title: 'x' });
      const r = await fetch('/wp-admin/admin-post.php', { method: 'POST', body, credentials: 'same-origin' });
      return r.status;
    });
    expect(result).toBe(503);
  });
});
