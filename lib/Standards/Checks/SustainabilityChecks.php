<?php

/**
 * CSRD / ESRS + IFRS S2 Sustainability-Reporting Check Provider
 *
 * Contributes executable checks for the `sustainability` rule domain to the
 * RuleEngine. The predicates map the structured datapoints of the European
 * Sustainability Reporting Standards (ESRS, the technical annex of the CSRD,
 * Commission Delegated Regulation (EU) 2023/2772) and of IFRS S2 Climate-related
 * Disclosures onto a single flat EsrsStatement object — the period's GHG inventory
 * (Scope 1/2/3 + the arithmetic total and intensity), the energy mix, the per-topic
 * environmental/social/governance metrics, and the cross-cutting target structure.
 *
 * EsrsStatement is a NEW object type with no rows in the local register, so this
 * provider also implements SeedsObjects and supplies one fully-compliant FY2025
 * sample (an EU undertaking that is also a global IFRS-S2 filer); the seeder creates
 * it so the audit actually evaluates these checks against real data. The sample
 * SATISFIES every predicate below.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained (it inlines its logic
 * via the private static helpers on this class rather than reaching into
 * RuleEngine). Every ruleId is a real `machineCheckable` id from
 * lib/Standards/rules/sustainability.json. Purely narrative disclosure rules already
 * carrying machineCheckable=false are not registered here.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.Commenting.InlineComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Executable CSRD/ESRS + IFRS-S2 sustainability checks against EsrsStatement.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * Pre-existing debt (issue #506): this class enumerates every CSRD/ESRS/
 * IFRS-S2 rule the catalogue defines; inherent complexity. Deferred to a
 * follow-up.
 */
final class SustainabilityChecks implements CheckProvider, SeedsObjects {
	/**
	 * The cross-cutting ESRS material-matter codes (ESRS 1 §AR16) a per-matter target
	 * may relate to. A target's `matter` must be one of these.
	 *
	 * @var array<int, string>
	 */
	private const ESRS_MATTERS = [
		'E1',
		'E2',
		'E3',
		'E4',
		'E5',
		'S1',
		'S2',
		'S3',
		'S4',
		'G1',
	];

