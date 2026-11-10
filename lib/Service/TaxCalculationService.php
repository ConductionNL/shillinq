<?php

/**
 * Tax Calculation Service
 *
 * Orchestrates the deferred-tax lifecycle for a fiscal period: detecting
 * temporary differences per balance-sheet account (REQ-DT-001), compensating
 * tax-loss carry-forwards under the applicable Wet Vpb regime (REQ-DT-003),
 * assessing DTA recoverability (REQ-DT-004), re-measuring deferred positions
 * when a new tax rate is enacted (REQ-DT-005), and building the movement
 * roll-forward (REQ-DT-009) and aggregated tax provision (REQ-DT-008).
 *
 * Per ADR-031 the ETR reconciliation (TaxRateReconciliation) is produced as
 * x-openregister-calculations output on the schema — this service only
 * orchestrates the underlying data writes; the declarative engine computes
 * the derived fields. The pure arithmetic logic is in TaxCalculationHelper
 * so each rule is unit-testable in isolation.
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
 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Orchestrates deferred-tax calculation at fiscal-period close.
 *
 * Reads are administration-scoped (server-resolved administrationId, not client-
 * supplied) to prevent IDOR per ADR-005. All money values stored and returned in
 * integer euro cents. No stack traces are propagated to callers; exceptions are
 * caught, logged, and re-raised as \RuntimeException with a safe message.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
 */
class TaxCalculationService {
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param TaxCalculationHelper $helper Pure-logic helper (loss compensation, rate calc).
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly TaxCalculationHelper $helper,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Run all deferred-tax calculations for a fiscal period end (REQ-DT-001..REQ-DT-010).
	 *
	 * Calls detectTemporaryDifferences, compensateLosses, assessRecoverability,
	 * applyRateChanges, and calculateTaxProvision in order. Callers (e.g. the GL
	 * close hook) should invoke this single entry-point rather than calling
	 * individual methods, so the ordering guarantee (REQ-DT-001 before REQ-DT-009)
	 * is enforced here.
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope (server-resolved; never client-supplied).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function calculateAllPeriodEnd(string $periodId, string $administrationId): void {
		$this->detectTemporaryDifferences(periodId: $periodId, administrationId: $administrationId);
		$this->compensateLosses(periodId: $periodId, administrationId: $administrationId);
		$this->assessRecoverability(periodId: $periodId, administrationId: $administrationId);
		$this->applyRateChanges(periodId: $periodId, administrationId: $administrationId);

		// Build movement rolls and provisions for each jurisdiction observed.
		$jurisdictions = $this->observedJurisdictions(
			run: new DeferredTaxRun(periodId: $periodId, administrationId: $administrationId)
		);
		foreach ($jurisdictions as $jurisdiction) {
			$this->calculateMovements(
				periodId: $periodId,
				administrationId: $administrationId,
				jurisdiction: $jurisdiction
			);
			$this->calculateTaxProvision(
				periodId: $periodId,
				administrationId: $administrationId,
				jurisdiction: $jurisdiction
			);
		}//end foreach

	}//end calculateAllPeriodEnd()

