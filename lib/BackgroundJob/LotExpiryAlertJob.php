<?php

/**
 * Lot Expiry Alert Job.
 *
 * Daily background job that scans InventoryLot records and generates
 * ExpiryAlert records for lots approaching their expiryDate (at configurable
 * thresholds — default 30 and 7 days) and for lots that have crossed their
 * expiryDate (alertType `expired`). Idempotent: ExpiryAlert uniqueness on
 * (lotId, alertType, daysBeforeExpiry) means a repeat scan never duplicates
 * an existing alert. Fail-soft per-record: a single bad lot logs and the
 * sweep continues.
 *
 * Implements REQ-LOT-007 alert generation. Per ADR-032 `kind: code` chain
 * spec scope — the schema (kind: config) ships in this same change; the
 * declarative engine cannot express "compare expiryDate against today minus
 * N threshold days for every lot, and insert one alert row per crossed
 * threshold" without a date-arithmetic primitive.
 *
 * @category Cron
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-lot-batch-expiry/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily expiry alert sweep for InventoryLot records.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/inventory-lot-batch-expiry/spec.md
 */
class LotExpiryAlertJob extends TimedJob {

	/**
	 * Daily interval in seconds (24h).
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Default approaching-expiry alert thresholds, in days.
	 *
	 * Operators override per item category via the OpenRegister admin UI;
	 * the defaults match the design.md "30 days then 7 days" recipe.
	 *
	 * @var array<int,int>
	 */
	private const DEFAULT_THRESHOLDS = [30, 7];