	/**
	 * Per-object-type, per-rule predicates for the `sustainability` domain.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'EsrsStatement' => [
				// ===== ESRS 2 — general / cross-cutting. =====
				// IRO-2: the statement lists which disclosure requirements it covers
				// and tags the reported datapoints — datapointsTagged true and a
				// non-empty list of covered DR codes.
				'esrs-2-iro-2-dr-coverage' => static fn (array $o): bool => self::isTrue($o, 'datapointsTagged')
					&& self::nonEmptyStringList($o, 'disclosureRequirementsCovered'),

				// MDR-T: for each material matter a measurable, outcome-oriented target
				// with a baseline, the period it relates to and progress — every
				// targets[] row is structurally complete.
				'esrs-2-mdr-t-targets' => static fn (array $o): bool => self::targetsWellFormed($o),

				// ===== ESRS E1 — Climate change. =====
				// E1-4: GHG reduction targets in absolute value with base year, target
				// year and a science-based flag — at least one E1 target carrying all of
				// baseYear, targetYear and a boolean scienceBased.
				'esrs-e1-4-ghg-targets' => static fn (array $o): bool => self::hasGhgReductionTarget($o),

				// E1-5: total energy consumption in MWh disaggregated into fossil,
				// nuclear and renewable, and the renewable share = renewable / total.
				'esrs-e1-5-energy-consumption-mix' => static fn (array $o): bool => self::energyMixConsistent($o),

				// E1-5: energy intensity = total energy consumption per net revenue
				// (MWh per EUR), equal to energyConsumption / netRevenue.
				'esrs-e1-5-energy-intensity' => static fn (array $o): bool => self::energyIntensityConsistent($o),

				// E1-6: gross Scope 1 in tCO2e (>= 0) and the % covered by an emission
				// trading scheme within 0-100.
				'esrs-e1-6-ghg-scope1' => static fn (array $o): bool => self::nonNegNumber($o, 'ghgScope1')
					&& self::percentInRange($o, 'ghgScope1EtsCoveredPct'),

				// E1-6: gross Scope 2 in tCO2e reported both location-based and
				// market-based (both present, both >= 0).
				'esrs-e1-6-ghg-scope2' => static fn (array $o): bool => self::nonNegNumber($o, 'ghgScope2LocationBased')
					&& self::nonNegNumber($o, 'ghgScope2MarketBased'),

				// E1-6: gross Scope 3 in tCO2e broken down by significant GHG-Protocol
				// value-chain categories — total >= 0 and the per-category breakdown
				// sums (within tolerance) to it.
				'esrs-e1-6-ghg-scope3' => static fn (array $o): bool => self::nonNegNumber($o, 'ghgScope3')
					&& self::scope3CategoriesSum($o),

				// E1-6: total GHG = Scope 1 + Scope 2 (location-based) + Scope 3, and the
				// intensity per net revenue = total / netRevenue.
				'esrs-e1-6-ghg-total' => static fn (array $o): bool => self::ghgTotalIsSum($o)
					&& self::ghgIntensityConsistent($o),

				// E1-7: GHG removals/storage and carbon credits cancelled, in tCO2e
				// (both present and >= 0).
				'esrs-e1-7-removals-credits' => static fn (array $o): bool => self::nonNegNumber($o, 'ghgRemovals')
					&& self::nonNegNumber($o, 'carbonCreditsCancelled'),

				// E1-8: whether internal carbon pricing is applied; if so a positive
				// price per tCO2e is disclosed (conditional consistency).
				'esrs-e1-8-internal-carbon-pricing' => static fn (array $o): bool => self::internalCarbonPriceConsistent($o, 'internalCarbonPricingApplied', 'internalCarbonPrice'),

				// ===== ESRS E2 — Pollution. =====
				// E2-3: measurable pollution-prevention targets — at least one E2 target.
				'esrs-e2-3-pollution-targets' => static fn (array $o): bool => self::hasTargetForMatter($o, 'E2'),

				// E2-4: amounts of pollutants emitted to air, water and soil in units of
				// mass — all three present and >= 0.
				'esrs-e2-4-pollution-air-water-soil' => static fn (array $o): bool => self::nonNegNumber($o, 'pollutantsToAir')
					&& self::nonNegNumber($o, 'pollutantsToWater')
					&& self::nonNegNumber($o, 'pollutantsToSoil'),

				// E2-5: quantities of substances of concern and of very high concern —
				// both present and >= 0.
				'esrs-e2-5-substances-of-concern' => static fn (array $o): bool => self::nonNegNumber($o, 'substancesOfConcern')
					&& self::nonNegNumber($o, 'substancesOfVeryHighConcern'),

				// ===== ESRS E3 — Water and marine resources. =====
				// E3-3: measurable water targets — at least one E3 target.
				'esrs-e3-3-water-targets' => static fn (array $o): bool => self::hasTargetForMatter($o, 'E3'),

				// E3-4: total water consumption in m3, the share in water-risk areas
				// (0-100 %), and water intensity = consumption / net revenue.
				'esrs-e3-4-water-consumption' => static fn (array $o): bool => self::nonNegNumber($o, 'waterConsumption')
					&& self::percentInRange($o, 'waterConsumptionAtRiskPct')
					&& self::intensityConsistent($o, 'waterConsumption', 'waterIntensity'),

				// ===== ESRS E4 — Biodiversity and ecosystems. =====
				// E4-4: measurable biodiversity targets — at least one E4 target.
				'esrs-e4-4-biodiversity-targets' => static fn (array $o): bool => self::hasTargetForMatter($o, 'E4'),

				// E4-5: biodiversity impact metrics — land-use change (ha) and the count
				// of sites near biodiversity-sensitive areas — both present and >= 0.
				'esrs-e4-5-biodiversity-impact-metrics' => static fn (array $o): bool => self::nonNegNumber($o, 'landUseChangeHectares')
					&& self::nonNegNumber($o, 'sitesNearBiodiversitySensitiveAreas'),

				// ===== ESRS E5 — Resource use and circular economy. =====
				// E5-3: measurable circular-economy targets — at least one E5 target.
				'esrs-e5-3-circular-targets' => static fn (array $o): bool => self::hasTargetForMatter($o, 'E5'),

				// E5-4: resource inflows — total weight of materials used and the share
				// of biological + secondary (recycled) materials (each share 0-100 %,
				// and the two shares do not jointly exceed 100 %).
				'esrs-e5-4-resource-inflows' => static fn (array $o): bool => self::resourceInflowsConsistent($o),

				// E5-5: resource outflows — total waste in tonnes split into the share
				// diverted from disposal and the share directed to disposal, which sum
				// to 100 %.
				'esrs-e5-5-resource-outflows-waste' => static fn (array $o): bool => self::wasteSplitConsistent($o),

				// ===== ESRS S1 — Own workforce. =====
				// S1-6: total employees by head count broken down by gender — the gender
				// breakdown sums to the headcount.
				'esrs-s1-6-employee-headcount' => static fn (array $o): bool => self::headcountGenderConsistent($o),

				// S1-7: total non-employees in own workforce — present and >= 0.
				'esrs-s1-7-non-employees' => static fn (array $o): bool => self::nonNegNumber($o, 'nonEmployees'),

				// S1-8: % of employees covered by collective bargaining (0-100 %).
				'esrs-s1-8-collective-bargaining' => static fn (array $o): bool => self::percentInRange($o, 'collectiveBargainingCoveragePct'),

				// S1-9: gender distribution at top management (a share that sums with its
				// complement to the management head count is not modelled flat; here the
				// female-share at top management is a 0-100 % datapoint) and the
				// distribution of employees by age band summing to the headcount.
				'esrs-s1-9-diversity-metrics' => static fn (array $o): bool => self::percentInRange($o, 'topManagementFemalePct')
					&& self::ageBandsConsistent($o),

				// S1-14: health-and-safety — % of workforce covered by the H&S system
				// (0-100 %) and the recordable-accidents count and fatalities count
				// (both >= 0, fatalities <= accidents).
				'esrs-s1-14-health-safety' => static fn (array $o): bool => self::healthSafetyConsistent($o),

				// S1-16: gender pay gap (a %, may be negative) and the CEO-to-median
				// total-remuneration ratio (>= 0).
				'esrs-s1-16-pay-gap' => static fn (array $o): bool => self::isNumber($o, 'genderPayGapPct')
					&& self::nonNegNumber($o, 'ceoPayRatio'),

				// S1-17: number of discrimination incidents and the related fines amount
				// (both present and >= 0).
				'esrs-s1-17-incidents-discrimination' => static fn (array $o): bool => self::nonNegNumber($o, 'discriminationIncidents')
					&& self::nonNegNumber($o, 'discriminationFines'),

				// ===== ESRS S2 / S3 / S4 — value-chain workers, communities, consumers. =====
				// S2-5: time-bound, outcome-oriented value-chain-worker targets — at
				// least one S2 target.
				'esrs-s2-5-vc-workers-targets' => static fn (array $o): bool => self::hasTargetForMatter($o, 'S2'),

				// S3-5: affected-community targets — at least one S3 target.
				'esrs-s3-5-communities-targets' => static fn (array $o): bool => self::hasTargetForMatter($o, 'S3'),

				// S4-5: consumer/end-user targets — at least one S4 target.
				'esrs-s4-5-consumers-targets' => static fn (array $o): bool => self::hasTargetForMatter($o, 'S4'),

				// ===== ESRS G1 — Business conduct. =====
				// G1-4: confirmed corruption/bribery incidents, convictions and fines —
				// all present and >= 0, convictions <= incidents.
				'esrs-g1-4-corruption-incidents' => static fn (array $o): bool => self::corruptionConsistent($o),

				// G1-6: payment practices — average days to pay, the standard payment
				// term (days) and the count of late-payment legal proceedings — all >= 0.
				'esrs-g1-6-payment-practices' => static fn (array $o): bool => self::nonNegNumber($o, 'averageDaysToPay')
					&& self::nonNegNumber($o, 'standardPaymentTermDays')
					&& self::nonNegNumber($o, 'latePaymentProceedings'),

				// ===== IFRS S2 — Climate-related disclosures (global). =====
				// gross Scope 1 in tCO2e (>= 0) — IFRS S2 reuses the same GHG datapoint.
				'ifrs-s2-ghg-scope1' => static fn (array $o): bool => self::nonNegNumber($o, 'ghgScope1'),

				// gross Scope 2 location-based in tCO2e (>= 0).
				'ifrs-s2-ghg-scope2' => static fn (array $o): bool => self::nonNegNumber($o, 'ghgScope2LocationBased'),

				// gross Scope 3 in tCO2e (>= 0) including the GHG-Protocol categories
				// included — a non-empty category breakdown.
				'ifrs-s2-ghg-scope3' => static fn (array $o): bool => self::nonNegNumber($o, 'ghgScope3')
					&& self::hasScope3Categories($o),

				// financed emissions disclosed only by financial-sector entities — when
				// financialSectorEntity is true a financedEmissions figure (>= 0) is
				// present; otherwise the rule is vacuously satisfied.
				'ifrs-s2-financed-emissions' => static fn (array $o): bool => self::conditionalNonNeg($o, 'financialSectorEntity', 'financedEmissions'),

				// assets/activities vulnerable to transition risk — an amount (>= 0) and
				// a percentage (0-100 %).
				'ifrs-s2-transition-risk-exposure' => static fn (array $o): bool => self::amountPercentConsistent($o, 'transitionRiskAmount', 'transitionRiskPct'),

				// assets/activities vulnerable to physical risk — amount + percentage.
				'ifrs-s2-physical-risk-exposure' => static fn (array $o): bool => self::amountPercentConsistent($o, 'physicalRiskAmount', 'physicalRiskPct'),

				// assets/activities aligned with climate opportunities — amount + %.
				'ifrs-s2-opportunities-exposure' => static fn (array $o): bool => self::amountPercentConsistent($o, 'climateOpportunityAmount', 'climateOpportunityPct'),

				// capital deployed towards climate risks/opportunities — present, >= 0.
				'ifrs-s2-capital-deployment' => static fn (array $o): bool => self::nonNegNumber($o, 'climateCapitalDeployed'),

				// an entity applying a carbon price discloses a positive price/tCO2e
				// (conditional consistency, same shape as ESRS E1-8).
				'ifrs-s2-internal-carbon-price' => static fn (array $o): bool => self::internalCarbonPriceConsistent($o, 'internalCarbonPricingApplied', 'internalCarbonPrice'),

				// % of executive remuneration linked to climate considerations (0-100 %).
				'ifrs-s2-remuneration-link' => static fn (array $o): bool => self::percentInRange($o, 'remunerationLinkedToClimatePct'),

				// industry-based metrics — a non-empty list of disclosed SASB/industry
				// metric codes.
				'ifrs-s2-industry-metrics' => static fn (array $o): bool => self::nonEmptyStringList($o, 'industryMetrics'),

				// each climate-related target carries a metric, base period and whether
				// it is absolute or intensity-based — every climate (E1) target is
				// well-typed.
				'ifrs-s2-climate-targets' => static fn (array $o): bool => self::climateTargetsTyped($o),
			],
		];

	}//end checks()

	/**
	 * No field backfill on existing rows — EsrsStatement is a new type seeded wholesale
	 * by seedObjects().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * One fully-compliant FY2025 EsrsStatement (an EU undertaking that is also a global
	 * IFRS-S2 filer), created only when the type is empty so the audit evaluates these
	 * checks. Every numeric relationship below SATISFIES the predicates: GHG total =
	 * Scope 1 + Scope 2 (location) + Scope 3; the Scope 3 category breakdown sums to the
	 * Scope 3 total; the energy mix sums to the energy total with a derived renewable
	 * share; intensities equal value / net revenue; the gender / age / waste / material
	 * splits reconcile to their totals.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		// Net revenue chosen so the per-revenue intensities below are clean 2-decimal
		// values: OpenRegister stores `number` at ~2 decimals, which would otherwise
		// truncate a realistic-revenue intensity (e.g. 0.00027) to 0.00 and break the
		// intensity-consistency checks. Test data only.
		$netRevenue = 100000.0;

		$scope1 = 1200.0;
		$scope2Location = 800.0;
		$scope2Market = 650.0;
		$scope3 = 18000.0;
		$ghgTotal = ($scope1 + $scope2Location + $scope3);
		$ghgIntensity = round(($ghgTotal / $netRevenue), 8);

		// Scope 3 by GHG-Protocol category, summing exactly to $scope3.
		$scope3Categories = [
			['category' => '3-cat-01', 'value' => 12000.0],
			['category' => '3-cat-04', 'value' => 3500.0],
			['category' => '3-cat-06', 'value' => 1500.0],
			['category' => '3-cat-07', 'value' => 1000.0],
		];

		$energyTotal = 40000.0;
		$energyFossil = 18000.0;
		$energyNuclear = 4000.0;
		$energyRenewable = 18000.0;
		$renewableShare = round((($energyRenewable / $energyTotal) * 100.0), 6);
		$energyIntensity = round(($energyTotal / $netRevenue), 10);

		$waterConsumption = 25000.0;
		$waterIntensity = round(($waterConsumption / $netRevenue), 10);

		$headcount = 250.0;
		$headcountMale = 140.0;
		$headcountFemale = 108.0;
		$headcountOther = 2.0;

		// Age bands summing to the headcount.
		$ageBands = [
			['band' => 'under-30', 'count' => 60.0],
			['band' => '30-50', 'count' => 150.0],
			['band' => 'over-50', 'count' => 40.0],
		];

		$wasteTotal = 500.0;
		$wasteDivertedPct = 70.0;
		$wasteDisposedPct = 30.0;

		$materialsTotalWeight = 9000.0;
		$biologicalSharePct = 35.0;
		$secondarySharePct = 25.0;

		$statement = [
			// Reporting context.
			'reportingPeriod' => 'FY2025',
			'entityName' => 'Conduction Sample Undertaking B.V.',
			'netRevenue' => $netRevenue,
			'currency' => 'EUR',
			'materialityAssessmentDone' => true,
			'datapointsTagged' => true,
			'disclosureRequirementsCovered' => ['E1-6', 'E1-5', 'E1-4', 'E2-4', 'E3-4', 'E4-5', 'E5-4', 'E5-5', 'S1-6', 'S1-14', 'G1-4', 'G1-6'],
			'highClimateImpactSector' => true,
			'financialSectorEntity' => true,

			// ESRS E1 — Climate / GHG.
			'ghgScope1' => $scope1,
			'ghgScope1EtsCoveredPct' => 40.0,
			'ghgScope2LocationBased' => $scope2Location,
			'ghgScope2MarketBased' => $scope2Market,
			'ghgScope3' => $scope3,
			'scope3Categories' => $scope3Categories,
			'ghgTotal' => $ghgTotal,
			'ghgIntensity' => $ghgIntensity,
			'ghgRemovals' => 150.0,
			'carbonCreditsCancelled' => 100.0,
			'internalCarbonPricingApplied' => true,
			'internalCarbonPrice' => 85.0,

			// Energy.
			'energyConsumption' => $energyTotal,
			'energyFossil' => $energyFossil,
			'energyNuclear' => $energyNuclear,
			'energyRenewable' => $energyRenewable,
			'renewableEnergySharePct' => $renewableShare,
			'energyIntensity' => $energyIntensity,

			// ESRS E2 — Pollution.
			'pollutantsToAir' => 12.5,
			'pollutantsToWater' => 3.2,
			'pollutantsToSoil' => 0.8,
			'substancesOfConcern' => 45.0,
			'substancesOfVeryHighConcern' => 2.0,

			// ESRS E3 — Water.
			'waterConsumption' => $waterConsumption,
			'waterConsumptionAtRiskPct' => 15.0,
			'waterIntensity' => $waterIntensity,

			// ESRS E4 — Biodiversity.
			'landUseChangeHectares' => 4.0,
			'sitesNearBiodiversitySensitiveAreas' => 1.0,

			// ESRS E5 — Resource use / circular.
			'materialsTotalWeight' => $materialsTotalWeight,
			'biologicalMaterialsSharePct' => $biologicalSharePct,
			'secondaryMaterialsSharePct' => $secondarySharePct,
			'wasteTotalTonnes' => $wasteTotal,
			'wasteDivertedFromDisposalPct' => $wasteDivertedPct,
			'wasteDirectedToDisposalPct' => $wasteDisposedPct,

			// ESRS S1 — Own workforce.
			'employeeHeadcount' => $headcount,
			'employeeHeadcountMale' => $headcountMale,
			'employeeHeadcountFemale' => $headcountFemale,
			'employeeHeadcountOther' => $headcountOther,
			'nonEmployees' => 30.0,
			'collectiveBargainingCoveragePct' => 65.0,
			'topManagementFemalePct' => 33.0,
			'employeeAgeBands' => $ageBands,
			'healthSafetyCoveragePct' => 100.0,
			'recordableAccidents' => 5.0,
			'workRelatedFatalities' => 0.0,
			'genderPayGapPct' => 4.2,
			'ceoPayRatio' => 12.0,
			'discriminationIncidents' => 0.0,
			'discriminationFines' => 0.0,

			// ESRS G1 — Business conduct.
			'corruptionIncidents' => 0.0,
			'corruptionConvictions' => 0.0,
			'corruptionFines' => 0.0,
			'averageDaysToPay' => 28.0,
			'standardPaymentTermDays' => 30.0,
			'latePaymentProceedings' => 0.0,

			// IFRS S2 — climate risk/opportunity exposure.
			'financedEmissions' => 5400.0,
			'transitionRiskAmount' => 2000000.0,
			'transitionRiskPct' => 8.0,
			'physicalRiskAmount' => 1500000.0,
			'physicalRiskPct' => 6.0,
			'climateOpportunityAmount' => 3000000.0,
			'climateOpportunityPct' => 12.0,
			'climateCapitalDeployed' => 4200000.0,
			'remunerationLinkedToClimatePct' => 15.0,
			'industryMetrics' => ['EM-EU-110a.1', 'IF-RE-130a.1'],

			// Cross-cutting targets (ESRS 2 MDR-T + per-matter targets).
			'targets' => [
				['matter' => 'E1', 'baseYear' => 2023, 'targetYear' => 2030, 'value' => 50.0, 'baseline' => 100.0, 'metric' => 'tCO2e', 'targetType' => 'absolute', 'scienceBased' => true, 'progressPct' => 18.0],
				['matter' => 'E2', 'baseYear' => 2023, 'targetYear' => 2028, 'value' => 5.0, 'baseline' => 12.5, 'metric' => 'tonnes', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 10.0],
				['matter' => 'E3', 'baseYear' => 2023, 'targetYear' => 2030, 'value' => 20000.0, 'baseline' => 25000.0, 'metric' => 'm3', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 12.0],
				['matter' => 'E4', 'baseYear' => 2023, 'targetYear' => 2030, 'value' => 0.0, 'baseline' => 4.0, 'metric' => 'hectares', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 25.0],
				['matter' => 'E5', 'baseYear' => 2023, 'targetYear' => 2030, 'value' => 50.0, 'baseline' => 25.0, 'metric' => 'percent-recycled', 'targetType' => 'intensity', 'scienceBased' => false, 'progressPct' => 20.0],
				['matter' => 'S2', 'baseYear' => 2024, 'targetYear' => 2027, 'value' => 100.0, 'baseline' => 60.0, 'metric' => 'percent-suppliers-audited', 'targetType' => 'intensity', 'scienceBased' => false, 'progressPct' => 30.0],
				['matter' => 'S3', 'baseYear' => 2024, 'targetYear' => 2027, 'value' => 0.0, 'baseline' => 3.0, 'metric' => 'community-grievances', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 40.0],
				['matter' => 'S4', 'baseYear' => 2024, 'targetYear' => 2027, 'value' => 0.0, 'baseline' => 2.0, 'metric' => 'product-safety-incidents', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 50.0],
			],
		];

		return [
			'EsrsStatement' => [$statement],
		];

	}//end seedObjects()

	// ===================== private helpers =====================

	/**
	 * The value under $key is a number (int/float/numeric string).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isNumber(array $o, string $key): bool {
		return array_key_exists($key, $o) === true && is_numeric($o[$key]) === true;
	}//end isNumber()

	/**
	 * The value under $key is a number that is >= 0.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function nonNegNumber(array $o, string $key): bool {
		return self::isNumber($o, $key) === true && (float)$o[$key] >= 0.0;
	}//end nonNegNumber()

	/**
	 * The numeric value under $key (0.0 when absent / non-numeric).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return float
	 */
	private static function num(array $o, string $key): float {
		return is_numeric($o[$key] ?? null) === true ? (float)$o[$key] : 0.0;
	}//end num()