	/**
	 * Detect temporary differences per balance-sheet account at balansdatum (REQ-DT-001).
	 *
	 * Reads Account records with their taxBasisDifferenceCategory hint, reads GL
	 * balances (debit/credit net per account), compares the commercial carrying
	 * amount to the tax basis and stores a TemporaryDifference record for each
	 * account that has a non-zero difference and is tagged or manually categorised.
	 * Permanent differences (those with no category hint) are skipped here and
	 * must be entered manually in TaxRateReconciliation.reconciliationItems (REQ-DT-002).
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function detectTemporaryDifferences(string $periodId, string $administrationId): void {
		$run = new DeferredTaxRun(periodId: $periodId, administrationId: $administrationId);

		// Fetch all tagged accounts for this administration (REQ-DT-001 / REQ-DT-002).
		$accounts = $this->store(schema: 'Account')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach ($accounts as $account) {
			$difference = $this->accountDifference(account: $account);
			if ($difference !== null) {
				$this->upsertRecord(
					schema: 'TemporaryDifference',
					row: $this->temporaryDifferenceRow(run: $run, difference: $difference)
				);
			}
		}

	}//end detectTemporaryDifferences()

	/**
	 * Compensate tax-loss carry-forwards per jurisdiction under the applicable regime (REQ-DT-003).
	 *
	 * Reads the period's taxable profit (from the Vpb return or FiscalPeriod), then for
	 * each open TaxLossCarryForward record (remainingAmount > 0) applies the regime rules:
	 * - pre-2019-6year: 100% utilisation, expiration check.
	 * - 2019-2021-transition: hybrid rules (coordinate with specialist; same 50%-cap logic as 2022+).
	 * - 2022-onwards: EUR 1M at 100%, amounts above threshold at 50% cap (Wet Vpb art. 20).
	 * Updates utilisedAmount and remainingAmount on each record.
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function compensateLosses(string $periodId, string $administrationId): void {
		$run = new DeferredTaxRun(periodId: $periodId, administrationId: $administrationId);

		// Fetch all open carry-forwards for this administration.
		$losses = $this->store(schema: 'TaxLossCarryForward')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach (self::openLossesByJurisdiction(losses: $losses) as $jurisdiction => $jurisdictionLosses) {
			$run->jurisdiction = $jurisdiction;
			$this->offsetLossesAgainstProfit(run: $run, losses: $jurisdictionLosses);
		}

	}//end compensateLosses()

	/**
	 * Validate DTA recoverability assessments for loss carry-forwards (REQ-DT-004).
	 *
	 * Reads all TaxLossCarryForward records with a recognised DTA (dtaRecognised > 0)
	 * and verifies that dtaRecoverabilityRationale is populated. Logs a warning for
	 * records lacking a rationale so the controller can review. This method does not
	 * auto-clear DTAs — that is a deliberate operator decision (design D4).
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function assessRecoverability(string $periodId, string $administrationId): void {
		unset($periodId);

		$losses = $this->store(schema: 'TaxLossCarryForward')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach ($losses as $loss) {
			$this->warnWhenDtaUnjustified(loss: $loss, administrationId: $administrationId);
		}

	}//end assessRecoverability()

	/**
	 * Re-measure deferred positions for accounts with expectedReversalYear >= enacted effective date (REQ-DT-005).
	 *
	 * Reads FiscalPeriod.enactedTaxRates; for each jurisdiction that has a new enacted
	 * rate, fetches TemporaryDifference records whose expectedReversalYear falls on or
	 * after the rate's effectiveDate and re-computes deferredTaxBalance at the new rate.
	 * Creates a rateChangeAdjustment entry in DeferredTaxMovement for the affected categories.
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function applyRateChanges(string $periodId, string $administrationId): void {
		$run = new DeferredTaxRun(periodId: $periodId, administrationId: $administrationId);

		$enactedRates = $this->fetchEnactedRates(run: $run);
		if (empty($enactedRates) === true) {
			return;
		}

		foreach ($enactedRates as $jurisdiction => $rateInfo) {
			$run->jurisdiction = $jurisdiction;
			$run->newRateBps = (int)$rateInfo['rate'];
			$run->effectiveDate = (string)$rateInfo['effectiveDate'];
			$this->applyJurisdictionRateChange(run: $run);
		}

	}//end applyRateChanges()

	/**
	 * Build or update DeferredTaxMovement roll-forward records for all categories in a jurisdiction (REQ-DT-009).
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $jurisdiction ISO 3166-1 alpha-2 country code.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function calculateMovements(string $periodId, string $administrationId, string $jurisdiction): void {
		$run = new DeferredTaxRun(periodId: $periodId, administrationId: $administrationId);
		$run->jurisdiction = $jurisdiction;

		$diffs = $this->store(schema: 'TemporaryDifference')
			->findAll(['filters' => $run->jurisdictionFilters()]);

		// Group deferred balances by category.
		foreach (self::deferredBalanceByCategory(diffs: $diffs) as $category => $totalCents) {
			$this->upsertRecord(
				schema: 'DeferredTaxMovement',
				row: $this->movementRow(run: $run, balance: new CategoryAmount(category: $category, cents: $totalCents))
			);
		}

	}//end calculateMovements()

	/**
	 * Aggregate DTA and DTL totals and upsert a TaxProvision record per jurisdiction (REQ-DT-008).
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $jurisdiction ISO 3166-1 alpha-2 country code.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function calculateTaxProvision(string $periodId, string $administrationId, string $jurisdiction): void {
		$run = new DeferredTaxRun(periodId: $periodId, administrationId: $administrationId);
		$run->jurisdiction = $jurisdiction;

		// Read all DeferredTaxMovement closingBalances for this period/jurisdiction.
		$movements = $this->store(schema: 'DeferredTaxMovement')
			->findAll(['filters' => $run->jurisdictionFilters()]);

		$this->upsertRecord(
			schema: 'TaxProvision',
			row: self::taxProvisionRow(run: $run, totals: self::dtaDtlTotals(movements: $movements))
		);

	}//end calculateTaxProvision()

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Collect unique jurisdictions observed in TemporaryDifference for a period.
	 *
	 * @param DeferredTaxRun $run The period and administration.
	 *
	 * @return array<int,string> Unique jurisdiction codes.
	 */
	private function observedJurisdictions(DeferredTaxRun $run): array {
		$diffs = $this->store(schema: 'TemporaryDifference')
			->findAll(['filters' => $run->periodFilters()]);

		$jurisdictions = [];
		foreach ($diffs as $diff) {
			$jur = (string)($diff['jurisdiction'] ?? 'NL');
			if (in_array($jur, $jurisdictions, true) === false) {
				$jurisdictions[] = $jur;
			}
		}

		return $jurisdictions;
	}//end observedJurisdictions()

