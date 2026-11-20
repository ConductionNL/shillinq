<?php

/**
 * Activity Cost Price Calculation
 *
 * The integral cost price of a commercial activity for a year, from the books
 * (public-sector-task-field-allocation, REQ-WMO-013). The direct costs are the
 * year's posted lines on the activity's cost centre and cost object, sorted by
 * their account's RGS code into payroll (WPer), depreciation (WAfs) and other
 * costs; revenue lines (WOmz) give the turnover. The overhead pool is the
 * year's expenses on BBV task field 0.4, spread by the activity's overhead
 * key. With the cost of capital and the profit markup,
 * `IntegralCostPriceCalculator::compose` writes the record: one provisional
 * `IntegralCostPrice` per activity and year, recalculated in place until it is
 * final.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DomainException;
use OCA\Shillinq\Kernel\Contracts\ActivityCostPriceCalculationInterface;
use OCA\Shillinq\Service\IntegralCostPriceCalculator;
use OCA\Shillinq\Service\LedgerActuals;

/**
 * Calculates and stores the integral cost price of a commercial activity.
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
 */
class ActivityCostPriceCalculation implements ActivityCostPriceCalculationInterface {

	/**
	 * The BBV task field that carries the overhead (Overhead, ondersteuning organisatie).
	 */
	public const OVERHEAD_TASK_FIELD = '0.4';

