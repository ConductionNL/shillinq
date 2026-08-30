<?php

/**
 * Shillinq Portal Assertion Verifier (portal-payment-initiation, A6 receiver).
 *
 * When portaliq forwards the `pay` endpoint-forward action server-to-server
 * (ADR-046 contract v2, A6) it attaches an `X-Portal-Subject` header: a
 * short-lived (60s TTL) HS256 JWT assertion carrying the resolved portal
 * subject. This class verifies that assertion and hands the receiving
 * controller a trusted claim set — the ONLY legitimate identity source for a
 * portal-driven request (ADR-005).
 *
 * ADAPTED VERBATIM from the fleet's reference A6 receiver
 * (`petstore/lib/Portal/PortalAssertionVerifier.php`) — same hand-rolled
 * HS256 verification (hash_equals, constant time), same secret derivation,
 * same fail-closed matrix. Copied rather than shared so every receiver stays
 * self-contained and independently reviewable (no portaliq import, no
 * firebase/php-jwt composer dependency).
 *
 * SECRET DERIVATION — copied verbatim from portaliq's
 * `PortalSessionService::__construct()` (the single place portaliq derives
 * the signing secret for sessions AND assertions); both ends MUST stay
 * byte-identical or every forward 401s:
 *
 *   1. `IConfig::getAppValue('portaliq', 'jwt_signing_secret', '')` — the
 *      dedicated app-config secret OF THE PORTALIQ APP ID (not shillinq's).
 *   2. When that is empty or shorter than 16 chars:
 *      `IConfig::getSystemValue('secret', str_pad('portaliq', 32, '_'))` —
 *      the Nextcloud instance secret with portaliq's default-of-last-resort.
 *
 * @category Portal
 * @package  OCA\Shillinq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Portal;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Verifies portaliq's `X-Portal-Subject` HS256 assertion — fail-closed.
 *
 * `verify()` returns the full claims array only when EVERY check passes and
 * null on ANY failure; it never throws and never leaks the rejection reason
 * to the caller (debug log only). A receiving controller derives ALL subject
 * scope from the returned claims — never from request parameters.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
 */
class PortalAssertionVerifier {
	/**
	 * The header portaliq attaches the assertion to (contract v2, A6).
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * The only accepted JWS algorithm — exact-match kills `none` and any
	 * RS/ES algorithm-confusion header in one check.
	 */
	private const ALG = 'HS256';

	/**
	 * Hash function name passed to hash_hmac.
	 */
	private const HASH_FN = 'sha256';

	/**
	 * The minting edge — portaliq stamps `iss` on every assertion.
	 */
	private const ISSUER = 'portaliq';

	/**
	 * The `use` claim value marking an X-Portal-Subject assertion. The
	 * receiver-side token-confusion guard: a portal SESSION token (no `use`
	 * claim) can never drive a domain endpoint, exactly as portaliq rejects
	 * assertions presented as session bearers.
	 */
	private const USE_ASSERTION = 'assertion';

	/**
	 * The app id whose config carries the signing secret (portaliq's, NOT
	 * shillinq's — the receiver reads the minter's secret).
	 */
	private const PORTALIQ_APP_ID = 'portaliq';

	/**
	 * The portaliq app-config key holding the dedicated signing secret.
	 */
	private const SECRET_KEY = 'jwt_signing_secret';

	/**
	 * Minimum usable secret length — portaliq refuses to MINT with less;
	 * the receiver refuses to ACCEPT with less.
	 */
	private const MIN_SECRET_LENGTH = 16;

	/**
	 * Tolerated clock skew (seconds) when checking `iat` is not in the
	 * future. The forward is an instance-local loopback hop, so real skew
	 * is zero; the leeway only absorbs same-host second boundaries.
	 */
	private const IAT_LEEWAY = 60;

