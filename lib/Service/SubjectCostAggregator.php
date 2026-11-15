<?php

/**
 * Subject Cost Aggregator
 *
 * Aggregates the hours booked against one domain object — a procest case
 * today, any case/matter object tomorrow — into an employer cost, per hydra
 * ADR-081. Shillinq does this because Shillinq owns the ledger; the domain app
 * classifies and displays, it does not sum money.
 *
 * The hrmq wage surface is intentionally abstracted, following the same
 * approach GrotendeelsCriteriumService already takes: the caller supplies an
 * already-resolved cost rate per person. The live adapter over
 * `POST /api/employees/cost-rate` (ConductionNL/hrmq#78) wires in here without
 * touching this policy.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Sum an hour set for one subject, and cost it when rates are available.
 *
 * WHAT THIS REFUSES TO DO, AND WHY IT MATTERS MORE THAN WHAT IT DOES.
 *
 * A cost that silently omits someone's hours is worse than no cost at all: it
 * is plausible, it is lower than the truth, and nothing about it looks wrong.
 * So this aggregator never returns a total it could not fully compute. If any
 * person with hours has no resolvable rate, `complete` is false, `costCents`
 * is null, and the unpriced people are named. Callers render "hours known,
 * cost unavailable" rather than a number that reads as authoritative.
 *
 * Hours are always returned, priced or not — hours are effort, not currency,
 * and a domain app may show them (ADR-081).
 *
 * @spec openspec/specs/subject-cost-aggregation/spec.md
 */
class SubjectCostAggregator {
	/**
	 * Wire collaborators.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Aggregate an hour set into hours-per-person and, where possible, a cost.
	 *
	 * `$rates` maps personId => employer cost in CENTS per hour, as resolved
	 * from hrmq. Cents, not euros: money is integer here for the same reason it
	 * is integer in hrmq — a float total over many rows drifts, and a ledger
	 * that drifts is not a ledger.
	 *
	 * @param array<int, array<string, mixed>> $hourRows UrenRegistratie rows for one subject.
	 * @param array<string, int> $rates personId => cost cents per hour.
	 *
	 * @return array{
	 *     hours: float, costCents: int|null, complete: bool, currency: string,
	 *     perPerson: array<int, array{personId: string, hours: float, centsPerHour: int|null, costCents: int|null}>,
	 *     unpricedPersonIds: array<int, string>
	 * }
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-cost-is-published-only-when-every-hour-in-it-could-be-priced
	 */
	public function aggregate(array $hourRows, array $rates): array {
		$hoursByPerson = $this->hoursByPerson(hourRows: $hourRows);
		$summary = self::summary(
			hoursByPerson: $hoursByPerson,
			perPerson: self::perPersonLines(hoursByPerson: $hoursByPerson, rates: $rates)
		);

		if ($summary['unpricedPersonIds'] !== []) {
			$this->logger->info(
				'SubjectCostAggregator: cost withheld — some hours have no resolvable rate',
				['unpricedPersonIds' => $summary['unpricedPersonIds']]
			);
		}

		return $summary;
	}//end aggregate()

	/**
	 * The usable hours of the rows summed per person, in first-seen order.
	 *
	 * @param array<int,mixed> $hourRows The hour rows.
	 *
	 * @return array<string,float>
	 */
	private function hoursByPerson(array $hourRows): array {
		$hoursByPerson = [];
		foreach ($hourRows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$hours = $this->toFloat(value: ($row['hours'] ?? null));
			if ($hours === null) {
				// A row with no usable hours contributes nothing. Skipping is
				// right; treating it as 0 silently would be too, but this way
				// the row never reaches the per-person breakdown and cannot be
				// mistaken for someone who worked zero hours.
				continue;
			}

			$personId = self::personKey(row: $row);
			$hoursByPerson[$personId] = (($hoursByPerson[$personId] ?? 0.0) + $hours);
		}//end foreach

		return $hoursByPerson;
	}//end hoursByPerson()

	/**
	 * The person a row's hours belong to. Hours nobody owns cannot be priced;
	 * they still count toward effort, under a reserved key, so the total hours
	 * stay honest.
	 *
	 * @param array<string,mixed> $row The hour row.
	 *
	 * @return string
	 */
	private static function personKey(array $row): string {
		$personId = trim((string)($row['personId'] ?? ''));
		if ($personId === '') {
			return '(unattributed)';
		}

		return $personId;
	}//end personKey()

