<?php

/**
 * Disposal Journal Emitter
 *
 * Shapes the closing `GLTransaction` payload the OR
 * `x-openregister-lifecycle.dispose.action.emit-journal-entry` action
 * materialises when a `FixedAsset` transitions from `active` to
 * `disposed` (REQ-FA-006).
 *
 * The emitter is the deterministic kernel called from the lifecycle
 * action: given a `FixedAsset` record, the disposal input
 * (`disposalDate`, `disposalAccountingTreatment`, `disposalProceeds`),
 * and the configured gain / loss / clearing accounts, it returns a
 * balanced journal payload (header + lines) that
 *
 *   - credits the asset account for its gross acquisition cost,
 *   - debits accumulated depreciation for the accumulated dep value,
 *   - debits cash / clearing for the disposal proceeds,
 *   - posts the gain or loss (proceeds − book value) to the configured
 *     P&L gain account (credit) or loss account (debit),
 *
 * so the asset's carrying amount nets to zero and the GL accepts the
 * lines via `JournalEntryGuard::canPost` (sum of debits == sum of
 * credits).
 *
 * Pure-logic; no OpenRegister dependency so the disposal arithmetic is
 * unit-testable in isolation against the REQ-FA-006 scenarios.
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
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free FixedAsset disposal journal emitter (REQ-FA-006).
 *
 * Wraps `DepreciationCalculator` to compute the current book value, then
 * shapes the balanced GL lines the lifecycle action posts. Every line
 * carries the asset's `assetNumber` as `subLedgerRef` and
 * `subLedgerType: "fixed-asset"` so the trial balance and audit trail
 * can trace the closing entry back to the asset.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class DisposalJournalEmitter {
	/**
	 * Default GL accounts for disposal gain and loss when the operator
	 * has not overridden them. Treated as RGS-3.5 placeholders; the live
	 * lifecycle action resolves the active overrides via InventoryGLConfig
	 * or per-asset configuration.
	 *
	 * @var array<string,string>
	 */
	private const DEFAULT_ACCOUNTS = [
		'gainAccountNumber' => '8000-fa-gain',
		'lossAccountNumber' => '8001-fa-loss',
		'clearingAccountNumber' => '1100-disposal-clearing',
	];

	/**
	 * Construct the emitter.
	 *
	 * @param DepreciationCalculator $calculator Pure-logic depreciation arithmetic helper.
	 */
	public function __construct(
		private readonly DepreciationCalculator $calculator,
	) {
	}//end __construct()

	/**
	 * Build the disposal `GLTransaction` payload (REQ-FA-006).
	 *
	 * @param array<string,mixed> $asset FixedAsset record.
	 * @param array<string,mixed> $disposal Disposal input:
	 *                                      `disposalDate`
	 *                                      (Y-m-d),
	 *                                      `disposalAccountingTreatment`
	 *                                      (sale|scrap|donation|transfer),
	 *                                      `disposalProceeds`
	 *                                      (numeric, in
	 *                                      the asset's
	 *                                      base currency).
	 * @param array<string,mixed> $accounts Account overrides:
	 *                                      `gainAccountNumber`, `lossAccountNumber`, `clearingAccountNumber`.
	 * @param float|null $bookValue Authoritative net book value at the disposal date. When
	 *                              supplied, it overrides the value {@see DepreciationCalculator::currentBookValue()}
	 *                              would recompute. {@see \OCA\Shillinq\Service\FixedAssetDisposalService}
	 *                              passes `purchaseCost − DepreciationSchedule.accumulatedDepreciation`,
	 *                              i.e. the depreciation that was actually POSTED to the GL: reversing a
	 *                              recomputed figure that differs by even a cent would leave a residual
	 *                              balance on the accumulated-depreciation account (design D3). Null
	 *                              keeps the pre-existing behaviour exactly.
	 *
	 * @return array{
	 *   header: array<string,mixed>,
	 *   lines: array<int,array<string,mixed>>,
	 *   bookValue: float,
	 *   gain: float,
	 *   loss: float
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function emit(array $asset, array $disposal, array $accounts = [], ?float $bookValue = null): array {
		$accounts = array_merge(self::DEFAULT_ACCOUNTS, $accounts);

		$assetNumber = (string)($asset['assetNumber'] ?? '');
		$cost = (float)($asset['acquisitionCost'] ?? 0);
		$costCents = $this->calculator->toCents(amount: $cost);

		$disposalDate = (string)($disposal['disposalDate'] ?? date('Y-m-d'));
		$treatment = (string)($disposal['disposalAccountingTreatment'] ?? 'sale');
		$proceeds = (float)($disposal['disposalProceeds'] ?? 0);
		$proceedsCents = $this->calculator->toCents(amount: $proceeds);

		if ($bookValue === null) {
			$bookValue = $this->calculator->currentBookValue(asset: $asset, referenceDate: $disposalDate);
		}

		$bookValueCents = $this->calculator->toCents(amount: $bookValue);
		$accumDepCents = max(0, ($costCents - $bookValueCents));
		$gainOrLossCents = ($proceedsCents - $bookValueCents);
		$gainCents = max(0, $gainOrLossCents);
		$lossCents = max(0, (-1 * $gainOrLossCents));

		$assetAccount = (string)($asset['assetAccountNumber'] ?? '');
		$accumDepAccount = (string)($asset['accumulatedDepAccountNumber'] ?? '');
		$gainAccount = (string)$accounts['gainAccountNumber'];
		$lossAccount = (string)$accounts['lossAccountNumber'];
		$clearingAccount = (string)$accounts['clearingAccountNumber'];

		$lines = [];

		// Credit the asset account for its gross value.
		if ($costCents > 0) {
			$lines[] = $this->line(
				accountNumber: $assetAccount,
				side: 'credit',
				amountCents: $costCents,
				assetNumber: $assetNumber,
				description: 'Disposal: reverse gross asset value'
			);
		}

		// Debit accumulated depreciation for the accumulated dep balance.
		if ($accumDepCents > 0) {
			$lines[] = $this->line(
				accountNumber: $accumDepAccount,
				side: 'debit',
				amountCents: $accumDepCents,
				assetNumber: $assetNumber,
				description: 'Disposal: reverse accumulated depreciation'
			);
		}

		// Debit cash/clearing for the disposal proceeds.
		if ($proceedsCents > 0) {
			$lines[] = $this->line(
				accountNumber: $clearingAccount,
				side: 'debit',
				amountCents: $proceedsCents,
				assetNumber: $assetNumber,
				description: 'Disposal: receive proceeds (' . $treatment . ')'
			);
		}

		// Post gain (credit P&L) or loss (debit P&L).
		if ($gainCents > 0) {
			$lines[] = $this->line(
				accountNumber: $gainAccount,
				side: 'credit',
				amountCents: $gainCents,
				assetNumber: $assetNumber,
				description: 'Disposal: boekwinst vaste activa'
			);
		}

		if ($lossCents > 0) {
			$lines[] = $this->line(
				accountNumber: $lossAccount,
				side: 'debit',
				amountCents: $lossCents,
				assetNumber: $assetNumber,
				description: 'Disposal: boekverlies vaste activa'
			);
		}

		$header = [
			'transactionType' => 'disposal',
			'postingDate' => $disposalDate,
			'description' => 'Fixed asset disposal ' . $assetNumber . ' (' . $treatment . ')',
			'sourceReference' => 'fixed-asset:' . $assetNumber,
			'subLedgerType' => 'fixed-asset',
			'subLedgerRef' => $assetNumber,
			'currency' => (string)($asset['currency'] ?? 'EUR'),
			'administrationId' => (string)($asset['administrationId'] ?? ''),
		];

		return [
			'header' => $header,
			'lines' => $lines,
			'bookValue' => $bookValue,
			'gain' => $this->calculator->fromCents(cents: $gainCents),
			'loss' => $this->calculator->fromCents(cents: $lossCents),
		];

	}//end emit()

	/**
	 * Returns true iff the disposal lines balance (debits == credits in cents).
	 *
	 * Mirrors `JournalEntryGuard::canPost` so the emitted payload is
	 * provably postable before it reaches the GL surface.
	 *
	 * @param array<int,array<string,mixed>> $lines Disposal journal lines.
	 *
	 * @return bool True when debits equal credits.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function linesBalance(array $lines): bool {
		$debit = 0;
		$credit = 0;
		foreach ($lines as $line) {
			$cents = $this->calculator->toCents(amount: ($line['amount'] ?? 0));
			if (($line['side'] ?? '') === 'debit') {
				$debit += $cents;
				continue;
			}

			$credit += $cents;
		}

		return $debit === $credit;
	}//end linesBalance()

	/**
	 * Shape a single GL line.
	 *
	 * @param string $accountNumber GL account number.
	 * @param string $side debit|credit.
	 * @param int $amountCents Non-negative integer cents.
	 * @param string $assetNumber FixedAsset.assetNumber (subLedgerRef).
	 * @param string $description Operator-readable line description.
	 *
	 * @return array<string,mixed> GL line.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	private function line(
		string $accountNumber,
		string $side,
		int $amountCents,
		string $assetNumber,
		string $description,
	): array {
		return [
			'accountNumber' => $accountNumber,
			'side' => $side,
			'amount' => $this->calculator->fromCents(cents: $amountCents),
			'subLedgerType' => 'fixed-asset',
			'subLedgerRef' => $assetNumber,
			'description' => $description,
		];

	}//end line()
}//end class
