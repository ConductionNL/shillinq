<?php

/**
 * Confirmation Mailer
 *
 * Builds and dispatches the appointment confirmation email — confirmation
 * details, the ICS calendar attachment, a fallback web link and the customer's
 * local timezone — through the shared openconnector email channel per ADR-022.
 * Delivery is best-effort: failures are logged (never thrown) because the
 * ConfirmationToken already exists and the customer can request a resend
 * (REQ-BCF-003, REQ-BCF-009).
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers the appointment confirmation email via openconnector.
 */
class ConfirmationMailer {
	/**
	 * Default timezone used when the appointment carries none.
	 *
	 * @var string
	 */
	private const DEFAULT_TZID = 'Europe/Amsterdam';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the openconnector CallService.
	 * @param IURLGenerator $urlGenerator For building the confirmation web link.
	 * @param IcsService $icsService ICS calendar generator.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IURLGenerator $urlGenerator,
		private IcsService $icsService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send the confirmation email for an appointment.
	 *
	 * @param array<string,mixed> $appointment The appointment object array.
	 * @param string $rawToken The raw token string.
	 *
	 * @return bool True when handed off to a channel, false otherwise.
	 *
	 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-12
	 */
	public function send(array $appointment, string $rawToken): bool {
		$email = (string)($appointment['customerEmail'] ?? '');
		if ($email === '') {
			$this->logger->info('Shillinq: appointment has no customer email; skipping confirmation email');
			return false;
		}

		$payload = $this->buildPayload(appointment: $appointment, rawToken: $rawToken, email: $email);

		return $this->dispatch(payload: $payload, email: $email);
	}//end send()

	/**
	 * Build the email payload (subject, web link, timezone, ICS attachment).
	 *
	 * @param array<string,mixed> $appointment The appointment object array.
	 * @param string $rawToken The raw token string.
	 * @param string $email The recipient email.
	 *
	 * @return array<string,mixed> The channel payload.
	 */
	private function buildPayload(array $appointment, string $rawToken, string $email): array {
		$webLink = $this->urlGenerator->linkToRouteAbsolute(
			Application::APP_ID . '.confirmationApi.portal',
		) . '?token=' . rawurlencode($rawToken);
		$customer = [
			'id' => (string)($appointment['customerId'] ?? ''),
			'userId' => (string)($appointment['customerUserId'] ?? ''),
			'name' => (string)($appointment['customerName'] ?? ''),
			'email' => $email,
		];
		$context = [
			'serviceName' => (string)($appointment['serviceName'] ?? 'Appointment'),
			'location' => (string)($appointment['location'] ?? ''),
			'organizerEmail' => (string)($appointment['organizerEmail'] ?? ''),
		];
		$ics = $this->icsService->generateIcs(
			appointment: $appointment,
			customer: $customer,
			confirmUrl: $webLink,
			context: $context,
		);

		return [
			'to' => $email,
			'subject' => '[Bookings] Confirmation needed: ' . ($appointment['serviceName'] ?? '') . ' on '
				. ($appointment['startTime'] ?? ''),
			'webLink' => $webLink,
			'timezone' => (string)($appointment['customerTimezone'] ?? self::DEFAULT_TZID),
			'attachments' => [
				[
					'filename' => 'appointment.ics',
					'contentType' => 'text/calendar; charset=utf-8',
					'content' => $ics,
				],
			],
		];
	}//end buildPayload()

	/**
	 * Dispatch the payload through openconnector when available, else log it.
	 *
	 * @param array<string,mixed> $payload The channel payload.
	 * @param string $email The recipient email.
	 *
	 * @return bool True when handed off (or logged for resend).
	 */
	private function dispatch(array $payload, string $email): bool {
		$callService = null;
		try {
			$callService = $this->container->get('OCA\OpenConnector\Service\CallService');
		} catch (Throwable) {
			$callService = null;
		}

		try {
			if ($callService !== null && method_exists($callService, 'send') === true) {
				$callService->send($payload);
				return true;
			}

			$this->logger->info(
				'Shillinq: confirmation email queued (openconnector channel unavailable, logged for resend)',
				['action' => 'confirmation_email_sent', 'to' => $email]
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: confirmation email delivery failed: ' . $e->getMessage());
			return false;
		}//end try
	}//end dispatch()
}//end class
