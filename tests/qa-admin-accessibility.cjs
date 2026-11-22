'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const { chromium } = require(path.join(root, '.security-test/browser-tools/node_modules/playwright-core'));
async function main() {
  const fixturePath = process.env.CLEVO_ADMIN_BROWSER_FIXTURE;
  if (!fixturePath || path.resolve(fixturePath) !== path.join(root, '.security-test/private/admin-browser.json')) throw new Error('Disposable fixture required');
  const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
  if (fixture.base_url !== 'http://127.0.0.1:18089') throw new Error('Refusing non-isolated target');
  const cookies = fs.readFileSync(fixture.cookie_jar, 'utf8').split(/\r?\n/).filter(l => l.startsWith('#HttpOnly_') || (l && !l.startsWith('#'))).map(l => {
    const f = l.replace(/^#HttpOnly_/, '').split('\t');
    return { name: f[5], value: f[6], domain: f[0], path: f[2], secure: f[3] === 'TRUE', httpOnly: l.startsWith('#HttpOnly_'), sameSite: 'Lax' };
  });
  const browser = await chromium.launch({ executablePath: process.env.CLEVO_ADMIN_BROWSER_EXECUTABLE || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const checks = [];
  try {
    await context.addCookies(cookies);
    const page = await context.newPage();
    const routes = ['management.php?view=bookings', 'management.php?view=people', 'management.php?view=services', 'management.php?view=support', 'station-approvals.php', `financial-reports.php?from=${fixture.date}&to=${fixture.date}&book=cash`];
    for (const language of ['ar', 'en']) for (const theme of ['light', 'dark']) {
      await page.goto(fixture.base_url + '/admin/management.php', { waitUntil: 'networkidle' });
      await page.evaluate(({ language, theme }) => { localStorage.setItem('app_lang', language); localStorage.setItem('global_theme', theme); }, { language, theme });
      for (const route of routes) {
        const response = await page.goto(fixture.base_url + '/admin/' + route, { waitUntil: 'networkidle' });
        if (response.status() !== 200 || await page.locator('html').getAttribute('lang') !== language || await page.locator('html').getAttribute('data-theme') !== theme) throw new Error('Authenticated accessibility page/language/theme failed');
        if (!checks.length) {
          const upload = page.locator('.admin-pic-container');
          if (await upload.getAttribute('role') !== 'button' || await upload.getAttribute('tabindex') !== '0') throw new Error('Profile upload must be keyboard reachable');
          const chooserEvent = page.waitForEvent('filechooser');
          await upload.press('Enter');
          const chooser = await chooserEvent;
          if (await chooser.element().getAttribute('name') !== 'admin_pic_upload') throw new Error('Keyboard upload targeted an unexpected input');
        }
        await page.addScriptTag({ path: path.join(root, '.security-test/browser-tools/node_modules/axe-core/axe.min.js') });
        const result = await page.evaluate(async () => {
          const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } });
          return { engine: r.testEngine, violations: r.violations.map(v => ({ id: v.id, impact: v.impact, help: v.help, helpUrl: v.helpUrl, targets: v.nodes.map(n => n.target), contrast_details: v.nodes.map(n => ({target:n.target,checks:n.any.filter(c=>c.id==='color-contrast').map(c=>({foreground:c.data?.fgColor,background:c.data?.bgColor,ratio:c.data?.contrastRatio,fontSize:c.data?.fontSize,fontWeight:c.data?.fontWeight}))})) })), manual_review_rules: r.incomplete.map(v => v.id) };
        });
        checks.push({ route, language, theme, ...result });
      }
    }
  } finally { await context.close(); await browser.close(); }
  const result = { pages_checked: checks.length, keyboard_upload_checks: 1, violation_instances: checks.reduce((n, c) => n + c.violations.length, 0), checks, scope: 'Automated WCAG rule checks on representative rendered admin pages in both languages and themes; not a screen-reader or full accessibility certification.' };
  fs.writeFileSync(path.join(root, '.security-test/qa-admin-accessibility.json'), JSON.stringify(result, null, 2));
  console.log('Admin accessibility: ' + result.pages_checked + ' page/language combinations, ' + result.violation_instances + ' violation rule instances.');
  for (const c of checks) for (const v of c.violations) console.log(`${c.language} ${c.theme} ${c.route}: ${v.id}, ${v.impact}, ${v.targets.length} nodes`);
  if (result.violation_instances) process.exitCode = 1;
}
main().catch(error => { console.error('Admin accessibility incomplete: ' + String(error.message).split('\n')[0]); process.exitCode = 2; });
