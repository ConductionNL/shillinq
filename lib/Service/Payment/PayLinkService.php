<?php

/**
 * Pay Link Service
 *
 * The signed pay link an invoice carries (receivables-payment-links D4): a
 * token over the invoice id and its administration, signed with a key derived
 * from the instance secret and the administration's current key generation.
 * It does not expire: it stays valid until the invoice is paid or written off,
 * which the endpoint that receives it checks. Rotating an administration's
 * generation invalidates every link it handed out.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payment;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Signs, verifies and rotates the pay link tokens of an administration.
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.1
 */
class PayLinkService {

	/**
	 * Separates this use of the instance secret from every other.
	 *
	 * @var string
	 */
	private const CONTEXT = 'shillinq-pay-link';

	/**
	 * App config key prefix of an administration's key generation.
	 *
	 * @var string
	 */
	private const GENERATION_KEY = 'pay_link_generation_';

	/**
	 * App config key of the portal page a pay link opens.
	 *
	 * @var string
	 */
	public const PAGE_KEY = 'pay_link_page_url';

	/**
	 * The ids a token may carry.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IConfig    $config    The instance secret.
	 * @param IAppConfig $appConfig The key generations and the pay page.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The signed token for one invoice of one administration, or null when it cannot be signed.
	 *
	 * @param string $administrationId The administration.
	 * @param string $invoiceId        The ARInvoice id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.1
	 */
	public function tokenFor(string $administrationId, string $invoiceId): ?string {
		if (preg_match(self::ID_PATTERN, $administrationId) !== 1 || preg_match(self::ID_PATTERN, $invoiceId) !== 1) {
			return null;
		}

		$generation = $this->generation(administrationId: $administrationId);
		$key        = $this->key(administrationId: $administrationId, generation: $generation);
		if ($key === null) {
			return null;
		}

		$payload = self::encode(data: (string)json_encode(['a' => $administrationId, 'i' => $invoiceId, 'g' => $generation]));
		return $payload . '.' . self::encode(data: hash_hmac('sha256', $payload, $key, true));
	}//end tokenFor()

	/**
	 * The pay URL for an invoice: the portal's pay page with the token, or null without a page or a token.
	 *
	 * @param array<string, mixed> $invoice The ARInvoice, with its id and administrationId.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/receivables-payment-links/specs/ar-invoice-payment-links/spec.md#requirement-the-invoice-carries-a-pay-link-that-works-until-it-is-paid-req-rpl-004
	 */
	public function forInvoice(array $invoice): ?string {
		$page = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::PAGE_KEY, ''));
		if ($page === '' || preg_match('#^https://#i', $page) !== 1) {
			return null;
		}

		$token = $this->tokenFor(
			administrationId: (string)($invoice['administrationId'] ?? ''),
			invoiceId: (string)($invoice['id'] ?? ($invoice['uuid'] ?? ''))
		);
		if ($token === null) {
			return null;
		}

		$separator = '?';
		if (str_contains($page, '?') === true) {
			$separator = '&';
		}

		return $page . $separator . 'pay=' . rawurlencode($token);
	}//end forInvoice()

	/**
	 * What a token was signed for, or null when it does not verify.
	 *
	 * Refused: a malformed token, a broken signature, a token of a rotated
	 * generation, and, when an administration is expected, a token of another.
	 *
	 * @param string      $token                    The token.
	 * @param string|null $expectedAdministrationId The administration it must belong to, or null for any.
	 *
	 * @return array{administrationId: string, invoiceId: string}|null
	 *
	 * @spec openspec/changes/receivables-payment-links/specs/portal-payment-initiation/spec.md#requirement-a-signed-pay-link-is-accepted-from-the-portals-guest-page-req-rpl-006
	 */
	public function verify(string $token, ?string $expectedAdministrationId=null): ?array {
		$parts = explode('.', trim($token));
		if (count($parts) !== 2) {
			return null;
		}

		$claims = json_decode((string)self::decode(data: $parts[0]), true);
		if (is_array($claims) === false || is_string($claims['a'] ?? null) === false || is_string($claims['i'] ?? null) === false
			|| is_int($claims['g'] ?? null) === false
		) {
			return null;
		}

		$administrationId = $claims['a'];
		if (preg_match(self::ID_PATTERN, $administrationId) !== 1 || preg_match(self::ID_PATTERN, $claims['i']) !== 1
			|| ($expectedAdministrationId !== null && $expectedAdministrationId !== $administrationId)
			|| $claims['g'] !== $this->generation(administrationId: $administrationId)
		) {
			return null;
		}

		$key       = $this->key(administrationId: $administrationId, generation: $claims['g']);
		$signature = self::decode(data: $parts[1]);
		if ($key === null || $signature === null || hash_equals(hash_hmac('sha256', $parts[0], $key, true), $signature) === false) {
			return null;
		}

		return ['administrationId' => $administrationId, 'invoiceId' => $claims['i']];
	}//end verify()

	/**
	 * Invalidate every pay link an administration handed out.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return int The new key generation.
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.1
	 */
	public function rotate(string $administrationId): int {
		$next = ($this->generation(administrationId: $administrationId) + 1);
		$this->appConfig->setValueInt(AppIdentity::APP_ID, self::GENERATION_KEY . $administrationId, $next);
		return $next;
	}//end rotate()

	/**
	 * An administration's current key generation.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return int
	 */
	private function generation(string $administrationId): int {
		return $this->appConfig->getValueInt(AppIdentity::APP_ID, self::GENERATION_KEY . $administrationId, 0);
	}//end generation()

	/**
	 * The signing key of one generation of an administration, or null without an instance secret.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $generation       The key generation.
	 *
	 * @return string|null
	 */
	private function key(string $administrationId, int $generation): ?string {
		$secret = (string)$this->config->getSystemValueString('secret', '');
		if ($secret === '') {
			return null;
		}

		return hash_hmac('sha256', self::CONTEXT . '|' . $administrationId . '|' . $generation, $secret, true);
	}//end key()

	/**
	 * Base64url without padding.
	 *
	 * @param string $data The bytes.
	 *
	 * @return string
	 */
	private static function encode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}//end encode()

	/**
	 * Base64url decoded, or null when it is not.
	 *
	 * @param string $data The text.
	 *
	 * @return string|null
	 */
	private static function decode(string $data): ?string {
		if (preg_match('/^[A-Za-z0-9_-]+$/', $data) !== 1) {
			return null;
		}

		$bytes = base64_decode(strtr($data, '-_', '+/'), true);
		if ($bytes === false) {
			return null;
		}

		return $bytes;
	}//end decode()
}//end class
