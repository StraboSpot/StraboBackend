/* My Samples parent/child list behavior in REAL Firefox: a sample given a
 * parent KEEPS its own top-level card (searchable, counted, filtered) and
 * gains a "Child of …" chip linking to the parent; the parent card keeps
 * its expand toggle listing the child. Pins the 2026-08-28 fix for the
 * "assigned a parent and the sample vanished from My Samples" report
 * (pre-fix, children rendered only inside the parent's toggle and were
 * invisible to search and the count).
 *
 * Fixture + forged session come from smoke_test_my_samples_type_filter.php
 * (setup / teardown modes, run via docker exec); the parent link
 * (spsty_f -> spsty_fme) is staged directly in strabosamples.samples and
 * reverted by fixture teardown (which deletes the samples).
 * Needs `playwright` resolvable from the CWD + firefox installed.
 *
 * Run from the www dir:  node tests/strabosamples/ui_test_my_samples_child_cards_ff.js
 * PASS: exit 0 + final line "ok": true
 */
const { firefox } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = 'http://localhost';
const FIXTURE = '/srv/app/www/tests/strabosamples/smoke_test_my_samples_type_filter.php';
const php = (...args) => execFileSync('docker', ['exec', 'strabo-php', 'php', FIXTURE, ...args], { encoding: 'utf8' });
const psql = (sql) => execFileSync('docker', ['exec', 'strabo-postgres', 'psql', '-U', 'strabodbuser', '-d', 'strabospot', '-t', '-c', sql], { encoding: 'utf8' });

const results = [];
let fails = 0;
const check = (label, cond, detail) => {
  results.push({ label, pass: !!cond, detail });
  if (!cond) fails++;
  console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (detail !== undefined ? '  -- ' + detail : ''));
};

let sid = null;
const cleanup = () => { if (sid) { try { php('teardown', sid); } catch (e) { console.error('teardown failed', e); } sid = null; } };
process.on('unhandledRejection', (e) => { console.error(e); cleanup(); process.exit(2); });

(async () => {
  const fx = JSON.parse(php('setup').trim().split('\n').pop());
  sid = fx.sid;

  // Stage the hierarchy: spsty_f becomes a child of spsty_fme.
  psql("UPDATE strabosamples.samples SET parent_sample_id='spsty_fme', parent_userpkey=94570 WHERE id='spsty_f' AND userpkey=94570");

  const browser = await firefox.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addCookies([{ name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' }]);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));

  const go = async (qs) => { await page.goto(BASE + '/my_samples.php' + (qs || ''), { waitUntil: 'load' }); await page.waitForSelector('#ms-cards .ms-card, #ms-cards .ms-empty'); };
  const cardCodes = () => page.locator('#ms-cards .ms-card').evaluateAll((els) => els.map((e) => (e.textContent.match(/Fixture (\w+)/) || [])[1]).sort());
  const countText = () => page.locator('#ms-result-count').textContent();

  // ---- 1. Child keeps its own top-level card -----------------------------
  await go('');
  const codes = await cardCodes();
  check('all 8 samples render as top-level cards (child included)',
    JSON.stringify(codes) === JSON.stringify(['e', 'f', 'fe', 'fm', 'fme', 'm', 'me', 'none']),
    JSON.stringify(codes));
  check('count line says 8 samples', (await countText()).trim().startsWith('8 samples'), (await countText()).trim());

  // ---- 2. "Child of" chip on the child card, linking to the parent -------
  const childCard = page.locator('#ms-cards .ms-card').filter({ hasText: 'Fixture f' }).filter({ has: page.locator('.ms-parent-chip') }).first();
  check('child card carries a Child-of chip', await childCard.count() > 0 ? true : false);
  const chipText = (await page.locator('.ms-parent-chip').first().textContent()).trim();
  check('chip names the parent', chipText === 'Child of Fixture fme', chipText);
  const chipHref = await page.locator('.ms-parent-chip a').first().getAttribute('href');
  check('chip links to the parent overview', chipHref === '/samples/94570/spsty_fme', chipHref);
  check('exactly one card has the chip', await page.locator('.ms-parent-chip').count() === 1);

  // ---- 3. Search finds the child by id (pre-fix: children were invisible
  // to search entirely) ----------------------------------------------------
  await page.fill('#ms-search', 'spsty_f');
  await page.waitForTimeout(300);
  const searchCodes = await cardCodes();
  check('search "spsty_f" finds the child card', searchCodes.includes('f'), JSON.stringify(searchCodes));

  // ---- 3b. Search clear "×" ----------------------------------------------
  const clearBtn = page.locator('#ms-search-clear');
  check('clear × visible while searching', await clearBtn.isVisible());
  await clearBtn.click();
  await page.waitForTimeout(200);
  check('clear × empties the box', (await page.inputValue('#ms-search')) === '');
  check('clear × restores the full list', (await countText()).trim().startsWith('8 samples'), (await countText()).trim());
  check('clear × hides itself when empty', !(await clearBtn.isVisible()));
  check('clear × refocuses the search box',
    await page.evaluate(() => document.activeElement && document.activeElement.id === 'ms-search'));

  // URL-restored search shows the × on load.
  await go('?search=spsty_f');
  check('× visible on load with ?search= in URL', await page.locator('#ms-search-clear').isVisible());

  // ---- 4. Parent card keeps its expand toggle ----------------------------
  await page.fill('#ms-search', '');
  await page.waitForTimeout(300);
  const toggle = page.locator('.ms-children-toggle');
  check('parent card shows the child toggle', await toggle.count() === 1);
  check('toggle labeled with child count', (await toggle.first().textContent()).indexOf('1 child sample') !== -1, await toggle.first().textContent());
  await toggle.first().click();
  await page.waitForTimeout(200);
  const childRow = page.locator('.ms-child-row');
  check('expanded toggle lists the child row', await childRow.count() === 1);
  check('child row names the child', (await childRow.first().textContent()).indexOf('Fixture f') !== -1);

  // ---- 5. Type filter includes the child ---------------------------------
  await go('?type=field');
  const fieldCodes = await cardCodes();
  check('Field include filter shows the child (f is field-linked)', fieldCodes.includes('f'), JSON.stringify(fieldCodes));

  check('no page JS errors', errors.length === 0, JSON.stringify(errors));

  await browser.close();
  cleanup();

  const residue = psql("SELECT count(*) FROM strabosamples.samples WHERE userpkey=94570").trim();
  check('zero fixture residue', residue === '0', residue);

  console.log(JSON.stringify({ ok: fails === 0, checks: results.length, fails }));
  process.exit(fails === 0 ? 0 : 1);
})().catch((e) => { console.error(e); cleanup(); process.exit(2); });
