<?php

/**
 * Confirmation Mailer
 *
 * Builds and sends the appointment confirmation email: the confirmation
 * details, the ICS calendar attachment, a fallback web link and the customer's
 * local timezone (REQ-BCF-003). It sends through Nextcloud's IMailer, the way
 * PaymentRequestActionController::send() mails a payment link.
 *
 * It used to hand the mail to integriq's CallService::send(), a method that
 * class never had, and then answered true after "logged for resend", so no
 * confirmation ever left the instance while the caller was told it had
 * (issue #1680). integriq has since decided plain mail is not its job
 * (ConductionNL/integriq#2221).
 *
 * Delivery is best-effort: failures are logged (never thrown) because the
 * ConfirmationToken already exists and the customer can request a resend
 * (REQ-BCF-009). send() answers true only when the mailer accepted the mail.
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
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the appointment confirmation email through Nextcloud's mailer.
 *
 * @spec openspec/specs/bookings-confirm-flow/spec.md
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
	 * @param IMailer $mailer Nextcloud's mailer.
	 * @param IURLGenerator $urlGenerator For building the confirmation web link.
	 * @param IcsService $icsService ICS calendar generator.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private IMailer $mailer,
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
	 * @return bool True when the mailer accepted the email, false when nothing was sent.
	 *
	 * @spec openspec/specs/bookings-confirm-flow/spec.md
	 */
	public function send(array $appointment, string $rawToken): bool {
		$email = (string)($appointment['customerEmail'] ?? '');
		if ($email === '') {
			$this->logger->info('Shillinq: appointment has no customer email; skipping confirmation email');
			return false;
		}

		$payload = $this->buildPayload(appointment: $appointment, rawToken: $rawToken, email: $email);

		return $this->dispatch(payload: $payload);
	}//end send()

	/**
	 * Build the email payload (subject, body, web link, timezone, ICS attachment).
	 *
	 * @param array<string,mixed> $appointment The appointment object array.
	 * @param string $rawToken The raw token string.
	 * @param string $email The recipient email.
	 *
	 * @return array<string,mixed> The email payload.
	 *
	 * @spec openspec/specs/bookings-confirm-flow/spec.md
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

		$timezone = (string)($appointment['customerTimezone'] ?? self::DEFAULT_TZID);

		return [
			'to' => $email,
			'subject' => '[Bookings] Confirmation needed: ' . ($appointment['serviceName'] ?? '') . ' on '
				. ($appointment['startTime'] ?? ''),
			'body' => $this->buildBody(appointment: $appointment, webLink: $webLink, timezone: $timezone),
			'webLink' => $webLink,
			'timezone' => $timezone,
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
	 * The plain-text body: the appointment, the time zone and the link to confirm.
	 *
	 * @param array<string,mixed> $appointment The appointment object array.
	 * @param string $webLink The confirmation web link.
	 * @param string $timezone The customer's time zone.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/specs/bookings-confirm-flow/spec.md
	 */
	private function buildBody(array $appointment, string $webLink, string $timezone): string {
		$lines = [
			'Please confirm your appointment.',
			'',
			'Service: ' . (string)($appointment['serviceName'] ?? ''),
			'Start: ' . (string)($appointment['startTime'] ?? ''),
			'Your time zone: ' . $timezone,
		];
		$location = (string)($appointment['location'] ?? '');
		if ($location !== '') {
			$lines[] = 'Location: ' . $location;
		}

		$lines[] = '';
		$lines[] = 'Confirm your appointment here: ' . $webLink;
		$lines[] = '';
		$lines[] = 'Open the attached appointment.ics to add the appointment to your calendar.';

		return implode("\n", $lines) . "\n";
	}//end buildBody()

	/**
	 * Send the payload through Nextcloud's mailer.
	 *
	 * @param array<string,mixed> $payload The email payload.
	 *
	 * @return bool True when the mailer accepted the email for every recipient, false otherwise.
	 *
	 * @spec openspec/specs/bookings-confirm-flow/spec.md
	 */
	private function dispatch(array $payload): bool {
		try {
			$message = $this->mailer->createMessage();
			$message->setTo([(string)$payload['to']]);
			$message->setSubject((string)$payload['subject']);
			$message->setPlainBody((string)$payload['body']);
			foreach ((array)$payload['attachments'] as $attachment) {
				$message->attach(
					$this->mailer->createAttachment(
						$attachment['content'],
						$attachment['filename'],
						$attachment['contentType']
					)
				);
			}

			$failedRecipients = $this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: confirmation email could not be sent: ' . $e->getMessage());
			return false;
		}

		if ($failedRecipients !== []) {
			$this->logger->warning(
				'Shillinq: the mail server refused the confirmation email',
				['failedRecipients' => count($failedRecipients)]
			);
			return false;
		}

		$this->logger->info('Shillinq: confirmation email sent', ['action' => 'confirmation_email_sent']);

		return true;
	}//end dispatch()
}//end class
