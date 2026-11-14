<?php

/**
 * Withdrawal Service
 *
 * Whether a consumer may still withdraw from a booking made online, and why
 * not when they may not (sales-cancellation REQ-SCX-005, design D5).
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\IL10N;
use Throwable;

/**
 * The withdrawal rule.
 *
 * An appointment is withdrawable when a consumer booked it through the booking
 * widget, fewer than 14 days have passed since it was booked, it has not
 * started, it is still booked, and its service is not marked exempt with a
 * named reason. Every "no" names its reason, so the portal can say why the
 * button is absent and offer the ordinary cancellation instead.
 *
 * A widget booking carries a name, an e-mail address and a phone number and no
 * company or VAT number, so a booking through the widget is a consumer's.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
 */
class WithdrawalService {

	/**
	 * The withdrawal period for a service, in days from the booking (Directive 2011/83/EU, article 9).
	 *
	 * @var int
	 */
	public const PERIOD_DAYS = 14;

	/**
	 * Withdrawable.
	 *
	 * @var string
	 */
	public const WITHDRAWABLE = 'withdrawable';

	/**
	 * Not booked online through the widget.
	 *
	 * @var string
	 */
	public const NOT_ONLINE = 'not-booked-online';

	/**
	 * The withdrawal period has ended.
	 *
	 * @var string
	 */
	public const PERIOD_ENDED = 'period-ended';

	/**
	 * The appointment has started.
	 *
	 * @var string
	 */
	public const STARTED = 'started';

	/**
	 * The appointment is no longer booked (cancelled or completed).
	 *
	 * @var string
	 */
	public const NOT_BOOKED = 'not-booked';

	/**
	 * The service is exempt from withdrawal.
	 *
	 * @var string
	 */
	public const EXEMPT = 'exempt';

	/**
	 * The appointment states that are still booked.
	 *
	 * @var list<string>
	 */
	private const BOOKED_STATES = ['pending_confirmation', 'confirmed'];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the reasons.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Whether the appointment can still be withdrawn, and why not.
	 *
	 * @param array<string, mixed>      $appointment The appointment, with its `@self.created` moment.
	 * @param array<string, mixed>|null $service     The booked service, or null when it cannot be read.
	 * @param DateTimeImmutable         $now         The moment of the question.
	 *
	 * @return array{withdrawable: bool, code: string, reason: string|null, periodEndsOn: string|null}
	 *         The answer; `periodEndsOn` is the last day a withdrawal is accepted, when the booking moment is known.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
	 */
	public function isWithdrawable(array $appointment, ?array $service, DateTimeImmutable $now): array {
		$bookedAt = self::bookedAt(appointment: $appointment);
		$periodEndsOn = null;
		if ($bookedAt !== null) {
			$periodEndsOn = self::periodEnd(bookedAt: $bookedAt)->modify('-1 day')->format('Y-m-d');
		}

		if ((string)($appointment['source'] ?? '') !== 'widget' || $bookedAt === null) {
			return $this->refusal(
				code: self::NOT_ONLINE,
				reason: $this->l10n->t('This booking was not made online, so there is no right of withdrawal.'),
				periodEndsOn: $periodEndsOn
			);
		}

		if (in_array((string)($appointment['status'] ?? ''), self::BOOKED_STATES, true) === false) {
			return $this->refusal(
				code: self::NOT_BOOKED,
				reason: $this->l10n->t('This booking is no longer open, so it cannot be withdrawn.'),
				periodEndsOn: $periodEndsOn
			);
		}

		$exemption = self::exemptionOf(service: $service);
		if ($exemption !== null) {
			return $this->refusal(
				code: self::EXEMPT,
				reason: $this->l10n->t('This booking cannot be withdrawn: %s. You can still cancel it under the cancellation policy.', [$exemption]),
				periodEndsOn: $periodEndsOn
			);
		}

		if ($now >= self::periodEnd(bookedAt: $bookedAt)) {
			return $this->refusal(
				code: self::PERIOD_ENDED,
				reason: $this->l10n->t('The withdrawal period ended on %s. You can still cancel under the cancellation policy.', [$periodEndsOn]),
				periodEndsOn: $periodEndsOn
			);
		}

		$start = self::instant(value: ($appointment['startTime'] ?? null));
		if ($start !== null && $now >= $start) {
			return $this->refusal(
				code: self::STARTED,
				reason: $this->l10n->t('This appointment has started, so it can no longer be withdrawn.'),
				periodEndsOn: $periodEndsOn
			);
		}

		return ['withdrawable' => true, 'code' => self::WITHDRAWABLE, 'reason' => null, 'periodEndsOn' => $periodEndsOn];
	}//end isWithdrawable()

	/**
	 * The moment the withdrawal period ends: 14 days after the booking.
	 *
	 * @param DateTimeImmutable $bookedAt When the appointment was booked.
	 *
	 * @return DateTimeImmutable The first moment a withdrawal is no longer accepted.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
	 */
	public static function periodEnd(DateTimeImmutable $bookedAt): DateTimeImmutable {
		return $bookedAt->modify('+'.self::PERIOD_DAYS.' days');
	}//end periodEnd()

	/**
	 * When the appointment was booked: the object's creation moment.
	 *
	 * @param array<string, mixed> $appointment The appointment.
	 *
	 * @return DateTimeImmutable|null The moment, or null when unknown.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
	 */
	public static function bookedAt(array $appointment): ?DateTimeImmutable {
		$self = $appointment['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		return self::instant(value: ($self['created'] ?? ($appointment['created'] ?? null)));
	}//end bookedAt()

	/**
	 * The service's named exemption, or null when it is not exempt.
	 *
	 * An exemption without a reason does not count: staff must name it.
	 *
	 * @param array<string, mixed>|null $service The service.
	 *
	 * @return string|null The exemption reason.
	 */
	private static function exemptionOf(?array $service): ?string {
		if ($service === null || ($service['withdrawalExempt'] ?? false) !== true) {
			return null;
		}

		$reason = trim((string)($service['withdrawalExemptionReason'] ?? ''));
		if ($reason === '') {
			return null;
		}

		return $reason;
	}//end exemptionOf()

	/**
	 * A "no" with its reason.
	 *
	 * @param string      $code         The reason code.
	 * @param string      $reason       The sentence for the consumer.
	 * @param string|null $periodEndsOn The last day of the withdrawal period.
	 *
	 * @return array{withdrawable: false, code: string, reason: string, periodEndsOn: string|null}
	 */
	private function refusal(string $code, string $reason, ?string $periodEndsOn): array {
		return ['withdrawable' => false, 'code' => $code, 'reason' => $reason, 'periodEndsOn' => $periodEndsOn];
	}//end refusal()

	/**
	 * An ISO-8601 moment, in UTC.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null The moment, or null when it cannot be read.
	 */
	private static function instant(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			return null;
		}
	}//end instant()
}//end class
