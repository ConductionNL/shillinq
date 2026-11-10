<?php

/**
 * Ledger Pivot Run
 *
 * The state of one LedgerPivotService::pivot() call: the two axes and the
 * range, the lookups it loaded, the cell sums, and the totals and kept keys
 * of each axis.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pivot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pivot;

/**
 * Per-call state of a ledger pivot.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
final class LedgerPivotRun {

	/**
	 * First posting date, YYYY-MM-DD.
	 *
	 * @var string
	 */
	public string $from = '';

	/**
	 * Last posting date, YYYY-MM-DD.
	 *
	 * @var string
	 */
	public string $to = '';

	/**
	 * Posting date per posted transaction inside the range.
	 *
	 * @var array<string, string>
	 */
	public array $transactions = [];

	/**
	 * Display-name lookups: accounts, parents, types, customers.
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $labels = [];

	/**
	 * The customer of each transaction, when an axis needs it.
	 *
	 * @var array<string, string>
	 */
	public array $customers = [];

	/**
	 * Row key => column key => sum.
	 *
	 * @var array<string, array<string, float>>
	 */
	public array $cells = [];

	/**
	 * Row key => total.
	 *
	 * @var array<string, float>
	 */
	public array $rowTotals = [];

	/**
	 * Column key => total.
	 *
	 * @var array<string, float>
	 */
	public array $columnTotals = [];

	/**
	 * The row keys shown.
	 *
	 * @var array<int, string>
	 */
	public array $rowKeys = [];

	/**
	 * The column keys shown.
	 *
	 * @var array<int, string>
	 */
	public array $columnKeys = [];

	/**
	 * Constructor.
	 *
	 * @param string $rowAxis    One of LedgerPivotService::AXES.
	 * @param string $columnAxis One of LedgerPivotService::AXES.
	 */
	public function __construct(
		public readonly string $rowAxis,
		public readonly string $columnAxis,
	) {

	}//end __construct()
}//end class
