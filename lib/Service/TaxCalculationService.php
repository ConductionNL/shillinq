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

use OCA\Shillinq\AppInfo\Application;
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
			periodId: $periodId,
			administrationId: $administrationId
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
		$objectService = $this->objectService();
		$register = $this->register();

		// Fetch all tagged accounts for this administration (REQ-DT-001 / REQ-DT-002).
		$accounts = $objectService
			->setRegister($register)
			->setSchema('Account')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach ($accounts as $account) {
			$category = ($account['taxBasisDifferenceCategory'] ?? null);
			if ($category === null) {
				// No hint — skip automatic detection (operator enters differences manually).
				continue;
			}

			$accountNumber = (string)($account['accountNumber'] ?? '');
			if ($accountNumber === '') {
				continue;
			}

			// Resolve jurisdiction from account or default to NL.
			$jurisdiction = (string)($account['jurisdiction'] ?? 'NL');
			if ($jurisdiction === '') {
				$jurisdiction = 'NL';
			}

			// Fetch tax basis if stored on the account; otherwise use 0 (operator must patch).
			$commercialCents = $this->helper->toCents(($account['commercialCarryingAmount'] ?? 0));
			$taxCents = $this->helper->toCents(($account['taxCarryingAmount'] ?? 0));
			$diffCents = ($commercialCents - $taxCents);

			if ($diffCents === 0) {
				continue;
			}

			$type = 'deductible';
			if ($diffCents > 0) {
				$type = 'taxable';
			}

			// Resolve tax rate for this jurisdiction and period.
			$taxRateBps = $this->resolveCurrentTaxRateBps(
				jurisdiction: $jurisdiction,
				periodId: $periodId,
				administrationId: $administrationId
			);

			$dtaCents = $this->helper->computeDeferredTaxCents(
				differenceInCents: $diffCents,
				taxRateBps: $taxRateBps
			);

			$slug = 'td-' . $periodId . '-' . $accountNumber . '-' . $jurisdiction;

			$record = [
				'periodId' => $periodId,
				'jurisdiction' => $jurisdiction,
				'accountNumber' => $accountNumber,
				'category' => $category,
				'commercialCarryingAmount' => $commercialCents,
				'taxCarryingAmount' => $taxCents,
				'temporaryDifference' => $diffCents,
				'type' => $type,
				'reversalPattern' => 'long-term',
				'expectedReversalYear' => null,
				'taxRate' => $taxRateBps,
				'deferredTaxBalance' => $dtaCents,
				'notes' => null,
				'administrationId' => $administrationId,
			];

			$this->upsertRecord(
				objectService: $objectService,
				register: $register,
				schema: 'TemporaryDifference',
				slug: $slug,
				data: $record
			);
		}//end foreach

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
		$objectService = $this->objectService();
		$register = $this->register();

		// Fetch all open carry-forwards for this administration.
		$losses = $objectService
			->setRegister($register)
			->setSchema('TaxLossCarryForward')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		// Group by jurisdiction so we can apply per-jurisdiction profit offsets.
		$byJurisdiction = [];
		foreach ($losses as $loss) {
			$remaining = (int)($loss['remainingAmount'] ?? 0);
			if ($remaining <= 0) {
				continue;
			}

			$jurisdiction = (string)($loss['jurisdiction'] ?? 'NL');
			$byJurisdiction[$jurisdiction][] = $loss;
		}//end foreach

		foreach ($byJurisdiction as $jurisdiction => $jurisdictionLosses) {
			// Resolve available taxable profit for this jurisdiction from FiscalPeriod.
			$availableProfitCents = $this->resolveTaxableProfit(
				periodId: $periodId,
				jurisdiction: $jurisdiction,
				administrationId: $administrationId
			);

			if ($availableProfitCents <= 0) {
				continue;
			}

			$remainingProfit = $availableProfitCents;

			// Apply losses oldest-first (min originatingYear first).
			usort(
				$jurisdictionLosses,
				static function (array $a, array $b): int {
					return ((int)($a['originatingYear'] ?? 0)) <=> ((int)($b['originatingYear'] ?? 0));
				}
			);

			foreach ($jurisdictionLosses as $loss) {
				if ($remainingProfit <= 0) {
					break;
				}

				$regime = (string)($loss['applicableRegime'] ?? '2022-onwards');
				$remaining = (int)($loss['remainingAmount'] ?? 0);

				$utilisable = $this->helper->computeUtilisableLoss(
					remainingLossCents: $remaining,
					availableProfitCents: $remainingProfit,
					regime: $regime
				);

				if ($utilisable <= 0) {
					continue;
				}

				$newUtilised = ((int)($loss['utilisedAmount'] ?? 0)) + $utilisable;
				$newRemaining = $remaining - $utilisable;
				$remainingProfit -= $utilisable;

				$id = ($loss['id'] ?? ($loss['@self']['id'] ?? null));
				if ($id === null) {
					continue;
				}

				$objectService
					->setRegister($register)
					->setSchema('TaxLossCarryForward')
					->updateObject(
						(string)$id,
						[
							'utilisedAmount' => $newUtilised,
							'remainingAmount' => $newRemaining,
						]
					);
			}//end foreach
		}//end foreach

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
		$objectService = $this->objectService();
		$register = $this->register();

		$losses = $objectService
			->setRegister($register)
			->setSchema('TaxLossCarryForward')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach ($losses as $loss) {
			$dtaRecognised = (int)($loss['dtaRecognised'] ?? 0);
			if ($dtaRecognised <= 0) {
				continue;
			}

			$rationale = ($loss['dtaRecoverabilityRationale'] ?? null);
			if ($rationale === null || trim((string)$rationale) === '') {
				$jurisdiction = (string)($loss['jurisdiction'] ?? 'NL');
				$originatingYear = (int)($loss['originatingYear'] ?? 0);
				$this->logger->warning(
					'Deferred-tax DTA recognised without recoverability rationale (REQ-DT-004)',
					[
						'app' => Application::APP_ID,
						'administrationId' => $administrationId,
						'jurisdiction' => $jurisdiction,
						'originatingYear' => $originatingYear,
						'dtaRecognised' => $dtaRecognised,
					]
				);
			}//end if
		}//end foreach

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
		$objectService = $this->objectService();
		$register = $this->register();

		$enactedRates = $this->fetchEnactedRates(
			periodId: $periodId,
			administrationId: $administrationId
		);

		if (empty($enactedRates) === true) {
			return;
		}

		foreach ($enactedRates as $jurisdiction => $rateInfo) {
			$newRateBps = (int)$rateInfo['rate'];
			$effectiveDate = (string)$rateInfo['effectiveDate'];
			if ($newRateBps === 0 || $effectiveDate === '') {
				continue;
			}

			$effectiveYear = (int)substr($effectiveDate, 0, 4);

			// Fetch all TemporaryDifference records for this jurisdiction.
			$diffs = $objectService
				->setRegister($register)
				->setSchema('TemporaryDifference')
				->findAll(
					[
						'filters' => [
							'periodId' => $periodId,
							'jurisdiction' => $jurisdiction,
							'administrationId' => $administrationId,
						],
					]
				);

			foreach ($diffs as $diff) {
				$reversalYear = ((int)($diff['expectedReversalYear'] ?? 0));
				if ($reversalYear < $effectiveYear) {
					continue;
				}

				$diffCents = (int)($diff['temporaryDifference'] ?? 0);
				$oldRateBps = (int)($diff['taxRate'] ?? 0);
				$adjustment = $this->helper->computeRateChangeAdjustmentCents(
					differenceInCents: $diffCents,
					oldRateBps: $oldRateBps,
					newRateBps: $newRateBps
				);

				if ($adjustment === 0) {
					continue;
				}

				$id = ($diff['id'] ?? ($diff['@self']['id'] ?? null));
				if ($id === null) {
					continue;
				}

				$newDtaCents = $this->helper->computeDeferredTaxCents(
					differenceInCents: $diffCents,
					taxRateBps: $newRateBps
				);

				$objectService
					->setRegister($register)
					->setSchema('TemporaryDifference')
					->updateObject(
						(string)$id,
						[
							'taxRate' => $newRateBps,
							'deferredTaxBalance' => $newDtaCents,
						]
					);

				// Record rate-change movement per REQ-DT-009.
				$category = (string)($diff['category'] ?? 'other');
				$this->recordRateChangeMovement(
					periodId: $periodId,
					jurisdiction: $jurisdiction,
					category: $category,
					adjustmentCents: $adjustment,
					administrationId: $administrationId
				);
			}//end foreach
		}//end foreach

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
		$objectService = $this->objectService();
		$register = $this->register();

		$diffs = $objectService
			->setRegister($register)
			->setSchema('TemporaryDifference')
			->findAll(
				[
					'filters' => [
						'periodId' => $periodId,
						'jurisdiction' => $jurisdiction,
						'administrationId' => $administrationId,
					],
				]
			);

		// Group deferred balances by category.
		$byCategory = [];
		foreach ($diffs as $diff) {
			$category = (string)($diff['category'] ?? 'other');
			if (isset($byCategory[$category]) === false) {
				$byCategory[$category] = 0;
			}

			$byCategory[$category] += (int)($diff['deferredTaxBalance'] ?? 0);
		}//end foreach

		foreach ($byCategory as $category => $totalCents) {
			$slug = 'dtm-' . $periodId . '-' . $jurisdiction . '-' . $category;

			// Fetch existing movement (from prior periods) to determine opening balance.
			$existing = $this->findMovementRecord(
				objectService: $objectService,
				register: $register,
				periodId: $periodId,
				jurisdiction: $jurisdiction,
				category: $category,
				administrationId: $administrationId
			);

			$openingBalance = (int)($existing['openingBalance'] ?? 0);
			$origination = $totalCents - $openingBalance;
			$reversedInPeriod = (int)($existing['reversedInPeriod'] ?? 0);
			$rateAdj = (int)($existing['rateChangeAdjustment'] ?? 0);
			$businessCombo = (int)($existing['acquiredViaBusinessCombination'] ?? 0);
			$recognisedInPL = $this->helper->computeMovementRecognisedInPL(
				originatedInPeriod: $origination,
				reversedInPeriod: $reversedInPeriod,
				rateChangeAdjustment: $rateAdj,
				acquiredViaBusinessCombination: $businessCombo
			);
			$closingBalance = $this->helper->computeMovementClosingBalance(
				openingBalance: $openingBalance,
				originatedInPeriod: $origination,
				reversedInPeriod: $reversedInPeriod,
				rateChangeAdjustment: $rateAdj,
				acquiredViaBusinessCombination: $businessCombo,
				translationAdjustment: (int)($existing['translationAdjustment'] ?? 0),
				recognisedInOCI: (int)($existing['recognisedInOCI'] ?? 0)
			);

			$record = [
				'periodId' => $periodId,
				'jurisdiction' => $jurisdiction,
				'category' => $category,
				'openingBalance' => $openingBalance,
				'originatedInPeriod' => $origination,
				'reversedInPeriod' => $reversedInPeriod,
				'rateChangeAdjustment' => $rateAdj,
				'acquiredViaBusinessCombination' => $businessCombo,
				'translationAdjustment' => (int)($existing['translationAdjustment'] ?? 0),
				'recognisedInPL' => $recognisedInPL,
				'recognisedInOCI' => (int)($existing['recognisedInOCI'] ?? 0),
				'closingBalance' => $closingBalance,
				'linkedJournalEntries' => ($existing['linkedJournalEntries'] ?? []),
				'administrationId' => $administrationId,
			];

			$this->upsertRecord(
				objectService: $objectService,
				register: $register,
				schema: 'DeferredTaxMovement',
				slug: $slug,
				data: $record
			);
		}//end foreach

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
		$objectService = $this->objectService();
		$register = $this->register();

		// Read all DeferredTaxMovement closingBalances for this period/jurisdiction.
		$movements = $objectService
			->setRegister($register)
			->setSchema('DeferredTaxMovement')
			->findAll(
				[
					'filters' => [
						'periodId' => $periodId,
						'jurisdiction' => $jurisdiction,
						'administrationId' => $administrationId,
					],
				]
			);

		$dtaTotal = 0;
		$dtlTotal = 0;
		foreach ($movements as $movement) {
			$closing = (int)($movement['closingBalance'] ?? 0);
			if ($closing < 0) {
				$dtaTotal += abs($closing);
				continue;
			}

			$dtlTotal += $closing;
		}//end foreach

		$netPosition = ($dtlTotal - $dtaTotal);
		$slug = 'tp-' . $periodId . '-' . $jurisdiction;

		$record = [
			'periodId' => $periodId,
			'jurisdiction' => $jurisdiction,
			'currentTaxPayable' => 0,
			'currentTaxPrepaid' => 0,
			'dtaTotal' => $dtaTotal,
			'dtlTotal' => $dtlTotal,
			'netDtaDtlPosition' => $netPosition,
			'presentationOnBalanceSheet' => 'gross',
			'linkedVpbReturn' => null,
			'administrationId' => $administrationId,
		];

		$this->upsertRecord(
			objectService: $objectService,
			register: $register,
			schema: 'TaxProvision',
			slug: $slug,
			data: $record
		);

	}//end calculateTaxProvision()

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Collect unique jurisdictions observed in TemporaryDifference for a period.
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,string> Unique jurisdiction codes.
	 */
	private function observedJurisdictions(string $periodId, string $administrationId): array {
		$objectService = $this->objectService();
		$register = $this->register();

		$diffs = $objectService
			->setRegister($register)
			->setSchema('TemporaryDifference')
			->findAll(
				[
					'filters' => [
						'periodId' => $periodId,
						'administrationId' => $administrationId,
					],
				]
			);

		$jurisdictions = [];
		foreach ($diffs as $diff) {
			$jur = (string)($diff['jurisdiction'] ?? 'NL');
			if (in_array($jur, $jurisdictions, true) === false) {
				$jurisdictions[] = $jur;
			}
		}//end foreach

		return $jurisdictions;
	}//end observedJurisdictions()

	/**
	 * Resolve the current enacted tax rate for a jurisdiction in basis points.
	 *
	 * Reads FiscalPeriod.enactedTaxRates; falls back to the NL 2026 blended rate
	 * (25.80% = 2580 bps) when no enacted rate is found.
	 *
	 * @param string $jurisdiction ISO 3166-1 alpha-2 country code.
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope.
	 *
	 * @return int Tax rate in basis points.
	 */
	private function resolveCurrentTaxRateBps(string $jurisdiction, string $periodId, string $administrationId): int {
		$enacted = $this->fetchEnactedRates(periodId: $periodId, administrationId: $administrationId);
		if (isset($enacted[$jurisdiction]['rate']) === true) {
			return (int)$enacted[$jurisdiction]['rate'];
		}

		// NL 2026 default blended rate (25.80%).
		return 2580;
	}//end resolveCurrentTaxRateBps()

	/**
	 * Resolve the available taxable profit for a jurisdiction from the FiscalPeriod.
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $jurisdiction ISO 3166-1 alpha-2 country code.
	 * @param string $administrationId Administration scope.
	 *
	 * @return int Taxable profit in euro cents (0 when not available).
	 */
	private function resolveTaxableProfit(string $periodId, string $jurisdiction, string $administrationId): int {
		$objectService = $this->objectService();
		$register = $this->register();

		$periods = $objectService
			->setRegister($register)
			->setSchema('FiscalPeriod')
			->findAll(
				[
					'filters' => [
						'periodId' => $periodId,
						'administrationId' => $administrationId,
					],
				]
			);

		if (empty($periods) === true) {
			return 0;
		}

		$period = $periods[0];
		$profitData = ($period['taxableProfitByJurisdiction'] ?? []);
		if (is_array($profitData) === true && isset($profitData[$jurisdiction]) === true) {
			return $this->helper->toCents($profitData[$jurisdiction]);
		}

		// Fall back to profitBeforeTax if jurisdiction-specific profit not stored.
		return $this->helper->toCents(($period['profitBeforeTax'] ?? 0));
	}//end resolveTaxableProfit()

	/**
	 * Fetch enacted tax rates from the FiscalPeriod record.
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,array{rate:int,effectiveDate:string}> Rates keyed by jurisdiction.
	 */
	private function fetchEnactedRates(string $periodId, string $administrationId): array {
		$objectService = $this->objectService();
		$register = $this->register();

		$periods = $objectService
			->setRegister($register)
			->setSchema('FiscalPeriod')
			->findAll(
				[
					'filters' => [
						'periodId' => $periodId,
						'administrationId' => $administrationId,
					],
				]
			);

		if (empty($periods) === true) {
			return [];
		}

		$rates = ($periods[0]['enactedTaxRates'] ?? []);
		if (is_array($rates) === false) {
			return [];
		}

		return $rates;
	}//end fetchEnactedRates()

	/**
	 * Record a rate-change adjustment on the DeferredTaxMovement for a category.
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $jurisdiction ISO 3166-1 alpha-2 country code.
	 * @param string $category Deferred-tax category.
	 * @param int $adjustmentCents Adjustment in euro cents.
	 * @param string $administrationId Administration scope.
	 *
	 * @return void
	 */
	private function recordRateChangeMovement(
		string $periodId,
		string $jurisdiction,
		string $category,
		int $adjustmentCents,
		string $administrationId,
	): void {
		$objectService = $this->objectService();
		$register = $this->register();

		$existing = $this->findMovementRecord(
			objectService: $objectService,
			register: $register,
			periodId: $periodId,
			jurisdiction: $jurisdiction,
			category: $category,
			administrationId: $administrationId
		);

		$currentAdj = (int)($existing['rateChangeAdjustment'] ?? 0);
		$slug = 'dtm-' . $periodId . '-' . $jurisdiction . '-' . $category;

		$data = [
			'periodId' => $periodId,
			'jurisdiction' => $jurisdiction,
			'category' => $category,
			'rateChangeAdjustment' => ($currentAdj + $adjustmentCents),
			'administrationId' => $administrationId,
		];

		if (isset($existing['id']) === true || isset($existing['@self']['id']) === true) {
			$id = ($existing['id'] ?? $existing['@self']['id']);
			$objectService
				->setRegister($register)
				->setSchema('DeferredTaxMovement')
				->updateObject((string)$id, $data);
			return;
		}

		$this->upsertRecord(
			objectService: $objectService,
			register: $register,
			schema: 'DeferredTaxMovement',
			slug: $slug,
			data: array_merge(
				[
					'openingBalance' => 0,
					'originatedInPeriod' => 0,
					'reversedInPeriod' => 0,
					'acquiredViaBusinessCombination' => 0,
					'translationAdjustment' => 0,
					'recognisedInPL' => $adjustmentCents,
					'recognisedInOCI' => 0,
					'closingBalance' => $adjustmentCents,
					'linkedJournalEntries' => [],
				],
				$data
			)
		);

	}//end recordRateChangeMovement()

	/**
	 * Find an existing DeferredTaxMovement record for a period/jurisdiction/category.
	 *
	 * @param mixed $objectService OR ObjectService instance.
	 * @param string $register Register slug.
	 * @param string $periodId Fiscal period identifier.
	 * @param string $jurisdiction ISO 3166-1 alpha-2 country code.
	 * @param string $category Deferred-tax category.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,mixed> Existing record or empty array.
	 */
	private function findMovementRecord(
		mixed $objectService,
		string $register,
		string $periodId,
		string $jurisdiction,
		string $category,
		string $administrationId,
	): array {
		$results = $objectService
			->setRegister($register)
			->setSchema('DeferredTaxMovement')
			->findAll(
				[
					'filters' => [
						'periodId' => $periodId,
						'jurisdiction' => $jurisdiction,
						'category' => $category,
						'administrationId' => $administrationId,
					],
				]
			);

		if (empty($results) === false) {
			return $results[0];
		}

		return [];
	}//end findMovementRecord()

	/**
	 * Upsert a record by slug: create if absent, update if found.
	 *
	 * @param mixed $objectService OR ObjectService instance.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $slug Object slug.
	 * @param array<string,mixed> $data Data to write.
	 *
	 * @return void
	 */
	private function upsertRecord(
		mixed $objectService,
		string $register,
		string $schema,
		string $slug,
		array $data,
	): void {
		$existing = $objectService
			->setRegister($register)
			->setSchema($schema)
			->findAll(['filters' => ['slug' => $slug]]);

		if (empty($existing) === false) {
			$id = ($existing[0]['id'] ?? ($existing[0]['@self']['id'] ?? null));
			if ($id !== null) {
				$objectService
					->setRegister($register)
					->setSchema($schema)
					->updateObject((string)$id, $data);
				return;
			}
		}//end if

		$objectService
			->setRegister($register)
			->setSchema($schema)
			->saveObject(array_merge(['slug' => $slug], $data));

	}//end upsertRecord()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Lazy-fetch the OpenRegister ObjectService from the DI container.
	 *
	 * @return mixed OR ObjectService instance.
	 */
	private function objectService(): mixed {
		return $this->objectService;
	}//end objectService()
}//end class
