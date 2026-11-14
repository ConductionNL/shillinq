<?php

/**
 * Withdrawal Link Signer
 *
 * Signs and verifies the link that opens the withdrawal function for one
 * appointment without a portal account (sales-cancellation REQ-SCX-004,
 * design D4): an HMAC over the appointment id and the expiry, keyed from the
 * instance secret, expiring when the withdrawal period ends.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCP\IConfig;

/**
 * Signs and verifies one appointment's withdrawal link.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 */
class WithdrawalLinkSigner {

	/**
	 * Separates this use of the instance secret from every other.
	 *
	 * @var string
	 */
	private const CONTEXT = 'shillinq-withdrawal-link';

	/**
	 * The appointment ids a link may name.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IConfig $config The instance secret.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * The token for one appointment, valid until the given moment.
	 *
	 * @param string            $appointmentId The appointment's id.
	 * @param DateTimeImmutable $expiresAt     The end of the withdrawal period.
	 *
	 * @return string|null The token, or null for an unusable id or without an instance secret.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
	 */
	public function tokenFor(string $appointmentId, DateTimeImmutable $expiresAt): ?string {
		$key = $this->key();
		if ($key === null || preg_match(self::ID_PATTERN, $appointmentId) !== 1) {
			return null;
		}

		$payload = self::encode(data: $appointmentId . '|' . $expiresAt->getTimestamp());

		return $payload . '.' . self::encode(data: hash_hmac('sha256', $payload, $key, true));
	}//end tokenFor()

	/**
	 * The appointment a token opens, or null when it is malformed, signed
	 * with another key, altered, or expired.
	 *
	 * @param string            $token The token from the link.
	 * @param DateTimeImmutable $now   The moment the link is used.
	 *
	 * @return string|null The appointment id.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
	 */
	public function verify(string $token, DateTimeImmutable $now): ?string {
		$parts = explode('.', trim($token));
		$key = $this->key();
		if ($key === null || count($parts) !== 2) {
			return null;
		}

		$signature = self::decode(data: $parts[1]);
		if ($signature === null || hash_equals(hash_hmac('sha256', $parts[0], $key, true), $signature) === false) {
			return null;
		}

		$fields = explode('|', (string)self::decode(data: $parts[0]));
		if (count($fields) !== 2 || preg_match(self::ID_PATTERN, $fields[0]) !== 1 || ctype_digit($fields[1]) === false) {
			return null;
		}

		if ((int)$fields[1] < $now->getTimestamp()) {
			return null;
		}

		return $fields[0];
	}//end verify()

	/**
	 * The signing key, or null without an instance secret.
	 *
	 * @return string|null
	 */
	private function key(): ?string {
		$secret = (string)$this->config->getSystemValueString('secret', '');
		if ($secret === '') {
			return null;
		}

		return hash_hmac('sha256', self::CONTEXT, $secret, true);
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
