<?php

/**
 * Public-Sector IPSAS / GASB Check Provider
 *
 * Contributes executable checks for the international public-sector accrual
 * frameworks of the `public-sector` rule domain to the RuleEngine: the IPSASB's
 * IPSAS standards (statement composition, cash-flow classification, inventory
 * lower-of-cost-and-NRV / replacement-cost measurement, the contingent-liability
 * recognition prohibition, non-cash-generating-asset impairment, non-exchange
 * revenue / tax-on-taxable-event recognition, the budget comparison, consolidation
 * eliminations, short-term employee benefits, financial-instrument recognition and
 * the IPSAS 43 right-of-use lease model) and the US GASB statements (the GASB 34
 * dual government-wide / fund reporting model and its measurement focuses, MD&A,
 * the major-fund criteria, GASB 54 fund-balance classifications, GASB 84 fiduciary
 * fund types, the GASB 87 single lease model and GASB 96 SBITA model with their
 * short-term exceptions, and the GASB 103 budgetary-comparison RSI).
 *
 * These rules target a structured public-sector statement that has no existing rows
 * in the local register, so this provider declares a new PublicSectorStatement
 * object (in lib/Settings/register.d/checks-public-sector.json) and seeds two
 * fully-compliant samples — one IPSAS accrual entity and one US GASB state/local
 * government — via SeedsObjects so the audit actually evaluates these checks. The
 * NL-BBV rules are enforced by VatBbvLedgerTailChecks / BbvStatement and are NOT
 * re-registered here, and the judgemental recognition rules already flagged
 * machineCheckable=false (fair presentation, going concern, accrual basis,
 * provision recognition, control assessment, cost-formula choice, amortised cost,
 * expected credit losses, effective-date / supersession metadata, etc.) are
 * intentionally omitted.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained (it inlines its logic
 * via the private static helpers on this class rather than reaching into
 * RuleEngine). Every ruleId is a real `machineCheckable` id from
 * lib/Standards/rules/public-sector.json.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Executable IPSAS / GASB public-sector checks against PublicSectorStatement.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * Pre-existing debt (issue #506): this class enumerates every IPSAS/GASB
 * public-sector rule the catalogue defines; inherent complexity.
 * Deferred to a follow-up.
 */
final class PublicSectorIpsasGasbChecks implements CheckProvider, SeedsObjects {
	/**
	 * The IPSAS 1.21 complete set of financial statements (component codes).
	 *
	 * @var array<int, string>
	 */
	private const IPSAS_COMPONENTS = [
		'financialPosition',
		'financialPerformance',
		'changesInNetAssets',
		'cashFlow',
		'notes',
	];

	/**
	 * The IPSAS 2.18 cash-flow activity classes.
	 *
	 * @var array<int, string>
	 */
	private const CASH_FLOW_ACTIVITIES = ['operating', 'investing', 'financing'];

	/**
	 * The GASB 54 five fund-balance classifications.
	 *
	 * @var array<int, string>
	 */
	private const GASB54_CLASSIFICATIONS = [
		'nonspendable',
		'restricted',
		'committed',
		'assigned',
		'unassigned',
	];

	/**
	 * The GASB 84 four fiduciary fund types.
	 *
	 * @var array<int, string>
	 */
	private const GASB84_FUND_TYPES = [
		'pensionTrust',
		'investmentTrust',
		'privatePurposeTrust',
		'custodial',
	];

