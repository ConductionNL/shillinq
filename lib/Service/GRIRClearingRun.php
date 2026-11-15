<?php

/**
 * GR/IR clearing run
 *
 * The state of one GRIRClearingService::createGRIRPosting() call: the GRN
 * line being cleared, its PO line, the clearing amount, the accounts and
 * dimensions resolved for the posting and the labels derived for it.
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
 * Progress of clearing one accepted GoodsReceiptLine.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
 */
final class GRIRClearingRun {
	/**
	 * The accepted GoodsReceiptNote.
	 *
	 * @var array<string,mixed>
	 */
	public array $grn = [];

	/**
	 * The ThreeWayMatch id, when already known.
	 *
	 * @var string|null
	 */
	public ?string $threeWayMatchId = null;

	/**
	 * The PO line the GRN line receives against.
	 *
	 * @var array<string,mixed>
	 */
	public array $poLine = [];

	/**
	 * The clearing amount, in cents.
	 *
	 * @var int
	 */
	public int $amountCents = 0;

	/**
	 * The debit (PO line) account.
	 *
	 * @var string
	 */
	public string $debitAccount = '';

	/**
	 * The credit (GR/IR clearing) account.
	 *
	 * @var string
	 */
	public string $creditAccount = '';

	/**
	 * Cost center of the PO line.
	 *
	 * @var string
	 */
	public string $costCenter = '';

	/**
	 * Project code of the PO line.
	 *
	 * @var string
	 */
	public string $projectCode = '';

	/**
	 * The GRN number ('' when absent).
	 *
	 * @var string
	 */
	public string $grnNumber = '';

	/**
	 * The GRN line id.
	 *
	 * @var string
	 */
	public string $grnLineId = '';

	/**
	 * The description carried by the header and both lines.
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
	 * Start clearing a GRN line.
	 *
	 * @param string              $administrationId Tenant scope.
	 * @param array<string,mixed> $grnLine          The accepted GoodsReceiptLine row.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly array $grnLine,
	) {
	}//end __construct()

	/**
	 * The two clearing lines: DR the PO-line account, CR GR/IR clearing.
	 *
	 * @return array<int,array<string,mixed>> The clearing lines.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
	 */
	public function lines(): array {
		$dimensions = [
			'description' => $this->description,
			'costCenter' => $this->costCenter,
			'projectCode' => $this->projectCode,
		];

		return [
			['accountNumber' => $this->debitAccount, 'side' => 'debit', 'amountCents' => $this->amountCents] + $dimensions,
			['accountNumber' => $this->creditAccount, 'side' => 'credit', 'amountCents' => $this->amountCents] + $dimensions,
		];
	}//end lines()
}//end class
