<?php

/**
 * Depreciation Schedule Service
 *
 * Keeps an asset's monthly DepreciationSchedule lines and brings them to the
 * ledger (assets-method-change-and-reserve). An active asset without lines
 * gets its whole plan from its acquisition month (REQ-AMCR-001). A revision
 * of method or useful life replans the unposted months from its date, from
 * the book value then, and never touches a posted line (REQ-AMCR-002). An
 * extra depreciation is one line posted at once, after which the months from
 * its date are replanned from the lower book value (REQ-AMCR-003). Posting
 * makes one journal entry per administration and period: the expense account
 * debited and accumulated depreciation credited per asset, and each line is
 * marked posted with the journal entry's id.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Asset;

use DateTimeImmutable;
use DomainException;

/**
 * Plans, replans and posts depreciation schedule lines.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One schedule, four operations on it.
 */
class DepreciationScheduleService {

	public const SCHEDULE = 'DepreciationSchedule';

	public const ASSET = 'FixedAsset';

	public const POSTED = 'posted';

	public const PLANNED = 'planned';

	public const EXTRA = 'extra';

	/**
	 * FixedAsset method names as DepreciationSchedule names them.
	 */
	private const SCHEDULE_METHOD = [
		'linear'              => 'linear',
		'degressive'          => 'declining-balance',
		'units-of-production' => 'units-of-production',
	];

	/**
	 * Constructor.
	 *
	 * @param AssetRecords        $records The register.
	 * @param DepreciationPlanner $planner The monthly arithmetic.
	 */
	public function __construct(
		private readonly AssetRecords $records,
		private readonly DepreciationPlanner $planner,
	) {
	}//end __construct()

