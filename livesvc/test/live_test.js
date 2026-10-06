/**
 * File: livesvc/test/live_test.js
 * Description: Tests of the live service (spec v3 17ar-17ax, 17au layer 1)
 *              against the dev stack. Runs on the Mac (needs node and the
 *              dev Docker containers):
 *
 *                cd www/livesvc && npm install && npm test
 *
 *              It starts its own copy of server.js on port 3901 with small
 *              limits (caps, ping, timeouts) so they can be hit quickly,
 *              using the dev database on localhost:5436 and the dev
 *              includes/config.inc.php. Projects are made through the real
 *              API (http://localhost/microsync/v1) with the @test.strabospot.org
 *              fixture users, straboId prefix mscli- (client_fixture.php
 *              cleanup removes them). With LIVE_APACHE=1 it also checks the
 *              strabo-live container through the dev Apache
 *              (ws://localhost/microsync/live). The last section checks who
 *              may sync (MICROSYNC_ENABLED, MICROSYNC_ALLOW): sample configs
 *              read by config.js and by PHP must agree, and a second copy of
 *              the service on port 3902 runs on a temporary copy of the
 *              config whose list the test edits while it runs.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawn, execFileSync } = require('child_process');
const WebSocket = require('ws');
const pg = require('pg');
const { load, syncAccess } = require('../config');

const ROOT = path.resolve(__dirname, '..');
const CONFIG = path.resolve(ROOT, '../includes/config.inc.php');
const API = 'http://localhost/microsync/v1';
const PORT = 3901;
const URL = `ws://127.0.0.1:${PORT}/`;
const LIMITS = {
  LIVE_MAX_CONNECTIONS: '12', LIVE_MAX_PER_ACCOUNT: '3', LIVE_PING_MS: '700',
  LIVE_AUTH_TIMEOUT_MS: '800', LIVE_RECHECK_MS: '600000', LIVE_PRESENCE_MS: '1000',
};

process.env.LIVE_CONFIG = CONFIG;
process.env.LIVE_DB_HOST = 'localhost';
process.env.LIVE_DB_PORT = '5436';
const CFG = load();

let passed = 0;
let failed = 0;
function check(name, ok, detail) {
  if (ok) {
    passed++;
    console.log(`  ok   ${name}`);
  } else {
    failed++;
    console.log(`  FAIL ${name}${detail !== undefined ? ` :: ${typeof detail === 'string' ? detail : JSON.stringify(detail)}` : ''}`);
  }
}
function section(s) {
  console.log(`\n== ${s}`);
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------------------
// Fixture users, tokens, API
// ---------------------------------------------------------------------------

function fixture(...args) {
  const out = execFileSync('docker', ['exec', 'strabo-php', 'php', '/srv/app/www/tests/microsync/client_fixture.php', ...args],
    { encoding: 'utf8' });
  return JSON.parse(out);
}

function b64url(obj) {
  return Buffer.from(typeof obj === 'string' ? obj : JSON.stringify(obj)).toString('base64')
    .replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
}
function sign(payload, secret = CFG.jwtSecret) {
  const h = b64url({ alg: 'HS256', typ: 'JWT' });
  const p = b64url(payload);
  const s = crypto.createHmac('sha256', secret).update(`${h}.${p}`).digest('base64')
    .replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
  return `${h}.${p}.${s}`;
}
function tokenFor(pkey, { exp = 3600, aud = CFG.jwtAudience, secret } = {}) {
  const now = Math.floor(Date.now() / 1000);
  return sign({ iss: 'strabospot.org', aud, iat: now, exp: now + exp, sub: String(pkey) }, secret);
}

async function req(method, p, tok, body) {
  const r = await fetch(API + p, {
    method,
    headers: { Authorization: `Bearer ${tok}`, ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const raw = await r.text();
  let json = null;
  try {
    json = JSON.parse(raw);
  } catch {
    // not JSON
  }
  return { code: r.status, body: json, raw };
}

async function push(pid, tok, changes, clientId = 'live-test') {
  return req('POST', `/projects/${pid}/push`, tok, { pushId: crypto.randomUUID(), clientId, changes });
}
const dataset = (sid, id) => ({ op: 'create', type: 'dataset', id, parentType: 'project', parentId: sid, body: { name: id } });

// ---------------------------------------------------------------------------
// WebSocket client that records what it gets
// ---------------------------------------------------------------------------

class Client {
  constructor(url = URL, opts = {}) {
    this.msgs = [];
    this.closed = null;
    this.ws = new WebSocket(url, opts);
    this.opened = new Promise((resolve, reject) => {
      this.ws.once('open', resolve);
      this.ws.once('error', reject);
    });
    this.ws.on('message', (d) => this.msgs.push(JSON.parse(d.toString())));
    this.ws.on('close', (code, reason) => {
      this.closed = { code, reason: reason.toString() };
    });
    this.ws.on('error', () => {});
  }

  send(obj) {
    this.ws.send(typeof obj === 'string' ? obj : JSON.stringify(obj));
  }

  /** Wait for a message matching pred (searching from index `from`) */
  async wait(pred, ms = 3000, from = 0) {
    const end = Date.now() + ms;
    while (Date.now() < end) {
      const m = this.msgs.slice(from).find(pred);
      if (m) return m;
      if (this.closed) return null;
      await sleep(20);
    }
    return null;
  }

  async waitClose(ms = 3000) {
    const end = Date.now() + ms;
    while (Date.now() < end && !this.closed) await sleep(20);
    return this.closed;
  }

  of(t) {
    return this.msgs.filter((m) => m.t === t);
  }

  close() {
    try {
      this.ws.close();
    } catch {
      // already closed
    }
  }
}

