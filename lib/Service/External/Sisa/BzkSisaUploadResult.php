<?php

/**
 * Result value-object returned by a BZK SiSa upload adapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Sisa
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-sisa-reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Sisa;

/**
 * Result of a BZK SiSa upload attempt.
 *
 * @spec openspec/specs/bookkeeping-sisa-reporting/spec.md
 */
final class BzkSisaUploadResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $deliveryStatus ACCEPTED / REJECTED / DEFERRED.
	 * @param string $trackingId BZK-side tracking id.
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