	/**
	 * Per-object-type, per-rule predicates for the public-sector IPSAS / GASB rules.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'PublicSectorStatement' => [
				// ===== IPSAS — IPSASB accrual framework. =====
				// IPSAS 1.21: a complete set comprises the statement of financial
				// position, the statement of financial performance, the statement of
				// changes in net assets/equity, the cash-flow statement, notes (and a
				// budget comparison where the entity makes its budget public). Scoped
				// to IPSAS statements; a GASB statement is vacuously in scope.
				'ipsas-1-21-complete-set' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| (self::hasAll($o, 'components', self::IPSAS_COMPONENTS)
					&& (self::isFalse($o, 'budgetMadePublic') || self::contains($o, 'components', 'budgetComparison'))),

				// IPSAS 2.18: the cash-flow statement classifies cash flows by
				// operating, investing and financing activity.
				'ipsas-2-18-classify-activities' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::hasAll($o, 'cashFlowActivities', self::CASH_FLOW_ACTIVITIES),

				// IPSAS 12.15: ordinary inventories are measured at the lower of cost and
				// net realizable value; inventory acquired through a non-exchange
				// transaction or held for distribution is out of this rule's scope.
				'ipsas-12-15-lower-cost-nrv' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::inventoriesValuedLowerOf($o, 'netRealizableValue', ['ordinary']),

				// IPSAS 12.17: inventories held for distribution at no/nominal charge are
				// measured at the lower of cost and current replacement cost.
				'ipsas-12-17-distribution-replacement-cost' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::inventoriesValuedLowerOf($o, 'currentReplacementCost', ['distribution']),

				// IPSAS 19.35: a contingent liability is not recognized — every modelled
				// contingent liability is disclosed only, never recognized on the face.
				'ipsas-19-35-no-contingent-liability' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::noneRecognized($o, 'contingentLiabilities'),

				// IPSAS 21.52: where the recoverable service amount is below the carrying
				// amount the carrying amount is reduced to it and the difference is the
				// recognized impairment loss.
				'ipsas-21-52-recognise-impairment' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::impairmentsMeasured($o),

				// IPSAS 21.54: an impairment loss on a non-cash-generating asset is
				// recognized immediately in surplus or deficit, unless the asset is
				// carried at a revalued amount.
				'ipsas-21-54-loss-in-surplus-deficit' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::impairmentLossDestinationValid($o),

				// IPSAS 23.44: a non-exchange inflow with a present obligation (condition)
				// recognizes that conditioned portion as a liability; the rest is revenue.
				'ipsas-23-44-condition-creates-liability' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::nonExchangeConditionsSplit($o),

				// IPSAS 23.59: a tax asset is recognized when the taxable event occurs.
				'ipsas-23-59-tax-on-taxable-event' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::taxAssetsOnTaxableEvent($o),

				// IPSAS 24.14: a budget comparison presents original and final budget
				// against actuals on a comparable basis, with a material-difference note.
				'ipsas-24-14-budget-comparison' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::budgetComparisonComplete($o),

				// IPSAS 35.40: consolidation eliminates the controlling entity's
				// investment in each controlled entity and all intragroup balances and
				// transactions — required only of a consolidated statement.
				'ipsas-35-40-eliminate-intragroup' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::intragroupEliminated($o),

				// IPSAS 39.11: short-term employee benefits are recognized at the
				// undiscounted amount expected to be paid as a liability and an expense.
				'ipsas-39-11-short-term-benefits' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::shortTermBenefitsUndiscounted($o),

				// IPSAS 41.10: a financial asset/liability is recognized when, and only
				// when, the entity becomes party to the contractual provisions.
				'ipsas-41-10-recognise-on-becoming-party' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::financialInstrumentsRecognizedOnParty($o),

				// IPSAS 43.23: at commencement a lessee recognizes a right-of-use asset
				// and a lease liability.
				'ipsas-43-23-right-of-use-model' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::leasesHaveRouAndLiability($o),

				// IPSAS 43.27: the lease liability is the present value of the unpaid
				// lease payments discounted at the lease/IBR rate.
				'ipsas-43-27-lease-liability-pv' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::leaseLiabilitiesAtPresentValue($o),

				// IPSAS 47.36: a tax asset is recognized when the taxable event occurs and
				// the recognition criteria are met.
				'ipsas-47-36-taxes-on-taxable-event' => static fn (array $o): bool => self::frameworkNot($o, 'ipsas')
					|| self::taxAssetsOnTaxableEvent($o),

				// ===== GASB — US state and local governments. =====
				// GASB 34: both government-wide and fund financial statements are
				// presented. Scoped to GASB statements.
				'gasb-34-dual-reporting-model' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| (self::isTrue($o, 'governmentWideStatements') && self::isTrue($o, 'fundStatements')),

				// GASB 34: government-wide statements use the economic-resources focus and
				// the full accrual basis, reporting governmental and business-type
				// activities in separate columns.
				'gasb-34-govwide-economic-resources' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| (self::equalsField($o, 'govWideMeasurementFocus', 'economicResources')
					&& self::equalsField($o, 'govWideBasis', 'accrual')
					&& self::contains($o, 'govWideColumns', 'governmental')
					&& self::contains($o, 'govWideColumns', 'businessType')),

				// GASB 34: MD&A is required supplementary information that precedes the
				// basic financial statements.
				'gasb-34-mda-required-rsi' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| (self::isTrue($o, 'mdaPresent') && self::equalsField($o, 'mdaPlacement', 'precedes')),

				// GASB 34: governmental funds use the current-financial-resources focus
				// and the modified-accrual basis.
				'gasb-34-modified-accrual-govfunds' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| (self::equalsField($o, 'govFundsMeasurementFocus', 'currentFinancialResources')
					&& self::equalsField($o, 'govFundsBasis', 'modifiedAccrual')),

				// GASB 34: a fund is major when an element is at least 10% of its category
				// total AND at least 5% of the combined total; the general fund is always
				// major. The declared major flags are internally consistent with that.
				'gasb-34-major-fund-criteria' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| self::majorFundFlagsConsistent($o),

				// GASB 54: governmental fund balance is reported in the five-classification
				// hierarchy.
				'gasb-54-five-classifications' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| self::hasAll($o, 'fundBalanceClassifications', self::GASB54_CLASSIFICATIONS),

				// GASB 84: fiduciary activities are reported in the four fund types.
				'gasb-84-four-fund-types' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| self::fiduciaryFundTypesValid($o),

				// GASB 87: a single lease model treats leases as financings, with no
				// operating-vs-capital distinction.
				'gasb-87-single-lease-model' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| self::singleLeaseModel($o),

				// GASB 87: a lessee recognizes a lease liability at present value and an
				// intangible right-of-use lease asset at commencement (short-term
				// exception aside).
				'gasb-87-lessee-recognition' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| self::gasbLessRecognition($o, 'leases'),

				// GASB 87: leases with a maximum possible term of 12 months or less are
				// excluded from recognition and treated as outflows/inflows.
				'gasb-87-short-term-exception' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| self::shortTermExceptionApplied($o, 'leases'),

				// GASB 96: at commencement of a SBITA a subscription liability is
				// recognized at present value and a right-to-use subscription asset as an
				// intangible capital asset.
				'gasb-96-sbita-recognition' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| self::gasbLessRecognition($o, 'sbitas'),

				// GASB 96: SBITAs with a maximum possible term of 12 months or less are
				// excluded from subscription asset/liability recognition.
				'gasb-96-short-term-exception' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| self::shortTermExceptionApplied($o, 'sbitas'),

				// GASB 103: budgetary-comparison information is presented as RSI with
				// separate variance columns and notes explaining significant variances.
				'gasb-103-budgetary-comparison-rsi' => static fn (array $o): bool => self::frameworkNot($o, 'gasb')
					|| self::budgetaryComparisonRsi($o),
			],
		];

	}//end checks()

	/**
	 * No field backfill on existing rows — PublicSectorStatement is a new type seeded
	 * wholesale by seedObjects().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * Two fully-compliant PublicSectorStatement samples — one IPSAS accrual entity and
	 * one US GASB state/local government — created only when the type is empty so the
	 * audit evaluates these checks. Each sample satisfies EVERY predicate above for its
	 * framework (the framework field gates which rules a given audit context runs).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		// ----- IPSAS accrual entity. -----
		$ipsas = [
			'framework' => 'ipsas',
			'jurisdiction' => 'global',
			'reportingEntity' => 'National Public Health Authority',
			'fiscalYear' => 2025,
			'currency' => 'EUR',
			'consolidated' => true,
			'budgetMadePublic' => true,
			'components' => [
				'financialPosition',
				'financialPerformance',
				'changesInNetAssets',
				'cashFlow',
				'notes',
				'budgetComparison',
			],
			'cashFlowActivities' => ['operating', 'investing', 'financing'],
			'inventories' => [
				// Ordinary: lower of cost (100) and NRV (90) => 90.
				['name' => 'Medical supplies', 'category' => 'ordinary', 'cost' => 100000.0, 'netRealizableValue' => 90000.0, 'carryingAmount' => 90000.0],
				// Ordinary: cost (50) below NRV (70) => 50.
				['name' => 'Office stock', 'category' => 'ordinary', 'cost' => 50000.0, 'netRealizableValue' => 70000.0, 'carryingAmount' => 50000.0],
				// Distribution: lower of cost (40) and replacement cost (35) => 35.
				['name' => 'Aid parcels', 'category' => 'distribution', 'cost' => 40000.0, 'currentReplacementCost' => 35000.0, 'carryingAmount' => 35000.0],
			],
			'contingentLiabilities' => [
				['description' => 'Pending litigation', 'recognized' => false, 'disclosed' => true],
				['description' => 'Guarantee call risk', 'recognized' => false, 'disclosed' => true],
			],
			'impairments' => [
				// Carrying reduced to recoverable; loss = old carrying - recoverable.
				['asset' => 'School building', 'carryingAmountBefore' => 800000.0, 'recoverableServiceAmount' => 650000.0, 'carryingAmount' => 650000.0, 'impairmentLoss' => 150000.0, 'revalued' => false, 'recognizedIn' => 'surplusOrDeficit'],
				['asset' => 'Bridge (revalued)', 'carryingAmountBefore' => 500000.0, 'recoverableServiceAmount' => 470000.0, 'carryingAmount' => 470000.0, 'impairmentLoss' => 30000.0, 'revalued' => true, 'recognizedIn' => 'revaluationSurplus'],
			],
			'nonExchangeRevenues' => [
				// Conditioned grant: 100 total, 40 condition outstanding => 40 liability, 60 revenue.
				['source' => 'Capital grant', 'amount' => 100000.0, 'hasCondition' => true, 'liabilityRecognized' => 40000.0, 'revenueRecognized' => 60000.0],
				// Unconditioned transfer: full revenue, no liability.
				['source' => 'General transfer', 'amount' => 250000.0, 'hasCondition' => false, 'liabilityRecognized' => 0.0, 'revenueRecognized' => 250000.0],
			],
			'taxAssets' => [
				['taxType' => 'Property tax', 'taxableEventOccurred' => true, 'amount' => 320000.0],
				['taxType' => 'Income tax', 'taxableEventOccurred' => true, 'amount' => 1200000.0],
			],
			'budgetComparison' => [
				'basis' => 'comparable',
				'originalBudget' => 5000000.0,
				'finalBudget' => 5200000.0,
				'actualAmount' => 5050000.0,
				'materialDifferenceNote' => 'Final budget increased by supplementary appropriation; actual underspend explained in note 14.',
			],
			'intragroupEliminations' => [
				['type' => 'investment', 'amount' => 750000.0],
				['type' => 'intragroupBalance', 'amount' => 120000.0],
				['type' => 'intragroupTransaction', 'amount' => 90000.0],
			],
			'shortTermEmployeeBenefits' => [
				// Undiscounted, recognized as both liability and expense, service rendered.
				['type' => 'Accrued leave', 'undiscountedAmount' => 85000.0, 'liabilityRecognized' => 85000.0, 'expenseRecognized' => 85000.0, 'serviceRendered' => true, 'discounted' => false],
			],
			'financialInstruments' => [
				['name' => 'Government bond', 'partyToContract' => true, 'recognized' => true],
				['name' => 'Trade payable', 'partyToContract' => true, 'recognized' => true],
			],
			'leases' => [
				// 12-month-plus lease: ROU asset + liability at PV.
				// PV of 5 annual payments of 20000 at 5% = 86589.53...
				['asset' => 'Office space', 'maxTermMonths' => 60, 'rightOfUseAsset' => 86589.53, 'leaseLiability' => 86589.53, 'annualPayment' => 20000.0, 'termYears' => 5, 'discountRate' => 0.05, 'singleModel' => true],
			],
		];

		// ----- US GASB state/local government. -----
		$gasb = [
			'framework' => 'gasb',
			'jurisdiction' => 'US',
			'reportingEntity' => 'City of Springfield',
			'fiscalYear' => 2025,
			'currency' => 'USD',
			'governmentWideStatements' => true,
			'fundStatements' => true,
			'govWideMeasurementFocus' => 'economicResources',
			'govWideBasis' => 'accrual',
			'govWideColumns' => ['governmental', 'businessType'],
			'mdaPresent' => true,
			'mdaPlacement' => 'precedes',
			'govFundsMeasurementFocus' => 'currentFinancialResources',
			'govFundsBasis' => 'modifiedAccrual',
			'funds' => [
				// General fund: always major.
				['name' => 'General Fund', 'category' => 'governmental', 'isGeneralFund' => true, 'major' => true, 'categoryPercent' => 60.0, 'combinedPercent' => 45.0],
				// Major: >=10% of category AND >=5% combined.
				['name' => 'Capital Projects', 'category' => 'governmental', 'isGeneralFund' => false, 'major' => true, 'categoryPercent' => 25.0, 'combinedPercent' => 18.0],
				// Not major: fails the 10% category test.
				['name' => 'Special Grants', 'category' => 'governmental', 'isGeneralFund' => false, 'major' => false, 'categoryPercent' => 4.0, 'combinedPercent' => 3.0],
				// Not major: meets category but fails combined 5% test.
				['name' => 'Small Enterprise', 'category' => 'enterprise', 'isGeneralFund' => false, 'major' => false, 'categoryPercent' => 12.0, 'combinedPercent' => 3.5],
			],
			'fundBalanceClassifications' => [
				'nonspendable',
				'restricted',
				'committed',
				'assigned',
				'unassigned',
			],
			'fiduciaryFunds' => [
				['name' => 'Employee Pension Plan', 'fundType' => 'pensionTrust'],
				['name' => 'External Investment Pool', 'fundType' => 'investmentTrust'],
				['name' => 'Scholarship Trust', 'fundType' => 'privatePurposeTrust'],
				['name' => 'Tax Collection Custodial', 'fundType' => 'custodial'],
			],
			'leases' => [
				// Long-term: single-model financing, liability at PV + intangible ROU.
				// PV of 4 annual payments of 30000 at 4% = 108896.86.
				['asset' => 'Fleet vehicles', 'maxTermMonths' => 48, 'leaseLiability' => 108896.86, 'rightOfUseAsset' => 108896.86, 'intangibleRouAsset' => true, 'annualPayment' => 30000.0, 'termYears' => 4, 'discountRate' => 0.04, 'singleModel' => true],
				// Short-term: <=12 months, excluded from recognition, expensed.
				['asset' => 'Event tent', 'maxTermMonths' => 6, 'leaseLiability' => 0.0, 'rightOfUseAsset' => 0.0, 'intangibleRouAsset' => false, 'shortTerm' => true, 'singleModel' => true],
			],
			'sbitas' => [
				// Long-term SBITA: subscription liability at PV + intangible right-to-use asset.
				// PV of 3 annual payments of 50000 at 5% = 136162.40.
				['name' => 'ERP cloud subscription', 'maxTermMonths' => 36, 'subscriptionLiability' => 136162.40, 'rightOfUseAsset' => 136162.40, 'intangibleRouAsset' => true, 'annualPayment' => 50000.0, 'termYears' => 3, 'discountRate' => 0.05],
				// Short-term SBITA: <=12 months, excluded.
				['name' => 'Seasonal SaaS tool', 'maxTermMonths' => 9, 'subscriptionLiability' => 0.0, 'rightOfUseAsset' => 0.0, 'intangibleRouAsset' => false, 'shortTerm' => true],
			],
			'budgetaryComparison' => [
				'presentedAsRsi' => true,
				'varianceColumns' => ['originalToFinal', 'finalToActual'],
				'significantVarianceNote' => 'Significant variances in public-safety and capital-outlay lines explained in the budgetary-comparison schedule notes.',
			],
		];

		return [
			'PublicSectorStatement' => [$ipsas, $gasb],
		];

	}//end seedObjects()

	// ===================== private helpers =====================

	/**
	 * A non-empty scalar value is present under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return array_key_exists($key, $o) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * The string value under $key equals $value (case-insensitive, trimmed).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 * @param string $value The expected value.
	 *
	 * @return bool
	 */
	private static function equalsField(array $o, string $key, string $value): bool {
		return self::present($o, $key) === true
			&& strcasecmp(trim((string)$o[$key]), $value) === 0;

	}//end equalsField()

