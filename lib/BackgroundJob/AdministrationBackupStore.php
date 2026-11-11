<?php

/**
 * Administration Backup Store
 *
 * Reads the Administration records and writes the AdministrationBackupRun
 * records of one backup-scheduler tick through OpenRegister's ObjectService.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The OpenRegister reads and writes of the administration backup scheduler.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
 */
final class AdministrationBackupStore {
	/**
	 * Administration records read per page.
	 *
	 * @var integer
	 */
	private const PAGE_SIZE = 100;

	/**
	 * The OpenRegister ObjectService.
	 *
	 * @var object
	 */
	private readonly object $objectService;

	/**
	 * The register slug.
	 *
	 * @var string
	 */
	private readonly string $register;

	/**
	 * The scheduler's logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Read and write through an ObjectService in a register.
	 *
	 * @param object          $objectService The OpenRegister ObjectService.
	 * @param string          $register      The register slug.
	 * @param LoggerInterface $logger        The scheduler's logger.
	 */
	public function __construct(object $objectService, string $register, LoggerInterface $logger) {
		$this->objectService = $objectService;
		$this->register = $register;
		$this->logger = $logger;

	}//end __construct()

	/**
	 * Fetch all Administration records, paginated. A failing page is logged
	 * and ends the paging with what was read so far.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
	 */
	public function fetchAdministrations(): array {
		$result = [];

		do {
			// The offset is the number of administrations read so far.
			$batch = $this->pageRows(offset: count($result));
			if ($batch === null) {
				break;
			}

			$result = array_merge($result, $batch);
		} while (count($batch) >= self::PAGE_SIZE);

		return $result;
	}//end fetchAdministrations()

	/**
	 * One page of administrations as arrays, or null when the read failed (logged).
	 *
	 * @param int $offset The page offset.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private function pageRows(int $offset): array|null {
		$entities = $this->administrationPage(offset: $offset);
		if ($entities === false) {
			return null;
		}

		return $this->administrationRows(entities: $entities);
	}//end pageRows()

	/**
	 * Persist one AdministrationBackupRun record; a failure is logged.
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @return bool Whether it was persisted.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
	 */
	public function saveRun(array $record): bool {
		try {
			$this->objectService
				->setRegister($this->register)
				->setSchema('AdministrationBackupRun')
				->saveObject($record);
			return true;
		} catch (Throwable $e) {
			$this->logSaveFailure(record: $record, e: $e);
			return false;
		}
	}//end saveRun()

	/**
	 * One page of Administration entities, or false when the read failed (logged).
	 *
	 * @param int $offset The page offset.
	 *
	 * @return mixed
	 */
	private function administrationPage(int $offset): mixed {
		try {
			return $this->objectService
				->setRegister($this->register)
				->setSchema('Administration')
				->findAll($this->pageQuery(offset: $offset));
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq AdministrationBackupSchedulerJob: failed to fetch administrations',
				['offset' => $offset, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end administrationPage()

	/**
	 * The query for one page of Administration records.
	 *
	 * @param int $offset The page offset.
	 *
	 * @return array<string, int>
	 */
	private function pageQuery(int $offset): array {
		return [
			'limit' => self::PAGE_SIZE,
			'offset' => $offset,
		];
	}//end pageQuery()

	/**
	 * The page's administrations as arrays: array rows as they are, entities
	 * through getObject(); anything else is skipped.
	 *
	 * @param mixed $entities One page of entities.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function administrationRows(mixed $entities): array {
		$batch = [];
		foreach ($entities as $entity) {
			$row = $this->rowOf(entity: $entity);
			if ($row !== null) {
				$batch[] = $row;
			}
		}

		return $batch;
	}//end administrationRows()

	/**
	 * An entity as an array, or null when it is neither an array nor an entity with array data.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @return array<string,mixed>|null
	 */
	private function rowOf(mixed $entity): array|null {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === false || method_exists($entity, 'getObject') === false) {
			return null;
		}

		$data = $entity->getObject();
		if (is_array($data) === true) {
			return $data;
		}

		return null;
	}//end rowOf()

	/**
	 * Log a backup run that could not be persisted.
	 *
	 * @param array<string,mixed> $record The record.
	 * @param Throwable           $e      The failure.
	 *
	 * @return void
	 */
	private function logSaveFailure(array $record, Throwable $e): void {
		$this->logger->error(
			'Shillinq AdministrationBackupSchedulerJob: failed to persist backup run',
			[
				'administrationId' => $record['administrationId'],
				'exception' => $e->getMessage(),
			]
		);
	}//end logSaveFailure()
}//end class
