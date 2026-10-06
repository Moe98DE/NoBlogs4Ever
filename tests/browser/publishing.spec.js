// Core publishing workflows: routing, login, block and classic editing, export, feeds, migrated content.
const { test, expect } = require('@playwright/test');
const { base, site, login, closeWelcomeGuide } = require('./helpers');

test('base domain and tenant subdomains resolve without redirect loops', async ({ request }) => {
  for (const url of [`${base}/`, `${site('garden')}/`, `${site('journal')}/`]) {
    const response = await request.get(url, { maxRedirects: 0 });
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain('<html');
  }
});

test('site administrator sees the platform page but not network controls; export works', async ({ page }) => {
  await login(page);
  await page.goto(`${site('garden')}/wp-admin/admin.php?page=nbe`);
  await expect(page.getByRole('heading', { name: 'Your sites' })).toBeVisible();
  await page.goto(`${site('garden')}/wp-admin/network/settings.php`);
  await expect(page.locator('body')).toContainText(/not allowed|permission|restricted/i);
  await page.goto(`${site('garden')}/wp-admin/plugin-install.php`);
  await expect(page.locator('body')).toContainText(/not allowed|permission|restricted/i);
  await page.goto(`${site('garden')}/wp-admin/export.php`);
  await expect(page.getByRole('heading', { name: 'Export', exact: true })).toBeVisible();
  const download = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download Export File' }).click();
  expect((await download).suggestedFilename()).toMatch(/\.xml$/);
});

test('imported content renders with the old source host blocked', async ({ page }) => {
  const url = process.env.NBE_MIGRATED_URL;
  if (!url) throw new Error('NBE_MIGRATED_URL must point to the integration fixture post (run `make integration` first)');
  const sourceRequests = [];
  await page.route('**/*', async (route) => {
    const host = new URL(route.request().url()).hostname;
    if (host === 'source.example' || host === 'old.example') {
      sourceRequests.push(route.request().url());
      await route.abort();
    } else {
      await route.continue();
    }
  });
  await page.goto(url);
  await expect(page.locator('body')).toContainText('Grüße');
  expect(sourceRequests).toEqual([]);
  for (const image of await page.locator('article img, main img').all()) {
    expect(await image.evaluate((el) => el.complete && el.naturalWidth > 0)).toBeTruthy();
  }
  const feed = await page.request.get(new URL('/feed/', url).href);
  expect(feed.status()).toBe(200);
  expect(await feed.text()).toContain('<rss');
});

test('pages make no third-party requests', async ({ page }) => {
  const external = [];
  page.on('request', (request) => {
    const host = new URL(request.url()).hostname;
    if (!host.endsWith(new URL(base).hostname) && !request.url().startsWith('data:')) external.push(request.url());
  });
  await page.goto(`${site('garden')}/`);
  await page.waitForLoadState('networkidle');
  expect(external).toEqual([]);
});

test('publish an article in the block editor', async ({ page }) => {
  await login(page);
  await page.goto(`${site('garden')}/wp-admin/post-new.php`);
  await closeWelcomeGuide(page);
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  const title = `Browser verification ${test.info().project.name} ${Date.now()}`;
  await canvas.locator('h1[contenteditable="true"]').fill(title);
  await page.waitForFunction((expected) => window.wp?.data?.select('core/editor')?.getEditedPostAttribute('title') === expected, title);
  await canvas.locator('[aria-label="Add default block"]').click({ force: true });
  await canvas.locator('[contenteditable="true"]').last().fill('Published through the native block editor.');
  await page.getByRole('button', { name: 'Publish', exact: true }).last().click();
  await page.getByRole('button', { name: 'Publish', exact: true }).last().click();
  await expect(page.locator('body')).toContainText(/published|saved/i);
  const postId = await page.evaluate(() => window.wp.data.select('core/editor').getCurrentPostId());
  await page.goto(`${site('garden')}/?p=${postId}`);
  await expect(page.getByRole('main').getByRole('heading', { name: title, exact: true }).first()).toBeVisible();
});

test('publish and edit an article in the classic editor', async ({ page }) => {
  await login(page);
  await page.goto(`${site('garden')}/wp-admin/post-new.php?classic-editor&classic-editor__forget`);
  const title = `Classic ${test.info().project.name} ${Date.now()}`;
  await page.locator('#title').fill(title);
  await page.locator('#content-html').click();
  await page.locator('textarea#content').fill('Written in the classic editor.');
  await page.waitForTimeout(500);
  await Promise.all([page.waitForURL(/post\.php\?post=\d+/), page.locator('#publish').click()]);
  await expect(page.locator('#message')).toContainText(/published/i);
  await page.locator('textarea#content').fill('Written in the classic editor, then edited.');
  await Promise.all([page.waitForURL(/message=1/), page.locator('#publish').click()]);
  await expect(page.locator('#message')).toContainText(/updated/i);
  const link = await page.locator('#sample-permalink a').getAttribute('href');
  await page.goto(link);
  await expect(page.locator('body')).toContainText('then edited');
});

test('registration page is reachable and follows the policy', async ({ page }) => {
  await page.goto('/wp-signup.php');
  await expect(page.locator('body')).toContainText(/username|registration|invitation|create/i);
});