	/**
	 * The statement's framework is NOT $framework (case-insensitive) — used to scope a
	 * framework-specific rule out of a statement prepared under another framework.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $framework The framework key to exclude (e.g. 'ipsas').
	 *
	 * @return bool
	 */
	private static function frameworkNot(array $o, string $framework): bool {
		return self::present($o, 'framework') === true
			&& strcasecmp(trim((string)$o['framework']), $framework) !== 0;

	}//end frameworkNot()

	/**
	 * The field under $key is a boolean (or boolean-like) value at all.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isBool(array $o, string $key): bool {
		return array_key_exists($key, $o) === true
			&& (is_bool($o[$key]) === true || in_array($o[$key], [0, 1, '0', '1', 'true', 'false'], true) === true);

	}//end isBool()

	/**
	 * The boolean field under $key is explicitly true.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isTrue(array $o, string $key): bool {
		return self::isBool($o, $key) === true
			&& in_array($o[$key], [true, 1, '1', 'true'], true) === true;

	}//end isTrue()

	/**
	 * The boolean field under $key is explicitly false.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isFalse(array $o, string $key): bool {
		return self::isBool($o, $key) === true
			&& in_array($o[$key], [false, 0, '0', 'false'], true) === true;

	}//end isFalse()

	/**
	 * The list field under $key contains $value (case-insensitive scalar membership).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The list field name.
	 * @param string $value The value to find.
	 *
	 * @return bool
	 */
	private static function contains(array $o, string $key, string $value): bool {
		$list = ($o[$key] ?? null);
		if (is_array($list) === false) {
			return false;
		}

		foreach ($list as $item) {
			if (is_array($item) === false && strcasecmp(trim((string)$item), $value) === 0) {
				return true;
			}
		}

		return false;
	}//end contains()

