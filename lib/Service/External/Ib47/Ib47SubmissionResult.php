<?php

/**
 * Result value-object returned by an IB47 adapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Ib47
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Ib47;

/**
 * Result of an IB47 / UBD submission attempt.
 *
 * `kenmerk` is the Belastingdienst-side submission identifier — the
 * canonical handle the Gegevensportaal uses for tracking the
 * filing's status. The dormant default synthesises one so callers
 * can persist a non-null reference even when no outbound call took
 * place.
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
final class Ib47SubmissionResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $deliveryStatus ACCEPTED / REJECTED / DEFERRED.
	 * @param string $reference Belastingdienst-side
	 *                        submission id.
	 * @param bool $dormant TRUE when the adapter
	 *                      was dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (e.g.
	 *                                    rejectedRecipients,
	 *                                    gegevensportaalUrl).
	 */
	public function __construct(
		public readonly string $deliveryStatus,
		public readonly string $reference,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