	/**
	 * The temporary difference of one account, or null when the account has no
	 * category hint (the operator enters those manually, REQ-DT-002), no account
	 * number, or no difference between its carrying amounts.
	 *
	 * @param array<string,mixed> $account The Account record.
	 *
	 * @return array{category:mixed,accountNumber:string,jurisdiction:string,commercialCents:int,taxCents:int}|null
	 */
	private function accountDifference(array $account): ?array {
		$category = ($account['taxBasisDifferenceCategory'] ?? null);
		$accountNumber = (string)($account['accountNumber'] ?? '');
		if ($category === null || $accountNumber === '') {
			return null;
		}

		// Tax basis if stored on the account; otherwise 0 (operator must patch).
		$commercialCents = $this->helper->toCents(($account['commercialCarryingAmount'] ?? 0));
		$taxCents = $this->helper->toCents(($account['taxCarryingAmount'] ?? 0));
		if (($commercialCents - $taxCents) === 0) {
			return null;
		}

		return [
			'category' => $category,
			'accountNumber' => $accountNumber,
			'jurisdiction' => self::accountJurisdiction(account: $account),
			'commercialCents' => $commercialCents,
			'taxCents' => $taxCents,
		];
	}//end accountDifference()

	/**
	 * The account's jurisdiction, NL when it states none.
	 *
	 * @param array<string,mixed> $account The Account record.
	 *
	 * @return string
	 */
	private static function accountJurisdiction(array $account): string {
		$jurisdiction = (string)($account['jurisdiction'] ?? 'NL');
		if ($jurisdiction === '') {
			return 'NL';
		}

		return $jurisdiction;
	}//end accountJurisdiction()

	/**
	 * The TemporaryDifference record (with its slug) of one account's
	 * difference, valued at the jurisdiction's current tax rate.
	 *
	 * @param DeferredTaxRun                    $run        The period and administration.
	 * @param array<string,mixed>               $difference The account's difference.
	 *
	 * @return array<string,mixed>
	 */
	private function temporaryDifferenceRow(DeferredTaxRun $run, array $difference): array {
		$diffCents = ($difference['commercialCents'] - $difference['taxCents']);
		$type = 'deductible';
		if ($diffCents > 0) {
			$type = 'taxable';
		}

		$taxRateBps = $this->resolveCurrentTaxRateBps(run: $run, jurisdiction: $difference['jurisdiction']);

		return [
			'slug' => 'td-' . $run->periodId . '-' . $difference['accountNumber'] . '-' . $difference['jurisdiction'],
			'periodId' => $run->periodId,
			'jurisdiction' => $difference['jurisdiction'],
			'accountNumber' => $difference['accountNumber'],
			'category' => $difference['category'],
			'commercialCarryingAmount' => $difference['commercialCents'],
			'taxCarryingAmount' => $difference['taxCents'],
			'temporaryDifference' => $diffCents,
			'type' => $type,
			'reversalPattern' => 'long-term',
			'expectedReversalYear' => null,
			'taxRate' => $taxRateBps,
			'deferredTaxBalance' => $this->helper->computeDeferredTaxCents(differenceInCents: $diffCents, taxRateBps: $taxRateBps),
			'notes' => null,
			'administrationId' => $run->administrationId,
		];
	}//end temporaryDifferenceRow()