	/**
	 * The value under $key is a number in the inclusive 0..100 percentage range.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function percentInRange(array $o, string $key): bool {
		if (self::isNumber($o, $key) === false) {
			return false;
		}

		$v = (float)$o[$key];
		return $v >= 0.0 && $v <= 100.0;
	}//end percentInRange()

	/**
	 * The field under $key is a boolean-like value at all.
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
	 * Two values agree within a relative tolerance (1 % of the larger magnitude, with a
	 * small absolute floor) — used to validate derived sums / ratios against their
	 * stored datapoint without demanding exact float equality.
	 *
	 * @param float $a Expected value.
	 * @param float $b Stored value.
	 *
	 * @return bool
	 */
	private static function approxEq(float $a, float $b): bool {
		$tolerance = max((abs($a) * 0.01), (abs($b) * 0.01), 0.0001);
		return abs($a - $b) <= $tolerance;
	}//end approxEq()

	/**
	 * The value under $key is a non-empty list of non-empty strings.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function nonEmptyStringList(array $o, string $key): bool {
		$list = ($o[$key] ?? null);
		if (is_array($list) === false || $list === []) {
			return false;
		}

		foreach ($list as $item) {
			if (is_scalar($item) === false || trim((string)$item) === '') {
				return false;
			}
		}

		return true;
	}//end nonEmptyStringList()

	/**
	 * The statement's targets[] is a non-empty list and every row is structurally
	 * complete: a matter from the ESRS matter set, an integer-like base year and target
	 * year with targetYear >= baseYear, a numeric target value and a numeric baseline.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function targetsWellFormed(array $o): bool {
		$targets = ($o['targets'] ?? null);
		if (is_array($targets) === false || $targets === []) {
			return false;
		}

		foreach ($targets as $t) {
			if (is_array($t) === false) {
				return false;
			}

			$matter = strtoupper(trim((string)($t['matter'] ?? '')));
			if (in_array($matter, self::ESRS_MATTERS, true) === false) {
				return false;
			}

			if (is_numeric($t['baseYear'] ?? null) === false
				|| is_numeric($t['targetYear'] ?? null) === false
				|| is_numeric($t['value'] ?? null) === false
				|| is_numeric($t['baseline'] ?? null) === false
			) {
				return false;
			}

			if ((int)$t['targetYear'] < (int)$t['baseYear']) {
				return false;
			}
		}//end foreach

		return true;
	}//end targetsWellFormed()

	/**
	 * At least one well-formed target relates to ESRS matter $matter.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $matter The ESRS matter code (e.g. 'E2').
	 *
	 * @return bool
	 */
	private static function hasTargetForMatter(array $o, string $matter): bool {
		if (self::targetsWellFormed($o) === false) {
			return false;
		}

		foreach (($o['targets'] ?? []) as $t) {
			if (is_array($t) === true
				&& strcasecmp(trim((string)($t['matter'] ?? '')), $matter) === 0
			) {
				return true;
			}
		}

		return false;
	}//end hasTargetForMatter()

