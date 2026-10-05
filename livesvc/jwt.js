/**
 * File: livesvc/jwt.js
 * Description: Checks a StraboSpot access token the way jwtauth/middleware.php
 *              does: HS256 signature with JWT_SECRET, not expired, audience
 *              JWT_AUDIENCE. (The middleware also checks that the user row
 *              exists; server.js does that against the database.)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

const crypto = require('crypto');

function b64urlDecode(s) {
  return Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

/**
 * Returns { ok: true, pkey, exp } or { ok: false, error } where error is
 * the middleware's code: invalid_token_signature, token_expired,
 * incorrect_audience (plus bad_token for anything malformed).
 */
function verify(token, secret, audience, nowSec = Math.floor(Date.now() / 1000)) {
  if (typeof token !== 'string' || token.length > 4000) return { ok: false, error: 'bad_token' };
  const parts = token.split('.');
  if (parts.length !== 3) return { ok: false, error: 'bad_token' };
  let header;
  let payload;
  try {
    header = JSON.parse(b64urlDecode(parts[0]).toString('utf8'));
    payload = JSON.parse(b64urlDecode(parts[1]).toString('utf8'));
  } catch {
    return { ok: false, error: 'bad_token' };
  }
  if (!header || header.alg !== 'HS256' || !payload || typeof payload !== 'object') {
    return { ok: false, error: 'bad_token' };
  }
  const expected = crypto.createHmac('sha256', secret).update(`${parts[0]}.${parts[1]}`).digest();
  const given = b64urlDecode(parts[2]);
  if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) {
    return { ok: false, error: 'invalid_token_signature' };
  }
  const exp = Number(payload.exp);
  if (!Number.isFinite(exp) || nowSec > exp) return { ok: false, error: 'token_expired' };
  if (payload.aud !== audience) return { ok: false, error: 'incorrect_audience' };
  const pkey = Number(payload.sub);
  if (!Number.isInteger(pkey) || pkey <= 0) return { ok: false, error: 'bad_token' };
  return { ok: true, pkey, exp };
}

module.exports = { verify };
