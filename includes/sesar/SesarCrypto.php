<?php
/**
 * File: includes/sesar/SesarCrypto.php
 * Description: Encryption at rest for users' SESAR tokens (D1): libsodium
 *              secretbox (XSalsa20-Poly1305) under $sesar_token_key. Stored
 *              form is "v1:" . base64(nonce . ciphertext) so the scheme can
 *              change later without guessing. A tampered value, or one sealed
 *              under another key, fails to open and returns null; callers
 *              treat that as "reconnect needed", never as an error page.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class SesarCrypto
{
	const PREFIX = 'v1:';

	public static function seal($plaintext, $key)
	{
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox((string)$plaintext, $nonce, $key));
	}

	public static function open($sealed, $key)
	{
		if (!is_string($sealed) || strpos($sealed, self::PREFIX) !== 0 || $key === null) return null;
		$raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
		if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
		$nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
		return $plain === false ? null : $plain;
	}
}