/** Connected + authenticated client */
async function authed(tok, url, opts) {
  const c = new Client(url, opts);
  await c.opened;
  c.send({ t: 'auth', token: tok });
  c.ready = await c.wait((m) => m.t === 'ready');
  return c;
}

async function subscribed(tok, pid) {
  const c = await authed(tok);
  c.send({ t: 'sub', pid });
  c.subbed = await c.wait((m) => (m.t === 'subbed' || m.t === 'nosub') && m.pid === pid);
  return c;
}

// ---------------------------------------------------------------------------
// The service under test
// ---------------------------------------------------------------------------

let svc = null;
let svcLog = '';
async function startService() {
  svc = spawn(process.execPath, [path.join(ROOT, 'server.js')], {
    env: { ...process.env, LIVE_PORT: String(PORT), ...LIMITS },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  svc.stdout.on('data', (d) => { svcLog += d; });
  svc.stderr.on('data', (d) => { svcLog += d; });
  for (let i = 0; i < 100; i++) {
    try {
      const h = await (await fetch(`http://127.0.0.1:${PORT}/health`)).json();
      if (h.listening) return h;
    } catch {
      // not up yet
    }
    await sleep(100);
  }
  throw new Error(`service did not start:\n${svcLog}`);
}
async function health() {
  return (await fetch(`http://127.0.0.1:${PORT}/health`)).json();
}

// ---------------------------------------------------------------------------
// Who may sync (MICROSYNC_ENABLED + MICROSYNC_ALLOW, pre-release gap 5)
// ---------------------------------------------------------------------------

const ACCESS_PORT = 3902;
const ACCESS_URL = `ws://127.0.0.1:${ACCESS_PORT}/`;
const ON = "define('MICROSYNC_ENABLED', true);";

/** Config texts that config.js must read exactly as PHP does */
const ACCESS_SAMPLES = [
  ['switch off', "define('MICROSYNC_ENABLED', false);"],
  ['no switch', '$x = 1;'],
  ['on, no list', ON],
  ['on, list', `${ON}\ndefine('MICROSYNC_ALLOW', array('A@x.org', " b@y.org "));`],
  ['short array, several lines', `${ON}\ndefine("MICROSYNC_ALLOW", [\n  'a@x.org',\n  'c@z.org',\n]);`],
  ['true in capitals', "define('MICROSYNC_ENABLED', TRUE);\ndefine('MICROSYNC_ALLOW', array('a@x.org'));"],
  ['list commented out with //', `${ON}\n// define('MICROSYNC_ALLOW', array('a@x.org'));`],
  ['list commented out with #', `${ON}\n# define('MICROSYNC_ALLOW', array('a@x.org'));`],
  ['list in a block comment', `${ON}\n/* define('MICROSYNC_ALLOW', array('a@x.org')); */`],
  ['one entry commented at a line end', `${ON}\ndefine('MICROSYNC_ALLOW', array(\n  'a@x.org', // 'old@x.org'\n  'c@z.org'\n));`],
  ['empty list', `${ON}\ndefine('MICROSYNC_ALLOW', array());`],
  ['a URL elsewhere in the file', `$u = 'https://strabospot.org';\n${ON}\ndefine('MICROSYNC_ALLOW', array('a@x.org'));`],
];

/** The same sample read by PHP in strabo-php: {enabled, allow: null | sorted lowercase emails} */
function phpAccess(text) {
  const file = path.join(__dirname, '.access_sample.php');
  fs.writeFileSync(file, `<?php\n${text}\n`);
  try {
    const code = 'include "/srv/app/www/livesvc/test/.access_sample.php";'
      + ' $a = defined("MICROSYNC_ALLOW") ? MICROSYNC_ALLOW : null; $l = null;'
      + ' if ($a !== null) { $l = array(); if (is_array($a)) { foreach ($a as $e) { if (is_string($e)) { $l[] = strtolower(trim($e)); } } } }'
      + ' echo json_encode(array("enabled" => defined("MICROSYNC_ENABLED") && MICROSYNC_ENABLED === true, "allow" => $l));';
    const out = JSON.parse(execFileSync('docker', ['exec', 'strabo-php', 'php', '-r', code], { encoding: 'utf8' }));
    return { enabled: out.enabled, allow: out.allow === null ? null : [...new Set(out.allow)].sort() };
  } finally {
    fs.rmSync(file, { force: true });
  }
}

async function accessTests(U) {
  for (const [name, text] of ACCESS_SAMPLES) {
    const js = syncAccess(text);
    const mine = { enabled: js.enabled, allow: js.allow === null ? null : [...js.allow].sort() };
    const php = phpAccess(text);
    check(`config.js reads it like PHP: ${name}`, JSON.stringify(mine) === JSON.stringify(php), { js: mine, php });
  }

  // A second service on a copy of the dev config that the test edits
  const file = path.join(os.tmpdir(), `live-access-${process.pid}.php`);
  const base = fs.readFileSync(CONFIG, 'utf8');
  if (!base.includes(ON)) {
    check('dev config has the MICROSYNC_ENABLED line', false);
    return;
  }
  let stamp = Math.floor(Date.now() / 1000);
  const write = (allow, enabled = true) => {
    const line = allow === null ? '' : `\ndefine('MICROSYNC_ALLOW', array(${allow.map((e) => `'${e}'`).join(', ')}));`;
    fs.writeFileSync(file, base.replace(ON, (enabled ? ON : "define('MICROSYNC_ENABLED', false);") + line));
    stamp += 10; // a new mtime for every edit, whatever the file system's resolution
    fs.utimesSync(file, stamp, stamp);
  };
  const refused = async (tok) => {
    const c = new Client(ACCESS_URL);
    await c.opened;
    c.send({ t: 'auth', token: tok });
    const closed = await c.waitClose();
    // At login, not by the next periodic check: never 'ready'
    return closed?.code === 4503 && closed.reason === 'sync_disabled' && c.of('ready').length === 0
      && c.of('error').some((m) => m.error === 'sync_disabled');
  };
  const h2 = async () => (await fetch(`http://127.0.0.1:${ACCESS_PORT}/health`)).json();

  write([U.owner.email]);
  const svc2 = spawn(process.execPath, [path.join(ROOT, 'server.js')], {
    env: { ...process.env, ...LIMITS, LIVE_PORT: String(ACCESS_PORT), LIVE_CONFIG: file, LIVE_RECHECK_MS: '400' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  svc2.stdout.on('data', (d) => { svcLog += d; });
  svc2.stderr.on('data', (d) => { svcLog += d; });
  const mine = [];
  try {
    let h = null;
    for (let i = 0; i < 100 && !h?.listening; i++) {
      await sleep(100);
      h = await h2().catch(() => null);
    }
    check('second service up; health: sync on, list of 1', h?.syncEnabled === true && h.allowList === 1, h);

    const own = await authed(U.owner.tok, ACCESS_URL);
    mine.push(own);
    check('listed account connects', own.ready?.t === 'ready');
    check('unlisted account is refused (4503 sync_disabled)', await refused(U.editor.tok));

    write([U.owner.email, U.editor.email.toUpperCase()]);
    const edt = await authed(U.editor.tok, ACCESS_URL);
    mine.push(edt);
    check('added to the list (other case): connects, no restart', edt.ready?.t === 'ready');

    write([U.editor.email]);
    const ownClosed = await own.waitClose(3000);
    check('taken off the list: open connection closed 4503 at the next check', ownClosed?.code === 4503, ownClosed);
    await sleep(900);
    check('still listed: connection stays open', edt.closed === null, edt.closed);
    check('taken off the list: a new connection is refused', await refused(U.owner.tok));

    write(null);
    h = await h2();
    check('list removed: health says every account', h.syncEnabled === true && h.allowList === null, h);
    const out = await authed(U.outsider.tok, ACCESS_URL);
    mine.push(out);
    check('list removed: any account connects', out.ready?.t === 'ready');

    write(null, false);
    check('sync switched off: open connections closed 4503', (await out.waitClose(3000))?.code === 4503 && (await edt.waitClose(3000))?.code === 4503);
    check('sync switched off: a new connection is refused', await refused(U.owner.tok));
    check('sync switched off: health says so', (await h2()).syncEnabled === false);
  } finally {
    for (const c of mine) c.close();
    svc2.kill('SIGTERM');
    fs.rmSync(file, { force: true });
  }
}

// ---------------------------------------------------------------------------

async function main() {
  const U = {};
  for (const [k, email] of Object.entries({
    owner: 'owner@test.strabospot.org', editor: 'editor@test.strabospot.org',
    viewer: 'readonly@test.strabospot.org', outsider: 'outsider@test.strabospot.org',
    maya: 'maya.chen@test.strabospot.org',
  })) {
    const f = fixture('token', email);
    U[k] = { pkey: f.pkey, tok: f.token, email };
  }
  const db = new pg.Client(CFG.db);
  await db.connect();

  await startService();
  const SID = `mscli-live-${crypto.randomBytes(4).toString('hex')}`;
  let PID = 0;
  const open = [];
  const track = (c) => {
    open.push(c);
    return c;
  };

  try {
    section('Setup (real API)');
    let r = await req('POST', '/projects', U.owner.tok, { straboId: SID, name: 'Live test' });
    check('create project', r.code === 201, r.raw);
    PID = r.body.pid;
    r = await push(PID, U.owner.tok, [{ op: 'create', type: 'project', id: SID, body: { name: 'Live test' } }]);
    check('push project entity', r.code === 200 && r.body.results[0].status === 'accepted', r.raw);
    check('ready', (await req('POST', `/projects/${PID}/ready`, U.owner.tok)).code === 200);
    for (const [who, role] of [['editor', 'editor'], ['viewer', 'viewer'], ['maya', 'contributor']]) {
      r = await req('POST', `/projects/${PID}/members`, U.owner.tok, { email: U[who].email, role });
      check(`invite ${who}`, r.code === 201 || r.code === 200, r.raw);
      check(`${who} accepts`, (await req('POST', `/invites/${PID}/accept`, U[who].tok)).code === 200);
    }

    section('Authentication');
    let c = track(new Client());
    await c.opened;
    c.send({ t: 'auth', token: tokenFor(U.owner.pkey, { secret: 'not-the-secret' }) });
    check('bad signature -> error + close 4401', (await c.waitClose())?.code === 4401
      && c.of('error')[0]?.error === 'invalid_token_signature', c.msgs);
    c = track(new Client());
    await c.opened;
    c.send({ t: 'auth', token: tokenFor(U.owner.pkey, { exp: -10 }) });
    check('expired token -> close 4401 token_expired', (await c.waitClose())?.code === 4401 && c.of('error')[0]?.error === 'token_expired');
    c = track(new Client());
    await c.opened;
    c.send({ t: 'auth', token: tokenFor(U.owner.pkey, { aud: 'someone-else' }) });
    check('wrong audience -> close 4401', (await c.waitClose())?.code === 4401 && c.of('error')[0]?.error === 'incorrect_audience');
    c = track(new Client());
    await c.opened;
    c.send({ t: 'auth', token: tokenFor(999999999) });
    check('unknown user -> close 4401', (await c.waitClose())?.code === 4401);
    c = track(new Client());
    await c.opened;
    c.send({ t: 'sub', pid: PID });
    check('anything before auth -> close 4401', (await c.waitClose())?.code === 4401);
    c = track(new Client());
    await c.opened;
    check('no auth in time -> close 4408', (await c.waitClose(2000))?.code === 4408);
    c = track(await authed(U.owner.tok));
    check('PHP-signed fixture token -> ready with user and ping', c.ready?.user === U.owner.pkey && c.ready?.pingMs === 700, c.msgs);
    c.close();

    section('Subscribing');
    const own = track(await subscribed(U.owner.tok, PID));
    const edt = track(await subscribed(U.editor.tok, PID));
    const vie = track(await subscribed(U.viewer.tok, PID));
    const out = track(await subscribed(U.outsider.tok, PID));
    check('owner follows, gets seq + role', own.subbed?.t === 'subbed' && own.subbed.role === 'owner' && own.subbed.seq > 0, own.subbed);
    check('editor and viewer follow with their roles', edt.subbed?.role === 'editor' && vie.subbed?.role === 'viewer');
    check('outsider -> nosub not_found', out.subbed?.t === 'nosub' && out.subbed.error === 'not_found', out.subbed);
    const ghost = track(await subscribed(U.owner.tok, 2147480000));
    check('missing project -> nosub not_found', ghost.subbed?.t === 'nosub');
    ghost.close();

    section('Changes reach followers (NOTIFY on commit)');
    let mark = { o: own.msgs.length, e: edt.msgs.length, v: vie.msgs.length, x: out.msgs.length };
    r = await push(PID, U.owner.tok, [dataset(SID, 'D1')], 'copy-A');
    const head1 = r.body.headSeq;
    let ch = await edt.wait((m) => m.t === 'changed', 2000, mark.e);
    check('editor gets changed with the push headSeq and the pushing clientId', ch?.pid === PID && ch.seq === head1 && ch.by === 'copy-A', ch);
    check('viewer gets it', (await vie.wait((m) => m.t === 'changed' && m.seq === head1, 2000, mark.v)) !== null);
    check('the pushing account gets it too (the app skips its own clientId)', (await own.wait((m) => m.t === 'changed' && m.seq === head1, 2000, mark.o)) !== null);
    await sleep(300);
    check('a non-follower gets nothing', out.msgs.slice(mark.x).every((m) => m.t !== 'changed'));
    mark = { e: edt.msgs.length };
    r = await push(PID, U.viewer.tok, [dataset(SID, 'D-viewer')], 'copy-V');
    check('viewer push is turned down (no head move)', r.code === 200 && r.body.results[0].status !== 'accepted', r.raw);
    await sleep(600);
    check('a push that changes nothing sends no notice', edt.msgs.slice(mark.e).every((m) => m.t !== 'changed'), edt.msgs.slice(mark.e));
    const t0 = Date.now();
    mark = { e: edt.msgs.length };
    await push(PID, U.editor.tok, [dataset(SID, 'D2')], 'copy-E');
    const got = await vie.wait((m) => m.t === 'changed' && m.by === 'copy-E', 2000);
    check(`notice arrives quickly after the push answers (${Date.now() - t0} ms incl. the push)`, got !== null && Date.now() - t0 < 1500);

    section('Chat (MsLive chat and chatread notices)');
    const edt2 = track(await subscribed(U.editor.tok, PID));
    check('editor\'s second computer follows too', edt2.subbed?.role === 'editor');
    mark = { o: own.msgs.length, e: edt.msgs.length, e2: edt2.msgs.length, v: vie.msgs.length, x: out.msgs.length };
    r = await req('POST', `/projects/${PID}/chat`, U.editor.tok, { clientMsgId: crypto.randomUUID(), text: 'secret words about D1' });
    check('editor sends a chat message', r.code === 200, r.raw);
    const sent = r.body?.message;
    let cn = await own.wait((m) => m.t === 'chat', 2000, mark.o);
    check('owner gets chat {pid, rev} = the message rev', cn?.pid === PID && cn.rev === sent?.rev, own.msgs.slice(mark.o));
    check('the notice has ids only, no text', cn !== null && JSON.stringify(Object.keys(cn).sort()) === JSON.stringify(['pid', 'rev', 't'])
      && !JSON.stringify(cn).includes('secret'), cn);
    check('viewer gets it', (await vie.wait((m) => m.t === 'chat' && m.rev === sent?.rev, 2000, mark.v)) !== null);
    check('the sender\'s own connections get it too', (await edt.wait((m) => m.t === 'chat', 2000, mark.e)) !== null);
    await sleep(300);
    check('a non-follower gets no chat notice', out.msgs.slice(mark.x).every((m) => m.t !== 'chat'));
    mark = { v: vie.msgs.length };
    r = await req('DELETE', `/projects/${PID}/chat/${sent?.id}`, U.editor.tok);
    check('editor deletes it', r.code === 200, r.raw);
    check('the deletion is announced with the new rev', (await vie.wait((m) => m.t === 'chat' && m.rev === r.body?.rev, 2000, mark.v)) !== null);
    r = await req('POST', `/projects/${PID}/chat`, U.owner.tok, { clientMsgId: crypto.randomUUID(), text: 'owner message' });
    const lastMsg = r.body?.message?.id;
    await sleep(300);
    mark = { o: own.msgs.length, e: edt.msgs.length, e2: edt2.msgs.length, v: vie.msgs.length };
    r = await req('POST', `/projects/${PID}/chat/read`, U.editor.tok, { id: lastMsg });
    check('editor reads up to the owner\'s message', r.code === 200 && r.body.lastRead === lastMsg, r.raw);
    const rd = await edt2.wait((m) => m.t === 'chatread', 2000, mark.e2);
    check('the editor\'s other computer gets chatread {pid, id}', rd?.pid === PID && rd.id === lastMsg, edt2.msgs.slice(mark.e2));
    check('and the reading connection too', (await edt.wait((m) => m.t === 'chatread', 2000, mark.e)) !== null);
    await sleep(300);
    check('other accounts get no chatread', own.msgs.slice(mark.o).every((m) => m.t !== 'chatread')
      && vie.msgs.slice(mark.v).every((m) => m.t !== 'chatread'));
    edt2.close();

    if (process.env.LIVE_APACHE === '1') {
      section('Through the dev Apache to the strabo-live container');
      c = track(await authed(U.editor.tok, 'ws://localhost/microsync/live'));
      check('ws://localhost/microsync/live -> ready', c.ready?.user === U.editor.pkey, c.msgs);
      c.send({ t: 'sub', pid: PID });
      check('follows through Apache', (await c.wait((m) => m.t === 'subbed'))?.role === 'editor');
      r = await push(PID, U.owner.tok, [dataset(SID, 'D-apache')], 'copy-A');
      ch = await c.wait((m) => m.t === 'changed' && m.seq === r.body.headSeq, 2000);
      check('a push reaches the container and comes back through Apache', ch?.by === 'copy-A', c.msgs);
      c.close();
    }

    section('Presence');
    mark = { o: own.msgs.length };
    edt.send({ t: 'presence', pid: PID, state: 'here', viewing: { type: 'micrograph', id: 'M-1' }, editing: null });
    let pr = await own.wait((m) => m.t === 'presence' && m.people.some((p) => p.user === U.editor.pkey), 2000, mark.o);
    const ep = pr?.people.find((p) => p.user === U.editor.pkey);
    check('owner sees the editor viewing M-1 (ids only)', ep?.state === 'here' && ep.viewing?.id === 'M-1' && ep.viewing.type === 'micrograph'
      && ep.conn === edt.ready.conn && typeof ep.since === 'string', pr);
    mark = { o: own.msgs.length };
    // One every 250 ms for a second: without the 1/s limit that is 5 broadcasts
    for (let i = 2; i <= 6; i++) {
      edt.send({ t: 'presence', pid: PID, state: 'here', viewing: { type: 'micrograph', id: `M-${i}` }, editing: { type: 'spot', id: `S-${i}` } });
      await sleep(250);
    }
    await sleep(1300);
    const burst = own.msgs.slice(mark.o).filter((m) => m.t === 'presence');
    const last = burst[burst.length - 1]?.people.find((p) => p.user === U.editor.pkey);
    check(`five updates in a second -> at most 3 broadcasts (got ${burst.length}), the last one wins`, burst.length <= 3 && last?.viewing.id === 'M-6'
      && last.editing.id === 'S-6', burst.map((b) => b.people.find((p) => p.user === U.editor.pkey)?.viewing?.id));
    const late = track(await subscribed(U.maya.tok, PID));
    const first = late.msgs.find((m) => m.t === 'presence');
    check('a newcomer gets the current presence at once', first?.people.some((p) => p.user === U.editor.pkey && p.viewing.id === 'M-6'), first);
    mark = { o: own.msgs.length };
    late.send({ t: 'presence', pid: PID, state: 'away', viewing: null, editing: null });
    pr = await own.wait((m) => m.t === 'presence' && m.people.some((p) => p.user === U.maya.pkey && p.state === 'away'), 2000, mark.o);
    check('away is passed on', pr !== null);
    mark = { o: own.msgs.length };
    late.close();
    pr = await own.wait((m) => m.t === 'presence' && !m.people.some((p) => p.user === U.maya.pkey), 2000, mark.o);
    check('closing the app removes them from presence', pr !== null);
    c = track(await subscribed(U.editor.tok, PID));
    c.send({ t: 'presence', pid: PID, state: 'here', viewing: { type: 'micrograph', id: 'bad id with spaces' } });
    check('malformed presence -> close 4400', (await c.waitClose())?.code === 4400);
    c = track(await authed(U.editor.tok));
    c.send({ t: 'presence', pid: PID, state: 'here', viewing: null, editing: null });
    check('presence without following -> error not_subscribed', (await c.wait((m) => m.t === 'error'))?.error === 'not_subscribed');
    c.close();

    section('Token renewal and expiry');
    const shortTok = tokenFor(U.editor.pkey, { exp: 2 });
    const renew = track(await authed(shortTok));
    await sleep(800);
    renew.send({ t: 'auth', token: tokenFor(U.editor.pkey, { exp: 3600 }) });
    check('renewal -> renewed with the new exp', (await renew.wait((m) => m.t === 'renewed'))?.exp > Date.now() / 1000 + 3000);
    const lapse = track(await authed(tokenFor(U.editor.pkey, { exp: 2 })));
    check('a token that runs out with no renewal -> auth_expired + close 4401', (await lapse.waitClose(4500))?.code === 4401
      && lapse.of('error').some((m) => m.error === 'auth_expired'), lapse.msgs);
    check('the renewed connection is still open', renew.closed === null);
    renew.send({ t: 'auth', token: U.viewer.tok });
    check('renewal with another account\'s token -> close 4401', (await renew.waitClose())?.code === 4401);

    section('Access changes (MsLive members notices)');
    mark = { v: vie.msgs.length };
    r = await req('PATCH', `/projects/${PID}/members/${U.viewer.pkey}`, U.owner.tok, { role: 'editor' });
    check('owner makes the viewer an editor', r.code === 200, r.raw);
    let ac = await vie.wait((m) => m.t === 'access', 2000, mark.v);
    check('viewer gets access with the new role', ac?.pid === PID && ac.role === 'editor' && !ac.removed, ac);
    mark = { e: edt.msgs.length, o: own.msgs.length };
    edt.send({ t: 'presence', pid: PID, state: 'here', viewing: { type: 'micrograph', id: 'M-9' }, editing: null });
    await own.wait((m) => m.t === 'presence' && m.people.some((p) => p.viewing?.id === 'M-9'), 2000, mark.o);
    mark = { e: edt.msgs.length, o: own.msgs.length };
    r = await req('DELETE', `/projects/${PID}/members/${U.editor.pkey}`, U.owner.tok);
    check('owner removes the editor', r.code === 200, r.raw);
    ac = await edt.wait((m) => m.t === 'access', 2000, mark.e);
    check('editor gets access removed', ac?.removed === true, ac);
    pr = await own.wait((m) => m.t === 'presence' && !m.people.some((p) => p.user === U.editor.pkey), 2000, mark.o);
    check('the removed editor leaves everyone\'s presence', pr !== null);
    check('owner gets no access notice (role unchanged)', !own.msgs.slice(mark.o).some((m) => m.t === 'access'));
    mark = { e: edt.msgs.length };
    await push(PID, U.owner.tok, [dataset(SID, 'D3')], 'copy-A');
    await sleep(600);
    check('the removed editor gets no more changes', !edt.msgs.slice(mark.e).some((m) => m.t === 'changed'));
    edt.send({ t: 'sub', pid: PID });
    check('and cannot follow again', (await edt.wait((m) => m.t === 'nosub', 2000, mark.e))?.error === 'not_found');

    section('Parked pushes (MsLive parked notices)');
    mark = { o: own.msgs.length };
    r = await push(PID, U.editor.tok, [dataset(SID, 'D-parked')], 'copy-E');
    check('the removed editor\'s push is parked', r.code === 403 && r.body?.parked === true, r.raw);
    check('the owner hears of it', (await own.wait((m) => m.t === 'parked', 2000, mark.o))?.pid === PID, own.msgs.slice(mark.o));
    const parkedList = await req('GET', `/projects/${PID}/parked`, U.owner.tok);
    const parkedId = parkedList.body?.parked?.[0]?.id;
    check('owner lists it', Number.isInteger(parkedId), parkedList.raw);
    mark = { o: own.msgs.length };
    r = await req('POST', `/projects/${PID}/parked/${parkedId}/review`, U.owner.tok, { decisions: { 'dataset:D-parked': 'discarded' } });
    check('owner discards it', r.code === 200, r.raw);
    check('the review is announced too (the owner\'s other copies)', (await own.wait((m) => m.t === 'parked', 2000, mark.o)) !== null);
    mark = { v: vie.msgs.length };
    r = await req('DELETE', `/projects/${PID}/members/${U.viewer.pkey}`, U.viewer.tok);
    check('the (former) viewer leaves', r.code === 200, r.raw);
    check('leaving -> access removed', (await vie.wait((m) => m.t === 'access', 2000, mark.v))?.removed === true);

    section('Limits');
    const mine = [];
    for (let i = 0; i < 3; i++) mine.push(track(await authed(U.maya.tok)));
    const fourth = track(await authed(U.maya.tok));
    check('4th connection of an account (cap 3) -> the oldest is closed 4409', (await mine[0].waitClose())?.code === 4409
      && fourth.ready !== null && mine[1].closed === null);
    for (const x of [...mine, fourth]) x.close();
    await sleep(200);
    const h = await health();
    const fill = [];
    for (let i = h.connections; i < 12; i++) fill.push(track(new Client()));
    await Promise.all(fill.map((x) => x.opened));
    const over = track(new Client());
    await over.opened;
    check('global cap -> close 4503 server_full', (await over.waitClose())?.code === 4503 && over.of('error')[0]?.error === 'server_full');
    for (const x of fill) x.close();
    await sleep(300);
    c = track(await authed(U.owner.tok));
    c.send('x'.repeat(5000));
    check('a message over 4 KB -> close 1009', (await c.waitClose())?.code === 1009);
    c = track(await authed(U.owner.tok));
    for (let i = 0; i < 40; i++) c.send({ t: 'unsub', pid: PID });
    check('more than 30 messages in 10 s -> close 4429', (await c.waitClose())?.code === 4429);
    c = track(await authed(U.owner.tok));
    c.send('{not json');
    check('not JSON -> close 4400', (await c.waitClose())?.code === 4400);
    c = track(await authed(U.owner.tok));
    c.send({ t: 'sub', pid: 'x' });
    check('bad pid -> close 4400', (await c.waitClose())?.code === 4400);

    section('Keep-alive');
    mark = { o: own.msgs.length };
    const mute = track(new Client(URL, { autoPong: false }));
    await mute.opened;
    mute.send({ t: 'auth', token: U.maya.tok });
    await mute.wait((m) => m.t === 'ready');
    // maya is a member again? she is a contributor (never removed)
    mute.send({ t: 'sub', pid: PID });
    await mute.wait((m) => m.t === 'subbed');
    mute.send({ t: 'presence', pid: PID, state: 'here', viewing: null, editing: null });
    await own.wait((m) => m.t === 'presence' && m.people.some((p) => p.user === U.maya.pkey), 2000, mark.o);
    mark = { o: own.msgs.length };
    check('a connection that stops answering pings is dropped', (await mute.waitClose(3000)) !== null);
    pr = await own.wait((m) => m.t === 'presence' && !m.people.some((p) => p.user === U.maya.pkey), 2000, mark.o);
    check('and leaves presence', pr !== null);

    section('LISTEN connection lost and back');
    mark = { o: own.msgs.length };
    await db.query(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE query = 'LISTEN microsync_live' AND usename = $1`, [CFG.db.user]);
    await sleep(300);
    const r2 = await push(PID, U.maya.tok, [dataset(SID, 'D-maya')], 'copy-M');
    check('a push while the service is not listening still works', r2.code === 200 && r2.body.results[0].status === 'accepted', r2.raw);
    ch = await own.wait((m) => m.t === 'changed' && m.seq >= r2.body.headSeq, 5000, mark.o);
    check('after LISTEN is back, followers are caught up to the head', ch !== null && ch.by === null, own.msgs.slice(mark.o));
    mark = { o: own.msgs.length };
    await db.query(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE query = 'LISTEN microsync_live' AND usename = $1`, [CFG.db.user]);
    await sleep(300);
    const c2 = await req('POST', `/projects/${PID}/chat`, U.maya.tok, { clientMsgId: crypto.randomUUID(), text: 'while not listening' });
    check('a chat message while the service is not listening still works', c2.code === 200, c2.raw);
    const cc = await own.wait((m) => m.t === 'chat' && m.rev >= c2.body?.message?.rev, 5000, mark.o);
    check('after LISTEN is back, followers are told the newest chat rev', cc !== null, own.msgs.slice(mark.o));
    check('health says listening again', (await health()).listening === true);

    section('Project deleted');
    mark = { o: own.msgs.length };
    r = await req('DELETE', `/projects/${PID}`, U.owner.tok);
    check('owner deletes the project', r.code === 200, r.raw);
    ac = await own.wait((m) => m.t === 'access', 2000, mark.o);
    check('followers get access removed', ac?.removed === true, ac);

    section('Restart');
    c = track(await authed(U.owner.tok));
    svc.kill('SIGTERM');
    check('SIGTERM closes connections with 1012', (await c.waitClose())?.code === 1012);

    section('Who may sync (MICROSYNC_ENABLED, MICROSYNC_ALLOW)');
    await accessTests(U);
  } finally {
    for (const c of open) c.close();
    if (svc && svc.exitCode === null) svc.kill('SIGTERM');
    if (PID) {
      try {
        fixture('cleanup');
      } catch (e) {
        console.log('cleanup failed:', e.message);
      }
    }
    await db.end();
  }
  console.log(`\n${passed} passed, ${failed} failed`);
  if (failed) {
    console.log('\n--- service log ---\n' + svcLog);
    process.exit(1);
  }
}

main().catch((e) => {
  console.error(e);
  if (svc) svc.kill('SIGTERM');
  process.exit(1);
});
