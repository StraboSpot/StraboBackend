/**
 * File: livesvc/server.js
 * Description: strabo-live, the real-time notice service for StraboMicro
 *              collaboration (spec v3 17ah-17ax). One WebSocket per app
 *              copy; Apache forwards /microsync/live here (prod: the host
 *              Apache to 127.0.0.1:8095; dev: strabo-php to strabo-live:3001).
 *
 *              It carries notices and presence only, never project data:
 *              PHP sends a Postgres NOTIFY on 'microsync_live' when a push
 *              commits or membership changes (microsync/lib/MsLive.php);
 *              this service LISTENs and tells the apps that follow the
 *              project, which then pull through the normal API. If this
 *              service is down, pushes still work and the apps poll.
 *
 *              Messages are JSON text, at most 4 KB.
 *              App -> service:
 *                {"t":"auth","token":"<JWT>"}  first message, within 10 s;
 *                    again whenever the app refreshes its token (same user)
 *                {"t":"sub","pid":N}     follow a project (active members)
 *                {"t":"unsub","pid":N}
 *                {"t":"presence","pid":N,"state":"here"|"away",
 *                 "viewing":{"type","id"}|null,"editing":{"type","id"}|null}
 *                    applied at most once a second (the last one wins)
 *              Service -> app:
 *                {"t":"ready","user":pkey,"conn":"<id>","pingMs":25000}
 *                {"t":"renewed","exp":<unix s>}
 *                {"t":"subbed","pid","seq","role"} | {"t":"nosub","pid","error"}
 *                {"t":"changed","pid","seq","by":"<clientId>"|null}
 *                {"t":"access","pid","role"} role changed; or
 *                {"t":"access","pid","removed":true} no longer an active
 *                    member (removed, left, project deleted): the app runs
 *                    its normal check, which says which
 *                {"t":"parked","pid"} parked pushes changed (the owner's
 *                    copies count what waits for review again)
 *                {"t":"presence","pid","people":[{"conn","user","state",
 *                    "viewing","editing","since"}]} everyone following the
 *                    project, the app's own connection included (the app
 *                    hides its own account, 17an)
 *                {"t":"error","error","message"}
 *              Close codes: 4400 bad message, 4401 auth failed or expired,
 *              4408 no auth in time, 4409 replaced (11th connection of an
 *              account closes the oldest), 4429 too many messages,
 *              4503 service full (global cap; the app polls) or
 *              sync_disabled (sync off, or the account is not in
 *              MICROSYNC_ALLOW; checked at auth and every LIVE_RECHECK_MS),
 *              1009 message too big, 1012 service restarting.
 *
 *              GET /health answers {ok, connections, users, projects,
 *              listening, syncEnabled, allowList} (Docker network or 127.0.0.1 only).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

const http = require('http');
const crypto = require('crypto');
const { WebSocketServer } = require('ws');
const pg = require('pg');
const { load, currentAccess } = require('./config');
const jwt = require('./jwt');

const CFG = load();
const CHANNEL = 'microsync_live';

function log(...args) {
  console.log(new Date().toISOString(), ...args);
}

// ---------------------------------------------------------------------------
// Database: a small pool for checks, one dedicated client for LISTEN
// ---------------------------------------------------------------------------

const pool = new pg.Pool({ ...CFG.db, max: 4, idleTimeoutMillis: 30000 });
pool.on('error', (e) => log('[db] pool error:', e.message));

/** Active membership of pkey in a live synced project, or null (MsStore::project rules). */
async function membership(pid, pkey) {
  const r = await pool.query(
    `SELECT p.head_seq, m.role
       FROM strabomicro.micro_projectmetadata p
       JOIN strabomicro.micro_members m ON m.project_id = p.id AND m.user_pkey = $2 AND m.state = 'active'
      WHERE p.id = $1
        AND (p.sync_format = 'entity' OR (p.sync_format = 'legacy' AND p.sync_state = 'adopting'))
        AND NOT EXISTS (SELECT 1 FROM strabomicro.micro_deleted_projects dp WHERE dp.project_id = p.id)`,
    [pid, pkey]);
  return r.rows.length ? { seq: Number(r.rows[0].head_seq), role: r.rows[0].role } : null;
}

