<?php

/**
 * Confirmation Token Service.
 *
 * Encapsulates the lifecycle of ConfirmationToken records: creation on
 * appointment.create (REQ-BCF-001), revoke + reissue on customer resend
 * (REQ-BCF-006), validate-and-redeem on confirmation (REQ-BCF-004), and
 * dispatch of the confirmation email + ICS attachment through
 * openconnector (REQ-BCF-003).
 *
 * All persistence flows through OpenRegister via the runtime container:
 *
 *   $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')
 *       ->setRegister(<slug>)->setSchema('ConfirmationToken')
 *       ->saveObject(...) | ->findAll(...) | ->updateObject(...);
 *
 * — the real OR API surface (find / findAll / saveObject / createObject
 * / updateObject / deleteObject); no invented `createFromArray` /
 * `deleteFromId` (cf. reference_or-objectservice-api).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Booking;

use DateTimeZone;
use OCA\Shillinq\Service\IcsService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\TokenValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Confirmation token lifecycle and confirmation-email dispatcher.
 *
 * The service deliberately avoids any direct SMTP. Per ADR-022 + design D4
 * email delivery is routed through OpenConnector's email channel which the
 * service looks up lazily from the container — when openconnector is not
 * installed (dev / smoke), the service logs the prepared payload and
 * returns success so the rest of the flow remains exercisable.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-11
 */
class ConfirmationTokenService {

	/**
	 * Default confirmation deadline: 48 hours after appointment creation
	 * (design.md D6 "48–72 hours is typical").
	 */
	public const DEFAULT_DEADLINE_SECONDS = (48 * 60 * 60);

	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy OR /
	 *                                      openconnector resolution.
	 * @param SettingsService $settings Shillinq settings (register slug,
	 *                                  OR availability).
	 * @param IcsService $ics ICS generator for the email
	 *                        attachment.
	 * @param ITimeFactory $time Server clock (test-friendly).
	 * @param IURLGenerator $urls Absolute URL builder for the web
	 *                            confirmation portal.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly IcsService $ics,
		private readonly ITimeFactory $time,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Issue a fresh token for an appointment.
	 *
	 * @param string $appointmentId Appointment business-key.
	 * @param string $createdBy Actor that triggered creation ('system',
	 *                          admin or customer id).
	 *
	 * @return array{tokenId:string,plaintext:string,record:array<string,mixed>}
	 *                                                                           The new record (as OR returned it) and the plaintext token —
	 *                                                                           the plaintext MUST be discarded immediately after dispatch
	 *                                                                           and is NEVER persisted alongside the hash.
	 */
	public function issueToken(string $appointmentId, string $createdBy = 'system'): array {
		$now = $this->nowIso();
		$plaintext = TokenValidator::generate();
		$hash = TokenValidator::hash($plaintext);
		$tokenId = $this->buildTokenId();

		$payload = [
			'tokenId' => $tokenId,
			'appointmentId' => $appointmentId,
			'tokenString' => $hash,
			'expiresAt' => TokenValidator::expiresAtFor($now),
			'status' => 'active',
			'createdAt' => $now,
			'createdBy' => $createdBy,
		];

		$record = $this->saveToken(token: $payload);
		return [
			'tokenId' => $tokenId,
			'plaintext' => $plaintext,
			'record' => $record,
		];

	}//end issueToken()

	/**
	 * Generate a token + persist it + send the confirmation email. Used by
	 * appointment.create flow (REQ-BCF-001/003).
	 *
	 * @param array<string, mixed> $appointment Appointment record (must
	 *                                          contain `appointmentId`,
	 *                                          `startTime`, `endTime`;
	 *                                          `customerId`, `serviceName`,
	 *                                          `customerEmail` optional).
	 * @param array<string, mixed> $customer Customer descriptor — at
	 *                                       minimum `id` and `email`.
	 *                                       Optional `userId`, `name`.
	 *
	 * @return array{tokenId:string,sent:bool} Outcome — `sent` is FALSE
	 *                                         when openconnector is unavailable (email is logged for
	 *                                         operator review).
	 */
	public function issueAndSend(array $appointment, array $customer): array {
		$appointmentId = (string)($appointment['appointmentId'] ?? '');
		if ($appointmentId === '') {
			return [
				'tokenId' => '',
				'sent' => false,
			];
		}

		$issued = $this->issueToken(appointmentId: $appointmentId);
		$confirmUrl = $this->buildConfirmUrl(appointmentId: $appointmentId, plaintext: $issued['plaintext']);
		$context = [
			'serviceName' => (string)($appointment['serviceName'] ?? 'Appointment'),
			'location' => (string)($appointment['location'] ?? ''),
			'organizerEmail' => (string)($appointment['organizerEmail'] ?? ''),
		];

		$ics = $this->ics->generateIcs(
			appointment: $appointment,
			customer: $customer,
			confirmUrl: $confirmUrl,
			context: $context,
		);
		$sent = $this->dispatchEmail(
			customer: $customer,
			appointment: $appointment,
			confirmUrl: $confirmUrl,
			ics: $ics,
		);

		return [
			'tokenId' => $issued['tokenId'],
			'sent' => $sent,
		];

	}//end issueAndSend()

