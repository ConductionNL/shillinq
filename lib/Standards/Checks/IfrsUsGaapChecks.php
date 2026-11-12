<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * IFRS / IAS / US-GAAP Presentation & Structure Check Provider
 *
 * Contributes executable checks for the `ifrs` / `ifrs-usgaap-deep` / `us-gaap`
 * rule domains to the RuleEngine, mapped onto the EXISTING FinancialStatement
 * register object. The predicates cover the machine-checkable presentation,
 * structure, required-component, classification, offsetting, comparatives and
 * arithmetic-consistency obligations of the standards — IAS 1 (complete set / no
 * offsetting / annual frequency / comparatives), IAS 7 & ASC 230 (three cash-flow
 * categories), IAS 2 (FIFO/weighted-average, LIFO prohibited), IAS 38 (no internal
 * goodwill/brands), IAS 36 (goodwill-reversal prohibited), IAS 37 (contingent
 * liability not recognised), IAS 21 (initial spot rate / closing rate / historical
 * rate), IAS 12 & ASC 740 (current-tax position, no discounting, non-current
 * classification), IAS 19 (short-term benefits undiscounted), IFRS 9 (30/90 DPD
 * staging), IFRS 3 & ASC 805 (one-year measurement period), IAS 28 & ASC 323
 * (20 % significant-influence equity method), IFRS 15 (one-year practical
 * expedients), IFRS 16 & ASC 842 (right-of-use + liability / short-term exemption /
 * constant-rate interest), IFRS 18 (categories + operating subtotal), ASC 210
 * (classified balance sheet), ASC 220 (comprehensive income) and ASC 848 (2024
 * sunset). The recognition/measurement and judgemental disclosure rules of these
 * standards were already set machineCheckable=false in the corpus and are not
 * registered here.
 *
 * The four existing FinancialStatement rows (NL / DE / FR / EU-listed, seeded by
 * FinancialStatementsChecks) carry only the national-reporting fields, so this
 * provider backfills the IFRS/US-GAAP structural fields onto every row via
 * seedSpec() with defaults that SATISFY every predicate below. It also seeds one
 * additional US-jurisdiction FinancialStatement so the US-only ASC rules are
 * actually evaluated (and pass) when the audit runs under a US context. No second
 * FinancialStatement schema is created — the new properties are declared (and the
 * schema version bumped) in lib/Settings/register.d/checks-ifrsusgaap.json.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained via the private static
 * helpers on this class. Every ruleId is a real `machineCheckable` id from
 * lib/Standards/rules/ifrs.json, ifrs-usgaap-deep.json or us-gaap.json.
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

use DateTimeImmutable;

/**
 * Executable IFRS / IAS / US-GAAP presentation-and-structure checks against FinancialStatement.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): this class enumerates every IFRS/IAS/
 * US-GAAP rule the catalogue defines; inherent complexity. Variable
 * renames deferred pending a dedicated pass.
 */
