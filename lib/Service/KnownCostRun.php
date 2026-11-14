<?php

/**
 * One known-cost regeneration run.
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
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The administration, its loaded context and the summary of one
 * KnownCostBudgetWriter::run(). Built and read by KnownCostBudgetWriter.
 *
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md
 */
final class KnownCostRun {

	/**
	 * The administration scope.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The {@see KnownCostReader::loadContext()} bundle.
	 *
	 * @var array<string,mixed>
	 */
	public array $context = [];

	/**
	 * The run summary.
	 *
	 * @var array{created:int,updated:int,overridden:int,skippedFiscalYears:array<int,bool>}
	 */
	public array $summary = ['created' => 0, 'updated' => 0, 'overridden' => 0, 'skippedFiscalYears' => []];
}//end class
