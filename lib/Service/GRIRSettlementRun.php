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
}//end class
