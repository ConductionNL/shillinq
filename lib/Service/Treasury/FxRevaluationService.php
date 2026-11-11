<?php

/**
 * Shillinq FX Period-End Revaluation Service
 *
 * Fills the `SoftCloseExecutor::delegateFxRevaluation()` delegate contract
 * (`lib/Service/SoftCloseExecutor.php` ~line 406) that has probed the DI
 * container for `OCA\Shillinq\Service\Treasury\FxRevaluationService` since
 * `bookkeeping-soft-close-flux` shipped, but always returned 0 postings
 * because this class never existed (REQ-CLS-002 step 2, REQ-CLS-010).
 *
 * At period-end, every open `FXPosition` (AR/AP, bank/cash held in a
 * foreign currency — REQ-MC-006) is marked to the administratie's
 * `Administration.functionalCurrency` closing rate. The closing rate
 * prefers a live `TreasuryRateService::getFxSpot()` snapshot and falls
 * back to the position's own manually-maintained `spotRate` when the rate
 * adapter is dormant (REQ-MC-007) — the exact fallback
 * `LogTreasuryRateAdapter`'s own docblock documents. The incremental
 * unrealised movement since the position's last mark is posted as an
 * auditable `FxRevaluationPosting` record (REQ-MC-008) when material;
 * `FXPosition.spotRate`/`fairValue`/`unrealisedPL` are always refreshed.
 *
 * Per ADR-031 this is the imperative orchestration exception design.md
 * documents: an external-rate lookup with a stateful live/dormant branch,
 * iteration across an unbounded set of `FXPosition` records, and a
 * cross-schema read (`Administration.functionalCurrency`) to parameterise
 * the calculation — none of which is expressible as a single OR
 * calculation formula.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Period-end FX revaluation of open FXPosition balances (ADR-031 exception).
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 */
class FxRevaluationService {
	/**
	 * Marker used for the postedBy field on FxRevaluationPosting records
	 * (REQ-MC-008). REQ-CLS-010 permits either the orchestrator
	 * (`SoftCloseExecutor::SYSTEM_ACTOR`) or "specific service" as the
	 * posting actor; this service attributes to itself.
	 *
	 * @var string
	 */
	public const SYSTEM_ACTOR = 'SYSTEM:FxRevaluationService';

	/**
	 * Fallback functional currency when Administration.functionalCurrency
	 * cannot be resolved.
	 *
	 * @var string
	 */
	private const DEFAULT_FUNCTIONAL_CURRENCY = 'EUR';

	/**
	 * Materiality floor, in functional-currency cents. Movements below
	 * this refresh the FXPosition mark but do not post (REQ-MC-006
	 * scenario 3).
	 *
	 * @var int
	 */
	private const MATERIALITY_CENTS = 1;

	/**
	 * Documented IAppConfig defaults for GL account attribution (design.md
	 * "GL account configuration").
	 *
	 * @var string
	 */
	private const DEFAULT_GAIN_ACCOUNT = '8020';
	private const DEFAULT_LOSS_ACCOUNT = '8021';
	private const DEFAULT_ADJUSTMENT_ACCOUNT = '1699';

	/**
	 * Construct the FX period-end revaluation service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug + GL account overrides.
	 * @param TreasuryRateService $treasuryRateService Rate-lookup facade (REQ-MC-007).
	 * @param LoggerInterface $logger Structured logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly TreasuryRateService $treasuryRateService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Revalue every open FXPosition for one administratie + period
	 * (REQ-MC-006). Satisfies the exact contract
	 * `SoftCloseExecutor::delegateFxRevaluation()` already reads:
	 * `array{postingCount: int, ...}`.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm business period identifier.
	 *
	 * @return array{postingCount:int,positionsEvaluated:int,functionalCurrency:string,periodId:string}
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
	 */
	public function reval(string $administrationId, string $periodId): array {
		$periodEnd = $this->periodEndDate(periodId: $periodId);
		if ($periodEnd === null) {
			return $this->skipInvalidPeriod(periodId: $periodId);
		}

		$run = new FxRevaluationRun(
			administrationId: $administrationId,
			periodId: $periodId,
			functionalCurrency: $this->functionalCurrencyFor(administrationId: $administrationId),
			periodEndDate: $periodEnd,
			asOf: new DateTimeImmutable()
		);

		return $this->revalueOpenPositions(run: $run);
	}//end reval()

