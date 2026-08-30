<?php

/**
 * Result value-object returned by a Digipoort/SBR adapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Digipoort
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 * @spec openspec/specs/bookkeeping-sbr-xbrl-reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Digipoort;

/**
 * Result of a Digipoort/SBR submission attempt.
 *
 * `kenmerk` is the Digipoort-side submission identifier — Logius's
 * canonical handle for tracking the filing's status (e.g. via the
 * Statusinformatieservice). The dormant default synthesises one so
 * callers can persist a non-null reference even when no outbound
 * call took place.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 * @spec openspec/specs/bookkeeping-sbr-xbrl-reporting/spec.md
 */
final class DigipoortSubmissionResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $deliveryStatus ACCEPTED / REJECTED / DEFERRED.
	 * @param string $reference Digipoort-side submission id.
	 * @param bool $dormant TRUE when the adapter was dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (e.g. statusInformation URL,
	 *                                    rejection reason codes).
	 */
	public function __construct(
		public readonly string $deliveryStatus,
		public readonly string $reference,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
