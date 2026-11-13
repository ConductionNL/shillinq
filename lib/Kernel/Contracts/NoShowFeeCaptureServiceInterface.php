<?php

/**
 * Contract of NoShowFeeCaptureService
 *
 * The public surface of NoShowFeeCaptureService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of NoShowFeeCaptureService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NoShowFeeCaptureServiceInterface {

	/**
	 * No fee defined / nothing to capture.
	 *
	 * @var string
	 */
	public const STATUS_NONE = 'none';

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
	public function captureNoShowFee(array $appointment, ?DateTimeImmutable $capturedAt = null): array;
}//end interface