	/**
	 * Validate a presented (plaintext) token against the active token for
	 * the given appointment without mutating any state.
	 *
	 * @param string $appointmentId Appointment id.
	 * @param string $plaintext Plaintext token from the URL / portal.
	 *
	 * @return array{ok:bool,reason:string,token?:array<string,mixed>} Status — `ok`
	 *                                                                 TRUE iff the token verifies, is active and not expired. `reason`
	 *                                                                 encodes the failure mode for the controller to map onto an HTTP
	 *                                                                 status (REQ-BCF-004 scenarios).
	 */
	public function validate(string $appointmentId, string $plaintext): array {
		if ($appointmentId === '' || $plaintext === '') {
			return [
				'ok' => false,
				'reason' => 'invalid_input',
			];
		}

		$token = $this->findActiveTokenForAppointment(appointmentId: $appointmentId);
		if ($token === null) {
			return [
				'ok' => false,
				'reason' => 'not_found',
			];
		}

		$status = (string)($token['status'] ?? '');
		if ($status === 'redeemed') {
			return [
				'ok' => false,
				'reason' => 'already_redeemed',
				'token' => $token,
			];
		}

		if ($status === 'revoked') {
			return [
				'ok' => false,
				'reason' => 'revoked',
				'token' => $token,
			];
		}

		if ($status === 'expired'
			|| TokenValidator::isExpired((string)($token['expiresAt'] ?? ''), $this->nowIso()) === true
		) {
			return [
				'ok' => false,
				'reason' => 'expired',
				'token' => $token,
			];
		}

		if (TokenValidator::verify($plaintext, (string)($token['tokenString'] ?? '')) === false) {
			return [
				'ok' => false,
				'reason' => 'invalid',
				'token' => $token,
			];
		}

		return [
			'ok' => true,
			'reason' => 'ok',
			'token' => $token,
		];

	}//end validate()

	/**
	 * Mark the given token as redeemed.
	 *
	 * @param array<string, mixed> $token OR record for the token to redeem.
	 *
	 * @return array<string, mixed> The updated record.
	 */
	public function markRedeemed(array $token): array {
		$now = $this->nowIso();
		$token['status'] = 'redeemed';
		$token['redeemedAt'] = $now;
		return $this->saveToken(token: $token);
	}//end markRedeemed()

	/**
	 * Revoke the active token for an appointment (REQ-BCF-006). Returns the
	 * record or NULL if no active token exists.
	 *
	 * @param string $appointmentId Appointment id.
	 *
	 * @return array<string, mixed>|null The updated record, or NULL.
	 */
	public function revokeActiveForAppointment(string $appointmentId): ?array {
		$token = $this->findActiveTokenForAppointment(appointmentId: $appointmentId);
		if ($token === null) {
			return null;
		}

		$token['status'] = 'revoked';
		return $this->saveToken(token: $token);
	}//end revokeActiveForAppointment()

	/**
	 * Resend the confirmation email — revoke current token, issue a new one
	 * + dispatch (REQ-BCF-006).
	 *
	 * @param array<string, mixed> $appointment Appointment record.
	 * @param array<string, mixed> $customer Customer descriptor.
	 * @param string $actor User id that triggered resend
	 *                      (the customer or an admin).
	 *
	 * @return array{tokenId:string,sent:bool} Outcome — same shape as
	 *                                         {@see ConfirmationTokenService::issueAndSend()}.
	 */
	public function resend(array $appointment, array $customer, string $actor = 'system'): array {
		$appointmentId = (string)($appointment['appointmentId'] ?? '');
		if ($appointmentId === '') {
			return [
				'tokenId' => '',
				'sent' => false,
			];
		}

		$this->revokeActiveForAppointment(appointmentId: $appointmentId);
		$issued = $this->issueToken(appointmentId: $appointmentId, createdBy: $actor);
		$confirmUrl = $this->buildConfirmUrl(appointmentId: $appointmentId, plaintext: $issued['plaintext']);
		$context = [
			'serviceName' => (string)($appointment['serviceName'] ?? 'Appointment'),
			'location' => (string)($appointment['location'] ?? ''),
			'organizerEmail' => (string)($appointment['organizerEmail'] ?? ''),
		];

		$ics = $this->ics->generateIcs(
			appointment: $appointment,
			customer: $customer,
			confirmUrl: $confirmUrl,
			context: $context,
		);
		$sent = $this->dispatchEmail(
			customer: $customer,
			appointment: $appointment,
			confirmUrl: $confirmUrl,
			ics: $ics,
		);

		return [
			'tokenId' => $issued['tokenId'],
			'sent' => $sent,
		];

	}//end resend()

	/**
	 * Build the absolute web-portal URL pre-loaded with a plaintext token.
	 *
	 * @param string $appointmentId Appointment id (path segment).
	 * @param string $plaintext Plaintext token to embed as ?token=...
	 *
	 * @return string Absolute URL.
	 */
	public function buildConfirmUrl(string $appointmentId, string $plaintext): string {
		$relative = $this->urls->linkToRoute('shillinq.dashboard.page')
			. 'confirm/' . rawurlencode($appointmentId) . '?token=' . rawurlencode($plaintext);
		return $this->urls->getAbsoluteURL($relative);
	}//end buildConfirmUrl()