	/**
	 * One breakdown line per person: hours, rate and cost.
	 *
	 * @param array<string,float> $hoursByPerson The hours per person.
	 * @param array<string,mixed> $rates         Cents per hour per person.
	 *
	 * @return array<int,array{personId:string,hours:float,centsPerHour:?int,costCents:?int}>
	 */
	private static function perPersonLines(array $hoursByPerson, array $rates): array {
		$perPerson = [];
		foreach ($hoursByPerson as $personId => $hours) {
			$perPerson[] = [
				'personId' => (string)$personId,
				...self::pricedHours(hours: $hours, centsPerHour: self::usableRate(rate: ($rates[$personId] ?? null))),
			];
		}

		return $perPerson;
	}//end perPersonLines()

	/**
	 * A rate that can price hours: a non-negative integer of cents, else null.
	 *
	 * @param mixed $rate The configured rate.
	 *
	 * @return int|null
	 */
	private static function usableRate(mixed $rate): ?int {
		if (is_int($rate) === false || $rate < 0) {
			return null;
		}

		return $rate;
	}//end usableRate()

	/**
	 * The hours, the rate and their cost, rounded once at the person level
	 * rather than accumulating a float and rounding at the end — the latter
	 * lets sub-cent error from every row survive into the total. No rate, no
	 * cost.
	 *
	 * @param float    $hours        The person's hours.
	 * @param int|null $centsPerHour The person's rate.
	 *
	 * @return array{hours:float,centsPerHour:?int,costCents:?int}
	 */
	private static function pricedHours(float $hours, ?int $centsPerHour): array {
		$costCents = null;
		if ($centsPerHour !== null) {
			$costCents = (int)round($hours * $centsPerHour);
		}

		return [
			'hours' => round(num: $hours, precision: 2),
			'centsPerHour' => $centsPerHour,
			'costCents' => $costCents,
		];
	}//end pricedHours()

	/**
	 * The aggregate: total hours, the cost when every person could be priced,
	 * the breakdown and the people without a rate.
	 *
	 * A cost is published only when every person with hours could be priced.
	 * See the class docblock: a partial total is plausible, always too low,
	 * and indistinguishable from a correct one.
	 *
	 * @param array<string,float>             $hoursByPerson The hours per person.
	 * @param array<int,array<string,mixed>>  $perPerson     The breakdown lines.
	 *
	 * @return array<string,mixed>
	 */
	private static function summary(array $hoursByPerson, array $perPerson): array {
		[$unpriced, $totalCents] = self::unpricedAndPricedTotal(perPerson: $perPerson);
		$complete = ($unpriced === [] && $perPerson !== []);

		return [
			'hours' => round(num: self::totalHours(hoursByPerson: $hoursByPerson), precision: 2),
			'costCents' => self::publishedCost(complete: $complete, totalCents: $totalCents),
			'complete' => $complete,
			'currency' => 'EUR',
			'perPerson' => $perPerson,
			'unpricedPersonIds' => $unpriced,
		];
	}//end summary()

	/**
	 * The people without a rate, in breakdown order, and the summed cost of
	 * the people with one.
	 *
	 * @param array<int,array<string,mixed>> $perPerson The breakdown lines.
	 *
	 * @return array{0:array<int,string>,1:int}
	 */
	private static function unpricedAndPricedTotal(array $perPerson): array {
		$unpriced = [];
		$totalCents = 0;
		foreach ($perPerson as $line) {
			if ($line['costCents'] === null) {
				$unpriced[] = $line['personId'];
				continue;
			}

			$totalCents += $line['costCents'];
		}

		return [$unpriced, $totalCents];
	}//end unpricedAndPricedTotal()

	/**
	 * The total hours, summed in person order.
	 *
	 * @param array<string,float> $hoursByPerson The hours per person.
	 *
	 * @return float
	 */
	private static function totalHours(array $hoursByPerson): float {
		$totalHours = 0.0;
		foreach ($hoursByPerson as $hours) {
			$totalHours += $hours;
		}

		return $totalHours;
	}//end totalHours()

	/**
	 * The total cost when complete, else null.
	 *
	 * @param bool $complete   Whether every person was priced.
	 * @param int  $totalCents The summed person costs.
	 *
	 * @return int|null
	 */
	private static function publishedCost(bool $complete, int $totalCents): ?int {
		if ($complete === true) {
			return $totalCents;
		}

		return null;
	}//end publishedCost()

	/**
	 * Coerce an hours value to float, or null when it is not a number.
	 *
	 * OpenRegister returns numeric properties as int, float or numeric string
	 * depending on the storage path, so this accepts all three and rejects
	 * everything else rather than letting PHP cast "" or "n/a" to 0.0.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return float|null The hours, or null when unusable.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-unusable-hours-are-rejected-rather-than-coerced
	 */
	private function toFloat(mixed $value): ?float {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric(trim($value)) === true) {
			return (float)trim($value);
		}

		return null;
	}//end toFloat()
}//end class
