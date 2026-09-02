/* Export Builder + My Exports in REAL Firefox (design §12 M5; Jason tests in
 * Firefox). Drives the builder end to end: preselected project door, dataset
 * partial picks and the live "N spots match" count, the criteria builder
 * locked to Field criteria (keyword filter through the index, Start over),
 * the drawn-area modal ("Use current view" -> approximate count), format
 * gating, submit -> My Exports notice -> poll to done -> Download link ->
 * Details README -> Re-run -> in-page Clear dialog. Page errors fail the run.
 *
 * Fixture + forged session: tests/exportjobs/smoke_test_pages.php setup /
 * teardown modes (run via docker exec). Needs `playwright` resolvable (e.g.
 * NODE_PATH=<dir>/node_modules) + firefox installed.
 *
 * Run from the www dir:  node tests/exportjobs/ui_test_export_builder_ff.js [screenshot dir]
 * PASS: exit 0 + final line "ok": true
 */
const { firefox } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = 'http://localhost';
const FIXTURE = '/srv/app/www/tests/exportjobs/smoke_test_pages.php';
const SHOTS = process.argv[2] || null;
const php = (...args) => execFileSync('docker', ['exec', 'strabo-php', 'php', FIXTURE, ...args], { encoding: 'utf8' });

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
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const fx = JSON.parse(php('setup').trim().split('\n').pop());
  sid = fx.sid;
  const browser = await firefox.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1800 } });
  await ctx.addCookies([{ name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' }]);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('dialog', async (d) => { errors.push('native dialog: ' + d.message()); await d.dismiss(); });
  const countText = async () => (await page.locator('#eb-count').textContent()).replace(/\s+/g, ' ').trim();
  const waitCount = async (re, ms = 8000) => { await page.waitForFunction((src) => new RegExp(src).test(document.getElementById('eb-count').textContent.replace(/\s+/g, ' ')), re.source, { timeout: ms }); return countText(); };
  const shot = async (name) => { if (SHOTS) await page.screenshot({ path: SHOTS + '/' + name + '.png', fullPage: true }); };

  // ---- 1. door + picker + live count ---------------------------------------
  await page.goto(BASE + '/export_builder?p=' + fx.project + '&owner=' + fx.owner, { waitUntil: 'load' });
  await page.waitForSelector('.eb-proj');
  check('project preselected by the ?p=&owner= door', await page.isChecked('#eb-p-' + fx.project + '-' + fx.owner));
  check('datasets expanded and both ticked', await page.isChecked('#eb-d-' + fx.project + '-' + fx.owner + '-' + fx.ds_a) && await page.isChecked('#eb-d-' + fx.project + '-' + fx.owner + '-' + fx.ds_b));
  check('live count: 5 spots match', /^5 spots match/.test(await waitCount(/^5 spots match/)), await countText());
  check('add-row + start-over controls render in the filters panel', await page.locator('.ss-add-row a').count() === 2);
  check('criteria picker is Field-only (no Micro/Experimental/Image groups, no owner/subsystem)', await page.evaluate(() => {
    const opts = Array.from(document.querySelectorAll('.ss-crit-select option')).map((o) => o.value).filter(Boolean);
    const groups = Array.from(document.querySelectorAll('.ss-crit-select optgroup')).map((g) => g.label);
    return opts.includes('U1') && opts.includes('U2') && opts.includes('F7') && !opts.includes('U4') && !opts.includes('U8') && !opts.includes('M1') && !opts.includes('E1') && !opts.includes('I1') && groups.length === 2;
  }));
  check('layout block visible with two datasets in scope', await page.isVisible('#eb-layout-block'));
  check('extras block visible for a whole project', await page.isVisible('#eb-extras-block'));
  await shot('builder_loaded');
  // search.css locks body overflow for the /search app frame; the builder must still scroll to its footer
  check('page body scrolls (search.css overflow lock undone)', await page.evaluate(() => getComputedStyle(document.body).overflowY !== 'hidden'));
  await page.mouse.wheel(0, 20000); await sleep(400);
  check('footer reachable by scrolling', await page.evaluate(() => { const r = document.querySelector('#footer').getBoundingClientRect(); return window.scrollY > 0 && r.top < window.innerHeight; }), 'scrollY=' + await page.evaluate(() => window.scrollY));
  await page.mouse.wheel(0, -20000); await sleep(200);

  // untick Beta -> partial project, count 3, layout hidden
  await page.click('label[for="eb-d-' + fx.project + '-' + fx.owner + '-' + fx.ds_b + '"]');
  check('partial pick: project box unticked, "(1 of 2 datasets)" label', !(await page.isChecked('#eb-p-' + fx.project + '-' + fx.owner)) && /1 of 2 datasets/.test(await page.locator('.eb-partial').first().textContent()));
  check('live count follows the dataset pick: 3', /^3 spots match/.test(await waitCount(/^3 spots match/)), await countText());
  check('layout hidden for a single dataset; extras hidden for a partial project', !(await page.isVisible('#eb-layout-block')) && !(await page.isVisible('#eb-extras-block')));
  await page.click('label[for="eb-d-' + fx.project + '-' + fx.owner + '-' + fx.ds_b + '"]');
  check('re-ticking restores the whole project (box ticked, count 5)', await page.isChecked('#eb-p-' + fx.project + '-' + fx.owner) && /^5 spots match/.test(await waitCount(/^5 spots match/)));

  // ---- 2. keyword filter through the index ----------------------------------
  await page.selectOption('.ss-crit-select', 'U1');
  await page.fill('.ss-value input[type="text"]', 'Granite');
  check('keyword filter: count 1 + index drift note', /^1 spot match/.test(await waitCount(/^1 spot match/)) && /search index/.test(await page.locator('#eb-drift').textContent()), await countText());
  await page.click('.ss-start-over');
  check('Start over clears the filter (count 5, note gone)', /^5 spots match/.test(await waitCount(/^5 spots match/)) && (await page.locator('#eb-drift').textContent()).trim() === '');

  // ---- 3. drawn area via the Leaflet modal ----------------------------------
  await page.selectOption('.ss-crit-select', 'U2');
  await page.click('.ss-value a.button');
  await page.waitForSelector('#ssPolyMap.leaflet-container');
  await sleep(400);
  check('polygon modal opens with a Leaflet map', await page.isVisible('.ss-modal-map'));
  await page.click('.ss-modal-actions a:has-text("Use current view")');
  await page.click('.ss-modal-actions a:has-text("Save")');
  check('modal closes and the row shows a 4-vertex polygon', !(await page.isVisible('.ss-modal-map')) && /4 vertices/.test(await page.locator('.ss-bbox-summary').textContent()));
  check('area filter -> "About 5 spots match" + geometry note', /^About 5 spots match/.test(await waitCount(/^About 5 spots match/)) && /full geometries/.test(await countText()), await countText());
  await shot('builder_polygon');
  await page.click('.ss-start-over');
  await waitCount(/^5 spots match/);

  // ---- 4. output gating + submit ---------------------------------------------
  check('GeoJSON is the default format and Build is enabled', await page.isChecked('#eb-fmt-geojson') && (await page.getAttribute('#eb-build', 'aria-disabled')) === 'false');
  await page.click('label[for="eb-fmt-geojson"]');
  await sleep(50);
  check('no format -> Build disabled', (await page.getAttribute('#eb-build', 'aria-disabled')) === 'true');
  await page.click('label[for="eb-fmt-geojson"]');
  await page.click('label[for="eb-fmt-xls"]');
  await page.click('label[for="eb-fmt-sample_list"]');
  check('sample-list CSV option appears when Sample list is ticked', await page.isVisible('#eb-csv-wrap'));
  await page.click('label[for="eb-fmt-sample_list"]');
  check('field book options are hidden until Field book PDF is ticked', !(await page.isVisible('#eb-fb-wrap')));
  await page.click('label[for="eb-fmt-fieldbook"]');
  check('field book options row appears with the four selects at their defaults', await page.isVisible('#eb-fb-wrap') && (await page.inputValue('#eb-fb-map')) === 'outdoors' && (await page.inputValue('#eb-fb-photos')) === 'sheets' && (await page.inputValue('#eb-fb-nets')) === 'on' && (await page.inputValue('#eb-fb-page')) === 'letter');
  await page.selectOption('#eb-fb-map', 'none');      // keeps the worker off the tile network
  await page.selectOption('#eb-fb-page', 'a4');
  await shot('builder_fieldbook_options');
  await page.fill('#eb-notes', 'FF suite notes');
  await sleep(500);
  check('no page errors before submit', errors.length === 0, errors.join(' | '));
  await Promise.all([page.waitForURL(/\/my_exports\?new=/, { timeout: 15000 }), page.click('#eb-build')]);

  // ---- 5. My Exports: notice, poll to done, download, details ----------------
  await page.waitForSelector('.me-job');
  check('landed on My Exports with the queued notice', /queued/.test(await page.locator('#me-notice').textContent()) && await page.isVisible('#me-notice'));
  const uuid = new URL(page.url()).searchParams.get('new');
  const card = page.locator('.me-job[data-uuid="' + uuid + '"]');
  check('the new job card is listed with its summary', await card.count() === 1 && /Pages Project/.test(await card.locator('.me-summary').textContent()) && /geojson, xls/.test(await card.locator('.me-summary').textContent()));
  await page.waitForFunction((u) => { const c = document.querySelector('.me-job[data-uuid="' + u + '"]'); return c && c.getAttribute('data-status') === 'done'; }, uuid, { timeout: 90000 });
  check('polling reaches done with counts and size', /5 spots/.test(await card.locator('.me-meta').first().textContent()) && /expires in/.test(await card.locator('.me-meta').first().textContent()), await card.locator('.me-meta').first().textContent());
  const dl = card.locator('a[data-act="download"]');
  check('Download link points at download.php for this job', (await dl.getAttribute('href')) === '/exportjobs/download.php?j=' + uuid);
  const dlResp = await ctx.request.get(BASE + (await dl.getAttribute('href')));
  check('download streams a zip (200, attachment)', dlResp.status() === 200 && /application\/zip/.test(dlResp.headers()['content-type']) && /strabospot_export_/.test(dlResp.headers()['content-disposition'] || ''));
  await card.locator('a[data-act="details"]').click();
  await page.waitForSelector('.me-job[data-uuid="' + uuid + '"] .me-details pre:not(:empty)');
  const det = await card.locator('.me-details pre').textContent();
  check('Details shows notes + README', /FF suite notes/.test(det) && /Projects:/.test(det) && /Pages Project/.test(det));
  check('Details + README carry the field book options (map none, page a4)', /Field book: map none, photos sheets, stereonets on, page a4/.test(det), det.split('\n').filter((l) => /Field book/.test(l)).join(' | '));
  await shot('my_exports_done');

  // ---- 6. Re-run + Edit link + clear dialog ----------------------------------
  check('Edit and re-run links to the builder with ?from=', (await card.locator('a[data-act="edit"]').getAttribute('href')) === '/export_builder?from=' + uuid);
  const editPage = await ctx.newPage();
  await editPage.goto(BASE + '/export_builder?from=' + uuid, { waitUntil: 'load' });
  await editPage.waitForSelector('#eb-fb-wrap');
  await sleep(500);
  check('Edit reopens the builder with the field book options restored (map none, page a4)', await editPage.isVisible('#eb-fb-wrap') && (await editPage.inputValue('#eb-fb-map')) === 'none' && (await editPage.inputValue('#eb-fb-page')) === 'a4' && await editPage.isChecked('#eb-fmt-fieldbook'));
  await editPage.close();
  await card.locator('a[data-act="rerun"]').click();
  await page.waitForFunction(() => document.querySelectorAll('.me-job').length === 2, null, { timeout: 15000 });
  check('Re-run adds a second card marked re-run', await page.locator('.me-job').count() === 2 && /re-run/.test(await page.locator('.me-job').first().locator('.me-meta').first().textContent()));
  await page.waitForFunction(() => Array.from(document.querySelectorAll('.me-job')).every((c) => c.getAttribute('data-status') === 'done'), null, { timeout: 90000 });
  await page.click('#me-clear-finished');
  check('Clear finished opens the in-page dialog (no native confirm)', await page.isVisible('#me-dialog') && /2 exports/.test(await page.locator('#me-dialog-text').textContent()) && !errors.some((e) => /native dialog/.test(e)));
  await page.click('#me-dialog-cancel');
  check('dialog Cancel keeps both rows', !(await page.isVisible('#me-dialog')) && await page.locator('.me-job').count() === 2);
  await page.click('#me-clear-finished');
  await page.click('#me-dialog-ok');
  await page.waitForSelector('.me-empty');
  check('confirm clears them -> empty state with the builder link', /Build one/.test(await page.locator('.me-empty').textContent()));
  // ---- 7. M6 doors: menu, My Field Data toolbar, StraboSearch Export… ---------
  // HOLD 0d8555a (Jason 2026-09-02): menu entries + My Field Data button are commented out
  // until public testing. FULL LAUNCH = revert 0d8555a and flip these two checks back.
  check('HOLD 0d8555a: account menu hides Export Builder + My Exports (flip at full launch)', await page.evaluate(() => !document.querySelector('#header a[href="/export_builder"]') && !document.querySelector('#header a[href="/my_exports"]') && !!document.querySelector('#header a[href="/my_samples"]')));
  await page.goto(BASE + '/my_field_data', { waitUntil: 'load' });
  check('HOLD 0d8555a: My Field Data toolbar has + New Project (primary), Custom export… hidden', await page.evaluate(() => {
    const tb = document.querySelector('header.major + .mfd-toolbar');
    return !!tb && tb.querySelector('a[href="/new_project"].primary') !== null && tb.querySelector('a[href="/export_builder"]') === null && !/\(Add Project\)/.test(document.body.textContent);
  }));
  // Globe browse (Jason 2026-09-02): /globe -> ?view=globe runs an empty-criteria browse;
  // that is not an export scope, so Export… stays off with a tooltip that says why.
  await page.goto(BASE + '/strabosearch/?view=globe', { waitUntil: 'load' });
  await page.waitForSelector('#ssExportBtn');
  await page.waitForFunction(() => / projects have locations/.test((document.getElementById('ssLocCounter') || {}).textContent || ''), null, { timeout: 30000 });   // browse counter = the empty-criteria run landed
  check('globe browse: Export… stays disabled with the "add a filter" tooltip', (await page.getAttribute('#ssExportBtn', 'aria-disabled')) === 'true' && /Add at least one search filter/.test(await page.getAttribute('#ssExportBtn', 'title')));
  const popupsBefore = ctx.pages().length;
  await page.click('#ssExportBtn', { force: true });   // Playwright skips aria-disabled targets without force
  await sleep(500);
  check('globe browse: clicking the disabled Export… opens nothing', ctx.pages().length === popupsBefore);
  await page.goto(BASE + '/strabosearch/', { waitUntil: 'load' });
  await page.waitForSelector('#ssExportBtn');
  check('search page: Export… rendered disabled before any search', (await page.getAttribute('#ssExportBtn', 'aria-disabled')) === 'true');
  await page.selectOption('.ss-crit-select', 'U1');
  await page.fill('.ss-value input[type="text"]', 'Granite');
  await page.click('#ssSearchBtn');
  await page.waitForSelector('.ss-card', { timeout: 15000 });
  check('search page: Export… enabled once results are on screen, ready tooltip restored', (await page.getAttribute('#ssExportBtn', 'aria-disabled')) === 'false' && /Open the Export Builder/.test(await page.getAttribute('#ssExportBtn', 'title')));
  const [door] = await Promise.all([ctx.waitForEvent('page'), page.click('#ssExportBtn')]);
  await door.waitForLoadState('load');
  await door.waitForSelector('.eb-proj');
  check('Export… opens the builder in a new tab with the fixture project ticked', /export_builder/.test(door.url()) && await door.isChecked('#eb-p-' + fx.project + '-' + fx.owner));
  // Search-door mode (Jason 2026-09-02): only matched projects, all ticked; filters as read-only chips.
  check('search-door mode: every listed project is a match, ticked up to the 50 cap (no "all of mine" library)', await door.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('.eb-proj .eb-pcb'));
    const ticked = rows.filter((c) => c.checked).length;
    return rows.length >= 1 && ticked === Math.min(rows.length, 50) && /Projects with spots matching your search/.test(document.querySelector('#eb-selection .eb-sub').textContent);
  }), await door.evaluate(() => document.querySelectorAll('.eb-proj .eb-pcb').length + ' listed'));
  check('search-door mode: filters shown as read-only chips, no criteria builder mounted', await door.evaluate(() => {
    const chips = Array.from(document.querySelectorAll('#eb-criteria-summary .ss-chip')).map((c) => c.textContent);
    return !document.getElementById('criteriaBuilder') && chips.length === 1 && chips[0] === 'Keyword=Granite' && /go back to StraboSearch/.test(document.querySelector('#eb-filters .eb-sub').textContent);
  }), JSON.stringify(await door.evaluate(() => Array.from(document.querySelectorAll('#eb-criteria-summary .ss-chip')).map((c) => c.textContent))));
  check('search-door mode: the recipe carries the search criteria verbatim', await door.evaluate(() => JSON.stringify(window.ExportBuilderPage.recipe().criteria)) === JSON.stringify([{ id: 'U1', value: 'Granite' }]));
  if (SHOTS) await door.screenshot({ path: SHOTS + '/builder_search_door_mode.png', fullPage: true });
  check('builder banner reports the StraboSearch handoff with the fixture project named', /From StraboSearch/.test(await door.locator('#eb-from').textContent()) && /preselected/.test(await door.locator('#eb-from').textContent()) && /Pages Project/.test(await door.locator('#eb-from').textContent()));
  await door.waitForFunction(() => /^[\d,]+ spots? match/.test(document.getElementById('eb-count').textContent.replace(/\s+/g, ' ')), null, { timeout: 8000 });
  check('live count runs on the handed-off builder (fixture spot + any public dev matches)', true, (await door.locator('#eb-count').textContent()).trim());
  await door.close();
  await page.fill('.ss-value input[type="text"]', 'Granite Knob');
  check('editing the criteria disables Export… again (results invalidated)', (await page.getAttribute('#ssExportBtn', 'aria-disabled')) === 'true');
  // Field-less results (Jason 2026-09-02): a shared-URL search restricted to Micro lands with zero
  // StraboField projects, so Export… is HIDDEN (nothing it could export).
  const enc = (o) => Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  await page.goto(BASE + '/strabosearch/?q=' + enc({ dsl: { subsystems: ['micro'], criteria: [{ id: 'U1', value: 'Granite' }] }, tab: 'projects', view: 'list' }), { waitUntil: 'load' });
  await page.waitForSelector('#ssExportBtn', { state: 'attached' });
  let hidden = false;
  try { await page.waitForFunction(() => getComputedStyle(document.getElementById('ssExportBtn')).display === 'none', null, { timeout: 20000 }); hidden = true; } catch (e) { hidden = false; }
  check('Micro-only search (zero StraboField projects): Export… is hidden once results land', hidden);
  await page.goto(BASE + '/strabosearch/?q=' + enc({ dsl: { subsystems: ['field', 'micro', 'exp'], criteria: [{ id: 'U1', value: 'Granite' }] }, tab: 'projects', view: 'list' }), { waitUntil: 'load' });
  await page.waitForSelector('.ss-card', { timeout: 15000 });
  await page.waitForFunction(() => document.getElementById('ssExportBtn').getAttribute('aria-disabled') === 'false', null, { timeout: 15000 });
  check('the same keyword across all subsystems: Export… visible and enabled again', await page.evaluate(() => getComputedStyle(document.getElementById('ssExportBtn')).display !== 'none'));

  // ---- 8. Anonymous Export… (Jason 2026-09-02): visible, sign-in tooltip, click -> login with a return path
  const anon = await browser.newContext({ viewport: { width: 1280, height: 1800 } });
  const apage = await anon.newPage();
  const anonErrors = [];
  apage.on('pageerror', (e) => anonErrors.push(String(e)));
  const anonQ = enc({ dsl: { subsystems: ['field', 'micro', 'exp'], criteria: [{ id: 'U1', value: 'Granite' }] }, tab: 'projects', view: 'list' });
  await apage.goto(BASE + '/strabosearch/?q=' + anonQ, { waitUntil: 'load' });
  await apage.waitForSelector('#ssExportBtn', { state: 'attached' });
  check('anonymous: Export… rendered, no Save current / My searches', await apage.evaluate(() => !!document.getElementById('ssExportBtn') && !document.getElementById('ssSaveBtn') && !document.getElementById('ssMySearchesBtn')));
  await apage.waitForSelector('.ss-card', { timeout: 15000 });
  await apage.waitForFunction(() => document.getElementById('ssExportBtn').getAttribute('aria-disabled') === 'false', null, { timeout: 15000 });
  check('anonymous: Export… enabled once Field results land, tooltip says sign in', /^Sign in to export/.test(await apage.getAttribute('#ssExportBtn', 'title')));
  await Promise.all([apage.waitForURL(/\/login\.php\?uri=/, { timeout: 15000 }), apage.click('#ssExportBtn')]);
  const backTo = decodeURIComponent((apage.url().match(/[?&]uri=([^&]+)/) || [])[1] || '');
  const dec = (s) => { try { const b = s.replace(/-/g, '+').replace(/_/g, '/'); return JSON.parse(Buffer.from(b, 'base64').toString('utf8')); } catch (e) { return null; } };
  const backState = dec((backTo.match(/[?&]q=([^&]+)/) || [])[1] || '');
  check('anonymous: click goes to /login.php?uri=<this search> (same-site path whose ?q= carries the Granite DSL)', backTo.indexOf('/strabosearch/?q=') === 0 && !!backState && backState.dsl && backState.dsl.criteria[0].value === 'Granite', backTo);
  check('anonymous: login page renders the form + sign-up link', await apage.evaluate(() => !!document.querySelector('input[name="submit_login"]') && !!document.querySelector('a[href="/register"]')));
  check('anonymous: no page errors', anonErrors.length === 0, anonErrors.join(' | '));
  await anon.close();
  check('no page errors overall', errors.length === 0, errors.join(' | '));

  await browser.close();
  cleanup();
  console.log(JSON.stringify({ ok: fails === 0, passed: results.length - fails, failed: fails }));
  process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); cleanup(); process.exit(2); });
