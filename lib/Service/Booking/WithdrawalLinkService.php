<?php

/**
 * Withdrawal Link Service
 *
 * Signs and verifies the link that lets a consumer without a portal account
 * reach the withdrawal of one appointment (sales-cancellation REQ-SCX-004,
 * design D4). The token carries the appointment id and the moment the
 * withdrawal period ends, signed with an HMAC keyed from the instance secret;
 * it opens that appointment only and stops working when the period ends.
 *
 * The link points at the portal's guest page, configured as the https app
 * value `withdrawal_link_page_url`; without it no link is offered.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Booking;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\WithdrawalService;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Signed withdrawal links for one appointment.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 */
class WithdrawalLinkService {

	/**
	 * App-config key of the portal's guest page for the withdraw action.
	 */
	public const PAGE_KEY = 'withdrawal_link_page_url';

	/**
	 * Key derivation context, so this key signs nothing else.
	 */
	private const CONTEXT = 'shillinq-withdrawal-link';

	/**
	 * What an appointment id may look like.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * @param IConfig           $config      The instance secret.
	 * @param IAppConfig        $appConfig   The guest page URL.
	 * @param WithdrawalService $withdrawals When an appointment's withdrawal period ends.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly WithdrawalService $withdrawals,
	) {
	}//end __construct()

	/**
	 * The token for one appointment, valid until $expiresAt.
	 *
	 * @param string $appointmentId The appointment's id.
	 * @param int    $expiresAt     Unix time the withdrawal period ends.
	 *
	 * @return string|null Null for a malformed id or an instance without a secret.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
	 */
	public function tokenFor(string $appointmentId, int $expiresAt): ?string {
		$key = $this->key();
		if ($key === null || preg_match(self::ID_PATTERN, $appointmentId) !== 1) {
			return null;
		}

		$payload = self::encode(data: (string)json_encode(['i' => $appointmentId, 'e' => $expiresAt]));

		return $payload . '.' . self::encode(data: hash_hmac('sha256', $payload, $key, true));
	}//end tokenFor()

	/**
	 * The guest page link for an appointment booked online, valid for its withdrawal period.
	 *
	 * @param array<string, mixed> $appointment The appointment, with its id, `source` and `@self.created`.
	 *
	 * @return string|null Null when the appointment was not booked online, its booking moment is
	 *                     unknown, or no https guest page is configured.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.3
	 */
	public function forAppointment(array $appointment): ?string {
		$page = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::PAGE_KEY, ''));
		$periodEnd = $this->withdrawals->periodEndFor(appointment: $appointment);
		if ($page === '' || preg_match('#^https://#i', $page) !== 1 || (string)($appointment['source'] ?? '') !== 'widget' || $periodEnd === null) {
			return null;
		}

		$token = $this->tokenFor(
			appointmentId: (string)($appointment['id'] ?? ($appointment['appointmentId'] ?? '')),
			expiresAt: $periodEnd->getTimestamp()
		);
		if ($token === null) {
			return null;
		}

		$separator = '?';
		if (str_contains($page, '?') === true) {
			$separator = '&';
		}

		return $page . $separator . 'withdraw=' . rawurlencode($token);
	}//end forAppointment()

	/**
	 * The last day a withdrawal of the appointment is accepted, as Y-m-d.
	 *
	 * @param array<string, mixed> $appointment The appointment.
	 *
	 * @return string|null Null when its booking moment is unknown.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.3
	 */
	public function lastDayFor(array $appointment): ?string {
		$periodEnd = $this->withdrawals->periodEndFor(appointment: $appointment);
		if ($periodEnd === null) {
			return null;
		}

		return $periodEnd->modify('-1 day')->format('Y-m-d');
	}//end lastDayFor()

	/**
	 * The appointment a token opens, or null when it is tampered with, malformed or expired.
	 *
	 * @param string            $token The token from the link.
	 * @param DateTimeImmutable $now   The moment of the request.
	 *
	 * @return string|null The appointment id.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
	 */
	public function verify(string $token, DateTimeImmutable $now): ?string {
		$parts = explode('.', trim($token));
		$key = $this->key();
		if (count($parts) !== 2 || $key === null) {
			return null;
		}

		$signature = self::decode(data: $parts[1]);
		if ($signature === null || hash_equals(hash_hmac('sha256', $parts[0], $key, true), $signature) === false) {
			return null;
		}

		$claims = json_decode((string)self::decode(data: $parts[0]), true);
		if (is_array($claims) === false || is_string($claims['i'] ?? null) === false || is_int($claims['e'] ?? null) === false) {
			return null;
		}

		if (preg_match(self::ID_PATTERN, $claims['i']) !== 1 || $now->getTimestamp() >= $claims['e']) {
			return null;
		}

		return $claims['i'];
	}//end verify()

	/**
	 * The signing key, derived from the instance secret.
	 *
	 * @return string|null Null when the instance has no secret.
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
	 * Decode base64url, null when it is not.
	 *
	 * @param string $data The encoded text.
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