	/**
	 * The cost-price method that charges no profit markup.
	 */
	private const METHOD_WITHOUT_MARKUP = 'costprice-monitor-without-profit-markup';

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords         $records     Activities, GL lines, accounts and cost prices.
	 * @param TaskFieldRealisation        $realisation The year's expenses per task field, for the overhead pool.
	 * @param IntegralCostPriceCalculator $calculator  The cost-price arithmetic.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
		private readonly TaskFieldRealisation $realisation,
		private readonly IntegralCostPriceCalculator $calculator,
	) {
	}//end __construct()

	/**
	 * Calculate the activity's integral cost price for its `costPriceYear` and store it.
	 *
	 * @param array<string,mixed> $activity The commercial activity, with costPriceYear.
	 *
	 * @return array<string,mixed> The activity with integralCostPriceId.
	 *
	 * @throws DomainException When the year, the cost centre or the cost object is
	 *                         missing, or the year's cost price is already final.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function calculate(array $activity): array {
		$year = (int)($activity['costPriceYear'] ?? 0);
		if ($year < 1900 || $year > 9999) {
			throw new DomainException('Enter the year to calculate the cost price for.');
		}

		$costCentre = trim((string)($activity['costCentreCode'] ?? ''));
		$costObject = trim((string)($activity['costObjectCode'] ?? ''));
		$administrationId = (string)($activity['administrationId'] ?? '');
		if ($costCentre === '' || $costObject === '' || $administrationId === '') {
			throw new DomainException('Enter the administration, the cost centre and the cost object of the activity before calculating.');
		}

		// The cost prices, cost allocations and alerts of an activity name it by
		// its `code` business key, as the seeds and the activity page do.
		$activityId = trim((string)($activity['code'] ?? ''));
		if ($activityId === '') {
			$activityId = (string)($activity['id'] ?? '');
		}

		$period = $year . '-YTD';
		$existing = $this->existing(activityId: $activityId, period: $period);
		if ($existing !== null && (string)($existing['status'] ?? '') === 'final') {
			throw new DomainException('The cost price of ' . $year . ' is final and cannot be calculated again.');
		}

		$start = sprintf('%04d-01-01', $year);
		$end = sprintf('%04d-01-01', ($year + 1));
		$ledger = $this->ledgerLines(administrationId: $administrationId, startDate: $start, endDate: $end);
		$record = $this->calculator->compose(
			input: [
				'commercialActivityId'   => $activityId,
				'period'                 => $period,
				'administrationId'       => $administrationId,
				'costCentre'             => $costCentre,
				'costObject'             => $costObject,
				'glLines'                => $ledger['lines'],
				'omzetEur'               => $this->calculator->fromCents(cents: $ledger['revenueCents'][$costCentre . '|' . $costObject] ?? 0),
				'corporateOverheadCents' => $this->overheadCents(administrationId: $administrationId, year: $year, start: $start, end: $end),
				'overheadRule'           => ['ratios' => (array)($activity['overheadKey'] ?? [])],
				'investedBookValueCents' => $this->calculator->toCents(amount: $activity['investedBookValue'] ?? 0),
				'waccRate'               => self::rate(value: $activity['waccRate'] ?? null, default: IntegralCostPriceCalculator::DEFAULT_WACC),
				'profitMarkupRate'       => $this->markupRate(activity: $activity),
				'soldUnits'              => (float)($activity['soldUnits'] ?? 0),
				'unitLabel'              => ($activity['unitLabel'] ?? null),
				'appliedRate'            => ($activity['appliedRate'] ?? null),
				'status'                 => 'voorlopig',
			]
		);

		$record = array_filter($record, static fn (mixed $value): bool => $value !== null);
		if ($existing !== null) {
			$activity['integralCostPriceId'] = $this->records->update(schema: 'IntegralCostPrice', id: (string)$existing['id'], object: $record);
			return $activity;
		}

		$activity['integralCostPriceId'] = $this->records->create(schema: 'IntegralCostPrice', object: $record);
		return $activity;

	}//end calculate()

	/**
	 * The stored cost price of the activity for the period, if any.
	 *
	 * @param string $activityId The activity's code.
	 * @param string $period     The period identifier.
	 *
	 * @return array<string,mixed>|null
	 */
	private function existing(string $activityId, string $period): ?array {
		if ($activityId === '') {
			return null;
		}

		$rows = $this->records->records(schema: 'IntegralCostPrice', filters: ['commercialActivityId' => $activityId, 'period' => $period]);
		foreach ($rows as $row) {
			if ((string)($row['commercialActivityId'] ?? '') === $activityId && (string)($row['period'] ?? '') === $period) {
				return $row;
			}
		}

		return null;

	}//end existing()

	/**
	 * The year's cost lines in the calculator's shape, and the revenue per cost
	 * centre and cost object, from the posted lines on profit and loss accounts.
	 *
	 * @param string $administrationId The administration.
	 * @param string $startDate        The year's first day, inclusive.
	 * @param string $endDate          The day after the year, exclusive.
	 *
	 * @return array{lines: list<array<string,mixed>>, revenueCents: array<string,int>}
	 */
	private function ledgerLines(string $administrationId, string $startDate, string $endDate): array {
		$result = ['lines' => [], 'revenueCents' => []];
		$ledger = new LedgerActuals();
		$posted = [];
		$transactions = $this->records->records(schema: 'GLTransaction', filters: ['administrationId' => $administrationId, 'state' => 'posted']);
		foreach ($transactions as $transaction) {
			$day = substr((string)($transaction['postingDate'] ?? ''), 0, 10);
			if ((string)($transaction['administrationId'] ?? '') === $administrationId
				&& ($transaction['state'] ?? '') === 'posted'
				&& $day !== ''
				&& $day >= $startDate
				&& $day < $endDate
			) {
				$posted[] = $transaction;
			}
		}

		$monthByRef = $ledger->postingMonths(transactions: $posted);
		if ($monthByRef === []) {
			return $result;
		}

		$rgsByAccount = $this->rgsByAccount(administrationId: $administrationId);
		foreach ($this->records->records(schema: 'GLLine', filters: []) as $line) {
			$accountNumber = (string)($line['accountNumber'] ?? '');
			if (isset($monthByRef[(string)($line['transactionId'] ?? '')]) === false || $accountNumber === '') {
				continue;
			}

			$kind = self::kind(rgsCode: ($rgsByAccount[$accountNumber] ?? ''), accountClass: (string)($line['accountClass'] ?? ''));
			if ($kind === null) {
				continue;
			}

			$costCentre = (string)($line['costCenterCode'] ?? $line['costCenter'] ?? '');
			$costObject = (string)($line['costCarrierCode'] ?? '');
			$cents = $this->calculator->toCents(amount: $line['amount'] ?? 0);
			if ((string)($line['side'] ?? 'debit') === 'credit') {
				$cents = -$cents;
			}

			if ($kind === 'revenue') {
				$pair = $costCentre . '|' . $costObject;
				$result['revenueCents'][$pair] = (($result['revenueCents'][$pair] ?? 0) - $cents);
				continue;
			}

			$result['lines'][] = [
				'costCentre'  => $costCentre,
				'costObject'  => $costObject,
				'accountKind' => $kind,
				'amount'      => $this->calculator->fromCents(cents: $cents),
			];
		}//end foreach

		return $result;

	}//end ledgerLines()

	/**
	 * The RGS code of each account of the administration.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,string> Account number to RGS code.
	 */
	private function rgsByAccount(string $administrationId): array {
		$codes = [];
		foreach ($this->records->records(schema: 'Account', filters: ['administrationId' => $administrationId]) as $account) {
			$number = trim((string)($account['accountNumber'] ?? ''));
			if ($number !== '' && (string)($account['administrationId'] ?? '') === $administrationId) {
				$codes[$number] = trim((string)($account['rgsCode'] ?? ''));
			}
		}

		return $codes;

	}//end rgsByAccount()

	/**
	 * The calculator's account kind of a line, `revenue` for turnover, or null
	 * for a balance sheet line.
	 *
	 * @param string $rgsCode      The account's RGS code, empty when unknown.
	 * @param string $accountClass The line's account class (`pnl` or `balance`).
	 *
	 * @return string|null
	 */
	private static function kind(string $rgsCode, string $accountClass): ?string {
		if ($rgsCode === '') {
			if ($accountClass === 'balance') {
				return null;
			}

			return 'materialen';
		}

		return match (true) {
			str_starts_with($rgsCode, 'WOmz') => 'revenue',
			str_starts_with($rgsCode, 'WPer') => 'payrollCost',
			str_starts_with($rgsCode, 'WAfs') => 'depreciations',
			str_starts_with($rgsCode, 'W')    => 'materialen',
			default                           => null,
		};

	}//end kind()

	/**
	 * The year's overhead pool: the expenses on task field 0.4, in cents.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The year.
	 * @param string $start            The year's first day.
	 * @param string $end              The day after the year.
	 *
	 * @return int
	 */
	private function overheadCents(string $administrationId, int $year, string $start, string $end): int {
		$realisation = $this->realisation->forYear(administrationId: $administrationId, fiscalYear: (string)$year, startDate: $start, endDate: $end);
		foreach ($realisation['taskFields'] as $field) {
			if ($field['taskField'] === self::OVERHEAD_TASK_FIELD) {
				return $this->calculator->toCents(amount: $field['expenses']);
			}
		}

		return 0;

	}//end overheadCents()

	/**
	 * The profit markup rate: none for the monitor method, else the activity's or the default.
	 *
	 * @param array<string,mixed> $activity The activity.
	 *
	 * @return float
	 */
	private function markupRate(array $activity): float {
		if ((string)($activity['costPriceMethod'] ?? '') === self::METHOD_WITHOUT_MARKUP) {
			return 0.0;
		}

		return self::rate(value: $activity['profitMarkupRate'] ?? null, default: IntegralCostPriceCalculator::DEFAULT_PROFIT_MARKUP);

	}//end markupRate()

	/**
	 * A rate given as a fraction, or the default when it is not a number.
	 *
	 * @param mixed $value   The rate.
	 * @param float $default The default rate.
	 *
	 * @return float
	 */
	private static function rate(mixed $value, float $default): float {
		if (is_numeric($value) === false) {
			return $default;
		}

		return (float)$value;

	}//end rate()
}//end class