	/**
	 * An asset's figures under one name each, whichever of the two field sets it uses.
	 *
	 * @param array<string,mixed> $asset The FixedAsset record.
	 *
	 * @return array{id: string, number: string, administrationId: string, costCents: int, residualCents: int, months: int, acquired: string, method: string, rate: float, expenseAccount: string, accumulatedAccount: string, costCenter: string, active: bool} The figures.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function figures(array $asset): array {
		$months = (int)($asset['usefulLifeMonths'] ?? 0);
		if ($months <= 0) {
			$months = ((int)($asset['usefulLifeYears'] ?? 0) * 12);
		}

		$state = (string)($asset['status'] ?? '');
		if ($state === '') {
			$state = (string)($asset['lifecycleState'] ?? '');
		}

		return [
			'id'                 => (string)($asset['id'] ?? ''),
			'number'             => (string)($asset['assetNumber'] ?? ($asset['id'] ?? '')),
			'administrationId'   => (string)($asset['administrationId'] ?? ''),
			'costCents'          => self::cents(amount: ($asset['acquisitionCost'] ?? ($asset['purchaseCost'] ?? 0))),
			'residualCents'      => self::cents(amount: ($asset['residualValue'] ?? 0)),
			'months'             => $months,
			'acquired'           => substr((string)($asset['acquisitionDate'] ?? ($asset['purchaseDate'] ?? '')), 0, 10),
			'method'             => (string)($asset['depreciationMethod'] ?? 'linear'),
			'rate'               => (float)($asset['degressiveRate'] ?? ($asset['declineRate'] ?? 0)),
			'expenseAccount'     => (string)($asset['depreciationExpenseAccountNumber'] ?? ''),
			'accumulatedAccount' => (string)($asset['accumulatedDepAccountNumber'] ?? ($asset['accumulatedDepreciationAccountNumber'] ?? '')),
			'costCenter'         => (string)($asset['costCenterCode'] ?? ''),
			'active'             => $state === 'active',
		];

	}//end figures()

	/**
	 * An asset's schedule lines, oldest first.
	 *
	 * @param string $assetId The asset's id.
	 *
	 * @return list<array<string,mixed>> The lines.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function lines(string $assetId): array {
		$lines = $this->records->records(schema: self::SCHEDULE, filters: ['assetRef' => $assetId]);
		usort(
			$lines,
			static fn (array $left, array $right): int => [(string)($left['periodStartDate'] ?? ''), (string)($left['rateType'] ?? '')] <=> [(string)($right['periodStartDate'] ?? ''), (string)($right['rateType'] ?? '')]
		);
		return $lines;

	}//end lines()

	/**
	 * Write an active asset's whole plan when it has no lines yet.
	 *
	 * @param array<string,mixed> $asset The FixedAsset record.
	 *
	 * @return list<array<string,mixed>> The asset's lines.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function ensureSchedule(array $asset): array {
		$figures = $this->figures(asset: $asset);
		$lines = $this->lines(assetId: $figures['id']);
		if ($lines !== [] || $figures['active'] === false || $figures['acquired'] === '' || $figures['months'] < 1
			|| isset(self::SCHEDULE_METHOD[$figures['method']]) === false
		) {
			return $lines;
		}

		$plan = $this->planner->plan(
			fromMonth: substr($figures['acquired'], 0, 7),
			bookCents: $figures['costCents'],
			residualCents: $figures['residualCents'],
			months: $figures['months'],
			method: $figures['method'],
			rate: $figures['rate']
		);
		$this->writePlan(figures: $figures, plan: $plan, method: $figures['method'], months: $figures['months'], accumulatedCents: 0, reason: '');

		return $this->lines(assetId: $figures['id']);

	}//end ensureSchedule()

	/**
	 * Replan the unposted months from a date under a new method or useful life.
	 *
	 * @param array<string,mixed> $asset  The FixedAsset record.
	 * @param string              $date   The revision date, YYYY-MM-DD.
	 * @param string              $method The method from then on.
	 * @param int                 $months The total useful life in months from acquisition.
	 * @param string              $reason Why.
	 *
	 * @return array{fromMonth: string, bookValue: float, monthly: float, months: int} The new plan's start.
	 *
	 * @throws DomainException When a posted month lies after the date, or no month is left.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function revise(array $asset, string $date, string $method, int $months, string $reason): array {
		$figures = $this->figures(asset: $asset);
		if (isset(self::SCHEDULE_METHOD[$method]) === false) {
			throw new DomainException(sprintf('Depreciation method %s cannot be planned.', $method));
		}

		$this->ensureSchedule(asset: $asset);
		$fromMonth = substr($date, 0, 7);
		$kept = [];
		foreach ($this->lines(assetId: $figures['id']) as $line) {
			$start = (string)($line['periodStartDate'] ?? '');
			$posted = (string)($line['status'] ?? '') === self::POSTED;
			if ($start < $fromMonth . '-01' || $posted === true) {
				if ($posted === true && $start >= $fromMonth . '-01' && (string)($line['rateType'] ?? '') !== self::EXTRA) {
					throw new DomainException(sprintf('The depreciation of %s is already posted, so the revision cannot start before it.', substr($start, 0, 7)));
				}

				$kept[] = $line;
				continue;
			}

			$this->records->delete(schema: self::SCHEDULE, id: (string)$line['id']);
		}

		$accumulated = 0;
		foreach ($kept as $line) {
			$accumulated += self::cents(amount: ($line['depreciationAmount'] ?? 0));
		}

		$left = ($months - $this->monthsBetween(fromMonth: substr($figures['acquired'], 0, 7), toMonth: $fromMonth));
		if ($left < 1) {
			throw new DomainException(sprintf('A useful life of %d months has ended before %s.', $months, $fromMonth));
		}

		$book = ($figures['costCents'] - $accumulated);
		$plan = $this->planner->plan(fromMonth: $fromMonth, bookCents: $book, residualCents: $figures['residualCents'], months: $left, method: $method, rate: $figures['rate']);
		$this->writePlan(figures: $figures, plan: $plan, method: $method, months: $months, accumulatedCents: $accumulated, reason: $reason);

		return [
			'fromMonth' => $fromMonth,
			'bookValue' => ($book / 100),
			'monthly'   => ($plan[0]['cents'] / 100),
			'months'    => $left,
		];

	}//end revise()

	/**
	 * Book an extra depreciation at once and replan the months from its date.
	 *
	 * @param array<string,mixed> $asset  The FixedAsset record.
	 * @param float               $amount The amount in euros.
	 * @param string              $date   The date, YYYY-MM-DD.
	 * @param string              $reason Why.
	 *
	 * @return array{line: array<string,mixed>, journalId: string} The posted line and its journal entry.
	 *
	 * @throws DomainException When the amount is not above zero or exceeds what is left to depreciate.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function extra(array $asset, float $amount, string $date, string $reason): array {
		$figures = $this->figures(asset: $asset);
		$cents = self::cents(amount: $amount);
		if ($cents <= 0) {
			throw new DomainException('Extra depreciation needs an amount above zero.');
		}

		if (trim($reason) === '') {
			throw new DomainException('Extra depreciation needs a reason.');
		}

		$before = 0;
		foreach ($this->ensureSchedule(asset: $asset) as $line) {
			if ((string)($line['periodStartDate'] ?? '') < substr($date, 0, 7) . '-01' || (string)($line['status'] ?? '') === self::POSTED) {
				$before += self::cents(amount: ($line['depreciationAmount'] ?? 0));
			}
		}

		$room = ($figures['costCents'] - $figures['residualCents'] - $before);
		if ($cents > $room) {
			throw new DomainException(sprintf('Only %s is left to depreciate on this asset.', number_format(($room / 100), 2, '.', '')));
		}

		$line = $this->records->save(
			schema: self::SCHEDULE,
			object: $this->line(
				figures: $figures,
				line: ['month' => substr($date, 0, 7), 'start' => $date, 'end' => $date, 'cents' => $cents],
				method: $figures['method'],
				months: $figures['months'],
				accumulatedCents: ($before + $cents),
				reason: $reason,
				rateType: self::EXTRA
			)
		);
		$journalId = $this->post(administrationId: $figures['administrationId'], period: $date, entries: [['figures' => $figures, 'line' => $line]], description: sprintf('Extra depreciation %s: %s', $figures['number'], $reason));

		$this->revise(asset: $asset, date: $date, method: $figures['method'], months: $figures['months'], reason: $reason);

		$posted = $this->records->find(schema: self::SCHEDULE, id: (string)$line['id']);

		return ['line' => ($posted ?? $line), 'journalId' => $journalId];

	}//end extra()

	/**
	 * Post every planned line of active assets whose period ended in a month, one journal entry per administration.
	 *
	 * @param string $month The month, YYYY-MM.
	 *
	 * @return array{journals: int, lines: int} What was posted.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function postMonth(string $month): array {
		$byAdministration = [];
		foreach ($this->records->records(schema: self::ASSET, filters: ['status' => 'active']) as $asset) {
			$figures = $this->figures(asset: $asset);
			foreach ($this->ensureSchedule(asset: $asset) as $line) {
				if ($this->isDue(line: $line) === true && substr((string)($line['periodEndDate'] ?? ''), 0, 7) === $month) {
					$byAdministration[$figures['administrationId']][] = ['figures' => $figures, 'line' => $line];
				}
			}
		}

		$lines = 0;
		foreach ($byAdministration as $administrationId => $entries) {
			$this->post(administrationId: (string)$administrationId, period: $month . '-28', entries: $entries, description: sprintf('Depreciation %s', $month));
			$lines += count($entries);
		}

		return ['journals' => count($byAdministration), 'lines' => $lines];

	}//end postMonth()

	/**
	 * The planned lines of an asset whose period ended before a month: the depreciation the run did not post.
	 *
	 * @param array<string,mixed> $asset     The FixedAsset record.
	 * @param string              $beforeMonth The first month not counted, YYYY-MM.
	 *
	 * @return list<array{period: string, amount: float, lineId: string}> The missed periods, oldest first.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function missed(array $asset, string $beforeMonth): array {
		$rows = [];
		foreach ($this->ensureSchedule(asset: $asset) as $line) {
			if ($this->isDue(line: $line) === true && (string)($line['periodEndDate'] ?? '') < $beforeMonth . '-01') {
				$rows[] = [
					'period' => substr((string)$line['periodEndDate'], 0, 7),
					'amount' => (float)($line['depreciationAmount'] ?? 0),
					'lineId' => (string)$line['id'],
				];
			}
		}

		return $rows;

	}//end missed()

	/**
	 * Post the missed periods of an asset, one journal entry per period.
	 *
	 * @param array<string,mixed> $asset       The FixedAsset record.
	 * @param string              $beforeMonth The first month not counted, YYYY-MM.
	 *
	 * @return list<array{period: string, amount: float, lineId: string}> What was posted.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function postMissed(array $asset, string $beforeMonth): array {
		$figures = $this->figures(asset: $asset);
		$missed = $this->missed(asset: $asset, beforeMonth: $beforeMonth);
		foreach ($missed as $row) {
			$line = $this->records->find(schema: self::SCHEDULE, id: $row['lineId']);
			if ($line === null) {
				continue;
			}

			$this->post(administrationId: $figures['administrationId'], period: (string)$line['periodEndDate'], entries: [['figures' => $figures, 'line' => $line]], description: sprintf('Depreciation %s, %s', $row['period'], $figures['number']));
		}

		return $missed;

	}//end postMissed()

	/**
	 * Whether a line still waits to be posted.
	 *
	 * @param array<string,mixed> $line The schedule line.
	 *
	 * @return bool True when planned, not extra and not linked to a journal entry.
	 */
	private function isDue(array $line): bool {
		return (string)($line['status'] ?? self::PLANNED) === self::PLANNED
			&& (string)($line['rateType'] ?? '') !== self::EXTRA
			&& trim((string)($line['glTransactionRef'] ?? '')) === ''
			&& self::cents(amount: ($line['depreciationAmount'] ?? 0)) > 0;

	}//end isDue()

