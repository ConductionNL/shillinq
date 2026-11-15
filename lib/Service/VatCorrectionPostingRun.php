<?php

/**
 * VAT Correction Posting Run
 *
 * The state of one draft GL correction posting compiled by
 * VatSuppletieDetectionService::prepare(): the administration and the
 * VatCorrection it books for, the clearing account, the created
 * GLTransaction id, the running line number and the debit and credit totals.
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
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Per-call state of a VAT correction posting.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 */
final class VatCorrectionPostingRun {

	/**
	 * GL account the offsetting leg books to.
	 *
	 * @var string
	 */
	public string $clearingAccount = '';

	/**
	 * Id of the created draft GLTransaction.
	 *
	 * @var string
	 */
	public string $transactionId = '';

	/**
	 * Number of the last GLLine written.
	 *
	 * @var int
	 */
	public int $lineNumber = 0;

	/**
	 * Debit total in cents of the delta lines.
	 *
	 * @var int
	 */
	public int $totalDebitCt = 0;

	/**
	 * Credit total in cents of the delta lines.
	 *
	 * @var int
	 */
	public int $totalCreditCt = 0;

	/**
	 * Constructor.
	 *
	 * @param string $administrationId Administration scope of the posting.
	 * @param string $vatCorrectionId  The VatCorrection id (sourceReference).
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $vatCorrectionId,
	) {

	}//end __construct()

	/**
	 * Add a delta line's amount to the total of its side.
	 *
	 * @param string $side     'debit' or 'credit'.
	 * @param int    $amountCt Amount in cents.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function addToSide(string $side, int $amountCt): void {
		if ($side === 'debit') {
			$this->totalDebitCt += $amountCt;
		} else {
			$this->totalCreditCt += $amountCt;
		}

	}//end addToSide()
}//end class