	/**
	 * The carry-forwards with a remaining amount, grouped by jurisdiction (NL
	 * when none is stated) in the order they were read.
	 *
	 * @param array<int,array<string,mixed>> $losses TaxLossCarryForward records.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function openLossesByJurisdiction(array $losses): array {
		$byJurisdiction = [];
		foreach ($losses as $loss) {
			$remaining = (int)($loss['remainingAmount'] ?? 0);
			if ($remaining <= 0) {
				continue;
			}

			$jurisdiction = (string)($loss['jurisdiction'] ?? 'NL');
			$byJurisdiction[$jurisdiction][] = $loss;
		}

		return $byJurisdiction;
	}//end openLossesByJurisdiction()

	/**
	 * Offset one jurisdiction's open losses, oldest first, against the period's
	 * taxable profit there until the profit is used up.
	 *
	 * @param DeferredTaxRun                 $run    The period, administration and jurisdiction.
	 * @param array<int,array<string,mixed>> $losses The jurisdiction's open losses.
	 *
	 * @return void
	 */
	private function offsetLossesAgainstProfit(DeferredTaxRun $run, array $losses): void {
		// Resolve available taxable profit for this jurisdiction from FiscalPeriod.
		$remainingProfit = $this->resolveTaxableProfit(run: $run, jurisdiction: (string)$run->jurisdiction);
		if ($remainingProfit <= 0) {
			return;
		}

		foreach (self::oldestFirst(losses: $losses) as $loss) {
			if ($remainingProfit <= 0) {
				break;
			}

			$remainingProfit -= $this->utiliseLoss(loss: $loss, availableProfitCents: $remainingProfit);
		}
	}//end offsetLossesAgainstProfit()

	/**
	 * The losses ordered by originating year, oldest first.
	 *
	 * @param array<int,array<string,mixed>> $losses The losses.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function oldestFirst(array $losses): array {
		usort(
			$losses,
			static function (array $a, array $b): int {
				return ((int)($a['originatingYear'] ?? 0)) <=> ((int)($b['originatingYear'] ?? 0));
			}
		);

		return $losses;
	}//end oldestFirst()

	/**
	 * Utilise as much of one loss as its regime allows against the available
	 * profit and store the new utilised / remaining amounts.
	 *
	 * @param array<string,mixed> $loss                 The TaxLossCarryForward record.
	 * @param int                 $availableProfitCents Profit still available.
	 *
	 * @return int The cents utilised (0 when none).
	 */
	private function utiliseLoss(array $loss, int $availableProfitCents): int {
		$remaining = (int)($loss['remainingAmount'] ?? 0);
		$utilisable = $this->helper->computeUtilisableLoss(
			remainingLossCents: $remaining,
			availableProfitCents: $availableProfitCents,
			regime: (string)($loss['applicableRegime'] ?? '2022-onwards')
		);
		if ($utilisable <= 0) {
			return 0;
		}

		$id = ($loss['id'] ?? ($loss['@self']['id'] ?? null));
		if ($id !== null) {
			$this->store(schema: 'TaxLossCarryForward')->updateObject(
				(string)$id,
				[
					'utilisedAmount' => ((int)($loss['utilisedAmount'] ?? 0)) + $utilisable,
					'remainingAmount' => $remaining - $utilisable,
				]
			);
		}

		return $utilisable;
	}//end utiliseLoss()