final class IfrsUsGaapChecks implements CheckProvider, SeedsObjects {
	/**
	 * The data seedObjects() returns; see there.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_OBJECTS = [
		'FinancialStatement' => [
			[
				'statementType' => 'consolidated',
				'jurisdiction' => 'US',
				'framework' => 'usgaap',
				'reportingPeriodStart' => '2025-01-01',
				'reportingPeriodEnd' => '2025-12-31',
				'language' => 'en',
				'currency' => 'USD',
				'balanceTotal' => 200000.0,
				'turnover' => 500000.0,
				'employees' => 12.0,
				'sizeClass' => 'large',
				'sizeClassPriorYear' => 'large',
				'balanceSheet' => [
					'fixedAssets' => 120000.0,
					'currentAssets' => 80000.0,
					'equity' => 90000.0,
					'provisions' => 10000.0,
					'liabilities' => 100000.0,
				],
				'profitAndLoss' => [
					'netTurnover' => 500000.0,
					'operatingExpenses' => 430000.0,
					'financialResult' => -5000.0,
					'result' => 65000.0,
				],
				'priorYearComparatives' => true,
				'itemsNetted' => false,
				'components' => ['balance', 'pnl', 'notes'],
				'audited' => true,
				'listedOnRegulatedMarket' => false,
			],
		],
	];

	/**
	 * The data seedSpec() returns; see there.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_SPEC = [
		'FinancialStatement' => [
			// IAS 1 — complete set + comparatives. itemsNetted already false on the
			// existing rows; reasserted here for the US sample.
			'ifrsComponents' => self::IFRS_COMPLETE_SET,
			'itemsNetted' => false,
			'priorYearComparatives' => true,

			// IAS 7 / ASC 230 — the three cash-flow categories (reconciling to a
			// net change for the period).
			'cashFlowStatement' => [
				'operating' => 70000.0,
				'investing' => -20000.0,
				'financing' => -15000.0,
				'netChange' => 35000.0,
			],

			// IFRS 18 — categories + operating subtotal.
			'pnlCategories' => self::IFRS18_CATEGORIES,
			'operatingProfitSubtotal' => 70000.0,

			// IAS 2 — inventory cost formula.
			'inventoryCostFormula' => 'fifo',

			// IAS 38 / 36 / 37 — prohibited recognitions are all false.
			'internalGoodwillRecognised' => false,
			'internalBrandsRecognised' => false,
			'goodwillImpairmentReversed' => false,
			'contingentLiabilityRecognised' => false,

			// IAS 21 — foreign-currency translation: initial spot, monetary closing,
			// non-monetary historical.
			'fxInitialMeasurement' => [
				'foreignAmount' => 10000.0,
				'spotRate' => 1.10,
				'recordedAmount' => 11000.0,
			],
			'monetaryItemRateType' => 'closing',
			'nonMonetaryHistoricalRateType' => 'historical',

			// IAS 12 / ASC 740 — current tax position + no discounting + non-current
			// deferred tax. unpaidCurrentTax > 0 -> classified as a liability.
			'currentTaxPosition' => [
				'unpaidCurrentTax' => 12000.0,
				'classification' => 'liability',
			],
			'deferredTaxDiscounted' => false,
			'deferredTaxClassification' => 'noncurrent',

			// IAS 19 — short-term benefits undiscounted.
			'shortTermBenefitsDiscounted' => false,

			// IFRS 9 — credit-risk staging consistent with 30/90 DPD presumptions.
			'creditRiskExposure' => [
				'daysPastDue' => 0,
				'sicrRebutted' => false,
				'sicrStage' => 1,
				'defaultRebutted' => false,
				'inDefault' => false,
			],

			// IFRS 3 / ASC 805 — measurement period within one year.
			'acquisitionDate' => '2025-03-01',
			'measurementPeriodEnd' => '2025-09-30',

			// IAS 28 / ASC 323 — 20 % significant influence -> equity method.
			'investeeVotingPowerPct' => 25.0,
			'equityMethodApplied' => true,

			// IFRS 15 — one-year practical expedients.
			'financingExpedientApplied' => true,
			'transferToPaymentDays' => 30,
			'costObtainingExpedientApplied' => true,
			'costAmortisationMonths' => 8,

			// IFRS 16 / ASC 842 — right-of-use + liability, constant-rate interest,
			// short-term exemption.
			'leaseRecognition' => [
				'rouAsset' => 50000.0,
				'leaseLiability' => 50000.0,
				'openingLiability' => 50000.0,
				'discountRate' => 0.05,
				'firstPeriodInterest' => 2500.0,
			],
			'shortTermLease' => [
				'termMonths' => 9,
				'purchaseOptionLikely' => false,
				'exemptionElected' => true,
			],

			// ASC 210 — classified balance sheet.
			'classifiedBalanceSheet' => [
				'currentAssets' => 80000.0,
				'noncurrentAssets' => 120000.0,
				'currentLiabilities' => 60000.0,
				'noncurrentLiabilities' => 40000.0,
			],

			// ASC 220 — comprehensive income.
			'comprehensiveIncome' => [
				'netIncome' => 65000.0,
				'otherComprehensiveIncome' => 5000.0,
				'totalComprehensiveIncome' => 70000.0,
				'presentationFormat' => 'single',
			],

			// ASC 848 — modification on/before the 2024 sunset.
			'asc848ModificationDate' => '2024-06-30',
		],
	];

	/**
	 * The five IFRS primary statements that make up a complete set (IAS 1.10).
	 *
	 * @var array<int, string>
	 */
	private const IFRS_COMPLETE_SET = [
		'financialPosition',
		'profitOrLossOci',
		'changesInEquity',
		'cashFlows',
		'notes',
	];

	/**
	 * The five IFRS 18 income/expense categories (IFRS 18.47).
	 *
	 * @var array<int, string>
	 */
	private const IFRS18_CATEGORIES = [
		'operating',
		'investing',
		'financing',
		'incomeTaxes',
		'discontinuedOperations',
	];

