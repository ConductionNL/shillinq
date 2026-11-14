<?php

/**
 * Invoice Number Sequence Service
 *
 * Hands out the next number of an administration's invoice series. One
 * InvoiceNumberSequence record per administration, kind (`sales`, `billable`,
 * `credit`) and fiscal year holds the pattern and the last counter value
 * handed out. The counter is read, raised and written under an exclusive lock
 * per administration and kind, so two callers at the same moment never get
 * the same number; the second waits for the first.
 *
 * The series is read and written under OpenRegister's system identity: a
 * counter is the server's, never edited through a user's own rights.
 *
 * A year without a record starts at 1, with the pattern of the kind's most
 * recent year (or the kind's default), which is the yearly reset.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use RuntimeException;

/**
 * Takes the next number of an invoice series under a lock.
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 */
class InvoiceNumberSequenceService {

	/**
	 * The schema of a series.
	 *
	 * @var string
	 */
	public const SCHEMA = 'InvoiceNumberSequence';

	/**
	 * Sales invoices (ARInvoice).
	 *
	 * @var string
	 */
	public const KIND_SALES = 'sales';

	/**
	 * Time-and-expense invoices (BillableInvoice).
	 *
	 * @var string
	 */
	public const KIND_BILLABLE = 'billable';

	/**
	 * Credit notes.
	 *
	 * @var string
	 */
	public const KIND_CREDIT = 'credit';

	/**
	 * The pattern of a kind that has never had a series.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULT_PATTERNS = [
		self::KIND_SALES => InvoiceNumberPattern::DEFAULT,
		self::KIND_BILLABLE => 'BIL-{year}-{seq:4}',
		self::KIND_CREDIT => 'CN-{year}-{seq:4}',
	];

	/**
	 * How often a caller tries for a lock that is held.
	 *
	 * @var int
	 */
	private const LOCK_ATTEMPTS = 100;

