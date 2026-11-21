'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const { chromium } = require(path.join(root, '.security-test/browser-tools/node_modules/playwright-core'));
let checks = 0;
function check(value, label) { checks++; if (!value) throw new Error(label); }
async function main() {
  const fixturePath = process.env.CLEVO_ADMIN_BROWSER_FIXTURE;
  if (!fixturePath || path.resolve(fixturePath) !== path.join(root, '.security-test/private/admin-browser.json')) throw new Error('Disposable browser fixture required');
  const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
  const base = new URL(fixture.base_url);
  if (base.hostname !== '127.0.0.1' || base.port !== '18089' || base.protocol !== 'http:') throw new Error('Refusing a non-isolated browser target');
  const cookies = fs.readFileSync(fixture.cookie_jar, 'utf8').split(/\r?\n/).filter(line => line.startsWith('#HttpOnly_') || (line && !line.startsWith('#'))).map(line => {
    const httpOnly = line.startsWith('#HttpOnly_');
    const fields = line.replace(/^#HttpOnly_/, '').split('\t');
    return { name: fields[5], value: fields[6], domain: fields[0], path: fields[2], secure: fields[3] === 'TRUE', httpOnly, sameSite: 'Lax' };
  });
  const executablePath = process.env.CLEVO_ADMIN_BROWSER_EXECUTABLE || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  const browser = await chromium.launch({ executablePath, headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
  const artifacts = path.join(root, '.security-test/admin-browser'); fs.mkdirSync(artifacts, { recursive: true });
  const errors = [];
  try {
    await context.addCookies(cookies);
    const page = await context.newPage();
    page.on('pageerror', error => errors.push({ path: new URL(page.url()).pathname, message: String(error.message).split('\n')[0].slice(0, 180).replace(/[A-Za-z0-9_-]{24,}/g, '[redacted]') }));
    await page.goto(base.origin + '/admin/management.php?view=disputes', { waitUntil: 'networkidle' });
    check(await page.locator('html').getAttribute('dir') === 'rtl', 'Arabic management uses RTL');
    await page.screenshot({ path: path.join(artifacts, 'management-ar-dark.png'), fullPage: true });
    const ticketForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="support_status"]') }).filter({ has: page.locator(`input[name="id"][value="${fixture.ticket_id}"]`) });
    check(await ticketForm.count() === 1, 'Dispute renders one canonical status form');
    check(JSON.stringify(await ticketForm.locator('option').evaluateAll(options => options.map(o => o.value))) === JSON.stringify(['New', 'Replied', 'Closed']), 'Arabic option values remain canonical');
    await ticketForm.locator('select[name="status"]').selectOption('Closed');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), ticketForm.locator('button[type="submit"], button:not([type])').click()]);
    check(await page.locator('form').filter({ has: page.locator(`input[name="id"][value="${fixture.ticket_id}"]`) }).locator('select[name="status"]').inputValue() === 'Closed', 'Browser submission actually closes dispute');
    await page.locator('#language').click();
    await page.waitForFunction(() => document.documentElement.lang === 'en');
    check(await page.locator('.admin-sidebar [data-i18n="nav_main"]').textContent() === 'Dashboard', 'Shared navigation switches to English');
    const reopen = page.locator('form').filter({ has: page.locator('input[name="action"][value="support_status"]') }).filter({ has: page.locator(`input[name="id"][value="${fixture.ticket_id}"]`) });
    check(await reopen.locator('option[value="Closed"]').textContent() === 'Closed', 'English translates the option label without changing its value');
    await reopen.locator('select[name="status"]').selectOption('New');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), reopen.locator('button').click()]);
    check(await page.locator('form').filter({ has: page.locator(`input[name="id"][value="${fixture.ticket_id}"]`) }).locator('select[name="status"]').inputValue() === 'New', 'English browser submission reopens dispute');
    await page.locator('#theme').click();
    check(await page.locator('html').getAttribute('data-theme') === 'light', 'Management theme changes to light');
    await page.screenshot({ path: path.join(artifacts, 'management-en-light.png'), fullPage: true });
    for (const file of ['admin-dashboard.php', 'manage-users.php', 'manage-stations.php', 'manage-plans.php', 'station-approvals.php', 'security-center.php', 'all-support-chats.php', 'notifications.php']) {
      const response = await page.goto(base.origin + '/admin/' + file, { waitUntil: 'networkidle' });
      check(response.status() === 200, 'Browser renders ' + file);
      check(await page.locator('html').getAttribute('data-theme') === 'light', 'Stored light mode survives navigation to ' + file);
      check(await page.locator('.u-name-display').first().textContent() === fixture.display_name, 'Quoted admin name stays correct on ' + file);
      check(await page.evaluate(() => !globalThis.__adminXss), 'Untrusted database text stays inert on ' + file);
      if (file === 'manage-plans.php') {
        const buttons = page.locator('button[onclick^="editPlan("]');
        const index = await buttons.evaluateAll((elements, id) => elements.findIndex(element => {
          const action = element.getAttribute('onclick');
          try { return Number(JSON.parse(action.slice('editPlan('.length, -1)).id) === id; } catch { return false; }
        }), fixture.plan_id);
        check(index >= 0, 'Quoted plan has a valid edit action');
        await buttons.nth(index).click();
        check(await page.locator('#p_name_en').inputValue() === fixture.quoted_text, 'Plan editor retains apostrophes and inert markup');
        await page.locator('#planModal [data-bs-dismiss="modal"]').click();
      }
    }
    const reportUrl = base.origin + '/admin/financial-reports.php?' + new URLSearchParams({ from: fixture.date, to: fixture.date, book: 'cash' });
    await page.goto(reportUrl, { waitUntil: 'networkidle' });
    check(await page.locator('.u-name-display').first().textContent() === fixture.display_name, 'Quoted Arabic admin names survive JavaScript rendering');
    const chart = await page.evaluate(() => {
      const chart = window.Chart?.getChart(document.getElementById('revenueChart'));
      chart?.update('none');
      return chart ? { labels: chart.data.labels, values: chart.data.datasets[0].data } : null;
    });
    check(chart !== null, 'Financial chart initializes in a real browser');
    check(Math.round(chart.values.reduce((a, b) => a + b, 0) * 1000) === fixture.expected_report.recognized_gross_minor, 'Rendered chart matches canonical ledger report');
    await page.screenshot({ path: path.join(artifacts, 'financial-en-light.png'), fullPage: true });
    await page.locator('[onclick="openExportModal()"]').click();
    const downloadPromise = page.waitForEvent('download');
    await page.locator('[onclick="exportToExcel()"]').click();
    const download = await downloadPromise;
    const csv = fs.readFileSync(await download.path(), 'utf8');
    check(csv.startsWith('\uFEFFrow_type,'), 'Real browser downloads Arabic-compatible CSV');
    check(csv.includes(',' + fixture.expected_report.commission_minor + ','), 'Downloaded CSV contains canonical commission');
    // Exercise the browser PDF print route through its actual CSRF-protected form.
    const printPromise = context.waitForEvent('page');
    await page.locator('button[form="secureExportForm"][value="print"]').click();
    const printPage = await printPromise; await printPage.waitForLoadState('networkidle');
    check(await printPage.locator('html').getAttribute('lang') === 'en', 'PDF print request retains the active language');
    check((await printPage.locator('h1').textContent()).includes('Financial report'), 'Authorized print view contains the real report');
    await printPage.screenshot({ path: path.join(artifacts, 'print-en.png'), fullPage: true });
    await printPage.close();
    await page.goto(base.origin + '/admin/management.php?view=bookings', { waitUntil: 'networkidle' });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForFunction(() => {
      const bounds = document.querySelector('.admin-sidebar').getBoundingClientRect();
      return bounds.height <= 80 && Math.abs(bounds.bottom - window.innerHeight) <= 1;
    });
    check(await page.locator('.admin-sidebar').isVisible(), 'Compact navigation stays at the bottom without covering the content viewport');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Compact layout contains horizontal scrolling inside its tables');
    await page.screenshot({ path: path.join(artifacts, 'management-compact.png'), fullPage: true });
    if (errors.length) console.error(JSON.stringify(errors));
    check(errors.length === 0, 'Admin navigation, forms and reports execute without JavaScript runtime errors');
    fs.writeFileSync(path.join(artifacts, 'result.json'), JSON.stringify({ checks, failed: 0, screenshots: 5, actualCustomerMessagesSent: false }, null, 2));
    console.log(`Admin Chromium browser suite: ${checks}/${checks} checks passed; 0 failed.`);
  } finally { await context.close(); await browser.close(); }
}
main().catch(error => { const reason = String(error.message).split('\n')[0].replace(/[a-zA-Z0-9_-]{24,}/g, '[redacted]'); console.error(`Admin Chromium browser suite failed after ${checks} checks: ${reason}`); process.exitCode = 1; });
