<?php

/**
 * Withdrawal Mailer
 *
 * Mails the consumer the acknowledgement of their withdrawal, with the date
 * and time it was received in their own time zone (sales-cancellation
 * REQ-SCX-006). Best effort: a failed mail is logged and answered false, the
 * withdrawal itself stands.
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
 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Booking;

use DateTimeImmutable;
use DateTimeZone;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The withdrawal acknowledgement mail.
 *
 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-3.3
 */
class WithdrawalMailer {

	/**
	 * Time zone used when the appointment names none.
	 */
	private const DEFAULT_TZID = 'Europe/Amsterdam';

	/**
	 * @param IMailer         $mailer Nextcloud's mailer.
	 * @param IL10N           $l10n   Translates the mail.
	 * @param LoggerInterface $logger Diagnostics; never logs the address.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail the acknowledgement of a withdrawal to the booker.
	 *
	 * @param array<string, mixed> $appointment The withdrawn appointment.
	 * @param DateTimeImmutable    $withdrawnAt The moment the withdrawal was received.
	 *
	 * @return bool Whether the mail server accepted it.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-3.3
	 */
	public function acknowledge(array $appointment, DateTimeImmutable $withdrawnAt): bool {
		$email = trim((string)($appointment['customerEmail'] ?? ''));
		if ($email === '') {
			return false;
		}

		$service = (string)($appointment['serviceName'] ?? '');
		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->setSubject($this->l10n->t('We received your withdrawal: %s', [$service]));
			$message->setPlainBody($this->body(appointment: $appointment, withdrawnAt: $withdrawnAt));
			$refused = $this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: the withdrawal acknowledgement could not be sent', ['exception' => $e->getMessage()]);
			return false;
		}

		return $refused === [];
	}//end acknowledge()

	/**
	 * The acknowledgement text: when the withdrawal was received, what was withdrawn and what follows.
	 *
	 * @param array<string, mixed> $appointment The withdrawn appointment.
	 * @param DateTimeImmutable    $withdrawnAt The moment the withdrawal was received.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-3.3
	 */
	public function body(array $appointment, DateTimeImmutable $withdrawnAt): string {
		$local = $withdrawnAt->setTimezone(self::timezone(name: (string)($appointment['customerTimezone'] ?? '')));
		$lines = [
			$this->l10n->t('We received your withdrawal from this booking on %1$s at %2$s.', [$local->format('Y-m-d'), $local->format('H:i')]),
			'',
			$this->l10n->t('Service: %s', [(string)($appointment['serviceName'] ?? '')]),
			$this->l10n->t('Appointment: %s', [(string)($appointment['startTime'] ?? '')]),
			'',
			$this->l10n->t('The booking is cancelled without a cancellation fee. Any payment you made for it is refunded in full within 14 days.'),
		];

		return implode("\n", $lines) . "\n";
	}//end body()

	/**
	 * A time zone by name, Europe/Amsterdam when it is empty or unknown.
	 *
	 * @param string $name The IANA name.
	 *
	 * @return DateTimeZone
	 */
	private static function timezone(string $name): DateTimeZone {
		try {
			return new DateTimeZone($name);
		} catch (Throwable $e) {
			return new DateTimeZone(self::DEFAULT_TZID);
		}
	}//end timezone()
}//end class
