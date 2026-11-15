<?php

/**
 * GR/IR settlement run
 *
 * The state of one GRIRClearingService::settleGRIRPosting() call: the match
 * and invoice being settled, the invoice totals, the dimensions and accounts
 * resolved for the posting and the labels derived for it.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Progress of settling one approved ThreeWayMatch.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
 */
final class GRIRSettlementRun {
	/**
	 * The supplier invoice being settled.
	 *
	 * @var array<string,mixed>
	 */
	public array $invoice = [];

	/**
	 * Invoice total excluding VAT, in cents.
	 *
	 * @var int
	 */
	public int $totalExclVat = 0;

	/**
	 * Invoice VAT, in cents.
	 *
	 * @var int
	 */
	public int $totalVat = 0;

	/**
	 * Invoice total including VAT, in cents.
	 *
	 * @var int
	 */
	public int $totalInclVat = 0;

	/**
	 * Cost center of the first matched PO line.
	 *
	 * @var string
	 */
	public string $costCenter = '';

	/**
	 * Project code of the first matched PO line.
	 *
	 * @var string
	 */
	public string $projectCode = '';

	/**
	 * GR/IR clearing account.
	 *
	 * @var string
	 */
	public string $clearingAccount = '';

	/**
	 * Accounts payable account.
	 *
	 * @var string
	 */
	public string $apAccount = '';

	/**
	 * VAT account.
	 *
	 * @var string
	 */
	public string $vatAccount = '';

	/**
	 * The match id ('' when the match carries none).
	 *
	 * @var string
	 */
	public string $matchId = '';

	/**
	 * The invoice number ('' when the invoice carries none).
	 *
	 * @var string
	 */
	public string $invoiceNumber = '';

	/**
	 * The description carried by the header and every line.
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * The posting date (Y-m-d).
	 *
	 * @var string
	 */
	public string $postingDate = '';

	/**
	 * The invoice id named by the match.
	 *
	 * @var string
	 */
	public readonly string $invoiceId;

	/**
	 * Start settling a match.
	 *
	 * @param string              $administrationId Tenant scope.
	 * @param array<string,mixed> $threeWayMatch    The approved ThreeWayMatch record.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly array $threeWayMatch,
	) {
		$this->invoiceId = (string)($threeWayMatch['invoiceId'] ?? '');
	}//end __construct()

	/**
	 * Keep the invoice with its totals; totalInclVat defaults to excl + VAT
	 * when the invoice does not carry it.
	 *
	 * @param array<string,mixed> $invoice The supplier invoice.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
	 */
	public function useInvoice(array $invoice): void {
		$this->invoice = $invoice;
		$this->totalExclVat = (int)($invoice['totalExclVat'] ?? 0);
		$this->totalVat = (int)($invoice['totalVat'] ?? 0);
		$this->totalInclVat = (int)($invoice['totalInclVat'] ?? ($this->totalExclVat + $this->totalVat));
	}//end useInvoice()

	/**
	 * True when the invoice carries a positive excl-VAT or incl-VAT total.
	 *
	 * @return bool Whether there is anything to settle.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
	 */
	public function hasMonetaryValue(): bool {
		return $this->totalExclVat > 0 || $this->totalInclVat > 0;
	}//end hasMonetaryValue()

	/**
	 * True when the excl or the VAT leg carries an amount.
	 *
	 * @return bool Whether a settlement leg has an amount.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
	 */
	public function hasLegAmounts(): bool {
		return $this->totalExclVat !== 0 || $this->totalVat !== 0;
	}//end hasLegAmounts()

	/**
	 * The settlement lines per REQ-PO3W-009 settlement leg:
	 * DR GR/IR Clearing  (excl VAT) - closes the receipt-side clearing
	 *                                 balance accumulated at GRN time;
	 * DR VAT Input       (VAT)      - the input VAT the supplier charged us,
	 *                                 which we reclaim on our VAT return (RGS
	 *                                 3.5 "inputVat"). The spec calls this
	 *                                 account "VAT Payable" by its credit-side
	 *                                 label; on a purchase invoice the entry
	 *                                 is on the debit side, so it balances
	 *                                 against the AP leg;
	 * CR Accounts Payable (incl VAT) - what we owe the supplier.
	 *
	 * The tax-only edge (excl == 0, vat > 0) yields a balanced 2-line
	 * DR VAT / CR AP entry without the clearing leg.
	 *
	 * @return array<int,array<string,mixed>> The settlement lines.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
	 */
	public function lines(): array {
		$legs = [];
		if ($this->totalExclVat > 0) {
			$legs[] = ['accountNumber' => $this->clearingAccount, 'side' => 'debit', 'amountCents' => $this->totalExclVat];
		}

		if ($this->totalVat > 0) {
			$legs[] = ['accountNumber' => $this->vatAccount, 'side' => 'debit', 'amountCents' => $this->totalVat];
		}

		$legs[] = ['accountNumber' => $this->apAccount, 'side' => 'credit', 'amountCents' => ($this->totalExclVat + $this->totalVat)];

		$lines = [];
		foreach ($legs as $leg) {
			$lines[] = $this->line(leg: $leg);
		}

		return $lines;
	}//end lines()

	/**
	 * One settlement line: the leg's account, side and amount plus the
	 * description and dimensions every line carries.
	 *
	 * @param array<string,mixed> $leg accountNumber, side and amountCents.
	 *
	 * @return array<string,mixed> The settlement line.
	 */
	private function line(array $leg): array {
		return $leg + [
			'description' => $this->description,
			'costCenter' => $this->costCenter,
			'projectCode' => $this->projectCode,
		];
	}//end line()
}//end class
