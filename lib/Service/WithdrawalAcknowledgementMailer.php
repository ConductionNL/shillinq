<?php

/**
 * Withdrawal Acknowledgement Mailer
 *
 * Mails the consumer the acknowledgement of a withdrawal, with the date and
 * time it was received and what happens to the payment (sales-cancellation
 * REQ-SCX-006).
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The acknowledgement mail of a withdrawal.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.3
 */
class WithdrawalAcknowledgementMailer {

	/**
	 * The time zone the moment is written in when the appointment names none.
	 *
	 * @var string
	 */
	private const DEFAULT_TZID = 'Europe/Amsterdam';

	/**
	 * Constructor.
	 *
	 * @param IMailer         $mailer Nextcloud's mailer.
	 * @param IL10N           $l10n   The mail's language.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail the acknowledgement of a withdrawal to the consumer.
	 *
	 * @param array<string, mixed> $appointment The withdrawn appointment.
	 * @param array<string, mixed> $withdrawal  The saved Withdrawal record.
	 * @param DateTimeImmutable    $now         The moment of the withdrawal.
	 *
	 * @return bool True when the mailer accepted it; false when there is no address or it failed.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.3
	 */
	public function send(array $appointment, array $withdrawal, DateTimeImmutable $now): bool {
		$email = trim((string)($appointment['customerEmail'] ?? ''));
		if ($email === '') {
			$this->logger->info('Shillinq: withdrawn appointment has no customer email; no acknowledgement mailed');
			return false;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->setSubject($this->l10n->t('Your withdrawal has been received'));
			$message->setPlainBody($this->body(appointment: $appointment, withdrawal: $withdrawal, now: $now));
			return $this->mailer->send($message) === [];
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: the withdrawal acknowledgement was not mailed', ['exception' => get_class($e)]);
			return false;
		}
	}//end send()

	/**
	 * The plain-text body: the appointment, the moment received and the refund.
	 *
	 * @param array<string, mixed> $appointment The appointment.
	 * @param array<string, mixed> $withdrawal  The Withdrawal record.
	 * @param DateTimeImmutable    $now         The moment of the withdrawal.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.3
	 */
	public function body(array $appointment, array $withdrawal, DateTimeImmutable $now): string {
		$zone = $this->zone(name: (string)($appointment['customerTimezone'] ?? ''));
		$local = $now->setTimezone($zone);
		$lines = [
			$this->l10n->t(
				'We received your withdrawal on %1$s at %2$s (%3$s).',
				[$local->format('Y-m-d'), $local->format('H:i'), $zone->getName()]
			),
			'',
			$this->l10n->t('Service: %s', [(string)($appointment['serviceName'] ?? '')]),
			$this->l10n->t('Appointment: %s', [(string)($appointment['startTime'] ?? '')]),
			$this->l10n->t('The appointment is cancelled without a cancellation fee.'),
		];

		$amount = (int)($withdrawal['refundAmount'] ?? 0);
		if ($amount > 0) {
			$euros = number_format(($amount / 100), 2, ',', '.');
			$lines[] = match ((string)($withdrawal['refundStatus'] ?? '')) {
				'initiated' => $this->l10n->t('Your payment of EUR %s is being refunded in full.', [$euros]),
				default => $this->l10n->t(
					'Your payment of EUR %1$s will be refunded in full, no later than %2$s.',
					[$euros, (string)($withdrawal['refundDueBy'] ?? '')]
				),
			};
		}

		return implode("\n", $lines) . "\n";
	}//end body()

	/**
	 * The appointment's time zone, or the default when it names none or an unknown one.
	 *
	 * @param string $name The time zone name.
	 *
	 * @return DateTimeZone The zone.
	 */
	private function zone(string $name): DateTimeZone {
		if ($name === '') {
			$name = self::DEFAULT_TZID;
		}

		try {
			return new DateTimeZone($name);
		} catch (Throwable $e) {
			return new DateTimeZone(self::DEFAULT_TZID);
		}
	}//end zone()
}//end class
