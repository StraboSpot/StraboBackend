/**
 * File: livesvc/config.js
 * Description: Settings of the live service. The database login and the JWT
 *              secret come from the same includes/config.inc.php that PHP
 *              uses (the container mounts www read-only), so there is one
 *              place to change them. Environment variables override, for
 *              tests that run the service outside Docker:
 *                LIVE_CONFIG      path of config.inc.php
 *                LIVE_DB_HOST     LIVE_DB_PORT
 *                LIVE_PORT        listening port (3001)
 *                LIVE_MAX_CONNECTIONS, LIVE_MAX_PER_ACCOUNT, LIVE_PING_MS,
 *                LIVE_AUTH_TIMEOUT_MS, LIVE_RECHECK_MS, LIVE_PRESENCE_MS
 *
 *              Who may sync is read again whenever config.inc.php changes
 *              (currentAccess), so editing MICROSYNC_ALLOW needs no restart:
 *              MICROSYNC_ENABLED must be true, and MICROSYNC_ALLOW, when
 *              defined, lists the only accounts (emails) that may connect.
 *              Same rules as microsync/lib/MsAccess.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

const fs = require('fs');

/** $name = "value"; from a PHP file */
function phpVar(text, name) {
  const m = text.match(new RegExp('\\$' + name + "\\s*=\\s*(['\"])(.*?)\\1\\s*;"));
  return m ? m[2] : null;
}

/** define('NAME', 'value'); from a PHP file */
function phpDefine(text, name) {
  const m = text.match(new RegExp("define\\(\\s*(['\"])" + name + "\\1\\s*,\\s*(['\"])(.*?)\\2\\s*\\)"));
  return m ? m[3] : null;
}

function int(name, fallback) {
  const v = process.env[name];
  return v !== undefined && /^\d+$/.test(v) ? Number(v) : fallback;
}

function load() {
  const path = process.env.LIVE_CONFIG || '/srv/app/www/includes/config.inc.php';
  const text = fs.readFileSync(path, 'utf8');
  const need = (value, what) => {
    if (!value) throw new Error(`${what} not found in ${path}`);
    return value;
  };
  return {
    port: int('LIVE_PORT', 3001),
    db: {
      host: process.env.LIVE_DB_HOST || need(phpVar(text, 'dbhost'), '$dbhost'),
      port: int('LIVE_DB_PORT', 5432),
      user: need(phpVar(text, 'dbusername'), '$dbusername'),
      password: need(phpVar(text, 'dbpassword'), '$dbpassword'),
      database: need(phpVar(text, 'dbname'), '$dbname'),
    },
    jwtSecret: need(phpDefine(text, 'JWT_SECRET'), 'JWT_SECRET'),
    jwtAudience: need(phpDefine(text, 'JWT_AUDIENCE'), 'JWT_AUDIENCE'),
    // Limits (spec 17at, 17ax)
    maxConnections: int('LIVE_MAX_CONNECTIONS', 100),
    maxPerAccount: int('LIVE_MAX_PER_ACCOUNT', 10),
    maxSubscriptions: 5,
    maxMessageBytes: 4096,
    pingMs: int('LIVE_PING_MS', 25000),
    authTimeoutMs: int('LIVE_AUTH_TIMEOUT_MS', 10000),
    recheckMs: int('LIVE_RECHECK_MS', 5 * 60 * 1000),
    presenceMs: int('LIVE_PRESENCE_MS', 1000),
    // Any message: at most this many per window, or the connection closes
    rateMessages: 30,
    rateWindowMs: 10000,
  };
}

/** The PHP text without comments, so a commented-out define does not count */
function stripComments(text) {
  return text
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*(\/\/|#).*$/gm, '')
    .replace(/(^|[^:'"\\])\/\/.*$/gm, '$1'); // a // at the end of a line (not the one in https://)
}

/**
 * Who may sync, from the text of config.inc.php: enabled = MICROSYNC_ENABLED
 * is true; allow = null (every account) or a Set of lowercase emails from
 * define('MICROSYNC_ALLOW', array('a@b.org', ...)) (or [...]). A
 * MICROSYNC_ALLOW this cannot read allows nobody.
 */
function syncAccess(text) {
  const t = stripComments(text);
  const enabled = /define\(\s*(['"])MICROSYNC_ENABLED\1\s*,\s*true\s*\)/i.test(t);
  if (!/(['"])MICROSYNC_ALLOW\1/.test(t)) return { enabled, allow: null };
  const allow = new Set();
  const m = t.match(/define\(\s*(['"])MICROSYNC_ALLOW\1\s*,\s*(?:array\s*\(([^)]*)\)|\[([^\]]*)\])\s*\)/i);
  if (m) {
    for (const q of (m[2] ?? m[3]).matchAll(/(['"])(.*?)\1/g)) allow.add(q[2].trim().toLowerCase());
  }
  return { enabled, allow };
}

let accessCache = null;

/** syncAccess of the config file, read again when it changes; keeps the last good answer if it cannot be read */
function currentAccess() {
  const path = process.env.LIVE_CONFIG || '/srv/app/www/includes/config.inc.php';
  try {
    const st = fs.statSync(path);
    if (!accessCache || accessCache.path !== path || accessCache.mtimeMs !== st.mtimeMs || accessCache.size !== st.size) {
      accessCache = { path, mtimeMs: st.mtimeMs, size: st.size, ...syncAccess(fs.readFileSync(path, 'utf8')) };
    }
  } catch (e) {
    if (!accessCache) throw e;
  }
  return accessCache;
}

module.exports = { load, phpVar, phpDefine, syncAccess, currentAccess };