	/**
	 * Per-object-type, per-rule predicates for the IFRS / US-GAAP domains.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'FinancialStatement' => [
				...self::iasPresentationAndInventoryChecks(),
				...self::iasAssetAndEmployeeChecks(),
				...self::ifrsInstrumentAndUsGaapLeaseChecks(),
				...self::usGaapPresentationChecks(),
			],
		];

	}//end checks()

	/**
	 * The IAS presentation, revenue and inventory predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function iasPresentationAndInventoryChecks(): array {
		return [
			// ===== IAS 1 — Presentation of Financial Statements. =====
			'ias1-10-complete-set' => self::ias110CompleteSet(...),
			'ias1-32-no-offsetting' => self::ias132NoOffsetting(...),
			'ias1-36-annual-frequency' => self::ias136AnnualFrequency(...),
			'ias1-38-comparative-information' => self::ias138ComparativeInformation(...),

			// ===== IAS 7 / ASC 230 — Statement of Cash Flows. =====
			'ias7-10-classify-three-activities' => self::ias710ClassifyThreeActivities(...),
			'asc230-three-categories' => self::asc230ThreeCategories(...),

			// ===== IFRS 18 — Presentation and Disclosure. =====
			'ifrs18-47-classify-into-categories' => self::ifrs1847ClassifyIntoCategories(...),
			'ifrs18-69-operating-profit-subtotal' => self::ifrs1869OperatingProfitSubtotal(...),

			// ===== IAS 2 — Inventories. =====
			'ias2-25-fifo-or-weighted-average' => self::ias225FifoOrWeightedAverage(...),
			'ias2-25-lifo-prohibited' => self::ias225LifoProhibited(...),
		];

	}//end iasPresentationAndInventoryChecks()

	/**
	 * The IAS intangible-asset, provision, impairment and employee-benefit predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function iasAssetAndEmployeeChecks(): array {
		return [
			// ===== IAS 38 — Intangible Assets. =====
			'ias38-48-no-internal-goodwill' => self::ias3848NoInternalGoodwill(...),
			'ias38-63-no-internal-brands' => self::ias3863NoInternalBrands(...),

			// ===== IAS 36 — Impairment of Assets. =====
			'ias36-124-goodwill-reversal-prohibited' => self::ias36124GoodwillReversalProhibited(...),

			// ===== IAS 37 — Provisions, Contingent Liabilities and Assets. =====
			'ias37-27-contingent-liability-not-recognised' => self::ias3727ContingentLiabilityNotRecognised(...),

			// ===== IAS 21 — The Effects of Changes in Foreign Exchange Rates. =====
			'ias21-21-initial-spot-rate' => self::ias2121InitialSpotRate(...),
			'ias21-23a-monetary-closing-rate' => self::ias2123aMonetaryClosingRate(...),
			'ias21-23b-nonmonetary-historical-rate' => self::ias2123bNonmonetaryHistoricalRate(...),

			// ===== IAS 12 / ASC 740 — Income Taxes. =====
			'ias12-12-current-tax-liability' => self::ias1212CurrentTaxLiability(...),
			'ias12-53-no-discounting' => self::ias1253NoDiscounting(...),
			'asc740-dta-dtl-noncurrent' => self::asc740DtaDtlNoncurrent(...),

			// ===== IAS 19 — Employee Benefits. =====
			'ias19-11-short-term-benefits-undiscounted' => self::ias1911ShortTermBenefitsUndiscounted(...),
		];

	}//end iasAssetAndEmployeeChecks()

	/**
	 * The IFRS 9 / IFRS 16 and US GAAP revenue and lease predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function ifrsInstrumentAndUsGaapLeaseChecks(): array {
		return [
			// ===== IFRS 9 — Financial Instruments. =====
			'ifrs9-5511-sicr-30dpd-rebuttable' => self::ifrs95511Sicr30dpdRebuttable(...),
			'ifrs9-default-90dpd-rebuttable' => self::ifrs9Default90dpdRebuttable(...),

			// ===== IFRS 3 / ASC 805 — Business Combinations. =====
			'ifrs3-45-measurement-period-one-year' => self::ifrs345MeasurementPeriodOneYear(...),
			'asc805-measurement-period-one-year' => self::asc805MeasurementPeriodOneYear(...),

			// ===== IAS 28 / ASC 323 — Associates / Equity Method. =====
			'ias28-significant-influence-20pct' => self::ias28SignificantInfluence20pct(...),
			'asc323-equity-method-significant-influence' => self::asc323EquityMethodSignificantInfluence(...),

			// ===== IFRS 15 — Revenue from Contracts with Customers. =====
			'ifrs15-63-financing-practical-expedient' => self::ifrs1563FinancingPracticalExpedient(...),
			'ifrs15-94-cost-obtaining-practical-expedient' => self::ifrs1594CostObtainingPracticalExpedient(...),

			// ===== IFRS 16 / ASC 842 — Leases. =====
			'ifrs16-22-recognise-rou-and-liability' => self::ifrs1622RecogniseRouAndLiability(...),
			'ifrs16-37-liability-constant-rate-interest' => self::ifrs1637LiabilityConstantRateInterest(...),
			'asc842-short-term-exemption' => self::asc842ShortTermExemption(...),
		];

	}//end ifrsInstrumentAndUsGaapLeaseChecks()

	/**
	 * The US GAAP balance-sheet and reference-rate predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function usGaapPresentationChecks(): array {
		return [
			// ===== ASC 210 — Balance Sheet. =====
			'asc210-classified-balance-sheet' => self::asc210ClassifiedBalanceSheet(...),

			// ===== ASC 220 — Comprehensive Income. =====
			'asc220-comprehensive-income' => self::asc220ComprehensiveIncome(...),

			// ===== ASC 848 — Reference Rate Reform. =====
			'asc848-sunset-date-2024' => self::asc848SunsetDate2024(...),
		];

	}//end usGaapPresentationChecks()

	/**
	 * 1.10: a complete set comprises the statement of financial position,
	 * P&L and OCI, changes in equity, cash flows and notes.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias110CompleteSet(array $o): bool {
		return self::componentsContainAll($o, 'ifrsComponents', self::IFRS_COMPLETE_SET);

	}//end ias110CompleteSet()

	/**
	 * 1.32: assets and liabilities (and income and expenses) are not offset.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias132NoOffsetting(array $o): bool {
		return self::isFalse($o, 'itemsNetted');

	}//end ias132NoOffsetting()

	/**
	 * 1.36: a complete set (with comparatives) is presented at least
	 * annually — the reporting period spans about a year and comparatives
	 * are present.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias136AnnualFrequency(array $o): bool {
		return self::periodSpansAnnual($o)
			&& self::isTrue($o, 'priorYearComparatives');

	}//end ias136AnnualFrequency()

	/**
	 * 1.38: comparative information for the preceding period is presented.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias138ComparativeInformation(array $o): bool {
		return self::isTrue($o, 'priorYearComparatives');

	}//end ias138ComparativeInformation()

	/**
	 * 7.10 / ASC 230: cash flows are classified into operating, investing and
	 * financing activities.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias710ClassifyThreeActivities(array $o): bool {
		return self::cashFlowThreeCategories($o);

	}//end ias710ClassifyThreeActivities()

	/**
	 * Rule asc230-three-categories holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function asc230ThreeCategories(array $o): bool {
		return self::cashFlowThreeCategories($o);

	}//end asc230ThreeCategories()

	/**
	 * 18.47: income and expenses are classified into the operating,
	 * investing, financing, income-taxes and discontinued-operations
	 * categories.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrs1847ClassifyIntoCategories(array $o): bool {
		return self::componentsContainAll($o, 'pnlCategories', self::IFRS18_CATEGORIES);

	}//end ifrs1847ClassifyIntoCategories()

	/**
	 * 18.69: an operating profit-or-loss subtotal is presented.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrs1869OperatingProfitSubtotal(array $o): bool {
		return self::numericPresent($o, 'operatingProfitSubtotal');

	}//end ifrs1869OperatingProfitSubtotal()

	/**
	 * 2.25: interchangeable inventories use FIFO or weighted-average cost.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias225FifoOrWeightedAverage(array $o): bool {
		return self::inEnum($o, 'inventoryCostFormula', ['fifo', 'weightedaverage', 'weighted-average', 'weighted_average']);

	}//end ias225FifoOrWeightedAverage()

	/**
	 * 2.25: LIFO is prohibited.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias225LifoProhibited(array $o): bool {
		return self::present($o, 'inventoryCostFormula')
			&& strcasecmp(trim((string)$o['inventoryCostFormula']), 'lifo') !== 0;

	}//end ias225LifoProhibited()

	/**
	 * 38.48: internally generated goodwill is not recognised as an asset.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias3848NoInternalGoodwill(array $o): bool {
		return self::isFalse($o, 'internalGoodwillRecognised');

	}//end ias3848NoInternalGoodwill()

	/**
	 * 38.63: internally generated brands, mastheads, customer lists, etc.,
	 * are not recognised as intangible assets.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias3863NoInternalBrands(array $o): bool {
		return self::isFalse($o, 'internalBrandsRecognised');

	}//end ias3863NoInternalBrands()

	/**
	 * 36.124: an impairment loss recognised for goodwill is not reversed.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias36124GoodwillReversalProhibited(array $o): bool {
		return self::isFalse($o, 'goodwillImpairmentReversed');

	}//end ias36124GoodwillReversalProhibited()

	/**
	 * 37.27: a contingent liability is not recognised (disclosed only).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias3727ContingentLiabilityNotRecognised(array $o): bool {
		return self::isFalse($o, 'contingentLiabilityRecognised');

	}//end ias3727ContingentLiabilityNotRecognised()

	/**
	 * 21.21: a foreign-currency transaction is initially recorded at the spot
	 * rate at the transaction date applied to the foreign amount
	 * (recordedAmount = foreignAmount x spotRate).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias2121InitialSpotRate(array $o): bool {
		return self::fxInitialSpotConsistent($o);

	}//end ias2121InitialSpotRate()

	/**
	 * 21.23(a): monetary items are translated at the closing rate at each
	 * reporting date.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias2123aMonetaryClosingRate(array $o): bool {
		return self::fxItemRate($o, 'monetary', 'closing');

	}//end ias2123aMonetaryClosingRate()

	/**
	 * 21.23(b): non-monetary items at historical cost use the rate at the
	 * transaction date.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias2123bNonmonetaryHistoricalRate(array $o): bool {
		return self::fxItemRate($o, 'nonMonetaryHistorical', 'historical');

	}//end ias2123bNonmonetaryHistoricalRate()

	/**
	 * 12.12: unpaid current tax for current and prior periods is a liability;
	 * any excess paid is an asset — classification matches the sign.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias1212CurrentTaxLiability(array $o): bool {
		return self::currentTaxClassified($o);

	}//end ias1212CurrentTaxLiability()

	/**
	 * 12.53: deferred tax assets and liabilities are not discounted.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias1253NoDiscounting(array $o): bool {
		return self::isFalse($o, 'deferredTaxDiscounted');

	}//end ias1253NoDiscounting()

	/**
	 * ASC 740: deferred tax is classified non-current on a classified balance
	 * sheet, netted per tax-paying jurisdiction.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function asc740DtaDtlNoncurrent(array $o): bool {
		return self::equalsField($o, 'deferredTaxClassification', 'noncurrent');

	}//end asc740DtaDtlNoncurrent()

	/**
	 * 19.11: short-term employee benefits are recognised undiscounted.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias1911ShortTermBenefitsUndiscounted(array $o): bool {
		return self::isFalse($o, 'shortTermBenefitsDiscounted');

	}//end ias1911ShortTermBenefitsUndiscounted()

	/**
	 * 9.5.5.11: rebuttable presumption of significant increase in credit risk
	 * at 30 days past due — unless rebutted, the asset is in stage >= 2.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrs95511Sicr30dpdRebuttable(array $o): bool {
		return self::sicrStaging($o);

	}//end ifrs95511Sicr30dpdRebuttable()

	/**
	 * 9 (B5.5.37): rebuttable presumption of default no later than 90 days
	 * past due — unless rebutted, a 90+ DPD asset is flagged default.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrs9Default90dpdRebuttable(array $o): bool {
		return self::defaultStaging($o);

	}//end ifrs9Default90dpdRebuttable()

	/**
	 * 3.45 / ASC 805: the measurement period ends within one year of the
	 * acquisition date.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrs345MeasurementPeriodOneYear(array $o): bool {
		return self::measurementPeriodWithinYear($o);

	}//end ifrs345MeasurementPeriodOneYear()

	/**
	 * Rule asc805-measurement-period-one-year holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function asc805MeasurementPeriodOneYear(array $o): bool {
		return self::measurementPeriodWithinYear($o);

	}//end asc805MeasurementPeriodOneYear()

	/**
	 * 28 / ASC 323: significant influence is presumed at >= 20 % voting power,
	 * which requires the equity method — internally consistent flag.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ias28SignificantInfluence20pct(array $o): bool {
		return self::significantInfluenceConsistent($o);

	}//end ias28SignificantInfluence20pct()

	/**
	 * Rule asc323-equity-method-significant-influence holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function asc323EquityMethodSignificantInfluence(array $o): bool {
		return self::significantInfluenceConsistent($o);

	}//end asc323EquityMethodSignificantInfluence()

	/**
	 * 15.63: the no-financing-component practical expedient may be used when
	 * the period between transfer and payment is one year or less.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrs1563FinancingPracticalExpedient(array $o): bool {
		return self::expedientWithinDays($o, 'financingExpedientApplied', 'transferToPaymentDays', 366);

	}//end ifrs1563FinancingPracticalExpedient()

	/**
	 * 15.94: incremental costs of obtaining a contract may be expensed when
	 * the amortisation period would be one year or less.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrs1594CostObtainingPracticalExpedient(array $o): bool {
		return self::expedientWithinMonths($o, 'costObtainingExpedientApplied', 'costAmortisationMonths', 12);

	}//end ifrs1594CostObtainingPracticalExpedient()

	/**
	 * 16.22: at commencement a lessee recognises a right-of-use asset and a
	 * lease liability (both present and equal at commencement).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrs1622RecogniseRouAndLiability(array $o): bool {
		return self::rouAndLiabilityRecognised($o);

	}//end ifrs1622RecogniseRouAndLiability()

	/**
	 * 16.37: interest on the lease liability produces a constant periodic rate
	 * (period interest = opening liability x rate).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrs1637LiabilityConstantRateInterest(array $o): bool {
		return self::leaseInterestConstantRate($o);

	}//end ifrs1637LiabilityConstantRateInterest()

	/**
	 * ASC 842: short-term-lease exemption (term <= 12 months, no reasonably
	 * certain purchase option) — internally consistent election.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function asc842ShortTermExemption(array $o): bool {
		return self::shortTermLeaseConsistent($o);

	}//end asc842ShortTermExemption()

	/**
	 * ASC 210: a classified balance sheet separates current from non-current
	 * assets and liabilities.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function asc210ClassifiedBalanceSheet(array $o): bool {
		return self::classifiedBalanceSheet($o);

	}//end asc210ClassifiedBalanceSheet()

	/**
	 * ASC 220: comprehensive income = net income + OCI, presented in a single
	 * or two-statement format.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function asc220ComprehensiveIncome(array $o): bool {
		return self::comprehensiveIncomeConsistent($o);

	}//end asc220ComprehensiveIncome()

	/**
	 * ASC 848: relief applies only to contracts modified on or before the
	 * 31 December 2024 sunset date.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function asc848SunsetDate2024(array $o): bool {
		return self::asc848BeforeSunset($o);

	}//end asc848SunsetDate2024()

	/**
	 * Backfill the IFRS/US-GAAP structural fields onto the EXISTING FinancialStatement
	 * rows (the four national samples plus the US sample seeded below), with defaults
	 * that satisfy every predicate above. The fields are only added where missing/empty,
	 * so the national-reporting fields already present are untouched.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return self::SEED_SPEC;

	}//end seedSpec()

	/**
	 * One additional US-jurisdiction FinancialStatement so the US-only ASC rules are
	 * actually evaluated (and pass) when the audit runs under a US context. The
	 * IFRS/US-GAAP structural fields are backfilled by seedSpec() above; only the base
	 * required FinancialStatement fields are supplied here (matching the schema's
	 * required[] and enums exactly). Created only when no US row exists.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return self::SEED_OBJECTS;

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
	 * The value under $key is one of $allowed (case-insensitive).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 * @param array<int, string> $allowed Allowed values.
	 *
	 * @return bool
	 */
	private static function inEnum(array $o, string $key, array $allowed): bool {
		if (self::present($o, $key) === false) {
			return false;
		}

		$val = strtolower(trim((string)$o[$key]));
		foreach ($allowed as $a) {
			if (strtolower($a) === $val) {
				return true;
			}
		}

		return false;
	}//end inEnum()

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
	 * A present, numeric value lives under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function numericPresent(array $o, string $key): bool {
		return array_key_exists($key, $o) === true && is_numeric($o[$key]) === true;
	}//end numericPresent()

