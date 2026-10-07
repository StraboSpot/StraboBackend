/* StraboSearch subsystem chip bar in REAL Firefox (PIs 2026-10-07): the
 * toggle-only All / StraboField / StraboMicro / StraboExperimental chips
 * that replaced the U8 "Strabo Subsystem" criterion row. Checks chip logic
 * (All = no filter, all three on or last one off = All), the DSL's
 * top-level subsystems in each search request, the returned projects'
 * subsystems, criteria picker gating, URL round-trip + reload, Start Over,
 * a chip click with a keyword searches at once and keeps the tab + sort,
 * saved-search summaries, old shared links, the label-line + one-row chip
 * layout (nothing cut off, no shift when lit) from 360px to 1920px, the
 * phone drawer + Filters badge (drawer stays open on a chip click), and
 * that the Export Builder (which reuses builder.js) has no chip bar.
 *
 * Forges a dev PHP session for user 3 (Export Builder needs a login) and
 * deletes it afterwards. Needs `playwright` resolvable from the CWD
 * (NODE_PATH or a scratch npm install) + `npx playwright install firefox`.
 *
 * Run from the www dir:  node tests/strabosearch/ui_test_subsystem_chips_ff.js
 * PASS: exit 0 + final line "RESULT: all checks PASS"
 */
const { firefox } = require('playwright');
const { execFileSync } = require('child_process');
const crypto = require('crypto');