	/**
	 * At least one E1 (climate) target carries a base year, a target year and an
	 * explicit boolean science-based flag (ESRS E1-4).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function hasGhgReductionTarget(array $o): bool {
		if (self::targetsWellFormed($o) === false) {
			return false;
		}

		foreach (($o['targets'] ?? []) as $t) {
			if (is_array($t) === false || strcasecmp(trim((string)($t['matter'] ?? '')), 'E1') !== 0) {
				continue;
			}

			if (is_numeric($t['baseYear'] ?? null) === true
				&& is_numeric($t['targetYear'] ?? null) === true
				&& is_bool($t['scienceBased'] ?? null) === true
			) {
				return true;
			}
		}

		return false;
	}//end hasGhgReductionTarget()

	/**
	 * Every E1 (climate) target is well-typed for IFRS S2: it carries a metric string, a
	 * base year and an absolute/intensity target type. Requires at least one E1 target.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function climateTargetsTyped(array $o): bool {
		if (self::targetsWellFormed($o) === false) {
			return false;
		}

		$seen = false;
		foreach (($o['targets'] ?? []) as $t) {
			if (is_array($t) === false || strcasecmp(trim((string)($t['matter'] ?? '')), 'E1') !== 0) {
				continue;
			}

			$seen = true;
			$type = strtolower(trim((string)($t['targetType'] ?? '')));
			if (trim((string)($t['metric'] ?? '')) === ''
				|| is_numeric($t['baseYear'] ?? null) === false
				|| in_array($type, ['absolute', 'intensity'], true) === false
			) {
				return false;
			}
		}

		return $seen;
	}//end climateTargetsTyped()

	/**
	 * The energy mix is consistent: fossil + nuclear + renewable equals the total energy
	 * consumption (within tolerance), all are >= 0, and the renewable-share percentage
	 * equals renewable / total * 100.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function energyMixConsistent(array $o): bool {
		foreach (['energyConsumption', 'energyFossil', 'energyNuclear', 'energyRenewable', 'renewableEnergySharePct'] as $k) {
			if (self::nonNegNumber($o, $k) === false) {
				return false;
			}
		}

		$total = self::num($o, 'energyConsumption');
		if ($total <= 0.0) {
			return false;
		}

		$sum = (self::num($o, 'energyFossil') + self::num($o, 'energyNuclear') + self::num($o, 'energyRenewable'));
		if (self::approxEq($sum, $total) === false) {
			return false;
		}

		$expectedShare = ((self::num($o, 'energyRenewable') / $total) * 100.0);
		return self::approxEq($expectedShare, self::num($o, 'renewableEnergySharePct'));
	}//end energyMixConsistent()

	/**
	 * Energy intensity equals total energy consumption / net revenue. In a high-climate-
	 * impact sector the intensity must be present; otherwise the rule is vacuously
	 * satisfied (ESRS E1-5 limits the obligation to those sectors).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function energyIntensityConsistent(array $o): bool {
		if (self::isTrue($o, 'highClimateImpactSector') === false) {
			return true;
		}

		return self::intensityConsistent($o, 'energyConsumption', 'energyIntensity');
	}//end energyIntensityConsistent()

	/**
	 * The intensity datapoint under $intensityKey equals the numerator under $valueKey
	 * divided by net revenue (within tolerance). Requires net revenue > 0.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $valueKey The numerator datapoint.
	 * @param string $intensityKey The intensity datapoint.
	 *
	 * @return bool
	 */
	private static function intensityConsistent(array $o, string $valueKey, string $intensityKey): bool {
		if (self::nonNegNumber($o, $valueKey) === false
			|| self::isNumber($o, $intensityKey) === false
			|| self::isNumber($o, 'netRevenue') === false
		) {
			return false;
		}

		$revenue = self::num($o, 'netRevenue');
		if ($revenue <= 0.0) {
			return false;
		}

		return self::approxEq((self::num($o, $valueKey) / $revenue), self::num($o, $intensityKey));
	}//end intensityConsistent()