/** pkey => role of every active member of a live synced project (empty when gone). */
async function activeMembers(pid) {
  const r = await pool.query(
    `SELECT m.user_pkey, m.role
       FROM strabomicro.micro_projectmetadata p
       JOIN strabomicro.micro_members m ON m.project_id = p.id AND m.state = 'active'
      WHERE p.id = $1
        AND (p.sync_format = 'entity' OR (p.sync_format = 'legacy' AND p.sync_state = 'adopting'))
        AND NOT EXISTS (SELECT 1 FROM strabomicro.micro_deleted_projects dp WHERE dp.project_id = p.id)`,
    [pid]);
  return new Map(r.rows.map((row) => [Number(row.user_pkey), row.role]));
}

async function userExists(pkey) {
  const r = await pool.query('SELECT 1 FROM users WHERE pkey = $1', [pkey]);
  return r.rows.length > 0;
}

/** May this account sync (MICROSYNC_ENABLED + MICROSYNC_ALLOW, as PHP's MsAccess)? */
async function syncAllowed(pkey) {
  let a;
  try {
    a = currentAccess();
  } catch (e) {
    log('[access] config not readable:', e.message);
    return false;
  }
  if (!a.enabled) return false;
  if (a.allow === null) return true;
  const r = await pool.query('SELECT lower(trim(email)) AS email FROM users WHERE pkey = $1 AND deleted = false', [pkey]);
  return r.rows.length > 0 && a.allow.has(r.rows[0].email);
}

function refuseAccess(c) {
  c.send({ t: 'error', error: 'sync_disabled', message: 'StraboMicro sync is not enabled for this account' });
  c.close(4503, 'sync_disabled');
}

/** For /health: syncEnabled, and allowList = how many accounts MICROSYNC_ALLOW names (null: every account) */
function accessHealth() {
  try {
    const a = currentAccess();
    return { syncEnabled: a.enabled, allowList: a.allow === null ? null : a.allow.size };
  } catch {
    return { syncEnabled: false, allowList: 0 };
  }
}

/** Accounts taken off MICROSYNC_ALLOW (or sync switched off) lose their connections */
async function recheckAccess() {
  const users = new Set([...conns.values()].map((c) => c.user).filter((u) => u !== null));
  for (const pkey of users) {
    if (await syncAllowed(pkey)) continue;
    log('[access] closing connections of an account that may no longer sync:', pkey);
    for (const c of accountConns(pkey)) refuseAccess(c);
  }
}

async function headSeq(pid) {
  const r = await pool.query('SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1', [pid]);
  return r.rows.length ? Number(r.rows[0].head_seq) : null;
}

let listener = null;
let listening = false;
let listenRetryMs = 1000;
let listenedBefore = false;
let stopping = false;

async function startListening() {
  const client = new pg.Client(CFG.db);
  client.on('notification', (msg) => {
    if (msg.channel !== CHANNEL) return;
    let n;
    try {
      n = JSON.parse(msg.payload);
    } catch {
      return;
    }
    onNotice(n);
  });
  const lost = (why) => {
    if (listener !== client || stopping) return;
    listener = null;
    listening = false;
    log(`[db] LISTEN lost (${why}); retry in ${listenRetryMs} ms`);
    client.end().catch(() => {});
    setTimeout(startListening, listenRetryMs);
    listenRetryMs = Math.min(listenRetryMs * 2, 30000);
  };
  client.on('error', (e) => lost(e.message));
  client.on('end', () => lost('ended'));
  listener = client;
  try {
    await client.connect();
    await client.query(`LISTEN ${CHANNEL}`);
  } catch (e) {
    lost(e.message);
    return;
  }
  listening = true;
  listenRetryMs = 1000;
  log('[db] LISTEN', CHANNEL);
  if (listenedBefore) {
    // Notices sent while we were not listening are lost: catch everyone up
    catchUpAll().catch((e) => log('[db] catch-up failed:', e.message));
  }
  listenedBefore = true;
}

// ---------------------------------------------------------------------------
// Connections, subscriptions, presence
// ---------------------------------------------------------------------------

/** @type {Map<string, Conn>} */
const conns = new Map();
/** pid => Set<Conn> */
const followers = new Map();
/** pid => timer of a pending presence broadcast */
const presenceTimers = new Map();

class Conn {
  constructor(ws) {
    this.ws = ws;
    this.id = crypto.randomBytes(8).toString('hex');
    this.user = null; // pkey once authenticated
    this.exp = 0;
    this.expTimer = null;
    this.alive = true;
    this.openedAt = Date.now();
    /** pid => { role, presence, nextPresenceAt, pendingPresence, presenceTimer } */
    this.subs = new Map();
    this.msgTimes = [];
  }

