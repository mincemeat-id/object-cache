const { test, expect } = require('@playwright/test');

const phase = process.env.MINCEMEAT_E2E_PHASE || 'available';
const password = process.env.MINCEMEAT_E2E_ADMIN_PASSWORD || 'admin-e2e-only';
const forbiddenValues = ['redis-e2e-only', 'mincemeat-e2e', 'redis:6379'];

async function completeLogin(page) {
  await page.getByLabel('Username or Email Address').fill('admin');
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Log In' }).click();
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

async function login(page) {
  await page.goto('/wp-login.php');
  await completeLogin(page);
}

async function expectAvailable(response) {
  expect(response).not.toBeNull();
  expect(response.status()).toBeLessThan(500);
}

async function expectSecretsRedacted(page) {
  const body = await page.locator('body').innerText();
  for (const value of forbiddenValues) {
    expect(body).not.toContain(value);
  }
}

test('browser lifecycle and runtime state', async ({ page }) => {
  await login(page);

  if (phase === 'activate') {
    await page.goto('/wp-admin/plugins.php');
    const plugin = page.locator('tr[data-slug="mincemeat-object-cache"]');
    await expect(plugin).toBeVisible();
    await plugin.locator('.activate a').click();
    await expect(plugin).toHaveClass(/active/);
  }

  if (phase === 'multisite') {
    await page.goto('/wp-admin/network/plugins.php');
    let plugin = page.locator('tr[data-slug="mincemeat-object-cache"]');
    await expect(plugin).toBeVisible();
    if (await plugin.locator('.activate a').count()) {
      await plugin.locator('.activate a').click();
    }
    if (await page.locator('#user_login').isVisible()) {
      await completeLogin(page);
      await page.goto('/wp-admin/network/plugins.php');
      plugin = page.locator('tr[data-slug="mincemeat-object-cache"]');
      if (await plugin.locator('.activate a').count()) {
        await plugin.locator('.activate a').click();
      }
    }
    await expect(plugin).toHaveClass(/active/);
    await expect(page.getByRole('heading', { name: 'Plugins', exact: true })).toBeVisible();
    return;
  }

  await expectAvailable(await page.goto('/wp-admin/'));
  await expect(page.locator('#wpadminbar')).toBeVisible();
  await expectAvailable(await page.goto('/'));

  await page.goto('/wp-admin/site-health.php?tab=debug');
  const section = page.getByRole('button', { name: /Mincemeat Object Cache/ });
  await expect(section).toBeVisible();
  await section.click();
  await expect(page.getByText('Drop-in Status', { exact: true })).toBeVisible();
  await expect(page.getByText('Cache State', { exact: true })).toBeVisible();
  await expect(page.getByText('Connection Endpoint', { exact: true })).toBeVisible();
  await expectSecretsRedacted(page);

  if (phase === 'outage') {
    await expect(page.getByText(/runtime-only|degraded/, { exact: false })).toBeVisible();
  } else {
    await expect(page.getByText('persistent', { exact: true })).toBeVisible();
  }
});
