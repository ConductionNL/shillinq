<?php

/**
 * Shillinq Daily FX Rate Import Background Job
 *
 * Daily background job that fetches the previous business day's ECB
 * FX rates for every currency pair the administration cares about
 * and upserts them into the FxRate register (add-shillinq-multi-currency
 * REQ-MC-003 / Task 11).
 *
 * The rate transport is delegated to the TreasuryRateAdapter port
 * (`fetchFxSpot(base, quote, asOf)`) so the import stays decoupled from
 * the openconnector source slug `treasury-rates` (ECB SDMX feed). When
 * the adapter is dormant — the production default until the openconnector
 * source is wired — the import logs the deferred outcome and exits
 * without writing the FxRate record, so the SNAPSHOT_DEFERRED sentinel
 * never lands in the GL posting path. Manual operator entries (source =
 * `manual`) keep working through the FX Rates Vue admin tab regardless.
 *
 * Per REQ-MC-002 the upsert is idempotent on the composite key
 * (transactionCurrency, baseCurrency, date, source) so re-running on the
 * same calendar day is a no-op.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily ECB FX-rate import for the FxRate register (REQ-MC-003).
 *
 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-11
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class FxRateImportJob extends TimedJob {
	/**
	 * Interval between runs: 86400 seconds = 24 hours (daily ECB cycle).
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Default currency pairs imported when no `multi_currency_pairs`
	 * app-config override is set. ECB publishes all four daily.
	 *
	 * @var array<int, array{transaction: string, base: string}>
	 */
	private const DEFAULT_PAIRS = [
		['transaction' => 'USD', 'base' => 'EUR'],
		['transaction' => 'GBP', 'base' => 'EUR'],
		['transaction' => 'CHF', 'base' => 'EUR'],
		['transaction' => 'JPY', 'base' => 'EUR'],
	];

	/**
	 * Source enum value matching the FxRate.source enum (`ecb` per the
	 * register fragment).
	 */
	private const SOURCE_ECB = 'ecb';

	/**
	 * Construct the FxRate import job.
	 *
	 * @param ITimeFactory $time Nextcloud time factory (injected by TimedJob).
	 * @param ContainerInterface $container DI container for lazy ObjectService + TreasuryRateAdapter resolution.
	 * @param IAppConfig $appConfig App config for register slug + pair-list overrides.
	 * @param LoggerInterface $logger Structured logger.
	 * @param TreasuryRateAdapterInterface $adapter FX-spot adapter port (dormant in default deployment).
	 *
	 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-11
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly TreasuryRateAdapterInterface $adapter,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Fetch yesterday's FX rates for the configured pairs and upsert
	 * them into the FxRate register.
	 *
	 * @param mixed $argument Not used; required by TimedJob interface.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-11
	 */
	protected function run(mixed $argument): void {
		$this->logger->info('Shillinq: FxRateImportJob started');

		$asOf = $this->previousBusinessDay();

		if ($this->adapter->isDormant() === true) {
			$this->logger->info(
				'Shillinq FxRateImportJob: TreasuryRateAdapter is dormant — skipping ECB ingest. '
				. 'Bind openconnector source slug `treasury-rates` (ECB SDMX) and override '
				. 'TreasuryRateAdapterInterface in Application::register() to enable. '
				. 'Manual FxRate entries via the admin UI are unaffected.',
				['asOf' => $asOf]
			);
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq FxRateImportJob: OpenRegister not available, skipping.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$register = $this->getRegisterSlug();
		$pairs = $this->configuredPairs();

		$imported = 0;
		$skipped = 0;
		$errors = 0;

		foreach ($pairs as $pair) {
			$tx = $pair['transaction'];
			$base = $pair['base'];

			try {
				$result = $this->adapter->fetchFxSpot(baseCurrency: $base, quoteCurrency: $tx, asOf: $asOf);
			} catch (\Throwable $e) {
				$errors++;
				$this->logger->warning(
					'Shillinq FxRateImportJob: fetchFxSpot threw',
					['pair' => $tx . '/' . $base, 'asOf' => $asOf, 'exception' => $e->getMessage()]
				);
				continue;
			}

			if ($this->isDeferredResult(result: $result) === true) {
				$skipped++;
				continue;
			}

			if ($this->upsertRate(
				objectService: $objectService,
				register: $register,
				transactionCurrency: $tx,
				baseCurrency: $base,
				asOf: $asOf,
				result: $result,
			) === true
			) {
				$imported++;
			} else {
				$skipped++;
			}
		}//end foreach

		$this->logger->info(
			sprintf(
				'Shillinq FxRateImportJob: imported %d rate(s), skipped %d, %d error(s) for asOf=%s',
				$imported,
				$skipped,
				$errors,
				$asOf
			)
		);
	}//end run()

	/**
	 * Return the ISO date (YYYY-MM-DD) of the previous business day in
	 * Europe/Brussels (ECB publication TZ). Mon → previous Fri, Tue–Fri → previous day.
	 *
	 * @return string ISO date.
	 */
	private function previousBusinessDay(): string {
		$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
		$prev = $now->modify('-1 day');
		// Skip weekends — ECB only publishes business days.
		while ((int)$prev->format('N') >= 6) {
			$prev = $prev->modify('-1 day');
		}

		return $prev->format('Y-m-d');
	}//end previousBusinessDay()

	/**
	 * Detect a SNAPSHOT_DEFERRED / dormant outcome — both flavours
	 * indicate the rate must NOT be written.
	 *
	 * @param TreasuryRateResult $result The fetch result.
	 *
	 * @return bool TRUE when the result is a deferred sentinel.
	 */
	private function isDeferredResult(TreasuryRateResult $result): bool {
		return ($result->dormant === true || $result->status === 'SNAPSHOT_DEFERRED');
	}//end isDeferredResult()

	/**
	 * Upsert one FxRate record. Idempotent on (transactionCurrency,
	 * baseCurrency, date, source) — duplicate ingest is a no-op.
	 *
	 * @param object $objectService OR ObjectService.
	 * @param string $register Register slug.
	 * @param string $transactionCurrency Quote currency code.
	 * @param string $baseCurrency Base currency code.
	 * @param string $asOf ISO date.
	 * @param TreasuryRateResult $result Adapter result carrying the decimal rate.
	 *
	 * @return bool TRUE when a new rate was written; FALSE on duplicate / invalid.
	 */
	private function upsertRate(
		object $objectService,
		string $register,
		string $transactionCurrency,
		string $baseCurrency,
		string $asOf,
		TreasuryRateResult $result,
	): bool {
		$rate = (float)$result->value;
		if ($rate <= 0) {
			$this->logger->warning(
				'Shillinq FxRateImportJob: adapter returned non-positive rate — skipping',
				['pair' => $transactionCurrency . '/' . $baseCurrency, 'asOf' => $asOf, 'rate' => $result->value]
			);
			return false;
		}

		$existing = $this->findExisting(
			objectService: $objectService,
			register: $register,
			transactionCurrency: $transactionCurrency,
			baseCurrency: $baseCurrency,
			asOf: $asOf,
		);
		if ($existing !== null) {
			$this->logger->debug(
				'Shillinq FxRateImportJob: FxRate already present, idempotent skip',
				['pair' => $transactionCurrency . '/' . $baseCurrency, 'asOf' => $asOf]
			);
			return false;
		}

		$record = $this->buildRecord(
			transactionCurrency: $transactionCurrency,
			baseCurrency: $baseCurrency,
			asOf: $asOf,
			rate: $rate,
		);

		try {
			$objectService->setRegister($register);
			$objectService->setSchema('FxRate');
			$objectService->saveObject($record, $register, 'FxRate');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq FxRateImportJob: failed to save FxRate',
				['pair' => $transactionCurrency . '/' . $baseCurrency, 'asOf' => $asOf, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end upsertRate()

	/**
	 * Look up an existing FxRate for the composite key.
	 *
	 * @param object $objectService OR ObjectService.
	 * @param string $register Register slug.
	 * @param string $transactionCurrency Quote currency code.
	 * @param string $baseCurrency Base currency code.
	 * @param string $asOf ISO date.
	 *
	 * @return ?array<string,mixed> The existing record array, or NULL when none.
	 */
	private function findExisting(
		object $objectService,
		string $register,
		string $transactionCurrency,
		string $baseCurrency,
		string $asOf,
	): ?array {
		try {
			$matches = $objectService
				->setRegister($register)
				->setSchema('FxRate')
				->findAll(
					[
						'filters' => [
							'transactionCurrency' => $transactionCurrency,
							'baseCurrency' => $baseCurrency,
							'date' => $asOf,
							'source' => self::SOURCE_ECB,
						],
						'limit' => 1,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq FxRateImportJob: findExisting lookup failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}//end try

		if (empty($matches) === true) {
			return null;
		}

		$first = $matches[0];
		if (is_array($first) === true) {
			return $first;
		}

		if (method_exists($first, 'getObject') === true) {
			return $first->getObject();
		}

		return null;
	}//end findExisting()

	/**
	 * Build a FxRate record matching the register schema.
	 *
	 * @param string $transactionCurrency Quote currency code.
	 * @param string $baseCurrency Base currency code.
	 * @param string $asOf ISO date.
	 * @param float $rate Positive decimal rate (transaction → base).
	 *
	 * @return array<string,mixed> The serialisable record.
	 */
	private function buildRecord(
		string $transactionCurrency,
		string $baseCurrency,
		string $asOf,
		float $rate,
	): array {
		if ($rate > 0) {
			$inverseRate = (1 / $rate);
		} else {
			$inverseRate = null;
		}

		return [
			'transactionCurrency' => $transactionCurrency,
			'baseCurrency' => $baseCurrency,
			'date' => $asOf,
			'source' => self::SOURCE_ECB,
			'rate' => $rate,
			'inverseRate' => $inverseRate,
			'ingestedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
		];
	}//end buildRecord()

	/**
	 * Resolve the configured currency-pair list from app-config,
	 * falling back to DEFAULT_PAIRS.
	 *
	 * Operators may override via `occ config:app:set shillinq
	 * multi_currency_pairs --value='USD/EUR,GBP/EUR,CHF/EUR'`.
	 *
	 * @return array<int, array{transaction: string, base: string}>
	 */
	private function configuredPairs(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'multi_currency_pairs', '');
		if ($raw === '') {
			return self::DEFAULT_PAIRS;
		}

		$pairs = [];
		foreach (explode(',', $raw) as $token) {
			$token = trim($token);
			if ($token === '' || strpos($token, '/') === false) {
				continue;
			}

			[$tx, $base] = explode('/', $token, 2);
			$tx = strtoupper(trim($tx));
			$base = strtoupper(trim($base));
			if (preg_match('/^[A-Z]{3}$/', $tx) !== 1 || preg_match('/^[A-Z]{3}$/', $base) !== 1) {
				continue;
			}

			$pairs[] = ['transaction' => $tx, 'base' => $base];
		}

		if ($pairs === []) {
			return self::DEFAULT_PAIRS;
		}

		return $pairs;
	}//end configuredPairs()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string Register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()
}//end class