	/**
	 * The list field under $key contains every value in $required (each present).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The list field name.
	 * @param array<int, string> $required The values that must all be present.
	 *
	 * @return bool
	 */
	private static function hasAll(array $o, string $key, array $required): bool {
		foreach ($required as $value) {
			if (self::contains($o, $key, $value) === false) {
				return false;
			}
		}

		return true;
	}//end hasAll()

	/**
	 * The numeric value under $key (0.0 when absent / non-numeric).
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string $key The field name.
	 *
	 * @return float
	 */
	private static function num(array $row, string $key): float {
		return is_numeric($row[$key] ?? null) === true ? (float)$row[$key] : 0.0;
	}//end num()

	/**
	 * A list field under $key is a non-empty list of row arrays; returns it (or null).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The list field name.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function rows(array $o, string $key): ?array {
		$list = ($o[$key] ?? null);
		if (is_array($list) === false || $list === []) {
			return null;
		}

		foreach ($list as $row) {
			if (is_array($row) === false) {
				return null;
			}
		}

		return array_values($list);
	}//end rows()

	/**
	 * Every inventory row in one of $categories is carried at the lower of its cost and
	 * the $compareKey value (NRV or current replacement cost), at cent precision. Rows
	 * in other categories are ignored. Requires at least one matching row.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $compareKey The second measurement field to compare cost against.
	 * @param array<int, string> $categories The inventory categories this rule governs.
	 *
	 * @return bool
	 */
	private static function inventoriesValuedLowerOf(array $o, string $compareKey, array $categories): bool {
		$rows = self::rows($o, 'inventories');
		if ($rows === null) {
			return false;
		}

		$matched = 0;
		foreach ($rows as $row) {
			$category = strtolower(trim((string)($row['category'] ?? '')));
			if (in_array($category, array_map('strtolower', $categories), true) === false) {
				continue;
			}

			$matched++;
			$lower = min(self::num($row, 'cost'), self::num($row, $compareKey));
			if (abs(self::num($row, 'carryingAmount') - $lower) >= 0.005) {
				return false;
			}
		}

		return $matched > 0;
	}//end inventoriesValuedLowerOf()