	/**
	 * The pause between two attempts, in microseconds.
	 *
	 * @var int
	 */
	private const LOCK_PAUSE = 50000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation The register this app's objects live in.
	 * @param ILockingProvider       $locks            The lock per administration and kind.
	 * @param int                    $lockPause        Microseconds between two tries for a held lock.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly ILockingProvider $locks,
		private readonly int $lockPause = self::LOCK_PAUSE,
	) {
	}//end __construct()

	/**
	 * Take the next number of a series.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kind             `sales`, `billable` or `credit`.
	 * @param int    $year             The fiscal year.
	 *
	 * @return string The number, never handed out before.
	 *
	 * @throws InvalidArgumentException When the administration is empty or the kind unknown.
	 * @throws RuntimeException When the lock stays held or the series cannot be saved.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
	 */
	public function next(string $administrationId, string $kind, int $year): string {
		$this->requireSeries(administrationId: $administrationId, kind: $kind);

		$lock = $this->lockKey(administrationId: $administrationId, kind: $kind);
		$this->acquire(lock: $lock);
		try {
			return (string)$this->objectService->runAsSystem(
				function () use ($administrationId, $kind, $year): string {
					$sequence = $this->find(administrationId: $administrationId, kind: $kind, year: $year);
					$sequence = ($sequence ?? $this->fresh(administrationId: $administrationId, kind: $kind, year: $year));
					$pattern = new InvoiceNumberPattern(pattern: (string)($sequence['pattern'] ?? self::DEFAULT_PATTERNS[$kind]));
					$value = ((int)($sequence['lastValue'] ?? 0) + 1);
					$sequence['lastValue'] = $value;
					$this->save(sequence: $sequence);

					return $pattern->format(year: $year, sequence: $value);
				}
			);
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}//end next()

	/**
	 * Create a year's series, or raise its counter to at least a value, under the lock.
	 *
	 * Never lowers a counter, so a re-run cannot hand out a number again.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kind             `sales`, `billable` or `credit`.
	 * @param int    $year             The fiscal year.
	 * @param int    $lastValue        The highest counter value already in use.
	 *
	 * @return bool True when the series was created or raised.
	 *
	 * @throws InvalidArgumentException When the administration is empty or the kind unknown.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.1
	 */
	public function seed(string $administrationId, string $kind, int $year, int $lastValue): bool {
		$this->requireSeries(administrationId: $administrationId, kind: $kind);

		$lock = $this->lockKey(administrationId: $administrationId, kind: $kind);
		$this->acquire(lock: $lock);
		try {
			return (bool)$this->objectService->runAsSystem(
				function () use ($administrationId, $kind, $year, $lastValue): bool {
					$sequence = $this->find(administrationId: $administrationId, kind: $kind, year: $year);
					if ($sequence !== null && (int)($sequence['lastValue'] ?? 0) >= $lastValue) {
						return false;
					}

					$sequence = ($sequence ?? $this->fresh(administrationId: $administrationId, kind: $kind, year: $year));
					$sequence['lastValue'] = max(0, $lastValue);
					$this->save(sequence: $sequence);

					return true;
				}
			);
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}//end seed()

	/**
	 * The pattern of a kind's series: its most recent year's, else the kind's default.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kind             `sales`, `billable` or `credit`.
	 *
	 * @return string The pattern.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.1
	 */
	public function patternOf(string $administrationId, string $kind): string {
		$latest = null;
		foreach ($this->series(administrationId: $administrationId, kind: $kind) as $sequence) {
			if ($latest === null || (int)($sequence['fiscalYear'] ?? 0) > (int)($latest['fiscalYear'] ?? 0)) {
				$latest = $sequence;
			}
		}

		$pattern = trim((string)($latest['pattern'] ?? ''));
		if ($pattern === '') {
			return (self::DEFAULT_PATTERNS[$kind] ?? InvoiceNumberPattern::DEFAULT);
		}

		return $pattern;
	}//end patternOf()

	/**
	 * Refuse an empty administration or an unknown kind.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kind             The kind.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When either is not usable.
	 */
	private function requireSeries(string $administrationId, string $kind): void {
		if (trim($administrationId) === '') {
			throw new InvalidArgumentException('An invoice number needs an administration.');
		}

		if (isset(self::DEFAULT_PATTERNS[$kind]) === false) {
			throw new InvalidArgumentException("Unknown invoice number series '{$kind}'.");
		}
	}//end requireSeries()

	/**
	 * The lock of one administration's series of one kind.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kind             The kind.
	 *
	 * @return string The lock key.
	 */
	private function lockKey(string $administrationId, string $kind): string {
		return 'shillinq-invoice-seq-' . $administrationId . '-' . $kind;
	}//end lockKey()

	/**
	 * Take the lock, waiting while another caller holds it.
	 *
	 * @param string $lock The lock key.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the lock stays held.
	 */
	private function acquire(string $lock): void {
		for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
			try {
				$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'invoice number');
				return;
			} catch (LockedException) {
				if ($attempt < self::LOCK_ATTEMPTS) {
					usleep($this->lockPause);
				}
			}
		}

		throw new RuntimeException('The invoice number series is busy. Try issuing the invoice again.');
	}//end acquire()

	/**
	 * The series of one year, or null.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kind             The kind.
	 * @param int    $year             The fiscal year.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(string $administrationId, string $kind, int $year): ?array {
		foreach ($this->series(administrationId: $administrationId, kind: $kind) as $sequence) {
			if ((int)($sequence['fiscalYear'] ?? 0) === $year) {
				return $sequence;
			}
		}

		return null;
	}//end find()

	/**
	 * Every year's series of one administration and kind.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kind             The kind.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function series(string $administrationId, string $kind): array {
		$rows = $this->objectService
			->setRegister($this->registerLocation->getRegisterSlug())
			->setSchema(self::SCHEMA)
			->findAll(['filters' => ['administrationId' => $administrationId, 'kind' => $kind]]);

		$series = [];
		foreach ($rows as $row) {
			$sequence = ObjectIdentifier::recordWithId(candidate: $row);
			// The filter is the store's; the match is checked here too, so a
			// store that ignores a filter cannot hand out another series' number.
			if ($sequence !== null
				&& (string)($sequence['administrationId'] ?? '') === $administrationId
				&& (string)($sequence['kind'] ?? '') === $kind
			) {
				$series[] = $sequence;
			}
		}

		return $series;
	}//end series()

	/**
	 * A new year's series at 0, with the kind's current pattern.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kind             The kind.
	 * @param int    $year             The fiscal year.
	 *
	 * @return array<string, mixed>
	 */
	private function fresh(string $administrationId, string $kind, int $year): array {
		return [
			'administrationId' => $administrationId,
			'kind' => $kind,
			'pattern' => $this->patternOf(administrationId: $administrationId, kind: $kind),
			'fiscalYear' => $year,
			'lastValue' => 0,
		];
	}//end fresh()

	/**
	 * Write a series.
	 *
	 * @param array<string, mixed> $sequence The series.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the store refuses it.
	 */
	private function save(array $sequence): void {
		$uuid = null;
		if ((string)($sequence['id'] ?? '') !== '') {
			$uuid = (string)$sequence['id'];
		}

		try {
			$this->objectService->saveObject(
				object: $sequence,
				register: $this->registerLocation->getRegisterSlug(),
				schema: self::SCHEMA,
				uuid: $uuid
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('The invoice number series could not be saved: ' . $e->getMessage(), 0, $e);
		}
	}//end save()
}//end class