	/**
	 * The Scope 3 per-category breakdown sums (within tolerance) to the Scope 3 total,
	 * with at least one category, each a recognised GHG-Protocol scope-3 code and a
	 * non-negative value.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function scope3CategoriesSum(array $o): bool {
		$cats = ($o['scope3Categories'] ?? null);
		if (is_array($cats) === false || $cats === []) {
			return false;
		}

		$sum = 0.0;
		foreach ($cats as $c) {
			if (is_array($c) === false || is_numeric($c['value'] ?? null) === false) {
				return false;
			}

			if (self::isScope3Code((string)($c['category'] ?? '')) === false) {
				return false;
			}

			$val = (float)$c['value'];
			if ($val < 0.0) {
				return false;
			}

			$sum += $val;
		}

		return self::approxEq($sum, self::num($o, 'ghgScope3'));
	}//end scope3CategoriesSum()

	/**
	 * The statement carries a non-empty, well-formed Scope 3 category breakdown.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function hasScope3Categories(array $o): bool {
		$cats = ($o['scope3Categories'] ?? null);
		if (is_array($cats) === false || $cats === []) {
			return false;
		}

		foreach ($cats as $c) {
			if (is_array($c) === false || self::isScope3Code((string)($c['category'] ?? '')) === false) {
				return false;
			}
		}

		return true;
	}//end hasScope3Categories()

	/**
	 * The code is a GHG-Protocol Scope 3 category code of the form 3-cat-01 .. 3-cat-15.
	 *
	 * @param string $code The category code.
	 *
	 * @return bool
	 */
	private static function isScope3Code(string $code): bool {
		return preg_match('/^3-cat-(0[1-9]|1[0-5])$/', trim($code)) === 1;
	}//end isScope3Code()

