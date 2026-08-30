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
use OCA\Shillinq\AppInfo\Application;
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
		$cached = $this->latestForCustomer(administrationId: $administrationId, customerId: $customerId, provider: $provider);
		if ($cached !== null && $this->isFresh(score: $cached) === true) {
			return $cached;
		}

		// Cache miss / stale — dispatch via the bound fetch adapter
		// (LogCreditScoreFetchAdapter by default — swap for an
		// openconnector-backed implementation in production). When the
		// adapter returns a fresh snapshot, persist it and return it;
		// otherwise fall back to the stale cache so the caller can still
		// surface what's known.
		$fresh = null;
		try {
			$fresh = $this->fetch->fetch(
				administrationId: $administrationId,
				customerId: $customerId,
				provider: $provider
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: CreditScoreFetchAdapter::fetch threw — falling back to cache: ' . $e->getMessage()
			);
		}

		if ($fresh === null) {
			$this->logger->info('Shillinq: CreditScore live refresh unavailable for ' . $customerId . ' / ' . $provider . '; using cached snapshot.');
			return $cached;
		}

		// Normalise + persist the fresh snapshot so the next call hits the cache.
		$fresh['administrationId'] = ($fresh['administrationId'] ?? $administrationId);
		$fresh['customerId'] = ($fresh['customerId'] ?? $customerId);
		$fresh['provider'] = ($fresh['provider'] ?? $provider);
		if (isset($fresh['scoreDate']) === false || (string)$fresh['scoreDate'] === '') {
			$fresh['scoreDate'] = (new DateTimeImmutable())->format('Y-m-d');
		}

		try {
			$persisted = $this->saveScore(score: $fresh);
			if ($persisted !== null) {
				return $persisted;
			}

			return $fresh;
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: failed to persist fresh CreditScore — returning in-memory snapshot: ' . $e->getMessage());
			return $fresh;
		}

	}//end getOrRefresh()

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
			return [
				'warning' => false,
				'message' => '',
				'creditLimitAdvice' => null,
				'deelfacturatieAdvies' => false,
			];
		}

		$threshold = (float)$this->appConfig->getValueString(Application::APP_ID, self::CFG_WARNING_THRESHOLD, '3.0');
		$value = (float)($score['score'] ?? 0.0);
		$limit = null;
		if (isset($score['creditLimitAdvice']) === true) {
			$limit = (float)$score['creditLimitAdvice'];
		}

		$belowThreshold = ($value < $threshold);
		$overLimit = ($limit !== null && $invoiceAmount > $limit);

		if ($belowThreshold === false && $overLimit === false) {
			return [
				'warning' => false,
				'message' => '',
				'creditLimitAdvice' => $limit,
				'deelfacturatieAdvies' => false,
			];
		}

		$customerId = (string)($score['customerId'] ?? '');
		$message = sprintf(
			'Klant %s heeft lage creditscore (%s op %s). Overweeg vooruitbetaling of deelfacturatie.',
			$customerId,
			(string)$value,
			(string)($score['scoreScale'] ?? '')
		);

		return [
			'warning' => true,
			'message' => $message,
			'creditLimitAdvice' => $limit,
			'deelfacturatieAdvies' => ($overLimit === true),
		];

	}//end evaluateForInvoice()

	/**
	 * The most recent CreditScore for a klant / provider tuple.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $customerId Customer FK.
	 * @param string $provider Provider id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function latestForCustomer(string $administrationId, string $customerId, string $provider): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema('CreditScore')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'customerId' => $customerId,
							'provider' => $provider,
						],
					]
				);
			if (is_array($rows) === false || $rows === []) {
				return null;
			}

			usort(
				$rows,
				static function (array $a, array $b): int {
					return strcmp((string)($b['scoreDate'] ?? ''), (string)($a['scoreDate'] ?? ''));
				}
			);
			return $rows[0];
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: CreditScoreService::latestForKlant failed: ' . $e->getMessage());
			return null;
		}//end try

	}//end latestForKlant()

	/**
	 * Whether a CreditScore snapshot is within the configured cache window.
	 *
	 * @param array<string,mixed> $score The snapshot.
	 *
	 * @return bool
	 */
	private function isFresh(array $score): bool {
		$days = max(1, (int)$this->appConfig->getValueString(Application::APP_ID, self::CFG_CACHE_DAYS, '30'));
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
