<?php

/**
 * Shillinq Pillar Two Safe Harbour Service
 *
 * Runs the three transitional CbCR safe harbour tests per jurisdiction of a
 * minimum tax year on the jurisdiction summaries, writes one safe harbour
 * record per test, prepares a draft jurisdiction computation where no test
 * passes, and applies the Dutch QDMTT to an NL computation whose effective
 * tax rate is below 15% (design D4, REQ-CBC-006, REQ-CBC-009).
 *
 * Sources:
 *  - The transitional CbCR safe harbour and its window (fiscal years
 *    beginning on or before 31 December 2026 and ending before 1 July 2028),
 *    the de minimis thresholds (revenue below EUR 10M and profit below
 *    EUR 1M) and the simplified ETR steps (15% for years beginning in 2023
 *    and 2024, 16% in 2025, 17% in 2026): OECD, Safe Harbours and Penalty
 *    Relief, December 2022, section 2; Wet minimumbelasting 2024, art. 11.4.
 *  - The substance-based income exclusion rates during the transition:
 *    GloBE Model Rules art. 9.2.1 (payroll) and 9.2.2 (tangible assets),
 *    taken over in Wet minimumbelasting 2024, art. 11.2: for fiscal years
 *    beginning in 2023 9.8% and 7.8%, falling 0.2 point a year to 9.0% and
 *    7.0% in 2027, then 8.2/6.6, 7.4/6.2, 6.6/5.8, 5.8/5.4 and 5.4/5.0 in
 *    2028 to 2032, and 5% each from 2033 (art. 5.3.3 and 5.3.4).
 *    The archived scenario's 9.6% and 7.6% for 2026 are the 2024 rates.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Cbcr;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;

/**
 * Safe harbour tests, draft computations and the Dutch QDMTT.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.4
 */
class Pillar2SafeHarbourService {
	/**
	 * The minimum rate.
	 *
	 * @var float
	 */
	public const MINIMUM_RATE = 0.15;

	/**
	 * De minimis: revenue below this, in EUR.
	 *
	 * @var float
	 */
	public const DE_MINIMIS_REVENUE = 10000000.0;

	/**
	 * De minimis: profit before tax below this, in EUR.
	 *
	 * @var float
	 */
	public const DE_MINIMIS_PROFIT = 1000000.0;

	/**
	 * The simplified ETR threshold by the calendar year the fiscal year begins in.
	 *
	 * @var array<int,float>
	 */
	public const SIMPLIFIED_ETR = [
		2023 => 0.15,
		2024 => 0.15,
		2025 => 0.16,
		2026 => 0.17,
	];

	/**
	 * Carve-out rates (payroll, tangible assets) by the calendar year the fiscal year begins in.
	 *
	 * @var array<int,array{0:float,1:float}>
	 */
	public const CARVE_OUT_RATES = [
		2023 => [0.098, 0.078],
		2024 => [0.096, 0.076],
		2025 => [0.094, 0.074],
		2026 => [0.092, 0.072],
		2027 => [0.090, 0.070],
		2028 => [0.082, 0.066],
		2029 => [0.074, 0.062],
		2030 => [0.066, 0.058],
		2031 => [0.058, 0.054],
		2032 => [0.054, 0.050],
	];