	/**
	 * Log an invalid periodId and report that nothing was revalued.
	 *
	 * @param string $periodId The rejected period identifier.
	 *
	 * @return array{postingCount:int,positionsEvaluated:int,functionalCurrency:string,periodId:string}
	 */
	private function skipInvalidPeriod(string $periodId): array {
		$this->logger->warning(
			'FxRevaluationService: invalid periodId — skipping revaluation',
			['periodId' => $periodId]
		);

		return [
			'postingCount' => 0,
			'positionsEvaluated' => 0,
			'functionalCurrency' => '',
			'periodId' => $periodId,
		];
	}//end skipInvalidPeriod()

	/**
	 * Revalue every open FXPosition of the run's administration and count the evaluations and postings.
	 *
	 * @param FxRevaluationRun $run The revaluation run.
	 *
	 * @return array{postingCount:int,positionsEvaluated:int,functionalCurrency:string,periodId:string}
	 */
	private function revalueOpenPositions(FxRevaluationRun $run): array {
		$result = [
			'postingCount' => 0,
			'positionsEvaluated' => 0,
			'functionalCurrency' => $run->functionalCurrency,
			'periodId' => $run->periodId,
		];

		foreach ($this->findOpenPositions(administrationId: $run->administrationId) as $position) {
			$result['positionsEvaluated']++;
			if ($this->revaluatePosition(position: $position, run: $run) === true) {
				$result['postingCount']++;
			}
		}

		return $result;
	}//end revalueOpenPositions()

	/**
	 * Revalue one FXPosition; update its mark and, when the movement is
	 * material, persist an FxRevaluationPosting audit record.
	 *
	 * @param array<string,mixed> $position The FXPosition record.
	 * @param FxRevaluationRun $run The revaluation run.
	 *
	 * @return bool True when an FxRevaluationPosting was created.
	 */
	private function revaluatePosition(array $position, FxRevaluationRun $run): bool {
		if ($this->isRevaluable(position: $position, functionalCurrency: $run->functionalCurrency) === false) {
			return false;
		}

		$resolved = $this->resolveClosingRate(position: $position, run: $run);
		if ($resolved === null) {
			return $this->skipWithoutClosingRate(position: $position, run: $run);
		}

		$mark = new FxPositionMark(position: $position, closingRate: $resolved['rate'], rateSource: $resolved['source']);
		return $this->markPosition(mark: $mark, run: $run);
	}//end revaluatePosition()

	/**
	 * Whether a position is revalued: it holds a foreign currency other than the functional one, and a non-zero amount.
	 *
	 * @param array<string,mixed> $position The FXPosition record.
	 * @param string $functionalCurrency Administration's functional currency.
	 *
	 * @return bool
	 */
	private function isRevaluable(array $position, string $functionalCurrency): bool {
		$foreignCurrency = (string)($position['foreignCurrency'] ?? '');
		if ($foreignCurrency === '' || $foreignCurrency === $functionalCurrency) {
			return false;
		}

		return ((float)($position['position'] ?? 0.0) === 0.0) === false;
	}//end isRevaluable()

	/**
	 * Log a position that has no live or manual closing rate; it is skipped.
	 *
	 * @param array<string,mixed> $position The FXPosition record.
	 * @param FxRevaluationRun $run The revaluation run.
	 *
	 * @return bool Always false: nothing was posted.
	 */
	private function skipWithoutClosingRate(array $position, FxRevaluationRun $run): bool {
		$this->logger->info(
			'FxRevaluationService: no live or manual closing rate available — skipping position',
			['administrationId' => $run->administrationId, 'foreignCurrency' => (string)($position['foreignCurrency'] ?? '')]
		);
		return false;
	}//end skipWithoutClosingRate()

