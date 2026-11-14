<?php

/**
 * Shillinq Group Scope Service
 *
 * Decides per group and fiscal year whether the group files a
 * country-by-country report and whether the minimum tax (Pillar Two) applies,
 * from the consolidated revenue of the years before, and works out the filing
 * deadlines. The outcome is stored as a GroupScopeAssessment.
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/specs/bookkeeping-cbcr-pillar2/spec.md#requirement-req-cbc-001-drempelwaarde-detectie-eur-750m
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Cbcr;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * The scope assessment of design D1.
 *
 * Rules: country-by-country reporting applies to year Y when the consolidated
 * revenue of Y-1 is EUR 750,000,000 or more (Handleiding CbCNL 4.1, section 1);
 * the minimum tax applies to year Y when it is EUR 750,000,000 or more in at
 * least two of the four years immediately before Y (Wet minimumbelasting 2024
 * art. 2.1). Deadlines run from the end of the fiscal year: 12 months for the
 * country-by-country report, 15 months for the GloBE information return (18 in
 * the first minimum tax year) and 17 months for the top-up tax return (20 in
 * the first year).
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/specs/bookkeeping-cbcr-pillar2/spec.md#requirement-req-cbc-001-drempelwaarde-detectie-eur-750m
 */
class GroupScopeService {
	/**
	 * The consolidated revenue threshold in EUR.
	 */
	public const THRESHOLD_EUR = 750000000.0;

