<?php

/**
 * Credit Score Service
 *
 * REQ-CCD-007. Optional external credit-score fetcher (Graydon / Creditsafe /
 * Atradius Insights). Cached for dunning.credit_score_cache_days days (default
 * 30); a fresh snapshot is fetched via openconnector only when the cache window
 * elapses. Returns a warning-shape when the score sits below the configured
 * threshold so the UI can prompt for vooruitbetaling or factoring.
 *
 * Per ADR-031 the schema is the source of truth; this service is the thin PHP
 * seam that orchestrates the cache-vs-fetch decision and translates the
 * provider score into a UI-ready warning payload.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Optional credit-score integration. Uses the real OR ObjectService API.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 */
class CreditScoreService {
	/**
	 * App-config key for the cache window (days).
	 */
	private const CFG_CACHE_DAYS = 'dunning.credit_score_cache_days';

	/**
	 * App-config key for the warning threshold (decimal on the active scoreSchaal).
	 */
	private const CFG_WARNING_THRESHOLD = 'dunning.credit_score_warning_threshold';

	/**
	 * Construct the credit-score service with its dependencies.
	 *
	 * @param ContainerInterface $container DI for OR ObjectService.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 * @param CreditScoreFetchAdapterInterface $fetch Outbound credit-score fetch port (task-19).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly CreditScoreFetchAdapterInterface $fetch,
	) {
	}//end __construct()

	/**
	 * Fetch the most recent CreditScore for a klant, refreshing when stale.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $customerId Customer FK.
	 * @param string $provider GRAYDON / CREDITSAFE / ATRADIUS_INSIGHTS.
	 *
	 * @return array<string,mixed>|null The score record, null when none is available.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
	 */
	public function getOrRefresh(string $administrationId, string $customerId, string $provider): ?array {
		$query = new CreditScoreQuery();
		$query->administrationId = $administrationId;
		$query->customerId = $customerId;
		$query->provider = $provider;
		$cached = $this->latestForCustomer(query: $query);
		if ($cached !== null && $this->isFresh(score: $cached) === true) {
			return $cached;
		}

		$fresh = $this->fetchFresh(query: $query);
		if ($fresh === null) {
			return $cached;
		}

		return $this->persistFresh(score: $this->normalised(fresh: $fresh, query: $query));

	}//end getOrRefresh()

	/**
	 * Cache miss / stale — dispatch via the bound fetch adapter
	 * (LogCreditScoreFetchAdapter by default — swap for an
	 * openconnector-backed implementation in production). A throwing or
	 * empty adapter is logged and yields null, so the caller falls back to
	 * the stale cache and can still surface what's known.
	 *
	 * @param CreditScoreQuery $query The lookup.
	 *
	 * @return array<string,mixed>|null The fresh snapshot, or null.
	 */
	private function fetchFresh(CreditScoreQuery $query): ?array {
		$fresh = null;
		try {
			$fresh = $this->fetch->fetch(administrationId: $query->administrationId, customerId: $query->customerId, provider: $query->provider);
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: CreditScoreFetchAdapter::fetch threw — falling back to cache: ' . $e->getMessage());
		}

		if ($fresh === null) {
			$this->logger->info(
				'Shillinq: CreditScore live refresh unavailable for ' . $query->customerId . ' / ' . $query->provider . '; using cached snapshot.'
			);
		}

		return $fresh;

	}//end fetchFresh()

	/**
	 * Fill the lookup's keys and today's date into a fresh snapshot where the
	 * provider left them out.
	 *
	 * @param array<string,mixed> $fresh The fresh snapshot.
	 * @param CreditScoreQuery    $query The lookup.
	 *
	 * @return array<string,mixed>
	 */
	private function normalised(array $fresh, CreditScoreQuery $query): array {
		$fresh['administrationId'] = ($fresh['administrationId'] ?? $query->administrationId);
		$fresh['customerId'] = ($fresh['customerId'] ?? $query->customerId);
		$fresh['provider'] = ($fresh['provider'] ?? $query->provider);
		if (isset($fresh['scoreDate']) === false || (string)$fresh['scoreDate'] === '') {
			$fresh['scoreDate'] = (new DateTimeImmutable())->format('Y-m-d');
		}

		return $fresh;

	}//end normalised()

	/**
	 * Persist the fresh snapshot so the next call hits the cache; return the
	 * stored record, or the in-memory snapshot when the save yields nothing.
	 *
	 * @param array<string,mixed> $score The normalised snapshot.
	 *
	 * @return array<string,mixed>
	 */
	private function persistFresh(array $score): array {
		try {
			return ($this->saveScore(score: $score) ?? $score);
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: failed to persist fresh CreditScore — returning in-memory snapshot: ' . $e->getMessage());
			return $score;
		}

	}//end persistFresh()

