<?php

/**
 * No-Show Fee Capture Service
 *
 * Closes the bookings-depth "no-show-fee-capture" gap: the
 * bookings-cancellation-rules change DEFINES `CancellationPolicy.noShowFee`
 * (a 0-100 percentage of the appointment cost, snapshotted onto the
 * appointment's `appliedPolicy`) but shipped NO mechanism to actually charge
 * it — the fee was spec'd and unenforceable. This service is the capture
 * mechanism: on a recorded no-show it computes the fee in integer cents and
 * captures/charges it through the existing DepositPayment payment-provider
 * rails (Mollie / Stripe via openconnector, authorise-now / capture-later).
 *
 * The service is a pure, OR-agnostic seam: it operates on plain appointment
 * arrays and returns the mutated appointment + the provider result so any
 * caller (cancellation flow, admin "mark no-show" action, portal) can persist
 * the outcome. A booking without a defined no-show fee is a no-op — no charge
 * is dispatched (design D1).
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
 * @spec openspec/specs/bookings-cancellation-rules/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentResult;
use Psr\Log\LoggerInterface;

/**
 * Computes and captures the no-show fee for an appointment through the
 * DepositPayment payment-provider rails.
 *
 * @spec openspec/specs/bookings-cancellation-rules/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class NoShowFeeCaptureService {

	/**
	 * No fee defined / nothing to capture.
	 *
	 * @var string
	 */
	public const STATUS_NONE = 'none';

	/**
	 * Fee captured through the provider.
	 *
	 * @var string
	 */
	public const STATUS_CAPTURED = 'captured';

	/**
	 * Capture attempt failed and needs operator attention.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Construct the service.
	 *
	 * @param DepositPaymentAdapterInterface $adapter Payment-provider lifecycle
	 *                                                adapter (dormant log-only
	 *                                                default; production binding
	 *                                                delegates to Mollie/Stripe).
	 * @param LoggerInterface $logger Structured logger for the
	 *                                audit trail.
	 */
	public function __construct(
		private readonly DepositPaymentAdapterInterface $adapter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the no-show fee (in integer cents) for an appointment.
	 *
	 * The fee is `round(appointmentCost * noShowFee / 100)` where `noShowFee`
	 * is the 0-100 percentage snapshotted onto `appliedPolicy` at appointment
	 * creation (bookings-cancellation-rules design D2). The percentage is
	 * clamped to 0-100 and the fee is clamped to the appointment cost. Returns
	 * 0 when no cost, no policy, or a zero no-show fee is defined — the signal
	 * the caller uses to skip the charge entirely (design D1).
	 *
	 * @param array<string, mixed> $appointment Appointment array carrying
	 *                                          `appointmentCost` (int cents) and
	 *                                          `appliedPolicy.noShowFee`.
	 *
	 * @return int Fee in integer cents (0 <= fee <= appointmentCost).
	 *
	 * @spec openspec/specs/bookings-cancellation-rules/spec.md
	 */
	public function computeNoShowFeeCents(array $appointment): int {
		$cost = (int)($appointment['appointmentCost'] ?? 0);
		if ($cost <= 0) {
			return 0;
		}

		$policy = ($appointment['appliedPolicy'] ?? []);
		$percent = 0;
		if (is_array($policy) === true) {
			$percent = (int)($policy['noShowFee'] ?? 0);
		}

		if ($percent <= 0) {
			return 0;
		}

		if ($percent > 100) {
			$percent = 100;
		}

		$fee = (int)round(($cost * $percent) / 100);
		if ($fee > $cost) {
			$fee = $cost;
		}

		return $fee;
	}//end computeNoShowFeeCents()

	/**
	 * Capture the no-show fee for an appointment through the provider rails and
	 * return the mutated appointment array.
	 *
	 * Behaviour:
	 *  - If no fee is defined ({@see computeNoShowFeeCents()} returns 0) the
	 *    method is a no-op: it stamps `noShowFeeStatus = none`, dispatches NO
	 *    provider call, and returns the appointment unchanged otherwise
	 *    (design D1 — a booking without a defined fee is not charged).
	 *  - If a fee is defined and the appointment carries an authorized
	 *    DepositPayment intent (`depositPaymentIntentId`), the fee is CAPTURED
	 *    against that existing authorization (capture-later rail).
	 *  - Otherwise a fresh charge is opened for the fee amount via
	 *    `requestPayment` (authorise-now rail, e.g. a card-on-file charge).
	 *
	 * The input is NOT mutated in place (returns a new array) so callers can
	 * diff before/after for the audit trail. A dormant adapter still advances
	 * the bookkeeping to `captured` with the synthetic intent id so the flow
	 * stays observable until a production PSP binding is wired.
	 *
	 * @param array<string, mixed> $appointment Appointment array (must carry
	 *                                          `appointmentId`,
	 *                                          `appointmentCost`,
	 *                                          `appliedPolicy`; MAY carry
	 *                                          `administrationId`,
	 *                                          `currency`,
	 *                                          `depositPaymentIntentId`).
	 * @param DateTimeImmutable|null $capturedAt Capture instant; defaults to
	 *                                           now (UTC).
	 *
	 * @return array{appointment: array<string, mixed>, feeCents: int, charged: bool, result: DepositPaymentResult|null}
	 *
	 * @spec openspec/specs/bookings-cancellation-rules/spec.md
	 */
	public function captureNoShowFee(array $appointment, ?DateTimeImmutable $capturedAt = null): array {
		$feeCents = $this->computeNoShowFeeCents(appointment: $appointment);

		if ($feeCents <= 0) {
			$appointment['noShowFeeAmount'] = 0;
			$appointment['noShowFeeStatus'] = self::STATUS_NONE;
			$this->logger->info(
				'Shillinq: no-show recorded but no fee defined — no charge dispatched',
				['appointmentId' => (string)($appointment['appointmentId'] ?? '')]
			);

			return [
				'appointment' => $appointment,
				'feeCents' => 0,
				'charged' => false,
				'result' => null,
			];
		}

		$capturedAt = ($capturedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$currency = (string)($appointment['currency'] ?? 'EUR');
		$intentId = (string)($appointment['depositPaymentIntentId'] ?? '');
		$meta = [
			'appointmentId' => (string)($appointment['appointmentId'] ?? ''),
			'administrationId' => (string)($appointment['administrationId'] ?? ''),
			'correlationId' => 'noshow-' . ((string)($appointment['appointmentId'] ?? 'unknown')),
		];

		if ($intentId !== '') {
			// Capture-later rail: capture the no-show fee against the existing
			// authorization / card hold.
			$result = $this->adapter->capturePayment(
				$intentId,
				[
					'amount' => ['value' => $feeCents, 'currency' => $currency],
					'reason' => 'noShowFee',
					'metadata' => $meta,
				]
			);
		} else {
			// Authorise-now rail: open a fresh charge for the fee amount.
			$result = $this->adapter->requestPayment(
				[
					'amount' => ['value' => $feeCents, 'currency' => $currency],
					'description' => 'No-show fee',
					'methodHint' => 'creditcard',
					'metadata' => $meta,
				]
			);
		}//end if

		$ok = in_array($result->lifecycleState, ['captured', 'authorized', 'pending'], true);
		$status = self::STATUS_FAILED;
		if ($ok === true) {
			$status = self::STATUS_CAPTURED;
		}

		$appointment['noShowFeeAmount'] = $feeCents;
		$appointment['noShowFeeStatus'] = $status;
		$appointment['noShowFeePaymentIntentId'] = $result->paymentIntentId;
		$appointment['noShowFeeCapturedAt'] = $capturedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

		$this->logger->info(
			'Shillinq: no-show fee captured via payment provider',
			[
				'appointmentId' => $meta['appointmentId'],
				'feeCents' => $feeCents,
				'gateway' => $result->gateway,
				'lifecycleState' => $result->lifecycleState,
				'dormant' => $result->dormant,
				'paymentIntentId' => $result->paymentIntentId,
			]
		);

		return [
			'appointment' => $appointment,
			'feeCents' => $feeCents,
			'charged' => true,
			'result' => $result,
		];

	}//end captureNoShowFee()
}//end class
