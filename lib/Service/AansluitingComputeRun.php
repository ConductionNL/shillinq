<?php

/**
 * One AansluitingService::compute request: the definition and any prior result.
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
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The reconciliation and period being computed, its definition and the
 * existing AansluitingResult for that pair, if any.
 *
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 */
final class AansluitingComputeRun {

	/**
	 * Construct the run.
	 *
	 * @param string $reconciliationId The Aansluiting id.
	 * @param string $periodId The period id.
	 * @param array<string,mixed> $definition The Aansluiting definition.
	 * @param array<string,mixed>|null $existing The existing result, or null.
	 */
	public function __construct(
		public readonly string $reconciliationId,
		public readonly string $periodId,
		public readonly array $definition,
		public readonly array|null $existing,
	) {

	}//end __construct()
}//end class