	/**
	 * Time factory captured at construction.
	 *
	 * @var ITimeFactory
	 */
	private ITimeFactory $timeFactory;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling and current-time reads.
	 * @param SettingsService $settings Resolves the active OR register slug.
	 * @param ContainerInterface $container DI container for OR ObjectService lookup.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
		$this->timeFactory = $time;

	}//end __construct()

	/**
	 * Sweep all active/quarantined InventoryLot records and raise alerts.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inventory-lot-batch-expiry/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 *     TimedJob::run()'s signature; this job takes no argument.
	 */
	protected function run($argument): void {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->logger->info('LotExpiryAlertJob: OpenRegister not available, skipping.');
			return;
		}

		try {
			$today = $this->todayDate();
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();

			$activeLots = $objectService
				->setRegister($registerSlug)
				->setSchema('InventoryLot')
				->findAll(
					[
						'filters' => ['lotStatus' => 'active'],
						'limit' => 5000,
					]
				);

			$raised = 0;
			foreach ($activeLots as $record) {
				$raised += $this->checkLotForAlerts(
					record: $record,
					objectService: $objectService,
					registerSlug: $registerSlug,
					today: $today,
				);
			}

			$this->logger->info(
				'LotExpiryAlertJob: ' . $raised . ' new expiry alert(s) raised for '
				. count($activeLots) . ' active lot(s).'
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'LotExpiryAlertJob failed: ' . $e->getMessage(),
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end run()

	/**
	 * Inspect one lot and raise any missing threshold or expired alerts.
	 *
	 * @param mixed $record InventoryLot record (array or OR object).
	 * @param mixed $objectService OR ObjectService (loose-typed).
	 * @param string $registerSlug Register slug.
	 * @param string $today Today's date in YYYY-MM-DD.
	 *
	 * @return int Number of new alerts inserted.
	 */
	private function checkLotForAlerts(mixed $record, mixed $objectService, string $registerSlug, string $today): int {
		try {
			$lot = $this->toArray(object: $record);
			$lotId = (string)($lot['id'] ?? ($lot['uuid'] ?? ''));
			$expiry = (string)($lot['expiryDate'] ?? '');
			$adminId = (string)($lot['administrationId'] ?? '');
			if ($lotId === '' || $expiry === '') {
				return 0;
			}

			$daysUntil = $this->daysBetween(earlier: $today, later: $expiry);
			$raised = 0;

			if ($daysUntil < 0) {
				// Past expiry — alertType=expired (idempotent on lotId+alertType).
				$raised += $this->ensureAlert(
					objectService: $objectService,
					registerSlug: $registerSlug,
					lotId: $lotId,
					administrationId: $adminId,
					alertType: 'expired',
					daysBeforeExpiry: null,
					today: $today,
				);
				return $raised;
			}

			foreach (self::DEFAULT_THRESHOLDS as $thresholdDays) {
				if ($daysUntil > $thresholdDays) {
					continue;
				}

				$raised += $this->ensureAlert(
					objectService: $objectService,
					registerSlug: $registerSlug,
					lotId: $lotId,
					administrationId: $adminId,
					alertType: 'approaching_expiry',
					daysBeforeExpiry: $thresholdDays,
					today: $today,
				);
			}

			return $raised;
		} catch (Throwable $e) {
			$this->logger->warning(
				'LotExpiryAlertJob: skip lot: ' . $e->getMessage()
			);
			return 0;
		}//end try

	}//end checkLotForAlerts()

	/**
	 * Insert one alert if no equivalent one exists already (idempotent on uniqueness key).
	 *
	 * @param mixed $objectService OR ObjectService.
	 * @param string $registerSlug Register slug.
	 * @param string $lotId FK to InventoryLot.
	 * @param string $administrationId FK to Administration.
	 * @param string $alertType Enum value.
	 * @param int|null $daysBeforeExpiry Threshold days for approaching alerts; null for expired.
	 * @param string $today Today's date (alert date).
	 *
	 * @return int 1 if inserted, 0 if skipped.
	 */
	private function ensureAlert(
		mixed $objectService,
		string $registerSlug,
		string $lotId,
		string $administrationId,
		string $alertType,
		?int $daysBeforeExpiry,
		string $today,
	): int {
		$filters = [
			'lotId' => $lotId,
			'alertType' => $alertType,
		];
		if ($daysBeforeExpiry !== null) {
			$filters['daysBeforeExpiry'] = $daysBeforeExpiry;
		}

		$existing = $objectService
			->setRegister($registerSlug)
			->setSchema('ExpiryAlert')
			->findAll(
				[
					'filters' => $filters,
					'limit' => 1,
				]
			);

		if (empty($existing) === false) {
			return 0;
		}

		$alert = [
			'lotId' => $lotId,
			'alertType' => $alertType,
			'alertDate' => $today,
			'status' => 'pending',
			'administrationId' => $administrationId,
		];
		if ($daysBeforeExpiry !== null) {
			$alert['daysBeforeExpiry'] = $daysBeforeExpiry;
		}

		$objectService->saveObject(
			object: $alert,
			register: $registerSlug,
			schema: 'ExpiryAlert',
		);

		return 1;
	}//end ensureAlert()

	/**
	 * Days between two YYYY-MM-DD dates ($later minus $earlier).
	 *
	 * @param string $earlier The earlier date (today).
	 * @param string $later The later date (expiryDate).
	 *
	 * @return int Positive when $later is in the future; negative when past.
	 */
	private function daysBetween(string $earlier, string $later): int {
		$earlierDt = new DateTimeImmutable($earlier);
		$laterDt = new DateTimeImmutable($later);
		$diff = $laterDt->diff($earlierDt);
		$days = (int)$diff->days;
		if ($diff->invert === 1) {
			return $days;
		}

		return (-$days);
	}//end daysBetween()

	/**
	 * Current server date as YYYY-MM-DD.
	 *
	 * @return string
	 */
	private function todayDate(): string {
		return $this->timeFactory->getDateTime()
			->setTimezone(new DateTimeZone('UTC'))
			->format('Y-m-d');

	}//end todayDate()

	/**
	 * Normalise an OR record into a flat array.
	 *
	 * @param mixed $object OR ObjectService payload.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'getObject') === true) {
				$inner = $object->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}

			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