	/**
	 * The numeric value under $key (0.0 when absent / non-numeric).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return float
	 */
	private static function numericVal(array $o, string $key): float {
		return is_numeric($o[$key] ?? null) === true ? (float)$o[$key] : 0.0;
	}//end numericVal()

	/**
	 * The list under $key contains every required code (case-insensitive).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The list field name.
	 * @param array<int, string> $required The codes that must all be present.
	 *
	 * @return bool
	 */
	private static function componentsContainAll(array $o, string $key, array $required): bool {
		$list = ($o[$key] ?? null);
		if (is_array($list) === false || $list === []) {
			return false;
		}

		$have = [];
		foreach ($list as $c) {
			$have[strtolower(trim((string)$c))] = true;
		}

		foreach ($required as $code) {
			if (array_key_exists(strtolower($code), $have) === false) {
				return false;
			}
		}

		return true;
	}//end componentsContainAll()

	/**
	 * The reporting period spans about a financial year (>= 350 days end-to-start),
	 * supporting the at-least-annual frequency requirement.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function periodSpansAnnual(array $o): bool {
		$start = self::date($o, 'reportingPeriodStart');
		$end = self::date($o, 'reportingPeriodEnd');
		if ($start === null || $end === null || $end <= $start) {
			return false;
		}

		$days = (int)(($end->getTimestamp() - $start->getTimestamp()) / 86400);
		return $days >= 350;
	}//end periodSpansAnnual()

	/**
	 * The cash-flow statement carries numeric operating, investing and financing
	 * activity totals (IAS 7.10 / ASC 230).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function cashFlowThreeCategories(array $o): bool {
		$cf = ($o['cashFlowStatement'] ?? null);
		if (is_array($cf) === false) {
			return false;
		}

		foreach (['operating', 'investing', 'financing'] as $key) {
			if (array_key_exists($key, $cf) === false || is_numeric($cf[$key]) === false) {
				return false;
			}
		}

		return true;
	}//end cashFlowThreeCategories()

	/**
	 * The foreign-currency initial measurement is internally consistent: the recorded
	 * amount equals the foreign amount times the spot rate (IAS 21.21), at cent
	 * precision.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function fxInitialSpotConsistent(array $o): bool {
		$fx = ($o['fxInitialMeasurement'] ?? null);
		if (is_array($fx) === false) {
			return false;
		}

		foreach (['foreignAmount', 'spotRate', 'recordedAmount'] as $key) {
			if (is_numeric($fx[$key] ?? null) === false) {
				return false;
			}
		}

		$expected = ((float)$fx['foreignAmount'] * (float)$fx['spotRate']);
		return abs(round($expected, 2) - round((float)$fx['recordedAmount'], 2)) < 0.01;
	}//end fxInitialSpotConsistent()

	/**
	 * The rate-type field for a foreign-currency item class equals the required rate
	 * (monetary -> closing; non-monetary historical-cost -> historical), per IAS 21.23.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $itemKind Item kind (`monetary` | `nonMonetaryHistorical`).
	 * @param string $rate Required rate type (`closing` | `historical`).
	 *
	 * @return bool
	 */
	private static function fxItemRate(array $o, string $itemKind, string $rate): bool {
		$field = ($itemKind === 'monetary') ? 'monetaryItemRateType' : 'nonMonetaryHistoricalRateType';
		return self::equalsField($o, $field, $rate);
	}//end fxItemRate()

