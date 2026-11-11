<?php

/**
 * Golden test for PortalAssertionVerifier's fail-closed check order.
 *
 * Pins, for every refused assertion, WHICH check refuses it (the debug-log
 * reason) and that the accepted assertions keep returning their claims. The
 * check order is part of the contract: a token that is wrong in two ways must
 * keep being refused by the earlier check.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Portal
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

namespace OCA\Shillinq\Tests\Unit\Portal;

use OCA\Shillinq\Portal\PortalAssertionVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden rejection reasons and accepted outcomes for PortalAssertionVerifier.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
 */
class PortalAssertionVerifierRejectionReasonTest extends TestCase {

	/**
	 * A >= 16 char signing secret.
	 */
	private const SECRET = 'shillinq-test-secret-01234567890';

	/**
	 * A different valid-length secret for forged signatures.
	 */
	private const OTHER_SECRET = 'attacker-secret-9876543210abcd';

	/**
	 * Reasons logged so far by the verifier under test.
	 *
	 * @var list<string>
	 */
	private array $reasons = [];

	/**
	 * Every refused assertion is refused by the expected check, and logged once.
	 *
	 * @return void
	 */
	public function testEachRefusalIsLoggedWithItsReason(): void {
		foreach ($this->refusals() as $label => [$token, $reason]) {
			$this->reasons = [];
			$this->assertNull($this->verifier(secret: self::SECRET)->verify($token), $label);
			$this->assertSame([$reason], $this->reasons, $label);
		}

	}//end testEachRefusalIsLoggedWithItsReason()

	/**
	 * A short secret refuses every token before any token check runs.
	 *
	 * @return void
	 */
	public function testShortSecretIsRefusedFirst(): void {
		$this->assertNull($this->verifier(secret: 'too-short')->verify('not-a-token'));
		$this->assertSame(['no usable signing secret'], $this->reasons);

	}//end testShortSecretIsRefusedFirst()

	/**
	 * Accepted edge cases return the full claim set and log nothing.
	 *
	 * @return void
	 */
	public function testAcceptedEdgesReturnTheClaims(): void {
		$now = time();
		$accepted = [
			'iat equals exp'    => ['iat' => ($now + 30), 'exp' => ($now + 30)],
			'iat inside leeway' => ['iat' => ($now + 59), 'exp' => ($now + 120)],
			'iat in the past'   => ['iat' => ($now - 300), 'exp' => ($now + 60)],
		];
		foreach ($accepted as $label => $overrides) {
			$claims = $this->verifier(secret: self::SECRET)->verify($this->mint(overrides: $overrides));
			$this->assertIsArray($claims, $label);
			$this->assertSame('subject-1', $claims['sub'], $label);
		}

		$this->assertSame([], $this->reasons);

	}//end testAcceptedEdgesReturnTheClaims()

	/**
	 * The refusal matrix: label => [token, expected reason].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private function refusals(): array {
		$now = time();
		$valid = $this->mint(overrides: []);
		[$hPart, $cPart] = explode('.', $valid);

		return [
			'two segments'              => [$hPart . '.' . $cPart, 'malformed structure'],
			'four segments'             => [$valid . '.x', 'malformed structure'],
			'empty segment'             => [$hPart . '..x', 'malformed structure'],
			'alg none'                  => [$this->mint(overrides: [], alg: 'none'), 'unexpected algorithm'],
			'alg lowercase'             => [$this->mint(overrides: [], alg: 'hs256'), 'unexpected algorithm'],
			'header not json'           => ['bm90LWpzb24.' . $cPart . '.x', 'unexpected algorithm'],
			'forged signature'          => [$this->mint(overrides: [], secret: self::OTHER_SECRET), 'signature mismatch'],
			'claims not json'           => [$this->signed(cPart: 'bm90LWpzb24'), 'malformed claims'],
			'session token, no use'     => [$this->mint(overrides: ['use' => null]), 'not an assertion'],
			'wrong use and issuer'      => [$this->mint(overrides: ['use' => 'session', 'iss' => 'x']), 'not an assertion'],
			'wrong issuer'              => [$this->mint(overrides: ['iss' => 'procest']), 'unexpected issuer'],
			'wrong issuer and expired'  => [$this->mint(overrides: ['iss' => 'x', 'exp' => ($now - 1)]), 'unexpected issuer'],
			'missing exp'               => [$this->mint(overrides: ['exp' => null]), 'expired or missing exp'],
			'string exp'                => [$this->mint(overrides: ['exp' => (string)($now + 60)]), 'expired or missing exp'],
			'expired, no subject'       => [$this->mint(overrides: ['exp' => ($now - 1), 'sub' => null]), 'expired or missing exp'],
			'missing iat'               => [$this->mint(overrides: ['iat' => null]), 'implausible iat'],
			'iat beyond leeway'         => [$this->mint(overrides: ['iat' => ($now + 120), 'exp' => ($now + 200)]), 'implausible iat'],
			'iat after exp'             => [$this->mint(overrides: ['iat' => ($now + 30), 'exp' => ($now + 10)]), 'implausible iat'],
			'bad iat, no subject'       => [$this->mint(overrides: ['iat' => 'x', 'sub' => null]), 'implausible iat'],
			'missing subject'           => [$this->mint(overrides: ['sub' => null]), 'missing subject'],
			'empty subject'             => [$this->mint(overrides: ['sub' => '']), 'missing subject'],
			'numeric subject'           => [$this->mint(overrides: ['sub' => 42]), 'missing subject'],
		];

	}//end refusals()

	/**
	 * A verifier with a plain secret and a logger recording every reason.
	 *
	 * @param string $secret The signing secret.
	 *
	 * @return PortalAssertionVerifier
	 */
	private function verifier(string $secret): PortalAssertionVerifier {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context): void {
				$this->assertSame('Shillinq: portal assertion rejected', $message);
				$this->reasons[] = $context['reason'];
			}
		);

		return new PortalAssertionVerifier(secretOverride: $secret, logger: $logger);

	}//end verifier()

	/**
	 * Mint an HS256 assertion with claim overrides (null removes a claim).
	 *
	 * @param array  $overrides Claim overrides.
	 * @param string $alg       Header algorithm.
	 * @param string $secret    Signing secret.
	 *
	 * @return string Compact JWT.
	 */
	private function mint(array $overrides, string $alg = 'HS256', string $secret = self::SECRET): string {
		$now = time();
		$claims = ['sub' => 'subject-1', 'use' => 'assertion', 'iat' => $now, 'exp' => ($now + 60), 'iss' => 'portaliq'];
		foreach ($overrides as $claim => $value) {
			unset($claims[$claim]);
			if ($value !== null) {
				$claims[$claim] = $value;
			}
		}

		$hPart = $this->b64(bytes: (string)json_encode(['alg' => $alg, 'typ' => 'JWT']));
		$cPart = $this->b64(bytes: (string)json_encode($claims));

		return $hPart . '.' . $cPart . '.' . $this->b64(bytes: hash_hmac('sha256', $hPart . '.' . $cPart, $secret, true));

	}//end mint()

	/**
	 * A correctly signed HS256 token around a raw claims segment.
	 *
	 * @param string $cPart The encoded claims segment.
	 *
	 * @return string Compact JWT.
	 */
	private function signed(string $cPart): string {
		$hPart = $this->b64(bytes: (string)json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

		return $hPart . '.' . $cPart . '.' . $this->b64(bytes: hash_hmac('sha256', $hPart . '.' . $cPart, self::SECRET, true));

	}//end signed()

	/**
	 * Base64-url encode without padding.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

	}//end b64()
}//end class