	/**
	 * Every row in the list under $key is NOT recognized (recognized flag explicitly
	 * false). Requires at least one row.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The list field name.
	 *
	 * @return bool
	 */
	private static function noneRecognized(array $o, string $key): bool {
		$rows = self::rows($o, $key);
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			if (self::isFalse($row, 'recognized') === false) {
				return false;
			}
		}

		return true;
	}//end noneRecognized()

	/**
	 * Every impairment row reduces the carrying amount to the recoverable service amount
	 * and records the loss as the difference (carryingBefore - recoverable), at cent
	 * precision. Requires at least one row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function impairmentsMeasured(array $o): bool {
		$rows = self::rows($o, 'impairments');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			$before = self::num($row, 'carryingAmountBefore');
			$recoverable = self::num($row, 'recoverableServiceAmount');
			if (abs(self::num($row, 'carryingAmount') - $recoverable) >= 0.005) {
				return false;
			}

			if (abs(self::num($row, 'impairmentLoss') - ($before - $recoverable)) >= 0.005) {
				return false;
			}
		}

		return true;
	}//end impairmentsMeasured()

	/**
	 * Every impairment loss is recognized in surplus or deficit, unless the asset is
	 * carried at a revalued amount (then it may hit the revaluation surplus). Requires
	 * at least one row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function impairmentLossDestinationValid(array $o): bool {
		$rows = self::rows($o, 'impairments');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			if (self::isTrue($row, 'revalued') === true) {
				if (self::present($row, 'recognizedIn') === false) {
					return false;
				}

				continue;
			}

			if (strcasecmp(trim((string)($row['recognizedIn'] ?? '')), 'surplusOrDeficit') !== 0) {
				return false;
			}
		}

		return true;
	}//end impairmentLossDestinationValid()

	/**
	 * Every non-exchange revenue row splits correctly: when it carries a condition a
	 * liability portion is recognized and liability + revenue equals the inflow amount;
	 * without a condition the full amount is revenue and no liability arises. Cent
	 * precision; requires at least one row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function nonExchangeConditionsSplit(array $o): bool {
		$rows = self::rows($o, 'nonExchangeRevenues');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			$amount = self::num($row, 'amount');
			$liability = self::num($row, 'liabilityRecognized');
			$revenue = self::num($row, 'revenueRecognized');
			if (abs(($liability + $revenue) - $amount) >= 0.005) {
				return false;
			}

			if (self::isTrue($row, 'hasCondition') === true) {
				if ($liability <= 0.0) {
					return false;
				}
			} elseif ($liability !== 0.0) {
				return false;
			}
		}

		return true;
	}//end nonExchangeConditionsSplit()

	/**
	 * Every tax-asset row is recognized only when its taxable event has occurred.
	 * Requires at least one row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function taxAssetsOnTaxableEvent(array $o): bool {
		$rows = self::rows($o, 'taxAssets');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			if (self::isTrue($row, 'taxableEventOccurred') === false) {
				return false;
			}
		}

		return true;
	}//end taxAssetsOnTaxableEvent()

	/**
	 * The budget comparison carries original + final budget and actuals as numbers on a
	 * comparable basis, with a non-empty material-difference explanation.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function budgetComparisonComplete(array $o): bool {
		$bc = ($o['budgetComparison'] ?? null);
		if (is_array($bc) === false) {
			return false;
		}

		if (is_numeric($bc['originalBudget'] ?? null) === false
			|| is_numeric($bc['finalBudget'] ?? null) === false
			|| is_numeric($bc['actualAmount'] ?? null) === false
		) {
			return false;
		}

		return strcasecmp(trim((string)($bc['basis'] ?? '')), 'comparable') === 0
			&& trim((string)($bc['materialDifferenceNote'] ?? '')) !== '';

	}//end budgetComparisonComplete()

	/**
	 * A consolidated statement eliminates the controlling entity's investment plus the
	 * intragroup balances and transactions (all three elimination types present); a
	 * non-consolidated statement is vacuously compliant.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function intragroupEliminated(array $o): bool {
		if (self::isTrue($o, 'consolidated') === false) {
			return true;
		}

		$rows = self::rows($o, 'intragroupEliminations');
		if ($rows === null) {
			return false;
		}

		$types = [];
		foreach ($rows as $row) {
			$types[strtolower(trim((string)($row['type'] ?? '')))] = true;
		}

		foreach (['investment', 'intragroupbalance', 'intragrouptransaction'] as $required) {
			if (array_key_exists($required, $types) === false) {
				return false;
			}
		}

		return true;
	}//end intragroupEliminated()

	/**
	 * Every short-term employee-benefit row is recognized at the undiscounted amount as
	 * both a liability and an expense (liability == expense == undiscounted) for service
	 * rendered, and is not discounted. Cent precision; requires at least one row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function shortTermBenefitsUndiscounted(array $o): bool {
		$rows = self::rows($o, 'shortTermEmployeeBenefits');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			if (self::isFalse($row, 'discounted') === false
				|| self::isTrue($row, 'serviceRendered') === false
			) {
				return false;
			}

			$undiscounted = self::num($row, 'undiscountedAmount');
			if (abs(self::num($row, 'liabilityRecognized') - $undiscounted) >= 0.005
				|| abs(self::num($row, 'expenseRecognized') - $undiscounted) >= 0.005
			) {
				return false;
			}
		}

		return true;
	}//end shortTermBenefitsUndiscounted()

	/**
	 * Every financial-instrument row is recognized exactly when the entity is party to
	 * the contract (recognized flag tracks the partyToContract flag). Requires at least
	 * one row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function financialInstrumentsRecognizedOnParty(array $o): bool {
		$rows = self::rows($o, 'financialInstruments');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			if (self::isBool($row, 'partyToContract') === false
				|| self::isBool($row, 'recognized') === false
			) {
				return false;
			}

			if (self::isTrue($row, 'partyToContract') !== self::isTrue($row, 'recognized')) {
				return false;
			}
		}

		return true;
	}//end financialInstrumentsRecognizedOnParty()

	/**
	 * Every non-short-term lease row recognizes both a right-of-use asset and a lease
	 * liability (both positive numbers); short-term leases are exempt. Requires at least
	 * one row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function leasesHaveRouAndLiability(array $o): bool {
		$rows = self::rows($o, 'leases');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			if (self::isShortTerm($row) === true) {
				continue;
			}

			if (self::num($row, 'rightOfUseAsset') <= 0.0 || self::num($row, 'leaseLiability') <= 0.0) {
				return false;
			}
		}

		return true;
	}//end leasesHaveRouAndLiability()

	/**
	 * Every non-short-term lease's liability equals the present value of its level annual
	 * payments discounted at its rate, within a one-currency-unit tolerance. Short-term
	 * leases are exempt. Requires at least one row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function leaseLiabilitiesAtPresentValue(array $o): bool {
		$rows = self::rows($o, 'leases');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			if (self::isShortTerm($row) === true) {
				continue;
			}

			$expected = self::presentValueAnnuity(
				self::num($row, 'annualPayment'),
				self::num($row, 'discountRate'),
				(int)self::num($row, 'termYears')
			);
			if ($expected === null || abs(self::num($row, 'leaseLiability') - $expected) > 1.0) {
				return false;
			}
		}

		return true;
	}//end leaseLiabilitiesAtPresentValue()

	/**
	 * Every fund's declared major flag is internally consistent with the GASB 34 test:
	 * the general fund is always major; any other fund is major iff its element is at
	 * least 10% of its category total AND at least 5% of the combined total. Requires
	 * at least one fund row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function majorFundFlagsConsistent(array $o): bool {
		$rows = self::rows($o, 'funds');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			if (self::isTrue($row, 'isGeneralFund') === true) {
				if (self::isTrue($row, 'major') === false) {
					return false;
				}

				continue;
			}

			$shouldBeMajor = (self::num($row, 'categoryPercent') >= 10.0
				&& self::num($row, 'combinedPercent') >= 5.0);
			if (self::isTrue($row, 'major') !== $shouldBeMajor) {
				return false;
			}
		}

		return true;
	}//end majorFundFlagsConsistent()

	/**
	 * Fiduciary funds are reported under the four GASB 84 fund types: every fiduciary
	 * fund row carries one of the allowed types and all four types are represented.
	 * Requires at least one row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function fiduciaryFundTypesValid(array $o): bool {
		$rows = self::rows($o, 'fiduciaryFunds');
		if ($rows === null) {
			return false;
		}

		$allowed = array_map('strtolower', self::GASB84_FUND_TYPES);
		$seen = [];
		foreach ($rows as $row) {
			$type = strtolower(trim((string)($row['fundType'] ?? '')));
			if (in_array($type, $allowed, true) === false) {
				return false;
			}

			$seen[$type] = true;
		}

		return count($seen) === count($allowed);
	}//end fiduciaryFundTypesValid()

	/**
	 * Every lease row is on the single (financing) lease model — no operating-vs-capital
	 * distinction. Requires at least one lease row.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function singleLeaseModel(array $o): bool {
		$rows = self::rows($o, 'leases');
		if ($rows === null) {
			return false;
		}

		foreach ($rows as $row) {
			if (self::isTrue($row, 'singleModel') === false) {
				return false;
			}
		}

		return true;
	}//end singleLeaseModel()

	/**
	 * Every non-short-term lease ('leases') / SBITA ('sbitas') row recognizes a liability
	 * at present value and an intangible right-of-use asset; short-term ones are exempt.
	 * The liability field differs per list (leaseLiability vs subscriptionLiability).
	 * Requires at least one row.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The list field name ('leases' or 'sbitas').
	 *
	 * @return bool
	 */
	private static function gasbLessRecognition(array $o, string $key): bool {
		$rows = self::rows($o, $key);
		if ($rows === null) {
			return false;
		}

		$liabilityKey = ($key === 'sbitas') ? 'subscriptionLiability' : 'leaseLiability';

		foreach ($rows as $row) {
			if (self::isShortTerm($row) === true) {
				continue;
			}

			$expected = self::presentValueAnnuity(
				self::num($row, 'annualPayment'),
				self::num($row, 'discountRate'),
				(int)self::num($row, 'termYears')
			);
			if ($expected === null || abs(self::num($row, $liabilityKey) - $expected) > 1.0) {
				return false;
			}

			if (self::num($row, 'rightOfUseAsset') <= 0.0
				|| self::isTrue($row, 'intangibleRouAsset') === false
			) {
				return false;
			}
		}

		return true;
	}//end gasbLessRecognition()

	/**
	 * Every short-term row in the list under $key (max possible term of 12 months or
	 * less) is excluded from recognition: no liability/asset is capitalised and it is
	 * flagged short-term. Long-term rows are ignored. The exception is satisfied even
	 * when no short-term row exists (vacuously). The liability field differs per list.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The list field name ('leases' or 'sbitas').
	 *
	 * @return bool
	 */
	private static function shortTermExceptionApplied(array $o, string $key): bool {
		$rows = self::rows($o, $key);
		if ($rows === null) {
			return false;
		}

		$liabilityKey = ($key === 'sbitas') ? 'subscriptionLiability' : 'leaseLiability';

		foreach ($rows as $row) {
			if (self::isShortTerm($row) === false) {
				continue;
			}

			if (self::num($row, $liabilityKey) !== 0.0 || self::num($row, 'rightOfUseAsset') !== 0.0) {
				return false;
			}
		}

		return true;
	}//end shortTermExceptionApplied()

	/**
	 * The budgetary comparison is presented as RSI with at least two variance columns and
	 * a non-empty significant-variance note.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function budgetaryComparisonRsi(array $o): bool {
		$bc = ($o['budgetaryComparison'] ?? null);
		if (is_array($bc) === false) {
			return false;
		}

		$columns = ($bc['varianceColumns'] ?? null);
		if (is_array($columns) === false || count($columns) < 2) {
			return false;
		}

		return self::isTrue($bc, 'presentedAsRsi') === true
			&& trim((string)($bc['significantVarianceNote'] ?? '')) !== '';

	}//end budgetaryComparisonRsi()

	/**
	 * A lease/SBITA row is short-term: it carries a maximum possible term of 12 months or
	 * less, or is explicitly flagged short-term.
	 *
	 * @param array<string, mixed> $row The lease / SBITA row.
	 *
	 * @return bool
	 */
	private static function isShortTerm(array $row): bool {
		if (self::isTrue($row, 'shortTerm') === true) {
			return true;
		}

		return is_numeric($row['maxTermMonths'] ?? null) === true
			&& (float)$row['maxTermMonths'] <= 12.0;

	}//end isShortTerm()

	/**
	 * Present value of an ordinary annuity of $payment per period for $periods periods at
	 * the per-period rate $rate. Returns null when the inputs are not a usable annuity
	 * (non-positive payment or periods). A zero rate yields payment * periods.
	 *
	 * @param float $payment The level payment per period.
	 * @param float $rate The per-period discount rate (e.g. 0.05).
	 * @param int $periods The number of periods.
	 *
	 * @return float|null
	 */
	private static function presentValueAnnuity(float $payment, float $rate, int $periods): ?float {
		if ($payment <= 0.0 || $periods <= 0) {
			return null;
		}

		if ($rate === 0.0) {
			return $payment * $periods;
		}

		return $payment * ((1.0 - (1.0 / ((1.0 + $rate) ** $periods))) / $rate);
	}//end presentValueAnnuity()
}//end class
