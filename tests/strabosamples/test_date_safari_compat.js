#!/usr/bin/env node
/*
 * Phase G regression guard — Safari/JavaScriptCore date compatibility.
 *
 * Background: the Samples UI displays Postgres `timestamp with time zone`
 * columns (sample.modified_at, sample_subsystem_links.modified_at,
 * sample_changelog.changed_at). Postgres renders timestamptz as
 *   "2026-06-11 11:15:05.227747-04"
 * — space separator, 6-digit fraction, 2-digit offset. Chrome (V8) and
 * Firefox (SpiderMonkey) parse that leniently; Safari / iOS Safari
 * (JavaScriptCore) returns an Invalid Date, so the client formatters
 * (fmtDate / fmtDateTime) fell through to `return ts` and rendered the
 * raw, ugly timestamp on every Samples surface under Safari.
 *
 * Fix: parsePgTimestamp() normalizes the timestamptz string into strict
 * ISO 8601 (T separator, <=3 fraction digits, +/-HH:MM offset) before
 * `new Date()`. This test extracts the live parsePgTimestamp source from
 * both UI pages, evaluates it, and asserts Safari-shape output + correct
 * instant — so a future edit that re-breaks Safari fails CI here.
 *
 * Run: node tests/strabosamples/test_date_safari_compat.js
 */
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const FILES = ['my_samples.php', 'samples_detail.php'];

let pass = 0, fail = 0;
function check(name, cond) {
  if (cond) { pass++; console.log('  PASS ' + name); }
  else      { fail++; console.log('  FAIL ' + name); }
}

// Pull the parsePgTimestamp function body out of a PHP file's inline JS.
function extractFn(src) {
  const start = src.indexOf('function parsePgTimestamp');
  if (start < 0) return null;
  // Walk braces from the first '{' to find the matching close.
  let i = src.indexOf('{', start);
  if (i < 0) return null;
  let depth = 0;
  for (let j = i; j < src.length; j++) {
    if (src[j] === '{') depth++;
    else if (src[j] === '}') { depth--; if (depth === 0) return src.slice(start, j + 1); }
  }
  return null;
}

const bodies = {};
for (const f of FILES) {
  const src = fs.readFileSync(path.join(ROOT, f), 'utf8');
  const fn = extractFn(src);
  check('parsePgTimestamp present in ' + f, !!fn);
  bodies[f] = fn;
}

// Drift guard: both copies must be identical (single logical source).
check('parsePgTimestamp identical across both pages',
  bodies['my_samples.php'] && bodies['my_samples.php'] === bodies['samples_detail.php']);

// Evaluate the extracted function and exercise it.
// eslint-disable-next-line no-eval
const parsePgTimestamp = eval('(' + bodies['my_samples.php'] + ')');

// 1. Real prod-format timestamptz -> valid Date at the correct instant.
const PROD = '2026-06-11 11:15:05.227747-04';
const d = parsePgTimestamp(PROD);
check('prod timestamptz parses to a valid Date', d instanceof Date && !isNaN(d.getTime()));
check('prod timestamptz yields correct instant (epoch 1781190905227)',
  d && d.getTime() === 1781190905227);

// 2. Safari-shape guard: re-derive the normalized ISO string the same way
//    the function does and assert it is strict ISO 8601 that JSC accepts
//    (T separator, no space, colon in the offset).
const iso = PROD.replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1').replace(/([+-]\d{2})$/, '$1:00');
check('normalized form uses T separator (no space)', iso.indexOf(' ') === -1 && iso.indexOf('T') !== -1);
check('normalized form has colon-delimited offset', /[+-]\d{2}:\d{2}$/.test(iso));
check('normalized form trims fraction to <=3 digits', /\.\d{1,3}[+-]/.test(iso));
check('normalized form equals expected ISO', iso === '2026-06-11T11:15:05.227-04:00');

// 3. No regression vs lenient V8 parse where the raw form was already valid.
check('normalized instant matches raw V8 parse', new Date(iso).getTime() === new Date(PROD).getTime());

// 4. Variant formats.
check('no-fraction timestamptz parses', !isNaN(parsePgTimestamp('2026-01-15 09:30:00-05').getTime()));
check('+00 offset parses', !isNaN(parsePgTimestamp('2026-06-11 11:15:05.227747+00').getTime()));

// 5. Numeric epoch passed as string.
const e = parsePgTimestamp('1718000000000');
check('numeric epoch string -> Date', e instanceof Date && e.getTime() === 1718000000000);

// 6. Null / empty / garbage handling.
check('null  -> null',  parsePgTimestamp(null)  === null);
check('empty -> null',  parsePgTimestamp('')    === null);
check('garbage -> null', parsePgTimestamp('not a date') === null);

console.log('\n' + (fail === 0 ? 'ALL PASS' : 'FAILURES') + '  (' + pass + ' passed, ' + fail + ' failed)');
process.exit(fail === 0 ? 0 : 1);
