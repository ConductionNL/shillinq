<?php

/**
 * Shillinq seed importer
 *
 * Reads seed files under lib/Settings/seeds/ and imports their rows through
 * OpenRegister's ObjectService, skipping rows that already exist.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Seed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Seed;

use Closure;
use OCA\Shillinq\Service\RegisterLocation;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads a seed file and imports its rows idempotently.
 *
 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
 */
class SeedImporter {

	/**
	 * Directory that holds the seed files.
	 *
	 * @var string
	 */
	public const SEED_DIR = __DIR__ . '/../../Settings/seeds/';

	/**
	 * Constructor for the SeedImporter.
	 *
	 * @param RegisterLocation $settings Where the register lives (slug + OpenRegister availability).
	 * @param ContainerInterface $container The container, for OpenRegister's ObjectService
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private RegisterLocation $settings,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A failed seed result.
	 *
	 * @param string $message Why the seed failed.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	public function failed(string $message): array {
		return [
			'success' => false,
			'message' => $message,
		];
	}//end failed()

	/**
	 * A successful seed result that wrote nothing.
	 *
	 * @param string $message Why nothing was written.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	public function nothingSeeded(string $message): array {
		return [
			'success' => true,
			'message' => $message,
			'seeded' => 0,
			'skipped' => 0,
		];
	}//end nothingSeeded()

	/**
	 * The read failures of a seed file, each naming the file.
	 *
	 * @param string $fileName The seed file name.
	 *
	 * @return array{missing: string, unreadable: string, invalid: string}
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	private function fileMessages(string $fileName): array {
		return [
			'missing' => 'Seed file not found: ' . $fileName,
			'unreadable' => 'Failed to read ' . $fileName,
			'invalid' => 'Failed to parse ' . $fileName . ': ',
		];
	}//end fileMessages()

	/**
	 * Read and decode a seed file.
	 *
	 * @param string $fileName The seed file name under SEED_DIR.
	 * @param array{missing: string, unreadable: string, invalid: string}|null $messages The read failures, by default
	 *                                                                                naming the file; "invalid" is
	 *                                                                                followed by the JSON error.
	 *
	 * @return array<string, mixed> ['data' => decoded content] or ['error' => failed result].
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	public function read(string $fileName, ?array $messages=null): array {
		$messages = ($messages ?? $this->fileMessages(fileName: $fileName));
		$seedPath = self::SEED_DIR . $fileName;
		if (file_exists($seedPath) === false) {
			return ['error' => $this->failed(message: $messages['missing'])];
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return ['error' => $this->failed(message: $messages['unreadable'])];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ['error' => $this->failed(message: $messages['invalid'] . json_last_error_msg())];
		}

		return ['data' => $data];
	}//end read()

	/**
	 * Import the rows that do not exist yet.
	 *
	 * A row whose match filters find an object is skipped. Any exception ends
	 * the import with a failed result and an error log line.
	 *
	 * @param SeedImport $import The rows, schema and match.
	 * @param SeedMessages $messages What to log and return.
	 *
	 * @return array<string, mixed> Result with success flag, message, seeded and skipped counts.
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	public function import(SeedImport $import, SeedMessages $messages): array {
		return $this->report(
			messages: $messages,
			save: fn (object $objectService): array => $this->saveRows(objectService: $objectService, import: $import)
		);
	}//end import()

	/**
	 * Import the rows that do not exist yet, as the system user.
	 *
	 * Seeding runs user-less (repair step / boot); without elevation
	 * OpenRegister RBAC denies the reads and writes as 'Anonymous'. An
	 * OpenRegister without runAsSystem() runs the import as is.
	 *
	 * @param SeedImport $import The rows, schema and match.
	 * @param SeedMessages $messages What to log and return.
	 *
	 * @return array<string, mixed> Result with success flag, message, seeded and skipped counts.
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	public function importAsSystem(SeedImport $import, SeedMessages $messages): array {
		return $this->report(
			messages: $messages,
			save: function (object $objectService) use ($import): array {
				$save = fn (): array => $this->saveRows(objectService: $objectService, import: $import);
				if (method_exists($objectService, 'runAsSystem') === true) {
					return $objectService->runAsSystem($save);
				}

				return $save();
			}
		);
	}//end importAsSystem()

	/**
	 * OpenRegister's ObjectService.
	 *
	 * @return object
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	public function objectService(): object {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * Run a save step and report its counts.
	 *
	 * @param SeedMessages $messages What to log and return.
	 * @param Closure(object): array{seeded: int, skipped: int} $save The save step, given the ObjectService.
	 *
	 * @return array<string, mixed>
	 */
	private function report(SeedMessages $messages, Closure $save): array {
		try {
			$counts = $save($this->objectService());

			$this->logger->info($messages->seededLog, $messages->logContext + $counts);

			return ['success' => true, 'message' => $messages->seededMessage] + $counts;
		} catch (\Throwable $e) {
			$this->logger->error($messages->failedLog, ['exception' => $e->getMessage()]);
			return $this->failed(message: $e->getMessage());
		}//end try
	}//end report()

	/**
	 * Save the rows that have a match and no existing copy.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param SeedImport $import The rows, schema and match.
	 *
	 * @return array{seeded: int, skipped: int}
	 */
	private function saveRows(object $objectService, SeedImport $import): array {
		$registerSlug = $this->settings->getRegisterSlug();
		$counts = [
			'seeded' => 0,
			'skipped' => 0,
		];

		foreach ($import->rows as $row) {
			$filters = ($import->match)($row);
			if ($filters === null) {
				continue;
			}

			$existing = $objectService
				->setRegister($registerSlug)
				->setSchema($import->schema)
				->findAll(['filters' => $filters, 'limit' => 1]);
			if (empty($existing) === false) {
				$counts['skipped']++;
				continue;
			}

			if ($import->administrationId !== null) {
				$row['administrationId'] = $import->administrationId;
			}

			$objectService->saveObject(object: $row, register: $registerSlug, schema: $import->schema);
			$counts['seeded']++;
		}//end foreach

		return $counts;
	}//end saveRows()
}//end class