  send(obj) {
    if (this.ws.readyState === this.ws.OPEN) this.ws.send(JSON.stringify(obj));
  }

  close(code, reason) {
    try {
      this.ws.close(code, reason);
    } catch {
      this.ws.terminate();
    }
  }
}

function accountConns(pkey) {
  return [...conns.values()].filter((c) => c.user === pkey);
}

function setExpiry(c, exp) {
  c.exp = exp;
  if (c.expTimer) clearTimeout(c.expTimer);
  const ms = Math.min(exp * 1000 - Date.now() + 1000, 0x7fffffff);
  c.expTimer = setTimeout(() => {
    c.send({ t: 'error', error: 'auth_expired', message: 'The access token expired; reconnect with a new one' });
    c.close(4401, 'auth_expired');
  }, Math.max(ms, 0));
}

async function onAuth(c, m) {
  const v = jwt.verify(m.token, CFG.jwtSecret, CFG.jwtAudience);
  if (!v.ok) {
    c.send({ t: 'error', error: v.error, message: 'Authentication failed' });
    c.close(4401, v.error);
    return;
  }
  if (c.user !== null) {
    // Renewal: same account only
    if (v.pkey !== c.user) {
      c.close(4401, 'other_user');
      return;
    }
    setExpiry(c, v.exp);
    c.send({ t: 'renewed', exp: v.exp });
    return;
  }
  if (!(await userExists(v.pkey))) {
    c.close(4401, 'user_not_found');
    return;
  }
  if (!(await syncAllowed(v.pkey))) {
    refuseAccess(c);
    return;
  }
  if (c.ws.readyState !== c.ws.OPEN) return;
  c.user = v.pkey;
  clearTimeout(c.authTimer);
  setExpiry(c, v.exp);
  // Per-account cap: the oldest connection makes room (17at)
  const mine = accountConns(v.pkey).sort((a, b) => a.openedAt - b.openedAt);
  while (mine.length > CFG.maxPerAccount) {
    const old = mine.shift();
    old.send({ t: 'error', error: 'replaced', message: 'Too many connections for this account; the oldest was closed' });
    old.close(4409, 'replaced');
  }
  c.send({ t: 'ready', user: c.user, conn: c.id, pingMs: CFG.pingMs });
}

async function onSub(c, m) {
  const pid = m.pid;
  if (c.subs.has(pid)) {
    const s = await membership(pid, c.user);
    if (s) {
      c.send({ t: 'subbed', pid, seq: s.seq, role: s.role });
    } else {
      unsubscribe(c, pid);
      c.send({ t: 'nosub', pid, error: 'not_found' });
    }
    return;
  }
  if (c.subs.size >= CFG.maxSubscriptions) {
    c.send({ t: 'nosub', pid, error: 'too_many' });
    return;
  }
  const s = await membership(pid, c.user);
  if (!s) {
    c.send({ t: 'nosub', pid, error: 'not_found' });
    return;
  }
  if (c.ws.readyState !== c.ws.OPEN || c.subs.has(pid)) return;
  c.subs.set(pid, { role: s.role, presence: null, nextPresenceAt: 0, pendingPresence: null, presenceTimer: null });
  if (!followers.has(pid)) followers.set(pid, new Set());
  followers.get(pid).add(c);
  c.send({ t: 'subbed', pid, seq: s.seq, role: s.role });
  // The newcomer needs to see who is here even before saying anything
  sendPresenceTo(c, pid);
}

function unsubscribe(c, pid) {
  const sub = c.subs.get(pid);
  if (!sub) return;
  if (sub.presenceTimer) clearTimeout(sub.presenceTimer);
  c.subs.delete(pid);
  const f = followers.get(pid);
  if (f) {
    f.delete(c);
    if (f.size === 0) followers.delete(pid);
  }
  if (sub.presence) schedulePresence(pid);
}

const ID_RE = /^[A-Za-z0-9._:-]{1,100}$/;
const TYPE_RE = /^[a-z_]{1,24}$/;

/** {type, id} or null; undefined for anything else */
function target(v) {
  if (v === null || v === undefined) return null;
  if (typeof v !== 'object' || Array.isArray(v)) return undefined;
  if (typeof v.type !== 'string' || !TYPE_RE.test(v.type)) return undefined;
  if (typeof v.id !== 'string' || !ID_RE.test(v.id)) return undefined;
  return { type: v.type, id: v.id };
}