const SID = 'sschips' + crypto.randomBytes(8).toString('hex');
const SESS = '/var/lib/php/sessions/sess_' + SID;
const OUT = process.env.SHOT_DIR || null;   // optional screenshot folder
const sh = cmd => execFileSync('docker', ['exec', 'strabo-php', 'sh', '-c', cmd], { encoding: 'utf8' });
sh(`printf 'loggedin|s:3:"yes";userpkey|i:3;username|s:15:"jasonash@ku.edu";LAST_ACTIVITY|i:%s;' $(date +%s) > ${SESS} && chown www-data ${SESS}`);
let fails = 0;
const check = (l, c) => { console.log((c ? '  PASS  ' : '  FAIL  ') + l); if (!c) fails++; };
const enc = o => Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
(async () => {
  const b = await firefox.launch();
  const ctx = await b.newContext({ viewport: { width: 1300, height: 900 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let lastReq = null, lastResp = null;
  p.on('request', r => { if (r.url().includes('action=search') && !r.url().includes('search_geo')) lastReq = JSON.parse(r.postData()); });
  p.on('response', async r => { if (r.url().includes('action=search') && !r.url().includes('search_geo')) { try { lastResp = await r.json(); } catch (e) {} } });
  const lit = () => p.$$eval('.ss-subsys-chip.ss-on', a => a.map(x => x.getAttribute('data-subsystem')));
  const settle = async () => { await p.waitForLoadState('networkidle'); await p.waitForTimeout(400); };
  const isSearch = r => r.url().includes('action=search') && !r.url().includes('search_geo');
  const click = async v => {
    lastReq = null; lastResp = null;
    const wait = p.waitForResponse(isSearch, { timeout: 4000 }).then(r => r.json()).catch(() => null);
    await p.click(`.ss-subsys-chip[data-subsystem="${v}"]`);
    const j = await wait; if (j) lastResp = j;
    await settle();
  };
  const subsOf = () => (lastResp && Array.isArray(lastResp.results) && lastResp.results.length) ? [...new Set(lastResp.results.map(r => r.project_subsystem))].sort() : null;

  console.log('=== first load ===');
  await p.goto('http://localhost/strabosearch/'); await settle();
  check('chip bar shows 4 chips', (await p.$$('.ss-subsys-chip')).length === 4);
  check('labels', JSON.stringify(await p.$$eval('.ss-subsys-chip', a => a.map(x => x.textContent))) === '["All","Field","Micro","Experimental"]');
  check('All lit', JSON.stringify(await lit()) === '["all"]');
  check('no Strabo Subsystem criterion', !(await p.$$eval('.ss-crit-select option', a => a.map(o => o.textContent))).includes('Strabo Subsystem'));
  check('catalog has several subsystems', (subsOf() || []).length >= 2); console.log('    catalog page subsystems:', subsOf());
  if (OUT) await p.screenshot({ path: OUT + '/chips_all.png', clip: { x: 0, y: 150, width: 420, height: 300 } });

  console.log('=== Micro only ===');
  await click('micro');
  check('Micro lit only', JSON.stringify(await lit()) === '["micro"]');
  check('request subsystems = [micro]', lastReq && JSON.stringify(lastReq.subsystems) === '["micro"]');
  check('results all micro', JSON.stringify(subsOf()) === '["micro"]');
  check('URL carries the filter', /\?q=/.test(p.url()));
  const gated = await p.$$eval('.ss-crit-select optgroup', gs => gs.map(g => [g.label, [...g.querySelectorAll('option')].every(o => o.disabled)]));
  check('Field group disabled, Micro not', gated.some(g => g[0] === 'Field' && g[1]) && gated.some(g => g[0] === 'Micro' && !g[1]));
  if (OUT) await p.screenshot({ path: OUT + '/chips_micro.png', clip: { x: 0, y: 150, width: 420, height: 300 } });

  console.log('=== reload keeps it ===');
  await p.reload(); await settle();
  check('Micro still lit after reload', JSON.stringify(await lit()) === '["micro"]');
  check('results still micro', JSON.stringify(subsOf()) === '["micro"]');

  console.log('=== add Field, then Exp -> All ===');
  await click('field');
  check('Field + Micro lit', JSON.stringify(await lit()) === '["field","micro"]');
  check('request [field, micro] in chip order', lastReq && JSON.stringify(lastReq.subsystems) === '["field","micro"]');
  check('results field + micro only', subsOf() !== null && subsOf().every(s => s === 'field' || s === 'micro'));
  await click('exp');
  check('all three on -> All lit', JSON.stringify(await lit()) === '["all"]');
  check('request has every subsystem', lastReq && JSON.stringify(lastReq.subsystems) === '["field","micro","exp"]');
  check('URL clean again', !/\?q=/.test(p.url()));

  console.log('=== toggle last off -> All; All resets ===');
  await click('exp'); await click('exp');
  check('Exp on then off -> All', JSON.stringify(await lit()) === '["all"]');
  await click('field'); await click('micro'); await click('all');
  check('All click resets', JSON.stringify(await lit()) === '["all"]');

  console.log('=== keyboard ===');
  await p.focus('.ss-subsys-chip[data-subsystem="exp"]'); await p.keyboard.press('Enter'); await settle();
  check('Enter toggles', JSON.stringify(await lit()) === '["exp"]');

  console.log('=== with a keyword: a chip click searches at once ===');
  await p.click('.ss-subsys-chip[data-subsystem="all"]'); await settle();
  await p.fill('.ss-row input[type="text"]', 'granite');
  await p.click('#ssSearchBtn'); await settle();
  check('keyword search ran unfiltered', lastReq && lastReq.subsystems.length === 3);
  await click('field');
  check('chip click sent keyword + [field] without Search', lastReq && JSON.stringify(lastReq.subsystems) === '["field"]' && lastReq.criteria.length === 1);
  check('results showing (no Press Search prompt)', await p.evaluate(() => window.SSResults.hasResults()) && !(await p.textContent('#ssResults')).includes('Press Search'));
  check('results all field', subsOf() !== null && subsOf().every(s => s === 'field'));
  check('URL mirrors keyword + chip', /\?q=/.test(p.url()));

  console.log('=== chip click keeps tab + sort ===');
  await p.click('.ss-subsys-chip[data-subsystem="all"]'); await settle();
  const imgTab = await p.$('.ss-tabbar [data-tab="images"], .ss-tabbar .ss-tab:nth-child(2)');
  if (imgTab) { await imgTab.click(); await settle(); }
  const before = await p.evaluate(() => window.SSResults.getUrlState());
  await click('micro');
  const after = await p.evaluate(() => window.SSResults.getUrlState());
  check('Images tab kept after chip click', before && before.tab === 'images' && after && after.tab === 'images');
  check('images request sent with [micro]', lastReq && lastReq.pathway === 'images' && JSON.stringify(lastReq.subsystems) === '["micro"]');
  await p.click('.ss-subsys-chip[data-subsystem="all"]'); await settle();

  console.log('=== Start Over ===');
  await p.click('.ss-start-over'); await settle();
  check('Start Over -> All', JSON.stringify(await lit()) === '["all"]');

  console.log('=== saved-search summary + save gate ===');
  check('summary of all = no criteria', await p.evaluate(() => SSCatalog.summarizeDsl({ subsystems: ['field','micro','exp'], criteria: [] })) === '(no criteria)');
  check('summary of legacy 4 = no criteria', await p.evaluate(() => SSCatalog.summarizeDsl({ subsystems: ['field','micro','exp','samples'], criteria: [] })) === '(no criteria)');
  check('summary of micro', await p.evaluate(() => SSCatalog.summarizeDsl({ subsystems: ['micro'], criteria: [] })) === 'subsystem=Micro');

  console.log('=== old shared links ===');
  await p.goto('http://localhost/strabosearch/?q=' + enc({ dsl: { subsystems: ['exp', 'field'], criteria: [] }, tab: 'projects', view: 'list' })); await settle();
  check('[exp, field] link lights Field + Exp', JSON.stringify(await lit()) === '["field","exp"]');
  await p.goto('http://localhost/strabosearch/?q=' + enc({ dsl: { subsystems: ['field','micro','exp','samples'], criteria: [{ id: 'U1', value: 'granite' }] }, tab: 'projects', view: 'list' })); await settle();
  check('legacy 4-subsystem link -> All', JSON.stringify(await lit()) === '["all"]');

  console.log('=== one-row layout, 360px to 1920px ===');
  for (const [w, h] of [[1920, 1100], [1440, 900], [1280, 900], [1024, 800], [1023, 800], [400, 800], [360, 740]]) {
    const q = await ctx.newPage(); await q.setViewportSize({ width: w, height: h });
    await q.goto('http://localhost/strabosearch/'); await q.waitForLoadState('networkidle');
    if (w < 1024) { await q.click('#ssFiltersBtn'); await q.waitForTimeout(400); }
    const geo = () => q.$$eval('.ss-subsys-chip', a => a.map(c => ({ cut: c.scrollWidth > c.clientWidth + 1, top: Math.round(c.getBoundingClientRect().top), w: Math.round(c.getBoundingClientRect().width) })));
    const labelAbove = await q.evaluate(() => document.querySelector('.ss-subsys-label').getBoundingClientRect().bottom <= document.querySelector('.ss-subsys-chip').getBoundingClientRect().top);
    const g0 = await geo();
    await q.click('.ss-subsys-chip[data-subsystem="micro"]'); await q.waitForTimeout(150);
    await q.click('.ss-subsys-chip[data-subsystem="exp"]'); await q.waitForTimeout(150);
    const g1 = await geo();
    check(`${w}px: label on its own line`, labelAbove);
    check(`${w}px: one row, nothing cut off`, new Set(g1.map(x => x.top)).size === 1 && !g0.concat(g1).some(x => x.cut));
    check(`${w}px: lit chips keep their width`, JSON.stringify(g0.map(x => x.w)) === JSON.stringify(g1.map(x => x.w)));
    if (w < 1024) check(`${w}px: drawer stays open on a chip click`, await q.evaluate(() => document.getElementById('ssAppFrame').classList.contains('ss-drawer-open')));
    await q.close();
  }

  console.log('=== phone width ===');
  await p.setViewportSize({ width: 400, height: 800 });
  await p.goto('http://localhost/strabosearch/'); await settle();
  await p.click('#ssFiltersBtn'); await p.waitForTimeout(500);
  await p.click('.ss-subsys-chip[data-subsystem="micro"]'); await settle();
  check('Filters badge counts the chip filter', (await p.textContent('#ssFiltersBadge')) === '1');
  check('no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  if (OUT) await p.screenshot({ path: OUT + '/chips_phone.png' });
  await p.click('#ssDrawerCloseLink'); await p.waitForTimeout(500);
  check('badge still 1 with drawer closed', (await p.textContent('#ssFiltersBadge')) === '1');

  console.log('=== Export Builder: no chip bar ===');
  await ctx.addCookies([{ name: 'PHPSESSID', value: SID, domain: 'localhost', path: '/' }]);
  await p.setViewportSize({ width: 1300, height: 900 });
  await p.goto('http://localhost/export_builder'); await settle();
  check('export builder has criteria rows', (await p.$$('#criteriaBuilder .ss-row')).length >= 1);
  check('export builder has no chip bar', (await p.$$('.ss-subsys')).length === 0);

  check('no page errors', errs.length === 0); if (errs.length) console.log(errs);
  await b.close();
  console.log(fails ? `RESULT: ${fails} FAILED` : 'RESULT: all checks PASS');
  process.exitCode = fails ? 1 : 0;
})().catch(e => { console.error(e); process.exitCode = 1; })
  .finally(() => { sh(`rm -f ${SESS}`); });