	/**
	 * The three tests, in the order they are written.
	 *
	 * @var array<int,string>
	 */
	public const TESTS = ['de-minimis', 'simplified-etr', 'routine-profits'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig             $appConfig     Names the register.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Run the safe harbour tests for every jurisdiction of a minimum tax year.
	 *
	 * Inside the transitional window each jurisdiction gets one safe harbour
	 * record per test; outside it none. A jurisdiction that passes no test
	 * gets a draft computation, prefilled from its summary; a computation past
	 * draft is left alone.
	 *
	 * @param string $upeId      The GroupEntityRegistry id of the ultimate parent.
	 * @param int    $fiscalYear The fiscal year.
	 *
	 * @return array<string,array{passed:array<int,string>,computation:string|null}> Per jurisdiction.
	 *
	 * @throws InvalidArgumentException When the year has no scope assessment or the minimum tax does not apply.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.4
	 */
	public function test(string $upeId, int $fiscalYear): array {
		$assessment = $this->first(schema: 'GroupScopeAssessment', filters: ['ultimateParentEntity' => $upeId, 'fiscalYear' => $fiscalYear]);
		if ($assessment === null || ($assessment['pillar2InScope'] ?? false) !== true) {
			throw new InvalidArgumentException(
				sprintf('The minimum tax does not apply to %d for this group; assess its scope first.', $fiscalYear)
			);
		}

		$start  = substr((string)($assessment['fiscalYearStart'] ?? sprintf('%04d-01-01', $fiscalYear)), 0, 10);
		$end    = substr((string)($assessment['fiscalYearEnd'] ?? sprintf('%04d-12-31', $fiscalYear)), 0, 10);
		$window = self::inTransitionalWindow(start: $start, end: $end);
		$period = 'FY' . $fiscalYear;
		$rates  = self::carveOutRates(startYear: (int)substr($start, 0, 4));

		$outcome = [];
		foreach ($this->all(schema: 'CbcrJurisdictionSummary', filters: ['ultimateParentEntity' => $upeId, 'period' => $period]) as $summary) {
			$code   = (string)($summary['jurisdiction'] ?? '');
			$passed = [];
			if ($window === true) {
				foreach (self::evaluate(summary: $summary, startYear: (int)substr($start, 0, 4), rates: $rates) as $test => $result) {
					$this->writeTest(upeId: $upeId, period: $period, jurisdiction: $code, test: $test, result: $result);
					if ($result['pass'] === true) {
						$passed[] = $test;
					}
				}
			}

			$computation = null;
			if ($passed === []) {
				$computation = $this->draftComputation(upeId: $upeId, period: $period, summary: $summary, rates: $rates);
			}

			$outcome[$code] = ['passed' => $passed, 'computation' => $computation];
		}//end foreach

		ksort($outcome);
		return $outcome;
	}//end test()

	/**
	 * Apply the Dutch QDMTT to an NL computation whose effective tax rate is below the minimum.
	 *
	 * Sets `qdmttApplicable` and `qdmttAmount` on the computation and creates
	 * or updates the QDMTT return of the Dutch filing entity, with the amount
	 * payable and the top-up tax return deadline of the scope assessment as
	 * filing and payment date. A jurisdiction other than NL, or one under a
	 * safe harbour, is left as it is.
	 *
	 * @param string $computationId The Pillar2JurisdictionComputation id.
	 *
	 * @return array<string,mixed>|null The QDMTT return, or null when no QDMTT applies.
	 *
	 * @throws InvalidArgumentException When the computation is unknown.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.4
	 */
	public function applyQdmtt(string $computationId): ?array {
		$computation = $this->one(schema: 'Pillar2JurisdictionComputation', id: $computationId);
		if ($computation === null) {
			throw new InvalidArgumentException('Unknown jurisdiction computation: ' . $computationId);
		}

		$upeId  = (string)($computation['ultimateParentEntity'] ?? '');
		$period = (string)($computation['period'] ?? '');
		if (strtoupper((string)($computation['jurisdiction'] ?? '')) !== 'NL'
			|| $this->underSafeHarbour(upeId: $upeId, period: $period, jurisdiction: 'NL') === true
		) {
			return null;
		}

		$figures = self::compute(computation: $computation);
		if ($figures['etr'] >= self::MINIMUM_RATE) {
			return null;
		}

		$computation['qdmttApplicable'] = true;
		$computation['qdmttAmount']     = $figures['topUpTax'];
		$this->save(schema: 'Pillar2JurisdictionComputation', object: $computation, uuid: $computationId);

		$fiscalYear = (int)substr($period, 2);
		$assessment = $this->first(schema: 'GroupScopeAssessment', filters: ['ultimateParentEntity' => $upeId, 'fiscalYear' => $fiscalYear]);
		$deadline   = ($assessment['bijheffingReturnDeadline'] ?? null);
		$entity     = $this->dutchFilingEntity(upeId: $upeId);
		$existing   = $this->first(schema: 'QdmttReturn', filters: ['entity' => $entity, 'period' => $period]);

		$return = [
			'period' => $period,
			'entity' => $entity,
			'taxableGlobeIncome' => $figures['globeIncome'],
			'qualifyingDomesticEtr' => $figures['etr'],
			'qdmttPayable' => $figures['topUpTax'],
			'filingDueDate' => $deadline,
			'paymentDueDate' => $deadline,
			'state' => (string)($existing['state'] ?? 'draft'),
		];

		return $this->save(schema: 'QdmttReturn', object: $return, uuid: self::idOf(row: $existing));
	}//end applyQdmtt()

	/**
	 * The jurisdiction-level computation: GloBE income, ETR, SBIE and top-up tax.
	 *
	 * The same arithmetic the schema's declared calculations perform, so the
	 * QDMTT amount does not wait for them to be materialised.
	 *
	 * @param array<string,mixed> $computation The computation.
	 *
	 * @return array{globeIncome:float,etr:float,sbie:float,topUpTax:float}
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.4
	 */
	public static function compute(array $computation): array {
		$adjustments = 0.0;
		foreach ((array)($computation['globeIncomeAdjustments'] ?? []) as $adjustment) {
			$adjustments += (float)($adjustment['amount'] ?? 0);
		}

		$income = (float)($computation['commercialProfitBeforeTax'] ?? 0) + $adjustments;
		$etr    = 0.0;
		if ($income > 0.0) {
			$etr = min(max(0.0, (float)($computation['adjustedCoveredTaxes'] ?? 0) / $income), 1.0);
		}

		$sbie = ((float)($computation['payroll'] ?? 0) * (float)($computation['carveOutRatePayroll'] ?? 0))
			+ ((float)($computation['tangibleAssetsNbv'] ?? 0) * (float)($computation['carveOutRateTangible'] ?? 0));
		$rate = max(0.0, (float)($computation['minimumRate'] ?? self::MINIMUM_RATE) - $etr);

		return [
			'globeIncome' => round($income, 2),
			'etr' => round($etr, 6),
			'sbie' => round($sbie, 2),
			'topUpTax' => round($rate * max(0.0, $income - $sbie), 2),
		];
	}//end compute()

	/**
	 * The carve-out rates for a fiscal year beginning in the year.
	 *
	 * @param int $startYear The calendar year the fiscal year begins in.
	 *
	 * @return array{payroll:float,tangible:float}
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.4
	 */
	public static function carveOutRates(int $startYear): array {
		if ($startYear < 2023) {
			$startYear = 2023;
		}

		[$payroll, $tangible] = (self::CARVE_OUT_RATES[$startYear] ?? [0.05, 0.05]);

		return ['payroll' => $payroll, 'tangible' => $tangible];
	}//end carveOutRates()

	/**
	 * Whether the transitional safe harbour covers a fiscal year.
	 *
	 * @param string $start First day, Y-m-d.
	 * @param string $end   Last day, Y-m-d.
	 *
	 * @return bool
	 */
	private static function inTransitionalWindow(string $start, string $end): bool {
		return ($start <= '2026-12-31' && $end < '2028-07-01');
	}//end inTransitionalWindow()

	/**
	 * The three tests on one summary.
	 *
	 * @param array<string,mixed>                $summary   The jurisdiction summary.
	 * @param int                                $startYear The calendar year the fiscal year begins in.
	 * @param array{payroll:float,tangible:float} $rates     The carve-out rates.
	 *
	 * @return array<string,array{pass:bool,inputs:array<string,mixed>}>
	 */
	private static function evaluate(array $summary, int $startYear, array $rates): array {
		$revenue = (float)($summary['unrelatedPartyRevenue'] ?? 0) + (float)($summary['relatedPartyRevenue'] ?? 0);
		$profit  = (float)($summary['profitBeforeTax'] ?? 0);
		$taxes   = (float)($summary['incomeTaxAccrued'] ?? 0) + (float)($summary['deferredTaxExpense'] ?? 0);
		$sbie    = round(((float)($summary['eligiblePayroll'] ?? 0) * $rates['payroll'])
			+ ((float)($summary['tangibleAssetsOtherThanCash'] ?? 0) * $rates['tangible']), 2);

		$threshold = (self::SIMPLIFIED_ETR[$startYear] ?? self::SIMPLIFIED_ETR[2026]);
		$etr       = null;
		if ($profit > 0.0) {
			$etr = round($taxes / $profit, 6);
		}

		return [
			'de-minimis' => [
				'pass' => ($revenue < self::DE_MINIMIS_REVENUE && $profit < self::DE_MINIMIS_PROFIT),
				'inputs' => [
					'totalRevenue' => $revenue,
					'profitBeforeTax' => $profit,
					'revenueThreshold' => self::DE_MINIMIS_REVENUE,
					'profitThreshold' => self::DE_MINIMIS_PROFIT,
				],
			],
			'simplified-etr' => [
				'pass' => ($etr !== null && $etr >= $threshold),
				'inputs' => ['coveredTaxes' => $taxes, 'profitBeforeTax' => $profit, 'simplifiedEtr' => $etr, 'threshold' => $threshold],
			],
			'routine-profits' => [
				'pass' => ($profit <= $sbie),
				'inputs' => [
					'profitBeforeTax' => $profit,
					'substanceBasedIncomeExclusion' => $sbie,
					'carveOutRatePayroll' => $rates['payroll'],
					'carveOutRateTangible' => $rates['tangible'],
				],
			],
		];
	}//end evaluate()

	/**
	 * Write one safe harbour record, in place when the test was run before.
	 *
	 * @param string                                       $upeId        The ultimate parent.
	 * @param string                                       $period       FY<year>.
	 * @param string                                       $jurisdiction The jurisdiction.
	 * @param string                                       $test         The test.
	 * @param array{pass:bool,inputs:array<string,mixed>} $result       The outcome.
	 *
	 * @return void
	 */
	private function writeTest(string $upeId, string $period, string $jurisdiction, string $test, array $result): void {
		$existing = $this->first(
			schema: 'Pillar2SafeHarbour',
			filters: ['ultimateParentEntity' => $upeId, 'period' => $period, 'jurisdiction' => $jurisdiction, 'testApplied' => $test]
		);
		$testResult = 'fail';
		if ($result['pass'] === true) {
			$testResult = 'pass';
		}

		$this->save(
			schema: 'Pillar2SafeHarbour',
			object: [
				'ultimateParentEntity' => $upeId,
				'period' => $period,
				'jurisdiction' => $jurisdiction,
				'testApplied' => $test,
				'testResult' => $testResult,
				'dataSource' => 'qualified-cbcr',
				'supportingCalculations' => $result['inputs'],
			],
			uuid: self::idOf(row: $existing)
		);
	}//end writeTest()

	/**
	 * Create or refresh the draft computation of a jurisdiction that passes no test.
	 *
	 * @param string                              $upeId   The ultimate parent.
	 * @param string                              $period  FY<year>.
	 * @param array<string,mixed>                 $summary The jurisdiction summary.
	 * @param array{payroll:float,tangible:float} $rates   The carve-out rates.
	 *
	 * @return string The computation id.
	 */
	private function draftComputation(string $upeId, string $period, array $summary, array $rates): string {
		$code     = (string)($summary['jurisdiction'] ?? '');
		$existing = $this->first(
			schema: 'Pillar2JurisdictionComputation',
			filters: ['ultimateParentEntity' => $upeId, 'period' => $period, 'jurisdiction' => $code]
		);
		if ($existing !== null && ($existing['state'] ?? 'draft') !== 'draft') {
			return (string)self::idOf(row: $existing);
		}

		$taxes = round((float)($summary['incomeTaxAccrued'] ?? 0) + (float)($summary['deferredTaxExpense'] ?? 0), 2);
		$draft = [
			'ultimateParentEntity' => $upeId,
			'period' => $period,
			'jurisdiction' => $code,
			'commercialProfitBeforeTax' => (float)($summary['profitBeforeTax'] ?? 0),
			'globeIncomeAdjustments' => ($existing['globeIncomeAdjustments'] ?? []),
			'coveredTaxAdjustments' => [
				['type' => 'current-and-deferred', 'amount' => $taxes, 'description' => 'Current and deferred income tax from the country-by-country figures'],
			],
			'adjustedCoveredTaxes' => $taxes,
			'minimumRate' => self::MINIMUM_RATE,
			'payroll' => (float)($summary['eligiblePayroll'] ?? 0),
			'tangibleAssetsNbv' => (float)($summary['tangibleAssetsOtherThanCash'] ?? 0),
			'carveOutRatePayroll' => $rates['payroll'],
			'carveOutRateTangible' => $rates['tangible'],
			'safeHarbourApplied' => false,
			'state' => 'draft',
		];

		return (string)self::idOf(row: $this->save(schema: 'Pillar2JurisdictionComputation', object: $draft, uuid: self::idOf(row: $existing)));
	}//end draftComputation()

	/**
	 * Whether a jurisdiction passed a safe harbour test for the period.
	 *
	 * @param string $upeId        The ultimate parent.
	 * @param string $period       FY<year>.
	 * @param string $jurisdiction The jurisdiction.
	 *
	 * @return bool
	 */
	private function underSafeHarbour(string $upeId, string $period, string $jurisdiction): bool {
		$filters = ['ultimateParentEntity' => $upeId, 'period' => $period, 'jurisdiction' => $jurisdiction, 'testResult' => 'pass'];

		return ($this->first(schema: 'Pillar2SafeHarbour', filters: $filters) !== null);
	}//end underSafeHarbour()

	/**
	 * The Dutch entity that files the QDMTT return: the ultimate parent when
	 * it is Dutch, else the first Dutch entity of the group.
	 *
	 * @param string $upeId The ultimate parent.
	 *
	 * @return string
	 */
	private function dutchFilingEntity(string $upeId): string {
		$dutch = [];
		foreach ($this->all(schema: 'GroupEntityRegistry', filters: []) as $entity) {
			$id = (string)self::idOf(row: $entity);
			$nl = (strtoupper((string)($entity['taxResidency'] ?? ($entity['jurisdiction'] ?? ''))) === 'NL');
			if ($nl === true && ($id === $upeId || (string)($entity['ultimateParentEntity'] ?? '') === $upeId)) {
				$dutch[] = $id;
			}
		}

		if (in_array($upeId, $dutch, true) === true || $dutch === []) {
			return $upeId;
		}

		sort($dutch);
		return $dutch[0];
	}//end dutchFilingEntity()

	/**
	 * One record by id, null when absent.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function one(string $schema, string $id): ?array {
		return ObjectIdentifier::findOne(scoped: $this->objectService->setRegister($this->register())->setSchema($schema), id: $id);
	}//end one()

	/**
	 * Every row of a schema matching the filters.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all(string $schema, array $filters): array {
		$records = [];
		foreach ($this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters, 'limit' => 10000]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}//end all()

	/**
	 * The first row of a schema matching the filters, or null.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function first(string $schema, array $filters): ?array {
		return ($this->all(schema: $schema, filters: $filters)[0] ?? null);
	}//end first()

	/**
	 * Save an object, in place when it has an id.
	 *
	 * @param string              $schema The schema.
	 * @param array<string,mixed> $object The object.
	 * @param string|null         $uuid   Its id, or null for a new one.
	 *
	 * @return array<string,mixed>
	 */
	private function save(string $schema, array $object, ?string $uuid): array {
		unset($object['@self']);
		if ($uuid !== null) {
			$object['id'] = $uuid;
		}

		$saved = $this->objectService->setRegister($this->register())->setSchema($schema)->saveObject(object: $object, uuid: $uuid);

		return (ObjectIdentifier::recordWithId(candidate: $saved) ?? []) + $object;
	}//end save()

	/**
	 * The id of a row, or null.
	 *
	 * @param array<string,mixed>|null $row The row.
	 *
	 * @return string|null
	 */
	private static function idOf(?array $row): ?string {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ($row['uuid'] ?? '')));
		if ($id === '') {
			return null;
		}

		return $id;
	}//end idOf()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