	/**
	 * Constructor.
	 *
	 * Auto-wireable from IConfig alone (the DI container falls back to the
	 * scalar defaults). Unit tests construct it with a plain secret instead,
	 * mirroring portaliq's PortalJwtService testability pattern:
	 * `new PortalAssertionVerifier(config: null, secretOverride: 'sixteen-chars-min')`.
	 *
	 * @param IConfig|null $config The configuration source for the secret derivation.
	 * @param string|null $secretOverride Plain signing secret for tests (bypasses config).
	 * @param LoggerInterface|null $logger Optional logger — rejection reasons at debug level only.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
	 */
	public function __construct(
		private readonly ?IConfig $config = null,
		private readonly ?string $secretOverride = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Verify an `X-Portal-Subject` assertion and return its claims.
	 *
	 * Fail-closed on EVERYTHING — the claims array is returned only when all
	 * of the following hold, null otherwise (no exception ever escapes):
	 *
	 *   1. compact JWS structure — exactly three non-empty segments;
	 *   2. header `alg` is exactly `HS256` (rejects `none`, case tricks,
	 *      and any asymmetric algorithm-confusion attempt);
	 *   3. HMAC signature matches — hash_equals, constant time;
	 *   4. claims decode to a JSON object;
	 *   5. `use` is exactly `assertion` (session tokens are refused);
	 *   6. `iss` is exactly `portaliq`;
	 *   7. `exp` present, integer, strictly in the future;
	 *   8. `iat` present, integer, not in the future (60s leeway), <= exp;
	 *   9. `sub` present, non-empty string — an assertion without a subject
	 *      can scope nothing, so it authorises nothing.
	 *
	 * @param string $jwt The raw header value (compact JWT).
	 *
	 * @return array<string, mixed>|null The verified claims, or null.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) -- one fail-closed guard
	 * per attack surface on an auth boundary (ADR-005); collapsing them would
	 * trade auditability for a score.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      -- same rationale: the
	 * guards are sequential early-returns, not combinatorial paths.
	 */
	public function verify(string $jwt): ?array {
		$secret = $this->secret();
		if ($secret === null) {
			return $this->reject(reason: 'no usable signing secret');
		}

		$parts = explode('.', $jwt);
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return $this->reject(reason: 'malformed structure');
		}

		[$hPart, $cPart, $sPart] = $parts;

		$header = json_decode($this->b64UrlDecode(encoded: $hPart), true);
		if (is_array($header) === false || ($header['alg'] ?? '') !== self::ALG) {
			return $this->reject(reason: 'unexpected algorithm');
		}

		$expected = $this->b64UrlEncode(bytes: hash_hmac(self::HASH_FN, $hPart . '.' . $cPart, $secret, true));
		if (hash_equals($expected, $sPart) === false) {
			return $this->reject(reason: 'signature mismatch');
		}

		$claims = json_decode($this->b64UrlDecode(encoded: $cPart), true);
		if (is_array($claims) === false) {
			return $this->reject(reason: 'malformed claims');
		}

		if (($claims['use'] ?? '') !== self::USE_ASSERTION) {
			return $this->reject(reason: 'not an assertion');
		}

		if (($claims['iss'] ?? '') !== self::ISSUER) {
			return $this->reject(reason: 'unexpected issuer');
		}

		$now = time();
		$exp = ($claims['exp'] ?? null);
		if (is_int($exp) === false || $exp <= $now) {
			return $this->reject(reason: 'expired or missing exp');
		}

		$iat = ($claims['iat'] ?? null);
		if (is_int($iat) === false || $iat > ($now + self::IAT_LEEWAY) || $iat > $exp) {
			return $this->reject(reason: 'implausible iat');
		}

		$sub = ($claims['sub'] ?? null);
		if (is_string($sub) === false || $sub === '') {
			return $this->reject(reason: 'missing subject');
		}

		return $claims;
	}//end verify()

	/**
	 * Derive the signing secret — EXACT copy of portaliq's derivation (see
	 * the class docblock). Returns null when no usable (>= 16 chars) secret
	 * exists, which makes verify() fail closed.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
	 */
	private function secret(): ?string {
		$secret = $this->secretOverride;
		if ($secret === null) {
			if ($this->config === null) {
				return null;
			}

			// Verbatim portaliq PortalSessionService::__construct(): dedicated
			// app-config secret first, instance secret as the fallback.
			$secret = (string)$this->config->getAppValue(self::PORTALIQ_APP_ID, self::SECRET_KEY, '');
			if ($secret === '' || strlen($secret) < self::MIN_SECRET_LENGTH) {
				$secret = (string)$this->config->getSystemValue('secret', str_pad(self::PORTALIQ_APP_ID, 32, '_'));
			}
		}

		if (strlen($secret) < self::MIN_SECRET_LENGTH) {
			return null;
		}

		return $secret;
	}//end secret()

	/**
	 * Fail closed — never tell the caller (or an attacker probing the
	 * endpoint) WHICH check failed; debug-level log only.
	 *
	 * @param string $reason The rejection reason for the debug log.
	 *
	 * @return null
	 */
	private function reject(string $reason): null {
		if ($this->logger !== null) {
			$this->logger->debug('Shillinq: portal assertion rejected', ['reason' => $reason]);
		}

		return null;
	}//end reject()

	/**
	 * Base64-url encode (no padding) — mirrors portaliq's encoding exactly.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64UrlEncode()

	/**
	 * Base64-url decode — mirrors portaliq's decoding exactly.
	 *
	 * @param string $encoded Encoded string.
	 *
	 * @return string Raw bytes.
	 */
	private function b64UrlDecode(string $encoded): string {
		$pad = (4 - (strlen($encoded) % 4));
		if ($pad < 4) {
			$encoded .= str_repeat('=', $pad);
		}

		return (string)base64_decode(strtr($encoded, '-_', '+/'));
	}//end b64UrlDecode()
}//end class
