/* My Samples "Type" filter in REAL Firefox: tri-state subsystem chips
 * (neutral -> include -> exclude -> neutral), the any/all Match pill
 * (visible only with 2+ included chips), the plain-English readout, the
 * URL round-trip (type=field,micro,-experimental + match=all) incl. the
 * legacy single-value ?type= alias, and exact result counts against an
 * 8-sample fixture covering every subsystem-membership combination.
 *
 * Fixture + forged session come from
 * smoke_test_my_samples_type_filter.php (setup / teardown modes, run via
 * docker exec). Needs `playwright` resolvable from the CWD (NODE_PATH or
 * a scratch npm install) + `npx playwright install firefox`.
 *
 * Run from the www dir:  node tests/strabosamples/ui_test_my_samples_type_filter_ff.js
 * PASS: exit 0 + final line "ok": true
 */
const { firefox } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = 'http://localhost';
const FIXTURE = '/srv/app/www/tests/strabosamples/smoke_test_my_samples_type_filter.php';
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

(async () => {
  const fx = JSON.parse(php('setup').trim().split('\n').pop());
  sid = fx.sid;

  const browser = await firefox.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addCookies([{ name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' }]);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));

  const go = async (qs) => { await page.goto(BASE + '/my_samples.php' + (qs || ''), { waitUntil: 'load' }); await page.waitForSelector('#ms-cards .ms-card, #ms-cards .ms-empty'); };
  const chip = (k) => page.locator('.ms-tab[data-type="' + k + '"]');
  const stateOf = (k) => chip(k).getAttribute('data-state');
  const allActive = () => page.locator('.ms-tab[data-type="all"]').evaluate((b) => b.classList.contains('active'));
  const pillVisible = () => page.locator('#ms-type-match').evaluate((el) => !el.hidden && getComputedStyle(el).display !== 'none');
  const matchActive = () => page.locator('.ms-type-match-btn.active').getAttribute('data-match');
  const count = () => page.locator('#ms-cards .ms-card').count();
  const readout = async () => { const n = await page.locator('#ms-type-readout').count(); return n ? page.locator('#ms-type-readout').textContent() : ''; };
  const countText = () => page.locator('#ms-result-count').textContent();
  const query = () => page.evaluate(() => window.location.search);
  const cardCodes = () => page.locator('#ms-cards .ms-card').evaluateAll((els) => els.map((e) => (e.textContent.match(/Fixture (\w+)/) || [])[1]).sort());
  const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  // ---- 1. Default state --------------------------------------------------
  await go('');
  check('default: 8 fixture samples listed', (await count()) === 8, await count());
  check('default: All chip active', await allActive());
  check('default: every chip neutral', (await stateOf('field')) === 'neutral' && (await stateOf('micro')) === 'neutral' && (await stateOf('experimental')) === 'neutral');
  check('default: Match pill hidden', !(await pillVisible()));
  check('default: no readout', (await readout()) === '');
  check('default: URL carries no type param', !(await query()).includes('type='));

  // ---- 2. Single include -------------------------------------------------
  await chip('field').click();
  check('Field x1 -> include state', (await stateOf('field')) === 'include');
  check('Field include: All chip no longer active', !(await allActive()));
  check('Field include: 4 samples (f, fm, fe, fme)', same(await cardCodes(), ['f', 'fe', 'fm', 'fme']), JSON.stringify(await cardCodes()));
  check('Field include: readout "in StraboField"', (await readout()) === 'in StraboField', await readout());
  check('Field include: pill still hidden (one chip)', !(await pillVisible()));
  check('Field include: URL type=field', (await query()) === '?type=field', await query());

  // ---- 3. Two includes: OR by default, AND via pill ----------------------
  await chip('micro').click();
  check('Micro x1 -> include state', (await stateOf('micro')) === 'include');
  check('Field+Micro: pill visible', await pillVisible());
  check('Field+Micro: pill defaults to any', (await matchActive()) === 'any');
  check('Field+Micro any: 6 samples (everything but none, e)', same(await cardCodes(), ['f', 'fe', 'fm', 'fme', 'm', 'me']), JSON.stringify(await cardCodes()));
  check('Field+Micro any: readout uses "or"', (await readout()) === 'in StraboField or StraboMicro', await readout());
  check('Field+Micro any: URL type=field,micro without match', (await query()) === '?type=field%2Cmicro', await query());

  await page.locator('.ms-type-match-btn[data-match="all"]').click();
  check('Match all: pill shows all', (await matchActive()) === 'all');
  check('Field+Micro all: 2 samples (fm, fme)  [colleague 2: "both"]', same(await cardCodes(), ['fm', 'fme']), JSON.stringify(await cardCodes()));
  check('Field+Micro all: readout uses "and"', (await readout()) === 'in StraboField and StraboMicro', await readout());
  check('Field+Micro all: URL carries match=all', (await query()) === '?type=field%2Cmicro&match=all', await query());
  check('count line reads "2 samples · in StraboField and StraboMicro"', /^2 samples\s*·\s*in StraboField and StraboMicro$/.test((await countText()).trim()), (await countText()).trim());

  // ---- 4. Include -> exclude ---------------------------------------------
  await chip('field').click();
  check('Field x2 -> exclude state', (await stateOf('field')) === 'exclude');
  check('Micro include + Field exclude: pill hidden again (one include)', !(await pillVisible()));
  check('Micro, not Field: 2 samples (m, me)', same(await cardCodes(), ['m', 'me']), JSON.stringify(await cardCodes()));
  check('Micro, not Field: readout', (await readout()) === 'in StraboMicro, not in StraboField', await readout());
  check('Micro, not Field: URL type=micro,-field', (await query()) === '?type=micro%2C-field', await query());

  // ---- 5. Colleague 1 verbatim: Micro or Exp, not Field ------------------
  await chip('experimental').click();
  check('Exp x1 -> include; pill back (two includes)', (await stateOf('experimental')) === 'include' && (await pillVisible()));
  check('pill remembers "all" from step 3', (await matchActive()) === 'all');
  check('Micro AND Exp, not Field: 1 sample (me)', same(await cardCodes(), ['me']), JSON.stringify(await cardCodes()));
  await page.locator('.ms-type-match-btn[data-match="any"]').click();
  check('Micro OR Exp, not Field: 3 samples (m, e, me)', same(await cardCodes(), ['e', 'm', 'me']), JSON.stringify(await cardCodes()));
  check('Micro OR Exp, not Field: readout', (await readout()) === 'in StraboMicro or StraboExperimental, not in StraboField', await readout());
  const q5 = await query();
  check('Micro OR Exp, not Field: URL type=micro,experimental,-field (no match)', q5 === '?type=micro%2Cexperimental%2C-field', q5);

  // ---- 6. Round-trip: reload the URL restores every control --------------
  await go(q5);
  check('reload: chip states restored', (await stateOf('micro')) === 'include' && (await stateOf('experimental')) === 'include' && (await stateOf('field')) === 'exclude');
  check('reload: 3 samples again', same(await cardCodes(), ['e', 'm', 'me']), JSON.stringify(await cardCodes()));
  check('reload: pill visible + any', (await pillVisible()) && (await matchActive()) === 'any');
  await go('?type=field%2Cexperimental&match=all');
  check('reload match=all: pill shows all + 2 samples (fe, fme)', (await matchActive()) === 'all' && same(await cardCodes(), ['fe', 'fme']), JSON.stringify(await cardCodes()));

  // ---- 7. Legacy + odd URL forms -----------------------------------------
  await go('?type=micro');
  check('legacy ?type=micro -> Micro include, 4 samples', (await stateOf('micro')) === 'include' && same(await cardCodes(), ['fm', 'fme', 'm', 'me']), JSON.stringify(await cardCodes()));
  await go('?type=%2Bfield%2C-micro');
  check('"+field,-micro" (plus tolerated) -> f, fe', (await stateOf('field')) === 'include' && (await stateOf('micro')) === 'exclude' && same(await cardCodes(), ['f', 'fe']), JSON.stringify(await cardCodes()));
  await go('?type=bogus%2Call%2Cfield');
  check('unknown tokens + "all" ignored -> Field include only', (await stateOf('field')) === 'include' && (await cardCodes()).length === 4 && !(await allActive()));
  await go('?type=field%2C-field');
  check('contradictory field,-field -> exclude wins (last write)', (await stateOf('field')) === 'exclude' && same(await cardCodes(), ['e', 'm', 'me', 'none']), JSON.stringify(await cardCodes()));

  // ---- 8. Exclude -> neutral, All reset, unlinked ------------------------
  await go('');
  await chip('field').click(); await chip('field').click(); await chip('field').click();
  check('Field x3 -> back to neutral; All active; 8 samples', (await stateOf('field')) === 'neutral' && (await allActive()) && (await count()) === 8);
  await chip('field').click(); await chip('micro').click();
  await page.locator('.ms-tab[data-type="all"]').click();
  check('All click resets both chips + URL', (await stateOf('field')) === 'neutral' && (await stateOf('micro')) === 'neutral' && (await allActive()) && !(await query()).includes('type='), await query());
  for (const k of ['field', 'micro', 'experimental']) { await chip(k).click(); await chip(k).click(); }
  check('exclude all three -> the one unlinked sample', same(await cardCodes(), ['none']), JSON.stringify(await cardCodes()));
  check('exclude all three: readout "with no subsystem links"', (await readout()) === 'with no subsystem links', await readout());
  check('exclude all three: URL type=-field,-micro,-experimental', (await query()) === '?type=-field%2C-micro%2C-experimental', await query());

  // ---- 9. Composes with the search box -----------------------------------
  await go('?type=field');
  // "Fixture fe" is not a substring of any other fixture name (unlike "fm" in "fme").
  await page.fill('#ms-search', 'Fixture fe');
  await page.waitForTimeout(150);
  check('type + search compose: only fe', same(await cardCodes(), ['fe']), JSON.stringify(await cardCodes()));
  check('count line keeps both the search phrase and the readout', /matching "Fixture fe"\s*·\s*in StraboField/.test(await countText()), (await countText()).trim());

  // ---- 10. Visual affordances --------------------------------------------
  await go('?type=field%2C-micro');
  const glyphs = await page.evaluate(() => ['field', 'micro'].map((k) => getComputedStyle(document.querySelector('.ms-tab[data-type="' + k + '"]'), '::before').content));
  check('include chip renders a check glyph, exclude chip an x glyph', /2713|✓/.test(glyphs[0]) && /2715|✕/.test(glyphs[1]), JSON.stringify(glyphs));
  const aria = await page.evaluate(() => ['field', 'micro', 'experimental'].map((k) => document.querySelector('.ms-tab[data-type="' + k + '"]').getAttribute('aria-pressed')));
  check('aria-pressed true / mixed / false for include / exclude / neutral', same(aria, ['true', 'mixed', 'false']), JSON.stringify(aria));
  const shot = require('path').join(process.env.SHOT_DIR || require('os').tmpdir(), 'my_samples_type_filter_ff.png');
  await page.screenshot({ path: shot }).catch(() => {});
  console.log('screenshot: ' + shot);

  check('no page errors in Firefox', errors.length === 0, errors.join(' | '));

  await browser.close();
  cleanup();
  console.log(JSON.stringify({ ok: fails === 0, checks: results.length, fails }));
  process.exit(fails ? 1 : 0);
})();