	/**
	 * Post schedule lines as one journal entry and mark them posted.
	 *
	 * @param string                                                         $administrationId The administration.
	 * @param string                                                         $period           A date in the period; the entry date is the period's end for a month.
	 * @param list<array{figures: array<string,mixed>, line: array<string,mixed>}> $entries          The lines with their assets.
	 * @param string                                                         $description      The journal entry's description.
	 *
	 * @return string The journal entry's id.
	 *
	 * @throws DomainException When an asset has no expense or accumulated depreciation account.
	 */
	private function post(string $administrationId, string $period, array $entries, string $description): string {
		$lines = [];
		$numbers = [];
		$date = '';
		foreach ($entries as $entry) {
			$figures = $entry['figures'];
			if ($figures['expenseAccount'] === '' || $figures['accumulatedAccount'] === '') {
				throw new DomainException(sprintf('Asset %s has no depreciation expense or accumulated depreciation account.', $figures['number']));
			}

			$amount = (round((float)$entry['line']['depreciationAmount'], 2));
			$text = sprintf('%s %s', $figures['number'], substr((string)$entry['line']['periodEndDate'], 0, 10));
			$lines[] = ['accountNumber' => $figures['expenseAccount'], 'side' => 'debit', 'amount' => $amount, 'description' => $text, 'costCenterCode' => $figures['costCenter']];
			$lines[] = ['accountNumber' => $figures['accumulatedAccount'], 'side' => 'credit', 'amount' => $amount, 'description' => $text];
			$numbers[] = (string)$entry['line']['id'];
			$date = max($date, substr((string)$entry['line']['periodEndDate'], 0, 10));
		}

		if ($date === '') {
			$date = $period;
		}

		$journalId = $this->records->postJournal(
			journal: [
				'journalNumber'    => sprintf('AFS-%s-%s', str_replace('-', '', $date), substr(hash('sha256', $administrationId . '|' . implode('|', $numbers)), 0, 8)),
				'entryDate'        => $date,
				'description'      => $description,
				'lines'            => $lines,
				'journalType'      => 'manual',
				'approvalState'    => 'not-required',
				'administrationId' => $administrationId,
				'state'            => 'draft',
			]
		);

		foreach ($numbers as $lineId) {
			$this->records->patch(schema: self::SCHEDULE, id: $lineId, fields: ['status' => self::POSTED, 'glTransactionRef' => $journalId]);
		}

		return $journalId;

	}//end post()