	/**
	 * Warn when a loss carries a recognised DTA without a recoverability
	 * rationale (REQ-DT-004).
	 *
	 * @param array<string,mixed> $loss             The TaxLossCarryForward record.
	 * @param string              $administrationId Administration scope.
	 *
	 * @return void
	 */
	private function warnWhenDtaUnjustified(array $loss, string $administrationId): void {
		$dtaRecognised = (int)($loss['dtaRecognised'] ?? 0);
		$rationale = ($loss['dtaRecoverabilityRationale'] ?? null);
		if ($dtaRecognised <= 0 || ($rationale !== null && trim((string)$rationale) !== '')) {
			return;
		}

		$this->logger->warning(
			'Deferred-tax DTA recognised without recoverability rationale (REQ-DT-004)',
			[
				'app' => AppIdentity::APP_ID,
				'administrationId' => $administrationId,
				'jurisdiction' => (string)($loss['jurisdiction'] ?? 'NL'),
				'originatingYear' => (int)($loss['originatingYear'] ?? 0),
				'dtaRecognised' => $dtaRecognised,
			]
		);
	}//end warnWhenDtaUnjustified()

	/**
	 * Revalue the jurisdiction's temporary differences that reverse in or
	 * after the year the run's enacted rate takes effect.
	 *
	 * @param DeferredTaxRun $run The period, administration, jurisdiction and enacted rate.
	 *
	 * @return void
	 */
	private function applyJurisdictionRateChange(DeferredTaxRun $run): void {
		if ($run->newRateBps === 0 || $run->effectiveDate === '') {
			return;
		}

		$effectiveYear = (int)substr($run->effectiveDate, 0, 4);

		// Fetch all TemporaryDifference records for this jurisdiction.
		$diffs = $this->store(schema: 'TemporaryDifference')
			->findAll(['filters' => $run->jurisdictionFilters()]);

		foreach ($diffs as $diff) {
			if (((int)($diff['expectedReversalYear'] ?? 0)) >= $effectiveYear) {
				$this->revalueDifference(run: $run, diff: $diff);
			}
		}
	}//end applyJurisdictionRateChange()

	/**
	 * Revalue one temporary difference at the enacted rate and record the
	 * rate-change movement (REQ-DT-009); nothing happens when the adjustment
	 * is zero or the record has no id.
	 *
	 * @param DeferredTaxRun      $run  The period, administration, jurisdiction and enacted rate.
	 * @param array<string,mixed> $diff The TemporaryDifference record.
	 *
	 * @return void
	 */
	private function revalueDifference(DeferredTaxRun $run, array $diff): void {
		$diffCents = (int)($diff['temporaryDifference'] ?? 0);
		$adjustment = $this->helper->computeRateChangeAdjustmentCents(
			differenceInCents: $diffCents,
			oldRateBps: (int)($diff['taxRate'] ?? 0),
			newRateBps: $run->newRateBps
		);
		$id = ($diff['id'] ?? ($diff['@self']['id'] ?? null));
		if ($adjustment === 0 || $id === null) {
			return;
		}

		$this->store(schema: 'TemporaryDifference')->updateObject(
			(string)$id,
			[
				'taxRate' => $run->newRateBps,
				'deferredTaxBalance' => $this->helper->computeDeferredTaxCents(differenceInCents: $diffCents, taxRateBps: $run->newRateBps),
			]
		);

		$this->recordRateChangeMovement(
			run: $run,
			adjustment: new CategoryAmount(category: (string)($diff['category'] ?? 'other'), cents: $adjustment)
		);
	}//end revalueDifference()

	/**
	 * Sum the deferred balances of the differences per category.
	 *
	 * @param array<int,array<string,mixed>> $diffs TemporaryDifference records.
	 *
	 * @return array<string|int,int>
	 */
	private static function deferredBalanceByCategory(array $diffs): array {
		$byCategory = [];
		foreach ($diffs as $diff) {
			$category = (string)($diff['category'] ?? 'other');
			if (isset($byCategory[$category]) === false) {
				$byCategory[$category] = 0;
			}

			$byCategory[$category] += (int)($diff['deferredTaxBalance'] ?? 0);
		}

		return $byCategory;
	}//end deferredBalanceByCategory()