	/**
	 * How many years before the assessed one the revenue is kept for.
	 */
	private const YEARS_BACK = 5;

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
	 * Assess one group for one fiscal year and store the outcome.
	 *
	 * Revenue of a year comes from the group's consolidated income statement
	 * for that year; a year without one takes the amount entered for it, here
	 * or on an earlier assessment of the same year. The stored assessment of
	 * the same group and year is updated in place, and the ultimate parent's
	 * thresholdCrossed and thresholdCrossedAt follow the outcome.
	 *
	 * @param string                 $upeId      The GroupEntityRegistry id of the ultimate parent.
	 * @param int                    $fiscalYear The fiscal year, named by the calendar year it ends in.
	 * @param array<int,float>       $entered    Revenue in EUR entered per year, for years without statements.
	 * @param string                 $actor      Who assesses: a user id, or system.
	 * @param DateTimeImmutable|null $now        The moment of assessment; now when null.
	 *
	 * @return array<string,mixed> The stored assessment, with `previous` the assessment of the year before, or null.
	 *
	 * @throws InvalidArgumentException When the ultimate parent is unknown.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.1
	 */
	public function assess(string $upeId, int $fiscalYear, array $entered = [], string $actor = 'system', ?DateTimeImmutable $now = null): array {
		$upe = $this->first(schema: 'GroupEntityRegistry', filters: ['id' => $upeId]);
		if ($upe === null) {
			throw new InvalidArgumentException('Unknown ultimate parent entity: ' . $upeId);
		}

		$group    = $this->groupOf(upe: $upe);
		$existing = $this->first(schema: 'GroupScopeAssessment', filters: ['ultimateParentEntity' => $upeId, 'fiscalYear' => $fiscalYear]);
		$entered  = $entered + $this->enteredOn(assessment: $existing);

		$revenue  = $this->revenueByYear(group: $group, fiscalYear: $fiscalYear, entered: $entered);
		$yearEnd  = $this->yearEnd(group: $group, fiscalYear: $fiscalYear);
		$outcome  = $this->outcome(revenue: $revenue, fiscalYear: $fiscalYear);
		$moment   = ($now ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		$girMonths   = $this->firstYearMonths(outcome: $outcome, fiscalYear: $fiscalYear, usual: 15, first: 18);
		$topUpMonths = $this->firstYearMonths(outcome: $outcome, fiscalYear: $fiscalYear, usual: 17, first: 20);

		$assessment = [
			'ultimateParentEntity' => $upeId,
			'fiscalYear' => $fiscalYear,
			'fiscalYearStart' => $yearEnd->modify('-1 year')->modify('+1 day')->format('Y-m-d'),
			'fiscalYearEnd' => $yearEnd->format('Y-m-d'),
			'revenueByYear' => array_values($revenue),
			'cbcrInScope' => $outcome['cbcrInScope'],
			'pillar2InScope' => $outcome['pillar2InScope'],
			'firstCbcrYear' => $outcome['firstCbcrYear'],
			'firstPillar2Year' => $outcome['firstPillar2Year'],
			'cbcrFilingDeadline' => $this->deadline(yearEnd: $yearEnd, months: 12, applies: $outcome['cbcrInScope']),
			'girFilingDeadline' => $this->deadline(yearEnd: $yearEnd, months: $girMonths, applies: $outcome['pillar2InScope']),
			'bijheffingReturnDeadline' => $this->deadline(yearEnd: $yearEnd, months: $topUpMonths, applies: $outcome['pillar2InScope']),
			'fxRates' => ($existing['fxRates'] ?? null),
			'assessedAt' => $moment,
			'assessedBy' => $actor,
		];

		$stored = $this->save(schema: 'GroupScopeAssessment', object: $assessment, uuid: $this->idOf(row: $existing));
		$this->markUpe(upe: $upe, assessment: $assessment, moment: $moment);

		$stored['previous'] = $this->first(schema: 'GroupScopeAssessment', filters: ['ultimateParentEntity' => $upeId, 'fiscalYear' => ($fiscalYear - 1)]);

		return $stored;
	}//end assess()

	/**
	 * In scope or not for one year, and the first year of the current run.
	 *
	 * A year before the oldest kept revenue counts as below the threshold, so
	 * a first year is never later than the truth, at worst earlier.
	 *
	 * @param array<int,array{year:int,amount:?float,source:string}> $revenue    Revenue per year.
	 * @param int                                                     $fiscalYear The assessed year.
	 *
	 * @return array{cbcrInScope:bool,pillar2InScope:bool,firstCbcrYear:?int,firstPillar2Year:?int}
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.1
	 */
	public function outcome(array $revenue, int $fiscalYear): array {
		$cbcr    = $this->cbcrApplies(revenue: $revenue, year: $fiscalYear);
		$pillar2 = $this->pillar2Applies(revenue: $revenue, year: $fiscalYear);

		$cbcrRule    = fn (int $year): bool => $this->cbcrApplies(revenue: $revenue, year: $year);
		$pillar2Rule = fn (int $year): bool => $this->pillar2Applies(revenue: $revenue, year: $year);

		return [
			'cbcrInScope' => $cbcr,
			'pillar2InScope' => $pillar2,
			'firstCbcrYear' => $this->firstOfRun(applies: $cbcr, year: $fiscalYear, rule: $cbcrRule),
			'firstPillar2Year' => $this->firstOfRun(applies: $pillar2, year: $fiscalYear, rule: $pillar2Rule),
		];
	}//end outcome()

	/**
	 * Country-by-country reporting applies when the year before reached the threshold.
	 *
	 * @param array<int,array{year:int,amount:?float,source:string}> $revenue Revenue per year.
	 * @param int                                                     $year    The year.
	 *
	 * @return bool
	 */
	private function cbcrApplies(array $revenue, int $year): bool {
		return $this->reached(revenue: $revenue, year: ($year - 1));
	}//end cbcrApplies()

	/**
	 * The minimum tax applies when two of the four years before reached the threshold.
	 *
	 * @param array<int,array{year:int,amount:?float,source:string}> $revenue Revenue per year.
	 * @param int                                                     $year    The year.
	 *
	 * @return bool
	 */
	private function pillar2Applies(array $revenue, int $year): bool {
		$reached = 0;
		for ($before = ($year - 4); $before < $year; $before++) {
			if ($this->reached(revenue: $revenue, year: $before) === true) {
				$reached++;
			}
		}

		return $reached >= 2;
	}//end pillar2Applies()

	/**
	 * Whether a year's revenue is known and at least the threshold.
	 *
	 * @param array<int,array{year:int,amount:?float,source:string}> $revenue Revenue per year.
	 * @param int                                                     $year    The year.
	 *
	 * @return bool
	 */
	private function reached(array $revenue, int $year): bool {
		$amount = ($revenue[$year]['amount'] ?? null);

		return $amount !== null && $amount >= self::THRESHOLD_EUR;
	}//end reached()

	/**
	 * The first year of the run of years the rule applies to, ending at $year.
	 *
	 * @param bool     $applies Whether the rule applies to $year.
	 * @param int      $year    The year.
	 * @param callable $rule    The rule for any year.
	 *
	 * @return int|null Null when the rule does not apply to $year.
	 */
	private function firstOfRun(bool $applies, int $year, callable $rule): ?int {
		if ($applies === false) {
			return null;
		}

		$first = $year;
		while ($first > ($year - self::YEARS_BACK) && $rule($first - 1) === true) {
			$first--;
		}

		return $first;
	}//end firstOfRun()

	/**
	 * The months to a minimum tax deadline: longer in the first year.
	 *
	 * @param array{firstPillar2Year:?int} $outcome    The outcome.
	 * @param int                          $fiscalYear The year.
	 * @param int                          $usual      The usual months.
	 * @param int                          $first      The months in the first year.
	 *
	 * @return int
	 */
	private function firstYearMonths(array $outcome, int $fiscalYear, int $usual, int $first): int {
		if ($outcome['firstPillar2Year'] === $fiscalYear) {
			return $first;
		}

		return $usual;
	}//end firstYearMonths()

	/**
	 * The last day of the month $months after the year end, or null when it does not apply.
	 *
	 * @param DateTimeImmutable $yearEnd The fiscal year end.
	 * @param int               $months  Months after it.
	 * @param bool              $applies Whether the deadline applies.
	 *
	 * @return string|null
	 */
	private function deadline(DateTimeImmutable $yearEnd, int $months, bool $applies): ?string {
		if ($applies === false) {
			return null;
		}

		return $yearEnd->modify('first day of this month')->modify('+' . $months . ' months')->modify('last day of this month')->format('Y-m-d');
	}//end deadline()

	/**
	 * The revenue of the YEARS_BACK years before and of the year itself.
	 *
	 * @param array<string,mixed>|null $group      The ConsolidationGroup, or null.
	 * @param int                      $fiscalYear The assessed year.
	 * @param array<int,float>         $entered    Entered amounts per year.
	 *
	 * @return array<int,array{year:int,amount:?float,source:string}> Keyed by year, oldest first.
	 */
	private function revenueByYear(?array $group, int $fiscalYear, array $entered): array {
		$statements = [];
		$groupId    = $this->idOf(row: $group);
		if ($groupId !== null) {
			$statements = $this->all(schema: 'ConsolidatedIncomeStatement', filters: ['consolidationGroupId' => $groupId]);
		}

		$revenue = [];
		for ($year = ($fiscalYear - self::YEARS_BACK); $year <= $fiscalYear; $year++) {
			$revenue[$year] = $this->yearRevenue(
				year: $year,
				fromStatement: $this->statementRevenue(statements: $statements, group: $group, year: $year),
				entered: $entered
			);
		}

		return $revenue;
	}//end revenueByYear()

	/**
	 * One year's revenue: the statement's, else the entered amount, else unknown.
	 *
	 * @param int              $year          The year.
	 * @param float|null       $fromStatement The statement's revenue, or null.
	 * @param array<int,float> $entered       Entered amounts per year.
	 *
	 * @return array{year:int,amount:?float,source:string}
	 */
	private function yearRevenue(int $year, ?float $fromStatement, array $entered): array {
		if ($fromStatement !== null) {
			return ['year' => $year, 'amount' => $fromStatement, 'source' => 'consolidated-statements'];
		}

		if (isset($entered[$year]) === true && is_numeric($entered[$year]) === true) {
			return ['year' => $year, 'amount' => (float)$entered[$year], 'source' => 'entered'];
		}

		return ['year' => $year, 'amount' => null, 'source' => 'none'];
	}//end yearRevenue()

	/**
	 * The total revenue of the latest statement dated inside the fiscal year.
	 *
	 * @param array<int,array<string,mixed>> $statements The group's statements.
	 * @param array<string,mixed>|null       $group      The group.
	 * @param int                            $year       The fiscal year.
	 *
	 * @return float|null
	 */
	private function statementRevenue(array $statements, ?array $group, int $year): ?float {
		$end   = $this->yearEnd(group: $group, fiscalYear: $year)->format('Y-m-d');
		$start = $this->yearEnd(group: $group, fiscalYear: ($year - 1))->modify('+1 day')->format('Y-m-d');

		$latest = null;
		foreach ($statements as $statement) {
			$date = substr((string)($statement['reportDate'] ?? ''), 0, 10);
			if ($date < $start || $date > $end || is_numeric($statement['totalRevenue'] ?? null) === false) {
				continue;
			}

			if ($latest === null || $date > $latest['date']) {
				$latest = ['date' => $date, 'amount' => (float)$statement['totalRevenue']];
			}
		}

		return $latest['amount'] ?? null;
	}//end statementRevenue()

	/**
	 * The last day of a fiscal year: the group's year-end day and month, else 31 December.
	 *
	 * @param array<string,mixed>|null $group      The group.
	 * @param int                      $fiscalYear The year it ends in.
	 *
	 * @return DateTimeImmutable
	 */
	private function yearEnd(?array $group, int $fiscalYear): DateTimeImmutable {
		$month = 12;
		$day   = 31;
		if (preg_match('/^\d{4}-(\d{2})-(\d{2})/', (string)($group['fiscalYearEnd'] ?? ''), $match) === 1) {
			$month = (int)$match[1];
			$day   = (int)$match[2];
		}

		$first = new DateTimeImmutable(sprintf('%04d-%02d-01', $fiscalYear, $month), new DateTimeZone('UTC'));

		return $first->setDate($fiscalYear, $month, min($day, (int)$first->format('t')));
	}//end yearEnd()

	/**
	 * The ConsolidationGroup whose parent administration is the UPE's.
	 *
	 * @param array<string,mixed> $upe The ultimate parent.
	 *
	 * @return array<string,mixed>|null
	 */
	private function groupOf(array $upe): ?array {
		$administrationId = (string)($upe['administrationId'] ?? '');
		if ($administrationId === '') {
			return null;
		}

		return $this->first(schema: 'ConsolidationGroup', filters: ['parentAdministrationId' => $administrationId]);
	}//end groupOf()

	/**
	 * The amounts entered on an earlier assessment, per year.
	 *
	 * @param array<string,mixed>|null $assessment The assessment.
	 *
	 * @return array<int,float>
	 */
	private function enteredOn(?array $assessment): array {
		$entered = [];
		foreach ((array)($assessment['revenueByYear'] ?? []) as $row) {
			if (($row['source'] ?? '') === 'entered' && is_numeric($row['amount'] ?? null) === true) {
				$entered[(int)$row['year']] = (float)$row['amount'];
			}
		}

		return $entered;
	}//end enteredOn()

	/**
	 * Write the latest outcome onto the ultimate parent.
	 *
	 * @param array<string,mixed> $upe        The ultimate parent.
	 * @param array<string,mixed> $assessment The assessment.
	 * @param string              $moment     When it was assessed.
	 *
	 * @return void
	 */
	private function markUpe(array $upe, array $assessment, string $moment): void {
		$crossed = ($assessment['cbcrInScope'] === true || $assessment['pillar2InScope'] === true);
		if (($upe['thresholdCrossed'] ?? null) === $crossed) {
			return;
		}

		$upe['thresholdCrossed']   = $crossed;
		$upe['thresholdCrossedAt'] = $moment;
		unset($upe['@self']);
		$this->save(schema: 'GroupEntityRegistry', object: $upe, uuid: $this->idOf(row: $upe));
	}//end markUpe()

	/**
	 * Every row of a schema matching the filters.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all(string $schema, array $filters): array {
		$found = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		return array_values(array_map(static fn ($row): array => self::rowOf(row: $row), $found));
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
	 * @return array<string,mixed> The stored object.
	 */
	private function save(string $schema, array $object, ?string $uuid): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject(object: $object, uuid: $uuid);

		return self::rowOf(row: $saved) + $object;
	}//end save()

	/**
	 * A stored row as an array.
	 *
	 * @param mixed $row An entity or an array.
	 *
	 * @return array<string,mixed>
	 */
	private static function rowOf(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return (array)$row->getObject();
		}

		return (array)$row;
	}//end rowOf()

	/**
	 * A row's id.
	 *
	 * @param array<string,mixed>|null $row The row.
	 *
	 * @return string|null
	 */
	private function idOf(?array $row): ?string {
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