	/**
	 * Store the position's new mark and, when it moved materially since a prior mark, post the revaluation.
	 *
	 * @param FxPositionMark $mark The new mark.
	 * @param FxRevaluationRun $run The revaluation run.
	 *
	 * @return bool True when an FxRevaluationPosting was created.
	 */
	private function markPosition(FxPositionMark $mark, FxRevaluationRun $run): bool {
		$this->persistPosition(
			position: $mark->position,
			spotRate: $mark->closingRate,
			fairValue: $mark->newFairValue(),
			unrealisedPL: $mark->newUnrealisedPL(),
			asOf: $run->asOf
		);

		// First-ever mark: establish the baseline only, post nothing
		// (REQ-MC-006 scenario 2 — no prior mark means no delta to report).
		if ($mark->priorSpotRate() === null || abs($mark->deltaCents()) < self::MATERIALITY_CENTS) {
			return false;
		}

		$this->postRevaluation(mark: $mark, run: $run);
		return true;
	}//end markPosition()

	/**
	 * Persist the FxRevaluationPosting of a material movement.
	 *
	 * @param FxPositionMark $mark The new mark (with a prior mark).
	 * @param FxRevaluationRun $run The revaluation run.
	 *
	 * @return void
	 */
	private function postRevaluation(FxPositionMark $mark, FxRevaluationRun $run): void {
		$this->persistRevaluationPosting(
			position: $mark->position,
			administrationId: $run->administrationId,
			periodId: $run->periodId,
			functionalCurrency: $run->functionalCurrency,
			netPosition: $mark->netPosition(),
			priorRate: (float)$mark->priorSpotRate(),
			closingRate: $mark->closingRate,
			rateSource: $mark->rateSource,
			deltaCents: $mark->deltaCents(),
			asOf: $run->asOf
		);
	}//end postRevaluation()

	/**
	 * Resolve the closing rate for one FXPosition (REQ-MC-007): prefer a
	 * live TreasuryRateService snapshot, fall back to the position's own
	 * manually-maintained spotRate, otherwise return null.
	 *
	 * @param array<string,mixed> $position The FXPosition record.
	 * @param FxRevaluationRun $run The revaluation run (functional currency and period-end date).
	 *
	 * @return array{rate:float,source:string}|null
	 */
	private function resolveClosingRate(array $position, FxRevaluationRun $run): ?array {
		$snapshot = $this->liveSnapshot(foreignCurrency: (string)($position['foreignCurrency'] ?? ''), run: $run);
		if ($snapshot !== null && $snapshot->isLive() === true) {
			return ['rate' => $snapshot->asFloat(), 'source' => 'live'];
		}

		$manualRate = $position['spotRate'] ?? null;
		if ($manualRate !== null && (float)$manualRate > 0.0) {
			return ['rate' => (float)$manualRate, 'source' => 'manual-fallback'];
		}

		return null;
	}//end resolveClosingRate()