	/**
	 * The DeferredTaxMovement record (with its slug) of one category's
	 * balance, rolled forward from the existing movement.
	 *
	 * @param DeferredTaxRun $run     The period, administration and jurisdiction.
	 * @param CategoryAmount $balance The category's summed deferred balance.
	 *
	 * @return array<string,mixed>
	 */
	private function movementRow(DeferredTaxRun $run, CategoryAmount $balance): array {
		// Fetch existing movement (from prior periods) to determine opening balance.
		$existing = $this->findMovementRecord(run: $run, category: $balance->category);

		return [
			'slug' => 'dtm-' . $run->periodId . '-' . $run->jurisdiction . '-' . $balance->category,
			'periodId' => $run->periodId,
			'jurisdiction' => $run->jurisdiction,
			'category' => $balance->category,
			...$this->movementFigures(existing: $existing, totalCents: $balance->cents),
			'linkedJournalEntries' => ($existing['linkedJournalEntries'] ?? []),
			'administrationId' => $run->administrationId,
		];
	}//end movementRow()

	/**
	 * The roll-forward figures of a movement whose closing deferred balance is
	 * $totalCents, carried from the existing movement.
	 *
	 * @param array<string,mixed> $existing   The existing movement ([] when none).
	 * @param int                 $totalCents The category's summed deferred balance.
	 *
	 * @return array<string,int>
	 */
	private function movementFigures(array $existing, int $totalCents): array {
		$openingBalance = (int)($existing['openingBalance'] ?? 0);
		$figures = [
			'openingBalance' => $openingBalance,
			'originatedInPeriod' => $totalCents - $openingBalance,
			'reversedInPeriod' => (int)($existing['reversedInPeriod'] ?? 0),
			'rateChangeAdjustment' => (int)($existing['rateChangeAdjustment'] ?? 0),
			'acquiredViaBusinessCombination' => (int)($existing['acquiredViaBusinessCombination'] ?? 0),
			'translationAdjustment' => (int)($existing['translationAdjustment'] ?? 0),
			'recognisedInPL' => 0,
			'recognisedInOCI' => (int)($existing['recognisedInOCI'] ?? 0),
			'closingBalance' => 0,
		];
		$figures['recognisedInPL'] = $this->movementRecognisedInPl(figures: $figures);
		$figures['closingBalance'] = $this->movementClosingBalance(figures: $figures);

		return $figures;
	}//end movementFigures()

	/**
	 * The movement recognised in P&L.
	 *
	 * @param array<string,int> $figures The movement's roll-forward figures.
	 *
	 * @return int
	 */
	private function movementRecognisedInPl(array $figures): int {
		return $this->helper->computeMovementRecognisedInPL(
			originatedInPeriod: $figures['originatedInPeriod'],
			reversedInPeriod: $figures['reversedInPeriod'],
			rateChangeAdjustment: $figures['rateChangeAdjustment'],
			acquiredViaBusinessCombination: $figures['acquiredViaBusinessCombination']
		);
	}//end movementRecognisedInPl()

	/**
	 * The movement's closing balance.
	 *
	 * @param array<string,int> $figures The movement's roll-forward figures.
	 *
	 * @return int
	 */
	private function movementClosingBalance(array $figures): int {
		return $this->helper->computeMovementClosingBalance(
			openingBalance: $figures['openingBalance'],
			originatedInPeriod: $figures['originatedInPeriod'],
			reversedInPeriod: $figures['reversedInPeriod'],
			rateChangeAdjustment: $figures['rateChangeAdjustment'],
			acquiredViaBusinessCombination: $figures['acquiredViaBusinessCombination'],
			translationAdjustment: $figures['translationAdjustment'],
			recognisedInOCI: $figures['recognisedInOCI']
		);
	}//end movementClosingBalance()

	/**
	 * The DTA total (negative closing balances, as a positive amount) and the
	 * DTL total (the other closing balances) of the movements.
	 *
	 * @param array<int,array<string,mixed>> $movements DeferredTaxMovement records.
	 *
	 * @return array{0:int,1:int} [dtaTotal, dtlTotal]
	 */
	private static function dtaDtlTotals(array $movements): array {
		$dtaTotal = 0;
		$dtlTotal = 0;
		foreach ($movements as $movement) {
			$closing = (int)($movement['closingBalance'] ?? 0);
			if ($closing < 0) {
				$dtaTotal += abs($closing);
				continue;
			}

			$dtlTotal += $closing;
		}

		return [$dtaTotal, $dtlTotal];
	}//end dtaDtlTotals()

