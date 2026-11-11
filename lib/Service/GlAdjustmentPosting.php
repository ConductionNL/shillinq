<?php

/**
 * GL adjustment posting
 *
 * The operation object of InventoryGlAdjustmentPoster::postAdjustment(): one
 * balanced two-line GLTransaction to post — the accounts debited and
 * credited, the integer-cent amount of both legs, the journal, description,
 * source reference and period. Required identity (administration, journal)
 * comes in through the constructor; the other fields are assigned one by one.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One inventory GL adjustment to post.
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
final class GlAdjustmentPosting {
	/**
	 * Account number debited.
	 *
	 * @var string
	 */
	public string $debitAccount = '';

	/**
	 * Account number credited.
	 *
	 * @var string
	 */
	public string $creditAccount = '';

	/**
	 * Amount in integer cents (both legs).
	 *
	 * @var int
	 */
	public int $amountCents = 0;

	/**
	 * Human-readable line description.
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * Originating document reference.
	 *
	 * @var string
	 */
	public string $sourceReference = '';

	/**
	 * ISO yyyy-mm-dd posting date.
	 *
	 * @var string
	 */
	public string $postingDate = '';

	/**
	 * Period identifier (e.g. 2026-Q4).
	 *
	 * @var string
	 */
	public string $periodId = '';

	/**
	 * Start a posting for an administration and journal.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $journalCode      Journal code (e.g. 'LAND', 'NRV').
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $journalCode,
	) {
	}//end __construct()

	/**
	 * The amount of each leg in euros, rounded to cents.
	 *
	 * @return float The leg amount.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function amount(): float {
		return round(($this->amountCents / 100), 2);
	}//end amount()
}//end class