	/**
	 * Write a plan as planned lines.
	 *
	 * @param array<string,mixed>                                                               $figures          The asset's figures.
	 * @param list<array{month: string, start: string, end: string, cents: int, bookCents: int}> $plan             The plan.
	 * @param string                                                                            $method           The FixedAsset method.
	 * @param int                                                                               $months           The total useful life.
	 * @param int                                                                               $accumulatedCents Depreciation before the plan.
	 * @param string                                                                            $reason           Why the plan was made, empty for the first.
	 *
	 * @return void
	 */
	private function writePlan(array $figures, array $plan, string $method, int $months, int $accumulatedCents, string $reason): void {
		foreach ($plan as $month) {
			$accumulatedCents += $month['cents'];
			$this->records->save(schema: self::SCHEDULE, object: $this->line(figures: $figures, line: $month, method: $method, months: $months, accumulatedCents: $accumulatedCents, reason: $reason, rateType: 'fixed-amount'));
		}

	}//end writePlan()

	/**
	 * One DepreciationSchedule line.
	 *
	 * @param array<string,mixed>                                          $figures          The asset's figures.
	 * @param array{month: string, start: string, end: string, cents: int} $line             The period and amount.
	 * @param string                                                       $method           The FixedAsset method.
	 * @param int                                                          $months           The total useful life.
	 * @param int                                                          $accumulatedCents Accumulated depreciation including this line.
	 * @param string                                                       $reason           Why, or empty.
	 * @param string                                                       $rateType         fixed-amount for a planned month, extra for an extra depreciation.
	 *
	 * @return array<string,mixed> The line.
	 */
	private function line(array $figures, array $line, string $method, int $months, int $accumulatedCents, string $reason, string $rateType): array {
		$rate = $figures['rate'];
		if ($method !== 'degressive') {
			$rate = round(12 / max(1, $months), 6);
		}

		$suffix = $line['month'];
		if ($rateType === self::EXTRA) {
			$suffix = 'X' . str_replace('-', '', $line['start']);
		}

		return [
			'scheduleNumber'          => sprintf('%s-%s', $figures['number'], $suffix),
			'assetRef'                => $figures['id'],
			'depreciationMethod'      => self::SCHEDULE_METHOD[$method],
			'annualRate'              => $rate,
			'rateType'                => $rateType,
			'periodStartDate'         => $line['start'],
			'periodEndDate'           => $line['end'],
			'depreciationAmount'      => ($line['cents'] / 100),
			'accumulatedDepreciation' => ($accumulatedCents / 100),
			'bookValue'               => (($figures['costCents'] - $accumulatedCents) / 100),
			'fiscalYear'              => (int)substr($line['start'], 0, 4),
			'status'                  => self::PLANNED,
			'costCenterCode'          => ($figures['costCenter'] !== '' ? $figures['costCenter'] : null),
			'reason'                  => ($reason !== '' ? $reason : null),
			'administrationId'        => $figures['administrationId'],
		];

	}//end line()

	/**
	 * Whole months from one month to another.
	 *
	 * @param string $fromMonth YYYY-MM.
	 * @param string $toMonth   YYYY-MM.
	 *
	 * @return int The months between them.
	 */
	private function monthsBetween(string $fromMonth, string $toMonth): int {
		$from = DateTimeImmutable::createFromFormat('!Y-m', $fromMonth);
		$to = DateTimeImmutable::createFromFormat('!Y-m', $toMonth);
		if ($from === false || $to === false) {
			return 0;
		}

		$diff = $from->diff($to);
		return ((($diff->y * 12) + $diff->m) * ($diff->invert === 1 ? -1 : 1));

	}//end monthsBetween()

	/**
	 * Euros to cents.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return int The cents.
	 */
	private static function cents(mixed $amount): int {
		return (int)round(((float)$amount) * 100);

	}//end cents()
}//end class
