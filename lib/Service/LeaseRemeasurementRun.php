<?php

/**
 * Shillinq LeaseRemeasurementRun
 *
 * The state of one lease reassessment event in LeaseReassessmentService: the
 * event type, remeasurement approach and default reason, the lease before and
 * after the event, and the figures measured for it (opening balances,
 * liability and RoU movements, P&L impact and GL lines). Never leaves the
 * service.
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
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One reassessment event being measured and saved.
 *
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 */
final class LeaseRemeasurementRun {
	/**
	 * Opening balances of the pre-event lease.
	 *
	 * @var array<string,mixed>
	 */
	public array $preOpening = [];

	/**
	 * Opening balances of the post-event lease.
	 *
	 * @var array<string,mixed>
	 */
	public array $postOpening = [];

	/**
	 * Pre-event liability in cents.
	 *
	 * @var int
	 */
	public int $preEventLiabilityCents = 0;

	/**
	 * RoU asset adjustment in cents.
	 *
	 * @var int
	 */
	public int $rouDeltaCents = 0;

	/**
	 * P&L impact of the event.
	 *
	 * @var float
	 */
	public float $plImpact = 0.0;

	/**
	 * GL lines of the event.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $glLines = [];

	/**
	 * Hold the event.
	 *
	 * @param string $eventType One of the REQ-LR-001 enum values.
	 * @param string $approach catch-up-adjustment|prospective|separate-lease.
	 * @param string $defaultTrigger The reason recorded when the request gives none.
	 * @param array<string,mixed> $lease The pre-event lease snapshot.
	 * @param array<string,mixed> $newLease The post-event lease snapshot.
	 */
	public function __construct(
		public readonly string $eventType,
		public readonly string $approach,
		public readonly string $defaultTrigger,
		public readonly array $lease,
		public readonly array $newLease,
	) {
	}//end __construct()
}//end class