	/**
	 * Persist a CreditScore snapshot via the canonical OR ObjectService API.
	 *
	 * @param array<string,mixed> $score The snapshot to persist.
	 *
	 * @return array<string,mixed>|null
	 */
	private function saveScore(array $score): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$saved = $objectService
				->setRegister($this->register())
				->setSchema('CreditScore')
				->saveObject($score);
			if (is_array($saved) === true) {
				return $saved;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: CreditScoreService::saveScore failed: ' . $e->getMessage());
			return null;
		}

	}//end saveScore()

	/**
	 * Render a UI warning payload for an invoice when the klant has a low score.
	 *
	 * @param array<string,mixed>|null $score The CreditScore record.
	 * @param float $invoiceAmount Invoice principal (EUR).
	 *
	 * @return array{warning:bool,message:string,creditLimitAdvice:?float,deelfacturatieAdvies:bool}
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
	 */
	public function evaluateForInvoice(?array $score, float $invoiceAmount): array {
		if ($score === null) {
			return $this->quietVerdict(limit: null);
		}

		$belowThreshold = $this->isBelowThreshold(score: $score);
		$limit = $this->creditLimitAdvice(score: $score);
		$overLimit = $this->exceedsLimit(invoiceAmount: $invoiceAmount, limit: $limit);
		if ($belowThreshold === false && $overLimit === false) {
			return $this->quietVerdict(limit: $limit);
		}

		return ['warning' => true, 'message' => $this->lowScoreMessage(score: $score), 'creditLimitAdvice' => $limit, 'deelfacturatieAdvies' => $overLimit];

	}//end evaluateForInvoice()

	/**
	 * The verdict without a warning.
	 *
	 * @param float|null $limit The advised credit limit, if any.
	 *
	 * @return array{warning:bool,message:string,creditLimitAdvice:?float,deelfacturatieAdvies:bool}
	 */
	private function quietVerdict(?float $limit): array {
		return ['warning' => false, 'message' => '', 'creditLimitAdvice' => $limit, 'deelfacturatieAdvies' => false];

	}//end quietVerdict()

	/**
	 * Whether the score is below the configured warning threshold.
	 *
	 * @param array<string,mixed> $score The CreditScore record.
	 *
	 * @return boolean
	 */
	private function isBelowThreshold(array $score): bool {
		$threshold = (float)$this->appConfig->getValueString(AppIdentity::APP_ID, self::CFG_WARNING_THRESHOLD, '3.0');

		return ((float)($score['score'] ?? 0.0) < $threshold);

	}//end isBelowThreshold()

	/**
	 * The provider's advised credit limit, when the record carries one.
	 *
	 * @param array<string,mixed> $score The CreditScore record.
	 *
	 * @return float|null
	 */
	private function creditLimitAdvice(array $score): ?float {
		if (isset($score['creditLimitAdvice']) === true) {
			return (float)$score['creditLimitAdvice'];
		}

		return null;

	}//end creditLimitAdvice()

	/**
	 * Whether the invoice exceeds the advised credit limit, if there is one.
	 *
	 * @param float      $invoiceAmount Invoice principal (EUR).
	 * @param float|null $limit         The advised credit limit, if any.
	 *
	 * @return boolean
	 */
	private function exceedsLimit(float $invoiceAmount, ?float $limit): bool {
		return ($limit !== null && $invoiceAmount > $limit);

	}//end exceedsLimit()

	/**
	 * The low-score warning shown on the invoice.
	 *
	 * @param array<string,mixed> $score The CreditScore record.
	 *
	 * @return string
	 */
	private function lowScoreMessage(array $score): string {
		return sprintf(
			'Klant %s heeft lage creditscore (%s op %s). Overweeg vooruitbetaling of deelfacturatie.',
			(string)($score['customerId'] ?? ''),
			(string)(float)($score['score'] ?? 0.0),
			(string)($score['scoreScale'] ?? '')
		);

	}//end lowScoreMessage()

	/**
	 * The most recent CreditScore for a klant / provider tuple.
	 *
	 * @param CreditScoreQuery $query The lookup.
	 *
	 * @return array<string,mixed>|null
	 */
	private function latestForCustomer(CreditScoreQuery $query): ?array {
		try {
			return ($this->newestFirst(rows: $this->findScores(query: $query))[0] ?? null);
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: CreditScoreService::latestForKlant failed: ' . $e->getMessage());
			return null;
		}

	}//end latestForCustomer()

	/**
	 * Every stored CreditScore for the lookup, or [] when the store answers
	 * with something other than a list.
	 *
	 * @param CreditScoreQuery $query The lookup.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findScores(CreditScoreQuery $query): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$rows = $objectService
			->setRegister($this->register())
			->setSchema('CreditScore')
			->findAll(
				['filters' => ['administrationId' => $query->administrationId, 'customerId' => $query->customerId, 'provider' => $query->provider]]
			);
		if (is_array($rows) === false) {
			return [];
		}

		return $rows;

	}//end findScores()

	/**
	 * Order snapshots newest scoreDate first.
	 *
	 * @param array<int,array<string,mixed>> $rows Snapshots.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function newestFirst(array $rows): array {
		usort(
			$rows,
			static function (array $a, array $b): int {
				return strcmp((string)($b['scoreDate'] ?? ''), (string)($a['scoreDate'] ?? ''));
			}
		);

		return $rows;

	}//end newestFirst()

	/**
	 * Whether a CreditScore snapshot is within the configured cache window.
	 *
	 * @param array<string,mixed> $score The snapshot.
	 *
	 * @return bool
	 */
	private function isFresh(array $score): bool {
		$days = max(1, (int)$this->appConfig->getValueString(AppIdentity::APP_ID, self::CFG_CACHE_DAYS, '30'));
		$date = (string)($score['scoreDate'] ?? '');
		if ($date === '') {
			return false;
		}

		try {
			$when = new DateTimeImmutable($date);
		} catch (\Throwable $e) {
			return false;
		}

		$cutoff = (new DateTimeImmutable())->modify('-' . $days . ' days');
		return $when >= $cutoff;
	}//end isFresh()

	/**
	 * Resolve the configured register slug.
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