	/**
	 * The current-tax position is classified consistently with its sign: an unpaid
	 * current tax (> 0) is a liability; an overpayment (< 0) is an asset (IAS 12.12).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function currentTaxClassified(array $o): bool {
		$pos = ($o['currentTaxPosition'] ?? null);
		if (is_array($pos) === false
			|| is_numeric($pos['unpaidCurrentTax'] ?? null) === false
			|| trim((string)($pos['classification'] ?? '')) === ''
		) {
			return false;
		}

		$amount = (float)$pos['unpaidCurrentTax'];
		$class = strtolower(trim((string)$pos['classification']));
		if ($amount > 0.0) {
			return $class === 'liability';
		}

		if ($amount < 0.0) {
			return $class === 'asset';
		}

		return $class === 'liability' || $class === 'asset';
	}//end currentTaxClassified()

	/**
	 * Credit-risk staging is consistent with the 30-DPD SICR presumption: an asset
	 * more than 30 days past due (and not rebutted) is in stage 2 or worse (IFRS 9
	 * 5.5.11). Requires the exposure structure.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function sicrStaging(array $o): bool {
		$exp = ($o['creditRiskExposure'] ?? null);
		if (is_array($exp) === false
			|| is_numeric($exp['daysPastDue'] ?? null) === false
			|| is_numeric($exp['sicrStage'] ?? null) === false
		) {
			return false;
		}

		$dpd = (float)$exp['daysPastDue'];
		$rebutted = in_array(($exp['sicrRebutted'] ?? null), [true, 1, '1', 'true'], true);
		if ($dpd > 30.0 && $rebutted === false) {
			return (int)$exp['sicrStage'] >= 2;
		}

		return true;
	}//end sicrStaging()

	/**
	 * Default flagging is consistent with the 90-DPD presumption: an asset 90+ days
	 * past due (and not rebutted) is flagged in default (IFRS 9 B5.5.37). Requires the
	 * exposure structure.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function defaultStaging(array $o): bool {
		$exp = ($o['creditRiskExposure'] ?? null);
		if (is_array($exp) === false
			|| is_numeric($exp['daysPastDue'] ?? null) === false
			|| self::isBoolValue($exp, 'inDefault') === false
		) {
			return false;
		}

		$dpd = (float)$exp['daysPastDue'];
		$rebutted = in_array(($exp['defaultRebutted'] ?? null), [true, 1, '1', 'true'], true);
		if ($dpd >= 90.0 && $rebutted === false) {
			return in_array(($exp['inDefault'] ?? null), [true, 1, '1', 'true'], true);
		}

		return true;
	}//end defaultStaging()

	/**
	 * The measurement period ends within one year of the acquisition date (IFRS 3.45 /
	 * ASC 805). Requires both dates.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function measurementPeriodWithinYear(array $o): bool {
		$acq = self::date($o, 'acquisitionDate');
		$end = self::date($o, 'measurementPeriodEnd');
		if ($acq === null || $end === null) {
			return false;
		}

		$deadline = (clone $acq)->modify('+1 year');
		return $end >= $acq && $end <= $deadline;
	}//end measurementPeriodWithinYear()

	/**
	 * Significant-influence accounting is internally consistent: a holding of 20 % or
	 * more voting power applies the equity method; below 20 % it does not (IAS 28 /
	 * ASC 323). Requires the voting-power metric and the flag.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function significantInfluenceConsistent(array $o): bool {
		if (self::numericPresent($o, 'investeeVotingPowerPct') === false
			|| self::isBool($o, 'equityMethodApplied') === false
		) {
			return false;
		}

		$pct = self::numericVal($o, 'investeeVotingPowerPct');
		$applied = self::isTrue($o, 'equityMethodApplied');
		if ($pct >= 20.0) {
			return $applied === true;
		}

		return $applied === false;
	}//end significantInfluenceConsistent()

	/**
	 * A one-year practical expedient is only applied when the relevant day count is at
	 * most $maxDays (IFRS 15.63). Vacuously true when the expedient is not applied.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $appliedKey The boolean expedient-applied flag.
	 * @param string $daysKey The day-count field.
	 * @param int $maxDays The inclusive upper bound in days.
	 *
	 * @return bool
	 */
	private static function expedientWithinDays(array $o, string $appliedKey, string $daysKey, int $maxDays): bool {
		if (self::isTrue($o, $appliedKey) === false) {
			return self::isBool($o, $appliedKey);
		}

		return self::numericPresent($o, $daysKey) && self::numericVal($o, $daysKey) <= (float)$maxDays;
	}//end expedientWithinDays()