	/**
	 * Total GHG equals Scope 1 + Scope 2 (location-based) + Scope 3 (within tolerance),
	 * with each component present and non-negative.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ghgTotalIsSum(array $o): bool {
		foreach (['ghgScope1', 'ghgScope2LocationBased', 'ghgScope3', 'ghgTotal'] as $k) {
			if (self::nonNegNumber($o, $k) === false) {
				return false;
			}
		}

		$expected = (self::num($o, 'ghgScope1') + self::num($o, 'ghgScope2LocationBased') + self::num($o, 'ghgScope3'));
		return self::approxEq($expected, self::num($o, 'ghgTotal'));
	}//end ghgTotalIsSum()

	/**
	 * GHG intensity equals total GHG / net revenue (within tolerance).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ghgIntensityConsistent(array $o): bool {
		return self::intensityConsistent($o, 'ghgTotal', 'ghgIntensity');
	}//end ghgIntensityConsistent()

	/**
	 * Internal-carbon-price consistency: when the applied-flag under $flagKey is true a
	 * positive price under $priceKey is disclosed; when false the rule is satisfied
	 * regardless. The applied-flag must be a decidable boolean.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $flagKey The applied-flag field.
	 * @param string $priceKey The price field.
	 *
	 * @return bool
	 */
	private static function internalCarbonPriceConsistent(array $o, string $flagKey, string $priceKey): bool {
		if (self::isBool($o, $flagKey) === false) {
			return false;
		}

		if (self::isTrue($o, $flagKey) === false) {
			return true;
		}

		return self::isNumber($o, $priceKey) === true && (float)$o[$priceKey] > 0.0;
	}//end internalCarbonPriceConsistent()