function onPresence(c, m) {
  const sub = c.subs.get(m.pid);
  if (!sub) {
    c.send({ t: 'error', error: 'not_subscribed', message: 'Follow the project first' });
    return;
  }
  const viewing = target(m.viewing);
  const editing = target(m.editing);
  if ((m.state !== 'here' && m.state !== 'away') || viewing === undefined || editing === undefined) {
    c.close(4400, 'bad_presence');
    return;
  }
  const next = { state: m.state, viewing, editing };
  // At most one update a second per connection; the last one wins (17at)
  const now = Date.now();
  if (now >= sub.nextPresenceAt) {
    applyPresence(c, m.pid, sub, next);
  } else {
    sub.pendingPresence = next;
    if (!sub.presenceTimer) {
      sub.presenceTimer = setTimeout(() => {
        sub.presenceTimer = null;
        if (c.subs.get(m.pid) === sub && sub.pendingPresence) {
          const p = sub.pendingPresence;
          sub.pendingPresence = null;
          applyPresence(c, m.pid, sub, p);
        }
      }, sub.nextPresenceAt - now);
    }
  }
}

function applyPresence(c, pid, sub, p) {
  sub.nextPresenceAt = Date.now() + CFG.presenceMs;
  const prev = sub.presence;
  const changedState = !prev || prev.state !== p.state;
  sub.presence = {
    ...p,
    // since = when the current state (here/away) began
    since: changedState ? new Date().toISOString() : prev.since,
  };
  schedulePresence(pid);
}

function peopleOf(pid) {
  const out = [];
  for (const c of followers.get(pid) ?? []) {
    const p = c.subs.get(pid)?.presence;
    if (p) out.push({ conn: c.id, user: c.user, state: p.state, viewing: p.viewing, editing: p.editing, since: p.since });
  }
  return out;
}

function sendPresenceTo(c, pid) {
  c.send({ t: 'presence', pid, people: peopleOf(pid) });
}

/** Changes in one project within 100 ms go out as one message */
function schedulePresence(pid) {
  if (presenceTimers.has(pid)) return;
  presenceTimers.set(pid, setTimeout(() => {
    presenceTimers.delete(pid);
    const msg = JSON.stringify({ t: 'presence', pid, people: peopleOf(pid) });
    for (const c of followers.get(pid) ?? []) {
      if (c.ws.readyState === c.ws.OPEN) c.ws.send(msg);
    }
  }, 100));
}

function dropConn(c) {
  if (!conns.has(c.id)) return;
  conns.delete(c.id);
  clearTimeout(c.authTimer);
  if (c.expTimer) clearTimeout(c.expTimer);
  for (const pid of [...c.subs.keys()]) unsubscribe(c, pid);
}

// ---------------------------------------------------------------------------
// Notices from PHP
// ---------------------------------------------------------------------------

function onNotice(n) {
  if (!n || typeof n !== 'object' || !Number.isInteger(n.pid)) return;
  if (n.t === 'changed' && Number.isInteger(n.seq)) {
    const msg = JSON.stringify({ t: 'changed', pid: n.pid, seq: n.seq, by: typeof n.by === 'string' ? n.by : null });
    for (const c of followers.get(n.pid) ?? []) {
      if (c.ws.readyState === c.ws.OPEN) c.ws.send(msg);
    }
  } else if (n.t === 'parked') {
    const msg = JSON.stringify({ t: 'parked', pid: n.pid });
    for (const c of followers.get(n.pid) ?? []) {
      if (c.ws.readyState === c.ws.OPEN) c.ws.send(msg);
    }
  } else if (n.t === 'members') {
    recheck(n.pid).catch((e) => log('[members] recheck failed:', n.pid, e.message));
  }
}

/** Check every follower of pid again: removed ones are dropped, role changes told (17as). */
async function recheck(pid) {
  if (!followers.has(pid)) return;
  const members = await activeMembers(pid);
  for (const c of [...(followers.get(pid) ?? [])]) {
    const sub = c.subs.get(pid);
    if (!sub) continue;
    const role = members.get(c.user);
    if (role === undefined) {
      unsubscribe(c, pid);
      c.send({ t: 'access', pid, removed: true });
    } else if (role !== sub.role) {
      sub.role = role;
      c.send({ t: 'access', pid, role });
    }
  }
}