	/**
	 * Look up the period-end spot rate of a foreign currency; null when the lookup throws.
	 *
	 * @param string $foreignCurrency The position's foreign currency.
	 * @param FxRevaluationRun $run The revaluation run (functional currency and period-end date).
	 *
	 * @return TreasuryRateSnapshot|null
	 */
	private function liveSnapshot(string $foreignCurrency, FxRevaluationRun $run): ?TreasuryRateSnapshot {
		try {
			return $this->treasuryRateService->getFxSpot(
				$foreignCurrency,
				$run->functionalCurrency,
				$run->periodEndDate->format('Y-m-d')
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'FxRevaluationService: TreasuryRateService::getFxSpot threw — falling back to manual rate',
				['foreignCurrency' => $foreignCurrency, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end liveSnapshot()

	/**
	 * Persist the updated FXPosition mark (spotRate, fairValue,
	 * unrealisedPL, lastUpdated). Upserts via the record's own identity
	 * when present, mirroring `SoftCloseExecutor::markSoftClosed()`'s
	 * find-then-modify-then-save pattern.
	 *
	 * @param array<string,mixed> $position The FXPosition record (may carry an existing id).
	 * @param float $spotRate New closing rate.
	 * @param float $fairValue New fair value (position × spotRate).
	 * @param float $unrealisedPL New cumulative unrealised P&L.
	 * @param DateTimeImmutable $asOf Run timestamp.
	 *
	 * @return void
	 */
	private function persistPosition(
		array $position,
		float $spotRate,
		float $fairValue,
		float $unrealisedPL,
		DateTimeImmutable $asOf,
	): void {
		$record = $position;
		$record['spotRate'] = $spotRate;
		$record['fairValue'] = $fairValue;
		$record['unrealisedPL'] = $unrealisedPL;
		$record['lastUpdated'] = $asOf->format(DateTimeInterface::ATOM);

		$this->objectService->saveObject(
			object: $record,
			register: $this->register(),
			schema: 'FXPosition',
		);

	}//end persistPosition()

	/**
	 * Persist one FxRevaluationPosting audit record (REQ-MC-008).
	 *
	 * @param array<string,mixed> $position The source FXPosition record.
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 * @param string $functionalCurrency Administration's functional currency.
	 * @param float $netPosition Snapshot of position.position.
	 * @param float $priorRate Rate before this revaluation.
	 * @param float $closingRate Rate applied this period.
	 * @param string $rateSource 'live' or 'manual-fallback'.
	 * @param int $deltaCents Incremental movement, functional-currency cents.
	 * @param DateTimeImmutable $asOf Run timestamp.
	 *
	 * @return void
	 */
	private function persistRevaluationPosting(
		array $position,
		string $administrationId,
		string $periodId,
		string $functionalCurrency,
		float $netPosition,
		float $priorRate,
		float $closingRate,
		string $rateSource,
		int $deltaCents,
		DateTimeImmutable $asOf,
	): void {
		$direction = 'loss';
		if ($deltaCents >= 0) {
			$direction = 'gain';
		}

		$targetGLAccount = $this->glAccount(key: 'fx_revaluation_loss_account', default: self::DEFAULT_LOSS_ACCOUNT);
		if ($direction === 'gain') {
			$targetGLAccount = $this->glAccount(key: 'fx_revaluation_gain_account', default: self::DEFAULT_GAIN_ACCOUNT);
		}

		$posting = [
			'administrationId' => $administrationId,
			'periodId' => $periodId,
			'positionId' => $this->positionId(position: $position),
			'foreignCurrency' => (string)($position['foreignCurrency'] ?? ''),
			'functionalCurrency' => $functionalCurrency,
			'netPosition' => $netPosition,
			'priorRate' => $priorRate,
			'closingRate' => $closingRate,
			'rateSource' => $rateSource,
			'unrealisedDeltaCents' => $deltaCents,
			'direction' => $direction,
			'targetGLAccount' => $targetGLAccount,
			'contraGLAccount' => $this->glAccount(
				key: 'fx_revaluation_adjustment_account',
				default: self::DEFAULT_ADJUSTMENT_ACCOUNT
			),
			'journalEntryId' => $this->journalEntryId(position: $position, periodId: $periodId, asOf: $asOf),
			'postedAt' => $asOf->format(DateTimeInterface::ATOM),
			'postedBy' => self::SYSTEM_ACTOR,
			'reversalId' => null,
			'reversalState' => 'posted',
		];

		$this->objectService->saveObject(
			object: $posting,
			register: $this->register(),
			schema: 'FxRevaluationPosting',
		);

	}//end persistRevaluationPosting()

	/**
	 * Resolve the administratie's functional currency (REQ-MC-006).
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return string ISO 4217 functional currency; 'EUR' when unresolvable.
	 */
	private function functionalCurrencyFor(string $administrationId): string {
		try {
			// NOT findAll(['filters' => ['id' => …]]) — that addresses JSON
			// properties and the entity's `id` is not one, so it matched
			// nothing for every administration and this method silently
			// returned the EUR default for all of them, ignoring whatever
			// functionalCurrency was actually configured.
			$admin = ObjectIdentifier::findOne(
				scoped: $this->objectService
					->setRegister($this->register())
					->setSchema('Administration'),
				id: $administrationId,
				fallbackProperty: 'administrationCode'
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'FxRevaluationService: failed to resolve Administration.functionalCurrency — defaulting to EUR',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return self::DEFAULT_FUNCTIONAL_CURRENCY;
		}

		if ($admin === null) {
			return self::DEFAULT_FUNCTIONAL_CURRENCY;
		}

		$currency = (string)($admin['functionalCurrency'] ?? '');
		if ($currency === '') {
			return self::DEFAULT_FUNCTIONAL_CURRENCY;
		}

		return $currency;
	}//end functionalCurrencyFor()

	/**
	 * Find every FXPosition record for an administratie.
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return array<int,array<string,mixed>> The positions (possibly empty).
	 */
	private function findOpenPositions(string $administrationId): array {
		try {
			$found = $this->objectService
				->setRegister($this->register())
				->setSchema('FXPosition')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
		} catch (Throwable $e) {
			$this->logger->error(
				'FxRevaluationService: failed to load FXPosition records',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return [];
		}


		return array_values(array_map(fn ($row): array => $this->asArray(row: $row), $found));
	}//end findOpenPositions()

	/**
	 * Resolve the identity of an FXPosition record for FxRevaluationPosting.positionId.
	 *
	 * @param array<string,mixed> $position The FXPosition record.
	 *
	 * @return string The position identity (id, slug, or a deterministic fallback).
	 */
	private function positionId(array $position): string {
		$id = (string)($position['id'] ?? '');
		if ($id !== '') {
			return $id;
		}

		$slug = (string)($position['slug'] ?? '');
		if ($slug !== '') {
			return $slug;
		}

		return sprintf(
			'fxpos-%s-%s',
			strtolower((string)($position['foreignCurrency'] ?? 'unknown')),
			(string)($position['administrationId'] ?? 'unknown')
		);

	}//end positionId()

	/**
	 * Synthesize a deterministic journal-entry linkage id, mirroring
	 * `SoftCloseExecutor::journalEntryId()` — no literal JournalEntry
	 * object is created (REQ-MC-008 / design.md "GL account configuration").
	 *
	 * @param array<string,mixed> $position The FXPosition record.
	 * @param string $periodId The yyyy-mm period.
	 * @param DateTimeImmutable $asOf Run timestamp.
	 *
	 * @return string The journal-entry linkage id.
	 */
	private function journalEntryId(array $position, string $periodId, DateTimeImmutable $asOf): string {
		$currency = (string)($position['foreignCurrency'] ?? 'fx');
		$slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $currency) ?? 'fx');
		return sprintf('je-%s-fx-%s-%s', $periodId, trim($slug, '-'), $asOf->format('d'));
	}//end journalEntryId()

	/**
	 * Resolve one configurable GL account code with a documented default.
	 *
	 * @param string $key IAppConfig key.
	 * @param string $default Documented default account code.
	 *
	 * @return string The account code.
	 */
	private function glAccount(string $key, string $default): string {
		$configured = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, $default);
		if ($configured === '') {
			return $default;
		}

		return $configured;
	}//end glAccount()

	/**
	 * Normalise an OpenRegister ObjectService row (ObjectEntity or array) to
	 * a plain array<string,mixed>, mirroring
	 * `AdministrationContextService::asArray()`.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll().
	 *
	 * @return array<string,mixed> The object as an array (empty array when unusable).
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		return [];
	}//end asArray()

	/**
	 * Split a yyyy-mm period id into its calendar-month-end date.
	 *
	 * @param string $periodId The period identifier.
	 *
	 * @return DateTimeImmutable|null The period-end date; null on parse failure.
	 */
	private function periodEndDate(string $periodId): ?DateTimeImmutable {
		$matched = preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periodId, $parts);
		if ($matched !== 1) {
			return null;
		}

		try {
			$firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', (int)$parts[1], (int)$parts[2]));
			return $firstOfMonth->modify('last day of this month');
		} catch (Throwable $e) {
			return null;
		}

	}//end periodEndDate()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
