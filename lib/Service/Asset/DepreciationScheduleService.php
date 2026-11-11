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
	 * @return array{
	 *     id: string, number: string, administrationId: string, costCents: int, residualCents: int, months: int,
	 *     acquired: string, method: string, rate: float, expenseAccount: string, accumulatedAccount: string,
	 *     costCenter: string, active: bool
	 * } The figures.
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
			static fn (array $left, array $right): int => [
				(string)($left['periodStartDate'] ?? ''),
				(string)($left['rateType'] ?? ''),
			] <=> [
				(string)($right['periodStartDate'] ?? ''),
				(string)($right['rateType'] ?? ''),
			]
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
		$this->writePlan(
			terms: new ScheduleTerms(figures: $figures, method: $figures['method'], months: $figures['months'], reason: '', accumulatedCents: 0),
			plan: $plan
		);

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
		$kept = $this->keptLines(assetId: $figures['id'], fromMonth: $fromMonth);

		$accumulated = 0;
		foreach ($kept as $line) {
			$accumulated += self::cents(amount: ($line['depreciationAmount'] ?? 0));
		}

		return $this->replan(
			terms: new ScheduleTerms(figures: $figures, method: $method, months: $months, reason: $reason, accumulatedCents: $accumulated),
			fromMonth: $fromMonth
		);

	}//end revise()

	/**
	 * Keep the lines before the month and every posted line; delete the rest.
	 *
	 * @param string $assetId   The asset's id.
	 * @param string $fromMonth The first replanned month, YYYY-MM.
	 *
	 * @return list<array<string,mixed>> The kept lines.
	 *
	 * @throws DomainException When a posted month lies in the replanned range.
	 */
	private function keptLines(string $assetId, string $fromMonth): array {
		$kept = [];
		foreach ($this->lines(assetId: $assetId) as $line) {
			$start = (string)($line['periodStartDate'] ?? '');
			if ($start < $fromMonth . '-01' || (string)($line['status'] ?? '') === self::POSTED) {
				$this->assertKeepable(line: $line, fromMonth: $fromMonth);
				$kept[] = $line;
				continue;
			}

			$this->records->delete(schema: self::SCHEDULE, id: (string)$line['id']);
		}

		return $kept;

	}//end keptLines()

	/**
	 * Refuse a posted, non-extra line in the replanned range: posted lines
	 * MUST NOT change, so the revision cannot start before it.
	 *
	 * @param array<string,mixed> $line      A kept line.
	 * @param string              $fromMonth The first replanned month, YYYY-MM.
	 *
	 * @return void
	 *
	 * @throws DomainException When the line is posted on or after the month.
	 */
	private function assertKeepable(array $line, string $fromMonth): void {
		$start = (string)($line['periodStartDate'] ?? '');
		if ((string)($line['status'] ?? '') === self::POSTED && $start >= $fromMonth . '-01' && (string)($line['rateType'] ?? '') !== self::EXTRA) {
			throw new DomainException(sprintf(
				'The depreciation of %s is already posted, so the revision cannot start before it.',
				substr($start, 0, 7)
			));
		}

	}//end assertKeepable()

	/**
	 * Plan and write the months left from a month on the book value.
	 *
	 * @param ScheduleTerms $terms     The figures, method, life, reason and depreciation so far.
	 * @param string        $fromMonth The first planned month, YYYY-MM.
	 *
	 * @return array{fromMonth: string, bookValue: float, monthly: float, months: int} The new plan's start.
	 *
	 * @throws DomainException When no month of the useful life is left.
	 */
	private function replan(ScheduleTerms $terms, string $fromMonth): array {
		$figures = $terms->figures;
		$left = ($terms->months - $this->monthsBetween(fromMonth: substr($figures['acquired'], 0, 7), toMonth: $fromMonth));
		if ($left < 1) {
			throw new DomainException(sprintf('A useful life of %d months has ended before %s.', $terms->months, $fromMonth));
		}

		$book = ($figures['costCents'] - $terms->accumulatedCents);
		$plan = $this->planner->plan(
			fromMonth: $fromMonth,
			bookCents: $book,
			residualCents: $figures['residualCents'],
			months: $left,
			method: $terms->method,
			rate: $figures['rate']
		);
		$this->writePlan(terms: $terms, plan: $plan);

		return ['fromMonth' => $fromMonth, 'bookValue' => ($book / 100), 'monthly' => ($plan[0]['cents'] / 100), 'months' => $left];

	}//end replan()

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
		$this->assertExtraRequest(cents: $cents, reason: $reason);

		$before = $this->depreciatedBefore(asset: $asset, date: $date);
		$terms = new ScheduleTerms(figures: $figures, method: $figures['method'], months: $figures['months'], reason: $reason, accumulatedCents: $before);
		$this->assertRoomFor(terms: $terms, cents: $cents);

		$line = $this->records->save(
			schema: self::SCHEDULE,
			object: $this->line(
				terms: $terms,
				entry: [
					'month' => substr($date, 0, 7),
					'start' => $date,
					'end' => $date,
					'cents' => $cents,
					'accumulatedCents' => ($before + $cents),
					'rateType' => self::EXTRA,
				]
			)
		);
		$journalId = $this->postExtra(terms: $terms, line: $line);

		$this->revise(asset: $asset, date: $date, method: $figures['method'], months: $figures['months'], reason: $reason);

		$posted = $this->records->find(schema: self::SCHEDULE, id: (string)$line['id']);

		return ['line' => ($posted ?? $line), 'journalId' => $journalId];

	}//end extra()

	/**
	 * Refuse an extra depreciation above what is left to depreciate.
	 *
	 * @param ScheduleTerms $terms The figures and the depreciation before the extra line.
	 * @param int           $cents The extra amount in cents.
	 *
	 * @return void
	 *
	 * @throws DomainException When the amount exceeds the room left.
	 */
	private function assertRoomFor(ScheduleTerms $terms, int $cents): void {
		$room = ($terms->figures['costCents'] - $terms->figures['residualCents'] - $terms->accumulatedCents);
		if ($cents > $room) {
			throw new DomainException(sprintf('Only %s is left to depreciate on this asset.', number_format(($room / 100), 2, '.', '')));
		}

	}//end assertRoomFor()

	/**
	 * Post a saved extra line as its own journal entry, dated on its day.
	 *
	 * @param ScheduleTerms       $terms The figures and reason.
	 * @param array<string,mixed> $line  The saved extra line.
	 *
	 * @return string The journal entry's id.
	 */
	private function postExtra(ScheduleTerms $terms, array $line): string {
		$figures = $terms->figures;
		return $this->post(
			posting: new DepreciationPosting(
				administrationId: $figures['administrationId'],
				period: (string)$line['periodStartDate'],
				entries: [['figures' => $figures, 'line' => $line]],
				description: sprintf('Extra depreciation %s: %s', $figures['number'], $terms->reason)
			)
		);

	}//end postExtra()

	/**
	 * Refuse an extra depreciation without an amount above zero or a reason.
	 *
	 * @param int    $cents  The amount in cents.
	 * @param string $reason Why.
	 *
	 * @return void
	 *
	 * @throws DomainException When either is missing.
	 */
	private function assertExtraRequest(int $cents, string $reason): void {
		if ($cents <= 0) {
			throw new DomainException('Extra depreciation needs an amount above zero.');
		}

		if (trim($reason) === '') {
			throw new DomainException('Extra depreciation needs a reason.');
		}

	}//end assertExtraRequest()

	/**
	 * The depreciation of every line before the date's month and of every posted line, in cents.
	 *
	 * @param array<string,mixed> $asset The FixedAsset record.
	 * @param string              $date  The date, YYYY-MM-DD.
	 *
	 * @return int
	 */
	private function depreciatedBefore(array $asset, string $date): int {
		$before = 0;
		foreach ($this->ensureSchedule(asset: $asset) as $line) {
			if ((string)($line['periodStartDate'] ?? '') < substr($date, 0, 7) . '-01' || (string)($line['status'] ?? '') === self::POSTED) {
				$before += self::cents(amount: ($line['depreciationAmount'] ?? 0));
			}
		}

		return $before;

	}//end depreciatedBefore()

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
			$this->post(
				posting: new DepreciationPosting(
					administrationId: (string)$administrationId,
					period: $month . '-28',
					entries: $entries,
					description: sprintf('Depreciation %s', $month)
				)
			);
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

			$this->post(
				posting: new DepreciationPosting(
					administrationId: $figures['administrationId'],
					period: (string)$line['periodEndDate'],
					entries: [['figures' => $figures, 'line' => $line]],
					description: sprintf('Depreciation %s, %s', $row['period'], $figures['number'])
				)
			);
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
	 * @param DepreciationPosting $posting The administration, period, lines and description.
	 *
	 * @return string The journal entry's id.
	 *
	 * @throws DomainException When an asset has no expense or accumulated depreciation account.
	 */
	private function post(DepreciationPosting $posting): string {
		$batch = $this->journalBatch(posting: $posting);
		$journalId = $this->records->postJournal(journal: $this->journal(posting: $posting, batch: $batch));

		foreach ($batch['numbers'] as $lineId) {
			$this->records->patch(schema: self::SCHEDULE, id: $lineId, fields: ['status' => self::POSTED, 'glTransactionRef' => $journalId]);
		}

		return $journalId;

	}//end post()

	/**
	 * The journal lines, the schedule line ids and the entry date (the latest
	 * period end, else the posting's period) of a posting.
	 *
	 * @param DepreciationPosting $posting The posting.
	 *
	 * @return array{lines: list<array<string,mixed>>, numbers: list<string>, date: string}
	 *
	 * @throws DomainException When an asset has no expense or accumulated depreciation account.
	 */
	private function journalBatch(DepreciationPosting $posting): array {
		$batch = ['lines' => [], 'numbers' => [], 'date' => ''];
		foreach ($posting->entries as $entry) {
			array_push($batch['lines'], ...$this->journalLines(entry: $entry));
			$batch['numbers'][] = (string)$entry['line']['id'];
			$batch['date'] = max($batch['date'], substr((string)$entry['line']['periodEndDate'], 0, 10));
		}

		if ($batch['date'] === '') {
			$batch['date'] = $posting->period;
		}

		return $batch;

	}//end journalBatch()

	/**
	 * The debit (expense) and credit (accumulated depreciation) line of one schedule line.
	 *
	 * @param array{figures: array<string,mixed>, line: array<string,mixed>} $entry The line with its asset.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @throws DomainException When the asset has no expense or accumulated depreciation account.
	 */
	private function journalLines(array $entry): array {
		$figures = $entry['figures'];
		if ($figures['expenseAccount'] === '' || $figures['accumulatedAccount'] === '') {
			throw new DomainException(sprintf('Asset %s has no depreciation expense or accumulated depreciation account.', $figures['number']));
		}

		$amount = (round((float)$entry['line']['depreciationAmount'], 2));
		$text = sprintf('%s %s', $figures['number'], substr((string)$entry['line']['periodEndDate'], 0, 10));
		return [
			[
				'accountNumber' => $figures['expenseAccount'],
				'side' => 'debit',
				'amount' => $amount,
				'description' => $text,
				'costCenterCode' => $figures['costCenter'],
			],
			['accountNumber' => $figures['accumulatedAccount'], 'side' => 'credit', 'amount' => $amount, 'description' => $text],
		];

	}//end journalLines()

	/**
	 * The draft journal entry of a posting.
	 *
	 * @param DepreciationPosting                                                           $posting The posting.
	 * @param array{lines: list<array<string,mixed>>, numbers: list<string>, date: string} $batch   Its lines, line ids and date.
	 *
	 * @return array<string,mixed>
	 */
	private function journal(DepreciationPosting $posting, array $batch): array {
		return [
			'journalNumber'    => sprintf(
				'AFS-%s-%s',
				str_replace('-', '', $batch['date']),
				substr(hash('sha256', $posting->administrationId . '|' . implode('|', $batch['numbers'])), 0, 8)
			),
			'entryDate'        => $batch['date'],
			'description'      => $posting->description,
			'lines'            => $batch['lines'],
			'journalType'      => 'manual',
			'approvalState'    => 'not-required',
			'administrationId' => $posting->administrationId,
			'state'            => 'draft',
		];

	}//end journal()

	/**
	 * Write a plan as planned lines.
	 *
	 * @param ScheduleTerms                                                                     $terms The terms and depreciation before the plan.
	 * @param list<array{month: string, start: string, end: string, cents: int, bookCents: int}> $plan  The plan.
	 *
	 * @return void
	 */
	private function writePlan(ScheduleTerms $terms, array $plan): void {
		$accumulatedCents = $terms->accumulatedCents;
		foreach ($plan as $month) {
			$accumulatedCents += $month['cents'];
			$this->records->save(
				schema: self::SCHEDULE,
				object: $this->line(
					terms: $terms,
					entry: $month + ['accumulatedCents' => $accumulatedCents, 'rateType' => 'fixed-amount']
				)
			);
		}

	}//end writePlan()

	/**
	 * One DepreciationSchedule line.
	 *
	 * @param ScheduleTerms       $terms The figures, method, life and reason.
	 * @param array<string,mixed> $entry month, start, end, cents, accumulatedCents (including this line) and
	 *                                   rateType (fixed-amount or extra).
	 *
	 * @return array<string,mixed> The line.
	 */
	private function line(ScheduleTerms $terms, array $entry): array {
		$figures = $terms->figures;
		return [
			'scheduleNumber'          => sprintf('%s-%s', $figures['number'], $this->lineSuffix(entry: $entry)),
			'assetRef'                => $figures['id'],
			'depreciationMethod'      => self::SCHEDULE_METHOD[$terms->method],
			'annualRate'              => $this->annualRate(terms: $terms),
			'rateType'                => $entry['rateType'],
			'periodStartDate'         => $entry['start'],
			'periodEndDate'           => $entry['end'],
			'depreciationAmount'      => ($entry['cents'] / 100),
			'accumulatedDepreciation' => ($entry['accumulatedCents'] / 100),
			'bookValue'               => (($figures['costCents'] - $entry['accumulatedCents']) / 100),
			'fiscalYear'              => (int)substr($entry['start'], 0, 4),
			'status'                  => self::PLANNED,
			'costCenterCode'          => $this->orNull(value: $figures['costCenter']),
			'reason'                  => $this->orNull(value: $terms->reason),
			'administrationId'        => $figures['administrationId'],
		];

	}//end line()

	/**
	 * The annual rate: the asset's own for degressive, else 12 / the useful life.
	 *
	 * @param ScheduleTerms $terms The figures, method and life.
	 *
	 * @return float
	 */
	private function annualRate(ScheduleTerms $terms): float {
		if ($terms->method === 'degressive') {
			return $terms->figures['rate'];
		}

		return round(12 / max(1, $terms->months), 6);

	}//end annualRate()

	/**
	 * The schedule number suffix: the month, or X and the date for an extra line.
	 *
	 * @param array{month: string, start: string, rateType: string} $entry The line entry.
	 *
	 * @return string
	 */
	private function lineSuffix(array $entry): string {
		if ($entry['rateType'] === self::EXTRA) {
			return 'X' . str_replace('-', '', $entry['start']);
		}

		return $entry['month'];

	}//end lineSuffix()

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
		$months = (($diff->y * 12) + $diff->m);
		if ($diff->invert === 1) {
			return -$months;
		}

		return $months;

	}//end monthsBetween()

	/**
	 * An empty string as null.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The value, or null when empty.
	 */
	private function orNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;

	}//end orNull()

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
