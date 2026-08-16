<?php

/**
 * Result value-object returned by a Salarisbureau adapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Salarisbureau
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Salarisbureau;

/**
 * Result of a salarisbureau payroll-run submission attempt.
 *
 * `runId` is the bureau-side identifier (ADP RUN identifier, Nmbrs
 * run number, Loket Period ID …) — the canonical handle the bureau
 * uses for downstream reconciliation. The dormant default synthesises
 * one so callers can persist a non-null reference even when no
 * outbound call took place.
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 */
final class SalarisbureauPayrollRunResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $deliveryStatus ACCEPTED / REJECTED / DEFERRED.
	 * @param string $runId Bureau-side run identifier.
	 * @param bool $dormant TRUE when the adapter was dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (e.g. payslipUrls,
	 *                                    loonaangifteKenmerk,
	 *                                    rejectionReasons).
	 */
	public function __construct(
		public readonly string $deliveryStatus,
		public readonly string $runId,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
