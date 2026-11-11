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
		$run = new DisposalJournalRun(asset: $asset, disposal: $disposal);
		$run->accounts = array_merge(self::DEFAULT_ACCOUNTS, $accounts);
		$this->measureDisposal(run: $run, bookValue: $bookValue);

		return [
			'header' => $this->header(run: $run),
			'lines' => $this->lines(run: $run),
			'bookValue' => $run->bookValue,
			'gain' => $this->calculator->fromCents(cents: $run->gainCents),
			'loss' => $this->calculator->fromCents(cents: $run->lossCents),
		];

	}//end emit()

	/**
	 * Measure the disposal in integer cents: gross value, proceeds, book
	 * value (the authoritative one when given, else recomputed at the
	 * disposal date), accumulated depreciation and the gain or loss.
	 *
	 * @param DisposalJournalRun $run       The disposal journal run.
	 * @param float|null         $bookValue The authoritative net book value, when known.
	 *
	 * @return void
	 */
	private function measureDisposal(DisposalJournalRun $run, ?float $bookValue): void {
		$run->costCents = $this->calculator->toCents(amount: (float)($run->asset['acquisitionCost'] ?? 0));
		$run->disposalDate = (string)($run->disposal['disposalDate'] ?? date('Y-m-d'));
		$run->treatment = (string)($run->disposal['disposalAccountingTreatment'] ?? 'sale');
		$run->proceedsCents = $this->calculator->toCents(amount: (float)($run->disposal['disposalProceeds'] ?? 0));
		$run->bookValue = ($bookValue ?? $this->calculator->currentBookValue(asset: $run->asset, referenceDate: $run->disposalDate));

		$bookValueCents = $this->calculator->toCents(amount: $run->bookValue);
		$run->accumDepCents = max(0, ($run->costCents - $bookValueCents));
		$gainOrLossCents = ($run->proceedsCents - $bookValueCents);
		$run->gainCents = max(0, $gainOrLossCents);
		$run->lossCents = max(0, (-1 * $gainOrLossCents));
	}//end measureDisposal()

	/**
	 * The disposal lines with an amount, in order: credit the asset account
	 * for its gross value, debit accumulated depreciation for its balance,
	 * debit cash/clearing for the proceeds, then post the gain (credit P&L)
	 * or loss (debit P&L).
	 *
	 * @param DisposalJournalRun $run The disposal journal run.
	 *
	 * @return array<int,array<string,mixed>> The GL lines.
	 */
	private function lines(DisposalJournalRun $run): array {
		$accounts = $run->accounts;
		$legs = [
			[(string)($run->asset['assetAccountNumber'] ?? ''), 'credit', $run->costCents, 'Disposal: reverse gross asset value'],
			[(string)($run->asset['accumulatedDepAccountNumber'] ?? ''), 'debit', $run->accumDepCents, 'Disposal: reverse accumulated depreciation'],
			[(string)$accounts['clearingAccountNumber'], 'debit', $run->proceedsCents, 'Disposal: receive proceeds (' . $run->treatment . ')'],
			[(string)$accounts['gainAccountNumber'], 'credit', $run->gainCents, 'Disposal: boekwinst vaste activa'],
			[(string)$accounts['lossAccountNumber'], 'debit', $run->lossCents, 'Disposal: boekverlies vaste activa'],
		];

		$lines = [];
		foreach ($legs as $leg) {
			if ($leg[2] > 0) {
				$lines[] = $this->line(run: $run, leg: $leg);
			}
		}

		return $lines;
	}//end lines()

	/**
	 * The journal header.
	 *
	 * @param DisposalJournalRun $run The disposal journal run.
	 *
	 * @return array<string,mixed> The header.
	 */
	private function header(DisposalJournalRun $run): array {
		$assetNumber = (string)($run->asset['assetNumber'] ?? '');
		return [
			'transactionType' => 'disposal',
			'postingDate' => $run->disposalDate,
			'description' => 'Fixed asset disposal ' . $assetNumber . ' (' . $run->treatment . ')',
			'sourceReference' => 'fixed-asset:' . $assetNumber,
			'subLedgerType' => 'fixed-asset',
			'subLedgerRef' => $assetNumber,
			'currency' => (string)($run->asset['currency'] ?? 'EUR'),
			'administrationId' => (string)($run->asset['administrationId'] ?? ''),
		];
	}//end header()

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
	 * @param DisposalJournalRun $run The disposal journal run (asset number).
	 * @param array{0:string,1:string,2:int,3:string} $leg Account number, debit|credit, non-negative integer cents, description.
	 *
	 * @return array<string,mixed> GL line.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	private function line(DisposalJournalRun $run, array $leg): array {
		return [
			'accountNumber' => $leg[0],
			'side' => $leg[1],
			'amount' => $this->calculator->fromCents(cents: $leg[2]),
			'subLedgerType' => 'fixed-asset',
			'subLedgerRef' => (string)($run->asset['assetNumber'] ?? ''),
			'description' => $leg[3],
		];

	}//end line()
}//end class