	/**
	 * The TaxProvision record (with its slug) of the jurisdiction's totals.
	 *
	 * @param DeferredTaxRun     $run    The period, administration and jurisdiction.
	 * @param array{0:int,1:int} $totals [dtaTotal, dtlTotal].
	 *
	 * @return array<string,mixed>
	 */
	private static function taxProvisionRow(DeferredTaxRun $run, array $totals): array {
		[$dtaTotal, $dtlTotal] = $totals;

		return [
			'slug' => 'tp-' . $run->periodId . '-' . $run->jurisdiction,
			'periodId' => $run->periodId,
			'jurisdiction' => $run->jurisdiction,
			'currentTaxPayable' => 0,
			'currentTaxPrepaid' => 0,
			'dtaTotal' => $dtaTotal,
			'dtlTotal' => $dtlTotal,
			'netDtaDtlPosition' => ($dtlTotal - $dtaTotal),
			'presentationOnBalanceSheet' => 'gross',
			'linkedVpbReturn' => null,
			'administrationId' => $run->administrationId,
		];
	}//end taxProvisionRow()

	/**
	 * Resolve the current tax rate in basis points for a jurisdiction.
	 *
	 * @param DeferredTaxRun $run          The period and administration.
	 * @param string         $jurisdiction ISO 3166-1 alpha-2 code.
	 *
	 * @return int Rate in basis points (e.g. 2580 = 25.80%).
	 */
	private function resolveCurrentTaxRateBps(DeferredTaxRun $run, string $jurisdiction): int {
		$enacted = $this->fetchEnactedRates(run: $run);
		if (isset($enacted[$jurisdiction]['rate']) === true) {
			return (int)$enacted[$jurisdiction]['rate'];
		}

		// NL 2026 default blended rate (25.80%).
		return 2580;
	}//end resolveCurrentTaxRateBps()

	/**
	 * Resolve taxable profit in cents for a jurisdiction from the FiscalPeriod
	 * record, falling back to profitBeforeTax.
	 *
	 * @param DeferredTaxRun $run          The period and administration.
	 * @param string         $jurisdiction ISO 3166-1 alpha-2 code.
	 *
	 * @return int Taxable profit in cents (0 if not found).
	 */
	private function resolveTaxableProfit(DeferredTaxRun $run, string $jurisdiction): int {
		$period = $this->fiscalPeriod(run: $run);
		if ($period === null) {
			return 0;
		}

		$profitData = ($period['taxableProfitByJurisdiction'] ?? []);
		if (is_array($profitData) === true && isset($profitData[$jurisdiction]) === true) {
			return $this->helper->toCents($profitData[$jurisdiction]);
		}

		// Fall back to profitBeforeTax if jurisdiction-specific profit not stored.
		return $this->helper->toCents(($period['profitBeforeTax'] ?? 0));
	}//end resolveTaxableProfit()

	/**
	 * Fetch the enacted tax rates per jurisdiction from the FiscalPeriod record.
	 *
	 * @param DeferredTaxRun $run The period and administration.
	 *
	 * @return array<string,array{rate:int,effectiveDate:string}> Keyed by jurisdiction.
	 */
	private function fetchEnactedRates(DeferredTaxRun $run): array {
		$period = $this->fiscalPeriod(run: $run);
		if ($period === null) {
			return [];
		}

		$rates = ($period['enactedTaxRates'] ?? []);
		if (is_array($rates) === false) {
			return [];
		}

		return $rates;
	}//end fetchEnactedRates()

