<?php

/**
 * Vpb Statement Run
 *
 * The running totals of one TaxReportCalculator::aggregate() call: the cents
 * per statement bucket, the count of untagged tax-relevant postings and the
 * per-account breakdown map.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Per-call totals of a quarterly Vpb aggregation.
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
 */
final class VpbStatementRun {

	/**
	 * Absolute cents per statement bucket.
	 *
	 * @var array{revenue:int,operating:int,nonOperating:int,special:int}
	 */
	public array $bucketCents = [
		'revenue' => 0,
		'operating' => 0,
		'nonOperating' => 0,
		'special' => 0,
	];

	/**
	 * Tax-relevant postings without a tax-treatment tag.
	 *
	 * @var int
	 */
	public int $untagged = 0;

	/**
	 * Per-account breakdown keyed by accountNumber.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $byAccount = [];

	/**
	 * Net taxable income in cents: revenue - operating + nonOperating - special.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
	 */
	public function netCents(): int {
		$cents = $this->bucketCents;
		return ($cents['revenue'] - $cents['operating'] + $cents['nonOperating'] - $cents['special']);

	}//end netCents()
}//end class
