<?php

/**
 * Reserve application run
 *
 * One application of a reinvestment reserve to a replacement asset: the
 * asset and the reserve, and what the steps decide — the fiscal cost basis,
 * the amount applied, the entry date and text, and the journal entry posted.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Asset;

/**
 * Progress of one ReinvestmentReserves::apply() call; never leaves the service.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
final class ReserveApplicationRun {

	/**
	 * The asset's fiscal cost basis before the application, in cents.
	 *
	 * @var int
	 */
	public int $basis = 0;

	/**
	 * The amount applied, in cents.
	 *
	 * @var int
	 */
	public int $applied = 0;

	/**
	 * The entry and line description.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * The entry date (YYYY-MM-DD).
	 *
	 * @var string
	 */
	public string $date = '';

	/**
	 * The journal entry id the application was posted as.
	 *
	 * @var string
	 */
	public string $journalId = '';

	/**
	 * Hold the asset and the reserve.
	 *
	 * @param array<string,mixed> $asset   The replacement asset.
	 * @param array<string,mixed> $reserve The open reinvestment reserve.
	 */
	public function __construct(
		public readonly array $asset,
		public readonly array $reserve,
	) {
	}//end __construct()
}//end class
