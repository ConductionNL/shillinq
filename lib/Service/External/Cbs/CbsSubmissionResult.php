<?php

/**
 * Result value-object returned by a CBS Bestanden adapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Cbs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Cbs;

/**
 * Result of a CBS Bestanden upload attempt.
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 */
final class CbsSubmissionResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $deliveryStatus ACCEPTED / REJECTED / DEFERRED.
	 * @param string $trackingId Provider-side tracking id.
	 * @param bool $dormant TRUE when the adapter was dormant.
	 * @param array<string,mixed> $extras Provider-specific extras.
	 */
	public function __construct(
		public readonly string $deliveryStatus,
		public readonly string $trackingId,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