	/**
	 * The period's FiscalPeriod record, or null when there is none.
	 *
	 * @param DeferredTaxRun $run The period and administration.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fiscalPeriod(DeferredTaxRun $run): ?array {
		$periods = $this->store(schema: 'FiscalPeriod')
			->findAll(['filters' => $run->periodFilters()]);
		if (empty($periods) === true) {
			return null;
		}

		return $periods[0];
	}//end fiscalPeriod()

	/**
	 * Add a rate-change adjustment to the category's DeferredTaxMovement: on
	 * the existing movement when it has an id, else on a new movement whose
	 * P&L and closing balance are the adjustment (REQ-DT-009).
	 *
	 * @param DeferredTaxRun $run        The period, administration and jurisdiction.
	 * @param CategoryAmount $adjustment The category and its adjustment in cents.
	 *
	 * @return void
	 */
	private function recordRateChangeMovement(DeferredTaxRun $run, CategoryAmount $adjustment): void {
		$existing = $this->findMovementRecord(run: $run, category: $adjustment->category);
		$data = [
			'periodId' => $run->periodId,
			'jurisdiction' => $run->jurisdiction,
			'category' => $adjustment->category,
			'rateChangeAdjustment' => ((int)($existing['rateChangeAdjustment'] ?? 0) + $adjustment->cents),
			'administrationId' => $run->administrationId,
		];

		$id = ($existing['id'] ?? ($existing['@self']['id'] ?? null));
		if ($id !== null) {
			$this->store(schema: 'DeferredTaxMovement')->updateObject((string)$id, $data);
			return;
		}

		$this->upsertRecord(
			schema: 'DeferredTaxMovement',
			row: self::newRateChangeMovementRow(slug: 'dtm-' . $run->periodId . '-' . $run->jurisdiction . '-' . $adjustment->category, data: $data)
		);
	}//end recordRateChangeMovement()

	/**
	 * A new DeferredTaxMovement (with its slug) holding only a rate-change
	 * adjustment: its P&L and closing balance are the adjustment.
	 *
	 * @param string              $slug The movement slug.
	 * @param array<string,mixed> $data The movement key and its rateChangeAdjustment.
	 *
	 * @return array<string,mixed>
	 */
	private static function newRateChangeMovementRow(string $slug, array $data): array {
		return array_merge(
			['slug' => $slug],
			[
				'openingBalance' => 0,
				'originatedInPeriod' => 0,
				'reversedInPeriod' => 0,
				'acquiredViaBusinessCombination' => 0,
				'translationAdjustment' => 0,
				'recognisedInPL' => $data['rateChangeAdjustment'],
				'recognisedInOCI' => 0,
				'closingBalance' => $data['rateChangeAdjustment'],
				'linkedJournalEntries' => [],
			],
			$data
		);
	}//end newRateChangeMovementRow()

	/**
	 * The category's existing DeferredTaxMovement in the run's jurisdiction,
	 * [] when there is none.
	 *
	 * @param DeferredTaxRun $run      The period, administration and jurisdiction.
	 * @param string|int     $category The movement category.
	 *
	 * @return array<string,mixed>
	 */
	private function findMovementRecord(DeferredTaxRun $run, string|int $category): array {
		$results = $this->store(schema: 'DeferredTaxMovement')->findAll(
			[
				'filters' => [
					'periodId' => $run->periodId,
					'jurisdiction' => $run->jurisdiction,
					'category' => $category,
					'administrationId' => $run->administrationId,
				],
			]
		);

		if (empty($results) === false) {
			return $results[0];
		}

		return [];
	}//end findMovementRecord()

	/**
	 * Update the record with the row's slug when one exists (with an id),
	 * else create it with the slug.
	 *
	 * @param string              $schema OR schema slug.
	 * @param array<string,mixed> $row    The record, its 'slug' first.
	 *
	 * @return void
	 */
	private function upsertRecord(string $schema, array $row): void {
		$data = $row;
		unset($data['slug']);

		$existing = $this->store(schema: $schema)->findAll(['filters' => ['slug' => $row['slug']]]);
		$id = null;
		if (empty($existing) === false) {
			$id = ($existing[0]['id'] ?? ($existing[0]['@self']['id'] ?? null));
		}

		if ($id !== null) {
			$this->store(schema: $schema)->updateObject((string)$id, $data);
			return;
		}

		$this->store(schema: $schema)->saveObject($row);
	}//end upsertRecord()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * The OpenRegister ObjectService set to the configured register and $schema.
	 *
	 * @param string $schema OR schema slug.
	 *
	 * @return mixed OR ObjectService instance.
	 */
	private function store(string $schema): mixed {
		return $this->objectService
			->setRegister($this->register())
			->setSchema($schema);
	}//end store()
}//end class
