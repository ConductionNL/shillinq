<?php

/**
 * Shillinq Treasury Rate Service
 *
 * Consumer of the `TreasuryRateAdapterInterface` port that the
 * bookkeeping-treasury-ihb surface uses to fetch floating reference-rate
 * snapshots (EURIBOR-3M, SOFR, SARON, ESTR — REQ-IHB-004 / Task 15) and
 * FX-spot rates (REQ-IHB-006 / Task 17 + REQ-IHB-010 / Task 22). The
 * service centralises three concerns the adapter MUST NOT own:
 *
 *  1. Dormancy handling — when the adapter returns SNAPSHOT_DEFERRED,
 *     callers MUST branch on the dormant flag rather than the synthetic
 *     `'0'` rate value. The service surfaces that decision through a
 *     boolean predicate (`hasLiveSnapshot()`) and converts the result
 *     into a typed value object (`TreasuryRateSnapshot`) carrying the
 *     dormancy flag so the calling lifecycle code does not have to
 *     remember to inspect both the adapter result `status` and the
 *     synthetic value.
 *  2. Per-request memoisation — within a single PHP request, the same
 *     (rateCode, asOf) tuple may be looked up multiple times (an
 *     IntercompanyLoan accrual aggregation walks every line; a
 *     LiquidityKPI aggregation walks every administration). The service
 *     keeps an in-memory cache keyed on the tuple so the adapter is hit
 *     once per request.
 *  3. Caller-side error handling — adapter implementations MAY throw
 *     when transport fails; the lifecycle code MUST NOT crash, so the
 *     service catches and converts to a dormant snapshot with an audit
 *     line.
 *
 * Per ADR-031 this service is the single-method orchestration exception
 * the treasury-ihb spec design.md D3 contract permits: the
 * IntercompanyLoan interest-accrual aggregation, FXPosition mark-to-
 * market aggregation, and LiquidityKPI consolidation aggregation all
 * read through this service so the dormancy + caching concerns stay in
 * exactly one place. The aggregations themselves remain declarative.
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
 * @spec openspec/changes/bookkeeping-treasury-ihb/tasks.md#external-adapter
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Consumer-side facade over `TreasuryRateAdapterInterface`.
 *
 * @spec openspec/changes/bookkeeping-treasury-ihb/tasks.md#external-adapter
 */
class TreasuryRateService {
	/**
	 * Sentinel status returned by the dormant adapter.
	 */
	public const STATUS_DEFERRED = 'SNAPSHOT_DEFERRED';

	/**
	 * Per-request memoisation cache.
	 *
	 * @var array<string, TreasuryRateSnapshot>
	 */
	private array $cache = [];

	/**
	 * Construct the consumer-side facade.
	 *
	 * @param TreasuryRateAdapterInterface $adapter The port (dormant default = LogTreasuryRateAdapter).
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly TreasuryRateAdapterInterface $adapter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch a floating reference-rate snapshot for a given as-of date.
	 *
	 * Returns a `TreasuryRateSnapshot` carrying both the decimal value
	 * and the dormant flag. Callers MUST inspect `$snapshot->isLive()`
	 * before applying the value to an accrual posting — the dormant
	 * branch indicates the IntercompanyLoan manual-entry path (REQ-IHB-004)
	 * should be used as the fallback.
	 *
	 * @param string $rateCode Reference-rate code (EURIBOR-3M, SOFR, etc.).
	 * @param string $asOf ISO-8601 snapshot date (YYYY-MM-DD).
	 *
	 * @return TreasuryRateSnapshot The snapshot value object.
	 */
	public function getReferenceRate(string $rateCode, string $asOf): TreasuryRateSnapshot {
		$key = 'ref|' . $rateCode . '|' . $asOf;
		if (isset($this->cache[$key]) === true) {
			return $this->cache[$key];
		}

		try {
			$result = $this->adapter->fetchReferenceRate($rateCode, $asOf);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq TreasuryRateService: reference-rate fetch threw — emitting dormant snapshot',
				['rateCode' => $rateCode, 'asOf' => $asOf, 'exception' => $e->getMessage()]
			);
			$result = $this->syntheticDeferred(rateCode: $rateCode, asOf: $asOf);
		}

