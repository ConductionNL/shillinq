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
	 * The sample undertaking's net revenue, chosen so the per-revenue intensities
	 * are clean 2-decimal values: OpenRegister stores `number` at ~2 decimals, which
	 * would otherwise truncate a realistic-revenue intensity (e.g. 0.00027) to 0.00
	 * and break the intensity-consistency checks. Test data only.
	 *
	 * @var float
	 */
	private const SEED_NET_REVENUE = 100000.0;

	/**
	 * The reporting context of the seeded sample statement.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_REPORTING_CONTEXT = [
		'reportingPeriod' => 'FY2025',
		'entityName' => 'Conduction Sample Undertaking B.V.',
		'netRevenue' => self::SEED_NET_REVENUE,
		'currency' => 'EUR',
		'materialityAssessmentDone' => true,
		'datapointsTagged' => true,
		'disclosureRequirementsCovered' => ['E1-6', 'E1-5', 'E1-4', 'E2-4', 'E3-4', 'E4-5', 'E5-4', 'E5-5', 'S1-6', 'S1-14', 'G1-4', 'G1-6'],
		'highClimateImpactSector' => true,
		'financialSectorEntity' => true,
	];

	/**
	 * The seeded GHG scope figures; Scope 3 by GHG-Protocol category sums exactly to Scope 3.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_GHG = [
		'ghgScope1' => 1200.0,
		'ghgScope1EtsCoveredPct' => 40.0,
		'ghgScope2LocationBased' => 800.0,
		'ghgScope2MarketBased' => 650.0,
		'ghgScope3' => 18000.0,
		'scope3Categories' => [
			['category' => '3-cat-01', 'value' => 12000.0],
			['category' => '3-cat-04', 'value' => 3500.0],
			['category' => '3-cat-06', 'value' => 1500.0],
			['category' => '3-cat-07', 'value' => 1000.0],
		],
	];

	/**
	 * The seeded removals, carbon credits and internal carbon pricing.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_CLIMATE_INSTRUMENTS = [
		'ghgRemovals' => 150.0,
		'carbonCreditsCancelled' => 100.0,
		'internalCarbonPricingApplied' => true,
		'internalCarbonPrice' => 85.0,
	];

	/**
	 * The seeded energy consumption and mix.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_ENERGY = [
		'energyConsumption' => 40000.0,
		'energyFossil' => 18000.0,
		'energyNuclear' => 4000.0,
		'energyRenewable' => 18000.0,
	];

	/**
	 * The seeded ESRS E2 pollution figures.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_POLLUTION = [
		'pollutantsToAir' => 12.5,
		'pollutantsToWater' => 3.2,
		'pollutantsToSoil' => 0.8,
		'substancesOfConcern' => 45.0,
		'substancesOfVeryHighConcern' => 2.0,
	];

	/**
	 * The seeded ESRS E4 biodiversity and ESRS E5 resource-use figures.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_NATURE_AND_RESOURCES = [
		'landUseChangeHectares' => 4.0,
		'sitesNearBiodiversitySensitiveAreas' => 1.0,

		// ESRS E5 — Resource use / circular.
		'materialsTotalWeight' => 9000.0,
		'biologicalMaterialsSharePct' => 35.0,
		'secondaryMaterialsSharePct' => 25.0,
		'wasteTotalTonnes' => 500.0,
		'wasteDivertedFromDisposalPct' => 70.0,
		'wasteDirectedToDisposalPct' => 30.0,
	];

	/**
	 * The seeded ESRS S1 own-workforce figures.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_WORKFORCE = [
		'employeeHeadcount' => 250.0,
		'employeeHeadcountMale' => 140.0,
		'employeeHeadcountFemale' => 108.0,
		'employeeHeadcountOther' => 2.0,
		'nonEmployees' => 30.0,
		'collectiveBargainingCoveragePct' => 65.0,
		'topManagementFemalePct' => 33.0,
		// Age bands summing to the headcount.
		'employeeAgeBands' => [
			['band' => 'under-30', 'count' => 60.0],
			['band' => '30-50', 'count' => 150.0],
			['band' => 'over-50', 'count' => 40.0],
		],
		'healthSafetyCoveragePct' => 100.0,
		'recordableAccidents' => 5.0,
		'workRelatedFatalities' => 0.0,
		'genderPayGapPct' => 4.2,
		'ceoPayRatio' => 12.0,
		'discriminationIncidents' => 0.0,
		'discriminationFines' => 0.0,
	];

	/**
	 * The seeded ESRS G1 business-conduct and IFRS S2 climate risk/opportunity figures.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_CONDUCT_AND_CLIMATE_RISK = [
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
	];

	/**
	 * The seeded cross-cutting targets (ESRS 2 MDR-T + per-matter targets).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const SEED_TARGETS = [
		['matter' => 'E1', 'baseYear' => 2023, 'targetYear' => 2030, 'value' => 50.0, 'baseline' => 100.0, 'metric' => 'tCO2e', 'targetType' => 'absolute', 'scienceBased' => true, 'progressPct' => 18.0],
		['matter' => 'E2', 'baseYear' => 2023, 'targetYear' => 2028, 'value' => 5.0, 'baseline' => 12.5, 'metric' => 'tonnes', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 10.0],
		['matter' => 'E3', 'baseYear' => 2023, 'targetYear' => 2030, 'value' => 20000.0, 'baseline' => 25000.0, 'metric' => 'm3', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 12.0],
		['matter' => 'E4', 'baseYear' => 2023, 'targetYear' => 2030, 'value' => 0.0, 'baseline' => 4.0, 'metric' => 'hectares', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 25.0],
		['matter' => 'E5', 'baseYear' => 2023, 'targetYear' => 2030, 'value' => 50.0, 'baseline' => 25.0, 'metric' => 'percent-recycled', 'targetType' => 'intensity', 'scienceBased' => false, 'progressPct' => 20.0],
		['matter' => 'S2', 'baseYear' => 2024, 'targetYear' => 2027, 'value' => 100.0, 'baseline' => 60.0, 'metric' => 'percent-suppliers-audited', 'targetType' => 'intensity', 'scienceBased' => false, 'progressPct' => 30.0],
		['matter' => 'S3', 'baseYear' => 2024, 'targetYear' => 2027, 'value' => 0.0, 'baseline' => 3.0, 'metric' => 'community-grievances', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 40.0],
		['matter' => 'S4', 'baseYear' => 2024, 'targetYear' => 2027, 'value' => 0.0, 'baseline' => 2.0, 'metric' => 'product-safety-incidents', 'targetType' => 'absolute', 'scienceBased' => false, 'progressPct' => 50.0],
	];

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
				...self::generalAndClimateChecks(),
				...self::environmentChecks(),
				...self::socialChecks(),
				...self::businessConductChecks(),
				...self::ifrsS2EmissionsAndRiskChecks(),
				...self::ifrsS2OpportunityAndTargetChecks(),
			],
		];

	}//end checks()

	/**
	 * The ESRS 2 general and ESRS E1 climate predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function generalAndClimateChecks(): array {
		return [
			// ===== ESRS 2 — general / cross-cutting. =====
			'esrs-2-iro-2-dr-coverage' => self::esrs2Iro2DrCoverage(...),
			'esrs-2-mdr-t-targets' => self::esrs2MdrTTargets(...),

			// ===== ESRS E1 — Climate change. =====
			'esrs-e1-4-ghg-targets' => self::esrsE14GhgTargets(...),
			'esrs-e1-5-energy-consumption-mix' => self::esrsE15EnergyConsumptionMix(...),
			'esrs-e1-5-energy-intensity' => self::esrsE15EnergyIntensity(...),
			'esrs-e1-6-ghg-scope1' => self::esrsE16GhgScope1(...),
			'esrs-e1-6-ghg-scope2' => self::esrsE16GhgScope2(...),
			'esrs-e1-6-ghg-scope3' => self::esrsE16GhgScope3(...),
			'esrs-e1-6-ghg-total' => self::esrsE16GhgTotal(...),
			'esrs-e1-7-removals-credits' => self::esrsE17RemovalsCredits(...),
			'esrs-e1-8-internal-carbon-pricing' => self::esrsE18InternalCarbonPricing(...),
		];

	}//end generalAndClimateChecks()

	/**
	 * The ESRS E2-E5 pollution, water, biodiversity and resource-use predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function environmentChecks(): array {
		return [
			// ===== ESRS E2 — Pollution. =====
			'esrs-e2-3-pollution-targets' => self::esrsE23PollutionTargets(...),
			'esrs-e2-4-pollution-air-water-soil' => self::esrsE24PollutionAirWaterSoil(...),
			'esrs-e2-5-substances-of-concern' => self::esrsE25SubstancesOfConcern(...),

			// ===== ESRS E3 — Water and marine resources. =====
			'esrs-e3-3-water-targets' => self::esrsE33WaterTargets(...),
			'esrs-e3-4-water-consumption' => self::esrsE34WaterConsumption(...),

			// ===== ESRS E4 — Biodiversity and ecosystems. =====
			'esrs-e4-4-biodiversity-targets' => self::esrsE44BiodiversityTargets(...),
			'esrs-e4-5-biodiversity-impact-metrics' => self::esrsE45BiodiversityImpactMetrics(...),

			// ===== ESRS E5 — Resource use and circular economy. =====
			'esrs-e5-3-circular-targets' => self::esrsE53CircularTargets(...),
			'esrs-e5-4-resource-inflows' => self::esrsE54ResourceInflows(...),
			'esrs-e5-5-resource-outflows-waste' => self::esrsE55ResourceOutflowsWaste(...),
		];

	}//end environmentChecks()

	/**
	 * The ESRS S1-S4 workforce, value-chain, community and consumer predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function socialChecks(): array {
		return [
			// ===== ESRS S1 — Own workforce. =====
			'esrs-s1-6-employee-headcount' => self::esrsS16EmployeeHeadcount(...),
			'esrs-s1-7-non-employees' => self::esrsS17NonEmployees(...),
			'esrs-s1-8-collective-bargaining' => self::esrsS18CollectiveBargaining(...),
			'esrs-s1-9-diversity-metrics' => self::esrsS19DiversityMetrics(...),
			'esrs-s1-14-health-safety' => self::esrsS114HealthSafety(...),
			'esrs-s1-16-pay-gap' => self::esrsS116PayGap(...),
			'esrs-s1-17-incidents-discrimination' => self::esrsS117IncidentsDiscrimination(...),

			// ===== ESRS S2 / S3 / S4 — value-chain workers, communities, consumers. =====
			'esrs-s2-5-vc-workers-targets' => self::esrsS25VcWorkersTargets(...),
			'esrs-s3-5-communities-targets' => self::esrsS35CommunitiesTargets(...),
			'esrs-s4-5-consumers-targets' => self::esrsS45ConsumersTargets(...),
		];

	}//end socialChecks()

	/**
	 * The ESRS G1 business-conduct predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function businessConductChecks(): array {
		return [
			// ===== ESRS G1 — Business conduct. =====
			'esrs-g1-4-corruption-incidents' => self::esrsG14CorruptionIncidents(...),
			'esrs-g1-6-payment-practices' => self::esrsG16PaymentPractices(...),
		];

	}//end businessConductChecks()

	/**
	 * The IFRS S2 emissions and climate-risk predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function ifrsS2EmissionsAndRiskChecks(): array {
		return [
			// ===== IFRS S2 — Climate-related disclosures (global). =====
			'ifrs-s2-ghg-scope1' => self::ifrsS2GhgScope1(...),
			'ifrs-s2-ghg-scope2' => self::ifrsS2GhgScope2(...),
			'ifrs-s2-ghg-scope3' => self::ifrsS2GhgScope3(...),
			'ifrs-s2-financed-emissions' => self::ifrsS2FinancedEmissions(...),
			'ifrs-s2-transition-risk-exposure' => self::ifrsS2TransitionRiskExposure(...),
			'ifrs-s2-physical-risk-exposure' => self::ifrsS2PhysicalRiskExposure(...),
		];

	}//end ifrsS2EmissionsAndRiskChecks()

	/**
	 * The IFRS S2 opportunity, transition and target predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function ifrsS2OpportunityAndTargetChecks(): array {
		return [
			'ifrs-s2-opportunities-exposure' => self::ifrsS2OpportunitiesExposure(...),
			'ifrs-s2-capital-deployment' => self::ifrsS2CapitalDeployment(...),
			'ifrs-s2-internal-carbon-price' => self::ifrsS2InternalCarbonPrice(...),
			'ifrs-s2-remuneration-link' => self::ifrsS2RemunerationLink(...),
			'ifrs-s2-industry-metrics' => self::ifrsS2IndustryMetrics(...),
			'ifrs-s2-climate-targets' => self::ifrsS2ClimateTargets(...),
		];

	}//end ifrsS2OpportunityAndTargetChecks()

	/**
	 * IRO-2: the statement lists which disclosure requirements it covers
	 * and tags the reported datapoints — datapointsTagged true and a
	 * non-empty list of covered DR codes.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrs2Iro2DrCoverage(array $o): bool {
		return self::isTrue($o, 'datapointsTagged')
			&& self::nonEmptyStringList($o, 'disclosureRequirementsCovered');

	}//end esrs2Iro2DrCoverage()

	/**
	 * MDR-T: for each material matter a measurable, outcome-oriented target
	 * with a baseline, the period it relates to and progress — every
	 * targets[] row is structurally complete.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrs2MdrTTargets(array $o): bool {
		return self::targetsWellFormed($o);

	}//end esrs2MdrTTargets()

	/**
	 * E1-4: GHG reduction targets in absolute value with base year, target
	 * year and a science-based flag — at least one E1 target carrying all of
	 * baseYear, targetYear and a boolean scienceBased.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE14GhgTargets(array $o): bool {
		return self::hasGhgReductionTarget($o);

	}//end esrsE14GhgTargets()

	/**
	 * E1-5: total energy consumption in MWh disaggregated into fossil,
	 * nuclear and renewable, and the renewable share = renewable / total.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE15EnergyConsumptionMix(array $o): bool {
		return self::energyMixConsistent($o);

	}//end esrsE15EnergyConsumptionMix()

	/**
	 * E1-5: energy intensity = total energy consumption per net revenue
	 * (MWh per EUR), equal to energyConsumption / netRevenue.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE15EnergyIntensity(array $o): bool {
		return self::energyIntensityConsistent($o);

	}//end esrsE15EnergyIntensity()

	/**
	 * E1-6: gross Scope 1 in tCO2e (>= 0) and the % covered by an emission
	 * trading scheme within 0-100.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE16GhgScope1(array $o): bool {
		return self::nonNegNumber($o, 'ghgScope1')
			&& self::percentInRange($o, 'ghgScope1EtsCoveredPct');

	}//end esrsE16GhgScope1()

	/**
	 * E1-6: gross Scope 2 in tCO2e reported both location-based and
	 * market-based (both present, both >= 0).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE16GhgScope2(array $o): bool {
		return self::nonNegNumber($o, 'ghgScope2LocationBased')
			&& self::nonNegNumber($o, 'ghgScope2MarketBased');

	}//end esrsE16GhgScope2()

	/**
	 * E1-6: gross Scope 3 in tCO2e broken down by significant GHG-Protocol
	 * value-chain categories — total >= 0 and the per-category breakdown
	 * sums (within tolerance) to it.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE16GhgScope3(array $o): bool {
		return self::nonNegNumber($o, 'ghgScope3')
			&& self::scope3CategoriesSum($o);

	}//end esrsE16GhgScope3()

	/**
	 * E1-6: total GHG = Scope 1 + Scope 2 (location-based) + Scope 3, and the
	 * intensity per net revenue = total / netRevenue.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE16GhgTotal(array $o): bool {
		return self::ghgTotalIsSum($o)
			&& self::ghgIntensityConsistent($o);

	}//end esrsE16GhgTotal()

	/**
	 * E1-7: GHG removals/storage and carbon credits cancelled, in tCO2e
	 * (both present and >= 0).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE17RemovalsCredits(array $o): bool {
		return self::nonNegNumber($o, 'ghgRemovals')
			&& self::nonNegNumber($o, 'carbonCreditsCancelled');

	}//end esrsE17RemovalsCredits()

	/**
	 * E1-8: whether internal carbon pricing is applied; if so a positive
	 * price per tCO2e is disclosed (conditional consistency).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE18InternalCarbonPricing(array $o): bool {
		return self::internalCarbonPriceConsistent($o, 'internalCarbonPricingApplied', 'internalCarbonPrice');

	}//end esrsE18InternalCarbonPricing()

	/**
	 * E2-3: measurable pollution-prevention targets — at least one E2 target.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE23PollutionTargets(array $o): bool {
		return self::hasTargetForMatter($o, 'E2');

	}//end esrsE23PollutionTargets()

	/**
	 * E2-4: amounts of pollutants emitted to air, water and soil in units of
	 * mass — all three present and >= 0.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE24PollutionAirWaterSoil(array $o): bool {
		return self::nonNegNumber($o, 'pollutantsToAir')
			&& self::nonNegNumber($o, 'pollutantsToWater')
			&& self::nonNegNumber($o, 'pollutantsToSoil');

	}//end esrsE24PollutionAirWaterSoil()

	/**
	 * E2-5: quantities of substances of concern and of very high concern —
	 * both present and >= 0.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE25SubstancesOfConcern(array $o): bool {
		return self::nonNegNumber($o, 'substancesOfConcern')
			&& self::nonNegNumber($o, 'substancesOfVeryHighConcern');

	}//end esrsE25SubstancesOfConcern()

	/**
	 * E3-3: measurable water targets — at least one E3 target.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE33WaterTargets(array $o): bool {
		return self::hasTargetForMatter($o, 'E3');

	}//end esrsE33WaterTargets()

	/**
	 * E3-4: total water consumption in m3, the share in water-risk areas
	 * (0-100 %), and water intensity = consumption / net revenue.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE34WaterConsumption(array $o): bool {
		return self::nonNegNumber($o, 'waterConsumption')
			&& self::percentInRange($o, 'waterConsumptionAtRiskPct')
			&& self::intensityConsistent($o, 'waterConsumption', 'waterIntensity');

	}//end esrsE34WaterConsumption()

	/**
	 * E4-4: measurable biodiversity targets — at least one E4 target.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE44BiodiversityTargets(array $o): bool {
		return self::hasTargetForMatter($o, 'E4');

	}//end esrsE44BiodiversityTargets()

	/**
	 * E4-5: biodiversity impact metrics — land-use change (ha) and the count
	 * of sites near biodiversity-sensitive areas — both present and >= 0.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE45BiodiversityImpactMetrics(array $o): bool {
		return self::nonNegNumber($o, 'landUseChangeHectares')
			&& self::nonNegNumber($o, 'sitesNearBiodiversitySensitiveAreas');

	}//end esrsE45BiodiversityImpactMetrics()

	/**
	 * E5-3: measurable circular-economy targets — at least one E5 target.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE53CircularTargets(array $o): bool {
		return self::hasTargetForMatter($o, 'E5');

	}//end esrsE53CircularTargets()

	/**
	 * E5-4: resource inflows — total weight of materials used and the share
	 * of biological + secondary (recycled) materials (each share 0-100 %,
	 * and the two shares do not jointly exceed 100 %).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE54ResourceInflows(array $o): bool {
		return self::resourceInflowsConsistent($o);

	}//end esrsE54ResourceInflows()

	/**
	 * E5-5: resource outflows — total waste in tonnes split into the share
	 * diverted from disposal and the share directed to disposal, which sum
	 * to 100 %.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsE55ResourceOutflowsWaste(array $o): bool {
		return self::wasteSplitConsistent($o);

	}//end esrsE55ResourceOutflowsWaste()

	/**
	 * S1-6: total employees by head count broken down by gender — the gender
	 * breakdown sums to the headcount.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS16EmployeeHeadcount(array $o): bool {
		return self::headcountGenderConsistent($o);

	}//end esrsS16EmployeeHeadcount()

	/**
	 * S1-7: total non-employees in own workforce — present and >= 0.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS17NonEmployees(array $o): bool {
		return self::nonNegNumber($o, 'nonEmployees');

	}//end esrsS17NonEmployees()

	/**
	 * S1-8: % of employees covered by collective bargaining (0-100 %).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS18CollectiveBargaining(array $o): bool {
		return self::percentInRange($o, 'collectiveBargainingCoveragePct');

	}//end esrsS18CollectiveBargaining()

	/**
	 * S1-9: gender distribution at top management (a share that sums with its
	 * complement to the management head count is not modelled flat; here the
	 * female-share at top management is a 0-100 % datapoint) and the
	 * distribution of employees by age band summing to the headcount.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS19DiversityMetrics(array $o): bool {
		return self::percentInRange($o, 'topManagementFemalePct')
			&& self::ageBandsConsistent($o);

	}//end esrsS19DiversityMetrics()

	/**
	 * S1-14: health-and-safety — % of workforce covered by the H&S system
	 * (0-100 %) and the recordable-accidents count and fatalities count
	 * (both >= 0, fatalities <= accidents).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS114HealthSafety(array $o): bool {
		return self::healthSafetyConsistent($o);

	}//end esrsS114HealthSafety()

	/**
	 * S1-16: gender pay gap (a %, may be negative) and the CEO-to-median
	 * total-remuneration ratio (>= 0).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS116PayGap(array $o): bool {
		return self::isNumber($o, 'genderPayGapPct')
			&& self::nonNegNumber($o, 'ceoPayRatio');

	}//end esrsS116PayGap()

	/**
	 * S1-17: number of discrimination incidents and the related fines amount
	 * (both present and >= 0).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS117IncidentsDiscrimination(array $o): bool {
		return self::nonNegNumber($o, 'discriminationIncidents')
			&& self::nonNegNumber($o, 'discriminationFines');

	}//end esrsS117IncidentsDiscrimination()

	/**
	 * S2-5: time-bound, outcome-oriented value-chain-worker targets — at
	 * least one S2 target.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS25VcWorkersTargets(array $o): bool {
		return self::hasTargetForMatter($o, 'S2');

	}//end esrsS25VcWorkersTargets()

	/**
	 * S3-5: affected-community targets — at least one S3 target.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS35CommunitiesTargets(array $o): bool {
		return self::hasTargetForMatter($o, 'S3');

	}//end esrsS35CommunitiesTargets()

	/**
	 * S4-5: consumer/end-user targets — at least one S4 target.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsS45ConsumersTargets(array $o): bool {
		return self::hasTargetForMatter($o, 'S4');

	}//end esrsS45ConsumersTargets()

	/**
	 * G1-4: confirmed corruption/bribery incidents, convictions and fines —
	 * all present and >= 0, convictions <= incidents.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsG14CorruptionIncidents(array $o): bool {
		return self::corruptionConsistent($o);

	}//end esrsG14CorruptionIncidents()

	/**
	 * G1-6: payment practices — average days to pay, the standard payment
	 * term (days) and the count of late-payment legal proceedings — all >= 0.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esrsG16PaymentPractices(array $o): bool {
		return self::nonNegNumber($o, 'averageDaysToPay')
			&& self::nonNegNumber($o, 'standardPaymentTermDays')
			&& self::nonNegNumber($o, 'latePaymentProceedings');

	}//end esrsG16PaymentPractices()

	/**
	 * gross Scope 1 in tCO2e (>= 0) — IFRS S2 reuses the same GHG datapoint.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2GhgScope1(array $o): bool {
		return self::nonNegNumber($o, 'ghgScope1');

	}//end ifrsS2GhgScope1()

	/**
	 * gross Scope 2 location-based in tCO2e (>= 0).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2GhgScope2(array $o): bool {
		return self::nonNegNumber($o, 'ghgScope2LocationBased');

	}//end ifrsS2GhgScope2()

	/**
	 * gross Scope 3 in tCO2e (>= 0) including the GHG-Protocol categories
	 * included — a non-empty category breakdown.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2GhgScope3(array $o): bool {
		return self::nonNegNumber($o, 'ghgScope3')
			&& self::hasScope3Categories($o);

	}//end ifrsS2GhgScope3()

	/**
	 * financed emissions disclosed only by financial-sector entities — when
	 * financialSectorEntity is true a financedEmissions figure (>= 0) is
	 * present; otherwise the rule is vacuously satisfied.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2FinancedEmissions(array $o): bool {
		return self::conditionalNonNeg($o, 'financialSectorEntity', 'financedEmissions');

	}//end ifrsS2FinancedEmissions()

	/**
	 * assets/activities vulnerable to transition risk — an amount (>= 0) and
	 * a percentage (0-100 %).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2TransitionRiskExposure(array $o): bool {
		return self::amountPercentConsistent($o, 'transitionRiskAmount', 'transitionRiskPct');

	}//end ifrsS2TransitionRiskExposure()

	/**
	 * assets/activities vulnerable to physical risk — amount + percentage.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2PhysicalRiskExposure(array $o): bool {
		return self::amountPercentConsistent($o, 'physicalRiskAmount', 'physicalRiskPct');

	}//end ifrsS2PhysicalRiskExposure()

	/**
	 * assets/activities aligned with climate opportunities — amount + %.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2OpportunitiesExposure(array $o): bool {
		return self::amountPercentConsistent($o, 'climateOpportunityAmount', 'climateOpportunityPct');

	}//end ifrsS2OpportunitiesExposure()

	/**
	 * capital deployed towards climate risks/opportunities — present, >= 0.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2CapitalDeployment(array $o): bool {
		return self::nonNegNumber($o, 'climateCapitalDeployed');

	}//end ifrsS2CapitalDeployment()

	/**
	 * an entity applying a carbon price discloses a positive price/tCO2e
	 * (conditional consistency, same shape as ESRS E1-8).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2InternalCarbonPrice(array $o): bool {
		return self::internalCarbonPriceConsistent($o, 'internalCarbonPricingApplied', 'internalCarbonPrice');

	}//end ifrsS2InternalCarbonPrice()

	/**
	 * % of executive remuneration linked to climate considerations (0-100 %).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2RemunerationLink(array $o): bool {
		return self::percentInRange($o, 'remunerationLinkedToClimatePct');

	}//end ifrsS2RemunerationLink()

	/**
	 * industry-based metrics — a non-empty list of disclosed SASB/industry
	 * metric codes.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2IndustryMetrics(array $o): bool {
		return self::nonEmptyStringList($o, 'industryMetrics');

	}//end ifrsS2IndustryMetrics()

	/**
	 * each climate-related target carries a metric, base period and whether
	 * it is absolute or intensity-based — every climate (E1) target is
	 * well-typed.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ifrsS2ClimateTargets(array $o): bool {
		return self::climateTargetsTyped($o);

	}//end ifrsS2ClimateTargets()

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
		return [
			'EsrsStatement' => [self::seedStatement()],
		];

	}//end seedObjects()

	/**
	 * The one compliant sample statement, assembled section by section in the
	 * order its fields are stored.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedStatement(): array {
		return [
			...self::SEED_REPORTING_CONTEXT,
			...self::seedClimateFigures(),
			...self::seedEnergyFigures(),
			...self::SEED_POLLUTION,
			...self::seedWaterFigures(),
			...self::SEED_NATURE_AND_RESOURCES,
			...self::SEED_WORKFORCE,
			...self::SEED_CONDUCT_AND_CLIMATE_RISK,
			'targets' => self::SEED_TARGETS,
		];

	}//end seedStatement()

	/**
	 * The seeded ESRS E1 climate figures: the GHG scopes, their total and
	 * per-revenue intensity, then the removal and pricing figures.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedClimateFigures(): array {
		$ghg = self::SEED_GHG;
		$ghgTotal = ($ghg['ghgScope1'] + $ghg['ghgScope2LocationBased'] + $ghg['ghgScope3']);

		return [
			...$ghg,
			'ghgTotal' => $ghgTotal,
			'ghgIntensity' => round(($ghgTotal / self::SEED_NET_REVENUE), 8),
			...self::SEED_CLIMATE_INSTRUMENTS,
		];

	}//end seedClimateFigures()

	/**
	 * The seeded energy figures with the renewable share and per-revenue intensity.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedEnergyFigures(): array {
		$energy = self::SEED_ENERGY;

		return [
			...$energy,
			'renewableEnergySharePct' => round((($energy['energyRenewable'] / $energy['energyConsumption']) * 100.0), 6),
			'energyIntensity' => round(($energy['energyConsumption'] / self::SEED_NET_REVENUE), 10),
		];

	}//end seedEnergyFigures()

	/**
	 * The seeded ESRS E3 water figures with the per-revenue intensity.
	 *
	 * @return array<string, mixed>
	 */
	private static function seedWaterFigures(): array {
		$waterConsumption = 25000.0;

		return [
			'waterConsumption' => $waterConsumption,
			'waterConsumptionAtRiskPct' => 15.0,
			'waterIntensity' => round(($waterConsumption / self::SEED_NET_REVENUE), 10),
		];

	}//end seedWaterFigures()

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
			if (self::isWellFormedTarget($t) === false) {
				return false;
			}
		}

		return true;
	}//end targetsWellFormed()

	/**
	 * One target relates to a known ESRS matter, states numeric base year, target
	 * year, value and baseline, and its target year is not before its base year.
	 *
	 * @param mixed $t The target entry.
	 *
	 * @return bool
	 */
	private static function isWellFormedTarget(mixed $t): bool {
		if (is_array($t) === false) {
			return false;
		}

		$matter = strtoupper(trim((string)($t['matter'] ?? '')));
		if (in_array($matter, self::ESRS_MATTERS, true) === false) {
			return false;
		}

		if (self::targetFiguresNumeric($t) === false) {
			return false;
		}

		return (int)$t['targetYear'] >= (int)$t['baseYear'];
	}//end isWellFormedTarget()

	/**
	 * A target states a numeric base year, target year, value and baseline.
	 *
	 * @param array<string, mixed> $t The target entry.
	 *
	 * @return bool
	 */
	private static function targetFiguresNumeric(array $t): bool {
		return is_numeric($t['baseYear'] ?? null) === true
			&& is_numeric($t['targetYear'] ?? null) === true
			&& is_numeric($t['value'] ?? null) === true
			&& is_numeric($t['baseline'] ?? null) === true;
	}//end targetFiguresNumeric()

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
