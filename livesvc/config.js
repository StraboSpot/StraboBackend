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

module.exports = { load, phpVar, phpDefine };
