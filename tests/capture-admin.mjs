/**
 * Capture the actual, authenticated WordPress plugin pages in disposable CI.
 */
import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
const base = process.env.WP_TEST_BASE || 'http://127.0.0.1:8765';
const out = process.env.SWC_SCREENSHOT_DIR || 'wporg-screenshots';
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 1600, height: 980 }, deviceScaleFactor: 1 });
await fs.mkdir(out, { recursive: true });
try {
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('local-test-only');
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/, { timeout: 30000 });
  async function capture(slug, filename, expected) {
    await page.goto(base + '/wp-admin/admin.php?page=' + slug, { waitUntil: 'networkidle' });
    await page.getByText(expected, { exact: false }).first().waitFor();
    await page.screenshot({ path: path.join(out, filename), fullPage: true, animations: 'disabled' });
    console.log('PASS: Captured live WordPress plugin UI ' + filename);
  }
  await capture('smart-webp-converter-settings', 'screenshot-1.png', 'Conversion settings');
  await capture('smart-webp-converter', 'screenshot-2.png', 'Media conversion status');
  const html = await page.content();
  if (html.includes('124 Original images') || html.includes('100% Success rate')) {
    throw new Error('Mock statistics must never appear in a real plugin screenshot');
  }
} finally {
  await browser.close();
}