	/**
	 * Resource inflows consistency (ESRS E5-4): total material weight present and >= 0,
	 * the biological and secondary material shares each within 0..100 %, and the two
	 * shares do not jointly exceed 100 %.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function resourceInflowsConsistent(array $o): bool {
		if (self::nonNegNumber($o, 'materialsTotalWeight') === false
			|| self::percentInRange($o, 'biologicalMaterialsSharePct') === false
			|| self::percentInRange($o, 'secondaryMaterialsSharePct') === false
		) {
			return false;
		}

		return (self::num($o, 'biologicalMaterialsSharePct') + self::num($o, 'secondaryMaterialsSharePct')) <= 100.0001;
	}//end resourceInflowsConsistent()

	/**
	 * Waste-split consistency (ESRS E5-5): total waste present and >= 0, the diverted and
	 * disposed shares each within 0..100 %, and the two shares sum to 100 %.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function wasteSplitConsistent(array $o): bool {
		if (self::nonNegNumber($o, 'wasteTotalTonnes') === false
			|| self::percentInRange($o, 'wasteDivertedFromDisposalPct') === false
			|| self::percentInRange($o, 'wasteDirectedToDisposalPct') === false
		) {
			return false;
		}

		return self::approxEq(
			(self::num($o, 'wasteDivertedFromDisposalPct') + self::num($o, 'wasteDirectedToDisposalPct')),
			100.0
		);

	}//end wasteSplitConsistent()

	/**
	 * Headcount gender consistency (ESRS S1-6): the total head count present and >= 0,
	 * the male/female/other counts present and >= 0, and the three summing to the total.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function headcountGenderConsistent(array $o): bool {
		foreach (['employeeHeadcount', 'employeeHeadcountMale', 'employeeHeadcountFemale', 'employeeHeadcountOther'] as $k) {
			if (self::nonNegNumber($o, $k) === false) {
				return false;
			}
		}

		$sum = (self::num($o, 'employeeHeadcountMale') + self::num($o, 'employeeHeadcountFemale') + self::num($o, 'employeeHeadcountOther'));
		return self::approxEq($sum, self::num($o, 'employeeHeadcount'));
	}//end headcountGenderConsistent()

	/**
	 * Age-band consistency (ESRS S1-9): a non-empty list of {band, count} rows whose
	 * counts (each >= 0) sum to the employee head count.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ageBandsConsistent(array $o): bool {
		$bands = ($o['employeeAgeBands'] ?? null);
		if (is_array($bands) === false || $bands === [] || self::nonNegNumber($o, 'employeeHeadcount') === false) {
			return false;
		}

		$sum = 0.0;
		foreach ($bands as $b) {
			if (is_array($b) === false || trim((string)($b['band'] ?? '')) === '' || is_numeric($b['count'] ?? null) === false) {
				return false;
			}

			$count = (float)$b['count'];
			if ($count < 0.0) {
				return false;
			}

			$sum += $count;
		}

		return self::approxEq($sum, self::num($o, 'employeeHeadcount'));
	}//end ageBandsConsistent()

	/**
	 * Health-and-safety consistency (ESRS S1-14): coverage % within 0..100, recordable
	 * accidents and fatalities present and >= 0, and fatalities not exceeding accidents.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function healthSafetyConsistent(array $o): bool {
		if (self::percentInRange($o, 'healthSafetyCoveragePct') === false
			|| self::nonNegNumber($o, 'recordableAccidents') === false
			|| self::nonNegNumber($o, 'workRelatedFatalities') === false
		) {
			return false;
		}

		return self::num($o, 'workRelatedFatalities') <= self::num($o, 'recordableAccidents');
	}//end healthSafetyConsistent()

	/**
	 * Corruption-incident consistency (ESRS G1-4): confirmed incidents, convictions and
	 * fines present and >= 0, with convictions not exceeding incidents.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function corruptionConsistent(array $o): bool {
		foreach (['corruptionIncidents', 'corruptionConvictions', 'corruptionFines'] as $k) {
			if (self::nonNegNumber($o, $k) === false) {
				return false;
			}
		}

		return self::num($o, 'corruptionConvictions') <= self::num($o, 'corruptionIncidents');
	}//end corruptionConsistent()

	/**
	 * Amount-and-percentage exposure consistency (IFRS S2): the amount present and >= 0
	 * and the percentage within 0..100 %.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $amountKey The amount field.
	 * @param string $pctKey The percentage field.
	 *
	 * @return bool
	 */
	private static function amountPercentConsistent(array $o, string $amountKey, string $pctKey): bool {
		return self::nonNegNumber($o, $amountKey) === true && self::percentInRange($o, $pctKey) === true;
	}//end amountPercentConsistent()

	/**
	 * Conditional non-negative (IFRS S2 financed emissions): when the gate flag under
	 * $flagKey is true the value under $valueKey is present and >= 0; otherwise the rule
	 * is vacuously satisfied. The gate flag must be a decidable boolean.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $flagKey The gating-flag field.
	 * @param string $valueKey The conditional value field.
	 *
	 * @return bool
	 */
	private static function conditionalNonNeg(array $o, string $flagKey, string $valueKey): bool {
		if (self::isBool($o, $flagKey) === false) {
			return false;
		}

		if (self::isTrue($o, $flagKey) === false) {
			return true;
		}

		return self::nonNegNumber($o, $valueKey);
	}//end conditionalNonNeg()
}//end class