		$snapshot = $this->resultToSnapshot(result: $result, rateCode: $rateCode, asOf: $asOf);
		$this->cache[$key] = $snapshot;
		return $snapshot;
	}//end getReferenceRate()

	/**
	 * Fetch an FX-spot snapshot for a currency pair on a given as-of date.
	 *
	 * @param string $baseCurrency ISO 4217 base currency (e.g. EUR).
	 * @param string $quoteCurrency ISO 4217 quote currency (e.g. USD).
	 * @param string $asOf ISO-8601 snapshot date.
	 *
	 * @return TreasuryRateSnapshot The snapshot value object.
	 */
	public function getFxSpot(string $baseCurrency, string $quoteCurrency, string $asOf): TreasuryRateSnapshot {
		$pair = $baseCurrency . '/' . $quoteCurrency;
		$key = 'fx|' . $pair . '|' . $asOf;
		if (isset($this->cache[$key]) === true) {
			return $this->cache[$key];
		}

		try {
			$result = $this->adapter->fetchFxSpot($baseCurrency, $quoteCurrency, $asOf);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq TreasuryRateService: FX-spot fetch threw — emitting dormant snapshot',
				['pair' => $pair, 'asOf' => $asOf, 'exception' => $e->getMessage()]
			);
			$result = $this->syntheticDeferred(rateCode: $pair, asOf: $asOf);
		}

		$snapshot = $this->resultToSnapshot(result: $result, rateCode: $pair, asOf: $asOf);
		$this->cache[$key] = $snapshot;
		return $snapshot;
	}//end getFxSpot()

	/**
	 * Whether the surrounding lifecycle code will see live snapshots.
	 *
	 * Cheap proxy over the adapter's dormancy flag — useful for the
	 * declarative aggregation hosts (interest-accrual, FX revaluation,
	 * liquidity-KPI) to short-circuit a noisy walk when nothing will
	 * actually update.
	 *
	 * @return bool TRUE when the adapter is bound to a live source.
	 */
	public function hasLiveSnapshotSource(): bool {
		return ($this->adapter->isDormant() === false);
	}//end hasLiveSnapshotSource()

	/**
	 * Drop the per-request memoisation cache.
	 *
	 * @return void
	 */
	public function resetCache(): void {
		$this->cache = [];
	}//end resetCache()

	/**
	 * Convert an adapter result into the typed snapshot value object,
	 * applying the dormant-or-deferred decision rule.
	 *
	 * @param TreasuryRateResult $result The adapter outcome.
	 * @param string $rateCode Original rate code (audit / log only).
	 * @param string $asOf ISO-8601 snapshot date.
	 *
	 * @return TreasuryRateSnapshot
	 */
	private function resultToSnapshot(
		TreasuryRateResult $result,
		string $rateCode,
		string $asOf,
	): TreasuryRateSnapshot {
		$deferred = ($result->dormant === true || $result->status === self::STATUS_DEFERRED);
		if ($deferred === true) {
			return new TreasuryRateSnapshot(
				value: '0',
				source: $result->source,
				asOf: $asOf,
				rateCode: $rateCode,
				dormant: true,
				rateId: $result->rateId,
			);
		}

		return new TreasuryRateSnapshot(
			value: $result->value,
			source: $result->source,
			asOf: $asOf,
			rateCode: $rateCode,
			dormant: false,
			rateId: $result->rateId,
		);
	}//end resultToSnapshot()

	/**
	 * Synthesise a SNAPSHOT_DEFERRED adapter result locally — used when
	 * the adapter throws, so the caller still receives a consistent
	 * envelope without having to handle exceptions of its own.
	 *
	 * @param string $rateCode Rate code or currency pair.
	 * @param string $asOf ISO-8601 snapshot date.
	 *
	 * @return TreasuryRateResult
	 */
	private function syntheticDeferred(string $rateCode, string $asOf): TreasuryRateResult {
		return new TreasuryRateResult(
			status: self::STATUS_DEFERRED,
			rateId: 'tr_synth_deferred',
			rateCode: $rateCode,
			value: '0',
			asOf: $asOf,
			source: 'LOG_DEFERRED',
			dormant: true,
			extras: ['reason' => 'adapter-threw'],
		);
	}//end syntheticDeferred()
}//end class