	/**
	 * A one-year practical expedient is only applied when the amortisation period is at
	 * most $maxMonths months (IFRS 15.94). Vacuously true when not applied.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $appliedKey The boolean expedient-applied flag.
	 * @param string $monthsKey The months field.
	 * @param int $maxMonths The inclusive upper bound in months.
	 *
	 * @return bool
	 */
	private static function expedientWithinMonths(array $o, string $appliedKey, string $monthsKey, int $maxMonths): bool {
		if (self::isTrue($o, $appliedKey) === false) {
			return self::isBool($o, $appliedKey);
		}

		return self::numericPresent($o, $monthsKey) && self::numericVal($o, $monthsKey) <= (float)$maxMonths;
	}//end expedientWithinMonths()

	/**
	 * A right-of-use asset and a lease liability are both recognised (and equal at
	 * commencement) per IFRS 16.22. Requires the lease structure.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function rouAndLiabilityRecognised(array $o): bool {
		$lease = ($o['leaseRecognition'] ?? null);
		if (is_array($lease) === false
			|| is_numeric($lease['rouAsset'] ?? null) === false
			|| is_numeric($lease['leaseLiability'] ?? null) === false
		) {
			return false;
		}

		return (float)$lease['rouAsset'] > 0.0
			&& abs((float)$lease['rouAsset'] - (float)$lease['leaseLiability']) < 0.01;

	}//end rouAndLiabilityRecognised()

	/**
	 * The first-period lease interest equals the opening liability times the discount
	 * rate, evidencing a constant periodic rate (IFRS 16.37), at cent precision.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function leaseInterestConstantRate(array $o): bool {
		$lease = ($o['leaseRecognition'] ?? null);
		if (is_array($lease) === false) {
			return false;
		}

		foreach (['openingLiability', 'discountRate', 'firstPeriodInterest'] as $key) {
			if (is_numeric($lease[$key] ?? null) === false) {
				return false;
			}
		}

		$expected = ((float)$lease['openingLiability'] * (float)$lease['discountRate']);
		return abs(round($expected, 2) - round((float)$lease['firstPeriodInterest'], 2)) < 0.01;
	}//end leaseInterestConstantRate()

	/**
	 * A short-term-lease exemption election is internally consistent: it is only
	 * elected when the term is at most 12 months and no purchase option is reasonably
	 * certain (ASC 842). Requires the short-term-lease structure.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function shortTermLeaseConsistent(array $o): bool {
		$stl = ($o['shortTermLease'] ?? null);
		if (is_array($stl) === false
			|| is_numeric($stl['termMonths'] ?? null) === false
			|| self::isBoolValue($stl, 'purchaseOptionLikely') === false
			|| self::isBoolValue($stl, 'exemptionElected') === false
		) {
			return false;
		}

		$elected = in_array(($stl['exemptionElected'] ?? null), [true, 1, '1', 'true'], true);
		if ($elected === true) {
			return (float)$stl['termMonths'] <= 12.0
				&& in_array(($stl['purchaseOptionLikely'] ?? null), [false, 0, '0', 'false'], true);
		}

		return true;
	}//end shortTermLeaseConsistent()

	/**
	 * The classified balance sheet separates current from non-current assets and
	 * liabilities, all numeric (ASC 210).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function classifiedBalanceSheet(array $o): bool {
		$cbs = ($o['classifiedBalanceSheet'] ?? null);
		if (is_array($cbs) === false) {
			return false;
		}

		foreach (['currentAssets', 'noncurrentAssets', 'currentLiabilities', 'noncurrentLiabilities'] as $key) {
			if (is_numeric($cbs[$key] ?? null) === false) {
				return false;
			}
		}

		return true;
	}//end classifiedBalanceSheet()

	/**
	 * Comprehensive income is internally consistent: net income plus OCI equals total
	 * comprehensive income (at cent precision) and the presentation format is single or
	 * two-statement (ASC 220).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function comprehensiveIncomeConsistent(array $o): bool {
		$ci = ($o['comprehensiveIncome'] ?? null);
		if (is_array($ci) === false) {
			return false;
		}

		foreach (['netIncome', 'otherComprehensiveIncome', 'totalComprehensiveIncome'] as $key) {
			if (is_numeric($ci[$key] ?? null) === false) {
				return false;
			}
		}

		$sum = ((float)$ci['netIncome'] + (float)$ci['otherComprehensiveIncome']);
		if (abs(round($sum, 2) - round((float)$ci['totalComprehensiveIncome'], 2)) >= 0.01) {
			return false;
		}

		$format = strtolower(trim((string)($ci['presentationFormat'] ?? '')));
		return in_array($format, ['single', 'two-statement', 'two_statement', 'twostatement'], true);
	}//end comprehensiveIncomeConsistent()

	/**
	 * The ASC 848 relief is only relied upon for a modification dated on or before the
	 * 31 December 2024 sunset date. Vacuously true when no ASC 848 modification date is
	 * modelled (the relief is not invoked).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function asc848BeforeSunset(array $o): bool {
		if (self::present($o, 'asc848ModificationDate') === false) {
			return true;
		}

		$date = self::date($o, 'asc848ModificationDate');
		if ($date === null) {
			return false;
		}

		$sunset = new DateTimeImmutable('2024-12-31');
		return $date <= $sunset;
	}//end asc848BeforeSunset()

	/**
	 * A nested map field under $key holds a boolean (or boolean-like) value.
	 *
	 * @param array<string, mixed> $map The nested map.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isBoolValue(array $map, string $key): bool {
		return array_key_exists($key, $map) === true
			&& (is_bool($map[$key]) === true || in_array($map[$key], [0, 1, '0', '1', 'true', 'false'], true) === true);

	}//end isBoolValue()

	/**
	 * Parse the date field under $key into a DateTimeImmutable, or null when absent /
	 * unparseable.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function date(array $o, string $key): ?DateTimeImmutable {
		if (self::present($o, $key) === false) {
			return null;
		}

		try {
			return new DateTimeImmutable(substr(trim((string)$o[$key]), 0, 10));
		} catch (\Exception $e) {
			return null;
		}

	}//end date()
}//end class