	/**
	 * Locate the active ConfirmationToken for an appointment (REQ-BCF-006
	 * picks the first active row when more than one exists).
	 *
	 * @param string $appointmentId Appointment id.
	 *
	 * @return array<string, mixed>|null OR record (flat array) or NULL.
	 */
	public function findActiveTokenForAppointment(string $appointmentId): ?array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$records = $objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('ConfirmationToken')
				->findAll(
					[
						'filters' => [
							'appointmentId' => $appointmentId,
							'status' => 'active',
						],
						'limit' => 5,
					]
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'ConfirmationTokenService: lookup failed',
				['exception' => $e->getMessage(), 'appointmentId' => $appointmentId]
			);
			return null;
		}//end try

		foreach ($records as $record) {
			return $this->toArray(object: $record);
		}

		return null;
	}//end findActiveTokenForAppointment()

	/**
	 * Persist a token record via OR's saveObject contract.
	 *
	 * @param array<string, mixed> $token Token payload (with or without `id`).
	 *
	 * @return array<string, mixed> The saved record (flat array).
	 */
	private function saveToken(array $token): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			// Dev fallback: return the payload unchanged so callers can keep
			// exercising the flow when OR is not wired up (smoke / unit).
			return $token;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$saved = $objectService->saveObject(
				object: $token,
				register: $this->settings->getRegisterSlug(),
				schema: 'ConfirmationToken',
			);
			return $this->toArray(object: $saved);
		} catch (Throwable $e) {
			$this->logger->error(
				'ConfirmationTokenService: save failed',
				['exception' => $e->getMessage(), 'tokenId' => (string)($token['tokenId'] ?? '')]
			);
			return $token;
		}

	}//end saveToken()

	/**
	 * Dispatch the confirmation email through openconnector when available
	 * — fall back to logging the prepared payload otherwise so the
	 * confirmation flow remains observable.
	 *
	 * @param array<string, mixed> $customer Customer descriptor.
	 * @param array<string, mixed> $appointment Appointment record.
	 * @param string $confirmUrl Absolute web-portal URL.
	 * @param string $ics ICS payload (may be empty).
	 *
	 * @return bool TRUE when the email was handed off to openconnector,
	 *              FALSE when only logged.
	 */
	private function dispatchEmail(array $customer, array $appointment, string $confirmUrl, string $ics): bool {
		$email = (string)($customer['email'] ?? '');
		if ($email === '') {
			$this->logger->warning(
				'ConfirmationTokenService: no customer email; skipping dispatch.',
				['appointmentId' => (string)($appointment['appointmentId'] ?? '')]
			);
			return false;
		}

		$payload = [
			'to' => $email,
			'subject' => 'Confirmation needed: ' . ((string)($appointment['serviceName'] ?? 'your appointment'))
							. ' on ' . ((string)($appointment['startTime'] ?? '')),
			'template' => 'BookingConfirmationTemplate',
			'variables' => [
				'customerName' => (string)($customer['name'] ?? ''),
				'appointmentDate' => (string)($appointment['startTime'] ?? ''),
				'confirmUrl' => $confirmUrl,
				'serviceName' => (string)($appointment['serviceName'] ?? ''),
				'location' => (string)($appointment['location'] ?? ''),
			],
			'attachments' => [
				[
					'filename' => 'appointment.ics',
					'contentType' => 'text/calendar; charset=utf-8',
					'content' => $ics,
				],
			],
		];

		try {
			if ($this->container->has('OCA\\OpenConnector\\Service\\NotificationDispatcher') === true) {
				$dispatcher = $this->container->get('OCA\\OpenConnector\\Service\\NotificationDispatcher');
				if (method_exists($dispatcher, 'dispatch') === true) {
					$dispatcher->dispatch($payload);
					return true;
				}
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'ConfirmationTokenService: openconnector dispatch failed',
				['exception' => $e->getMessage()]
			);
		}

		$this->logger->info(
			'ConfirmationTokenService: openconnector unavailable; prepared email payload only.',
			[
				'to' => $email,
				'subject' => $payload['subject'],
			]
		);
		return false;
	}//end dispatchEmail()

	/**
	 * Generate a sortable token id (tok-YYYYMMDDHHMMSS-rrrr).
	 *
	 * @return string Token id.
	 */
	private function buildTokenId(): string {
		return 'tok-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(2));
	}//end buildTokenId()

	/**
	 * Current server time as ISO 8601 UTC.
	 *
	 * @return string ISO 8601 UTC ("...Z").
	 */
	private function nowIso(): string {
		return $this->time->getDateTime()
			->setTimezone(new DateTimeZone('UTC'))
			->format('Y-m-d\TH:i:s\Z');

	}//end nowIso()

	/**
	 * Normalise an OR record (Entity, array, or JSON-serialisable) into a
	 * flat array.
	 *
	 * @param mixed $object OR ObjectService payload.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'getObject') === true) {
				$inner = $object->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}

			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