/** After LISTEN came back: say where every followed project is now, and recheck access. */
async function catchUpAll() {
  for (const pid of [...followers.keys()]) {
    const seq = await headSeq(pid);
    if (seq !== null) onNotice({ t: 'changed', pid, seq, by: null });
    await recheck(pid);
  }
}

// ---------------------------------------------------------------------------
// HTTP + WebSocket
// ---------------------------------------------------------------------------

const server = http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    const users = new Set([...conns.values()].map((c) => c.user).filter((u) => u !== null));
    const body = JSON.stringify({
      ok: true, node: process.version, connections: conns.size, users: users.size,
      projects: followers.size, listening, maxConnections: CFG.maxConnections, ...accessHealth(),
    });
    res.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
    res.end(body);
    return;
  }
  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not found');
});

const wss = new WebSocketServer({ server, maxPayload: CFG.maxMessageBytes, clientTracking: false });

wss.on('connection', (ws) => {
  const c = new Conn(ws);
  // Global cap (17ax): above it the app polls as before
  if (conns.size >= CFG.maxConnections) {
    c.send({ t: 'error', error: 'server_full', message: 'The live service is full; polling instead' });
    c.close(4503, 'server_full');
    return;
  }
  conns.set(c.id, c);
  c.authTimer = setTimeout(() => {
    if (c.user === null) c.close(4408, 'auth_timeout');
  }, CFG.authTimeoutMs);

  ws.on('pong', () => {
    c.alive = true;
  });
  ws.on('close', () => dropConn(c));
  ws.on('error', () => dropConn(c));

  // One message at a time per connection, in order
  let chain = Promise.resolve();
  ws.on('message', (data, isBinary) => {
    chain = chain.then(() => handle(c, data, isBinary)).catch((e) => {
      log('[conn] error:', e.message);
      c.send({ t: 'error', error: 'server_error', message: 'The live service could not handle that' });
    });
  });
});

async function handle(c, data, isBinary) {
  if (!conns.has(c.id)) return;
  const now = Date.now();
  c.msgTimes = c.msgTimes.filter((t) => now - t < CFG.rateWindowMs);
  c.msgTimes.push(now);
  if (c.msgTimes.length > CFG.rateMessages) {
    c.close(4429, 'too_many_messages');
    return;
  }
  let m;
  try {
    if (isBinary) throw new Error('binary');
    m = JSON.parse(data.toString('utf8'));
  } catch {
    c.close(4400, 'bad_message');
    return;
  }
  if (!m || typeof m !== 'object' || typeof m.t !== 'string') {
    c.close(4400, 'bad_message');
    return;
  }
  if (m.t === 'auth') return onAuth(c, m);
  if (c.user === null) {
    c.close(4401, 'auth_first');
    return;
  }
  if ((m.t === 'sub' || m.t === 'unsub' || m.t === 'presence') && !(Number.isInteger(m.pid) && m.pid > 0)) {
    c.close(4400, 'bad_pid');
    return;
  }
  if (m.t === 'sub') return onSub(c, m);
  if (m.t === 'unsub') return unsubscribe(c, m.pid);
  if (m.t === 'presence') return onPresence(c, m);
  c.close(4400, 'unknown_type');
}

// Keep-alive (17at): a connection that missed the last ping is gone
const pinger = setInterval(() => {
  for (const c of conns.values()) {
    if (!c.alive) {
      c.ws.terminate();
      dropConn(c);
      continue;
    }
    c.alive = false;
    try {
      c.ws.ping();
    } catch {
      // closed meanwhile
    }
  }
}, CFG.pingMs);

// Safety net: membership changes that bypassed MsLive (e.g. a legacy hard delete)
const rechecker = setInterval(() => {
  for (const pid of [...followers.keys()]) {
    recheck(pid).catch((e) => log('[recheck] failed:', pid, e.message));
  }
  recheckAccess().catch((e) => log('[access] recheck failed:', e.message));
}, CFG.recheckMs);

function shutdown(signal) {
  log(`[live] ${signal}: closing`);
  stopping = true;
  clearInterval(pinger);
  clearInterval(rechecker);
  for (const c of conns.values()) c.close(1012, 'restarting');
  server.close();
  setTimeout(() => process.exit(0), 500).unref();
  if (listener) listener.end().catch(() => {});
  pool.end().catch(() => {});
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

server.listen(CFG.port, () => {
  log(`[live] listening on ${CFG.port} (node ${process.version}; max ${CFG.maxConnections} connections, ${CFG.maxPerAccount} per account)`);
  startListening();
});
