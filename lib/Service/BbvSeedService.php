<?php

/**
 * Shillinq BBV Seed Service
 *
 * Imports BBV (Besluit Begroting en Verantwoording) stam-data — taakvelden,
 * economische categorieen, beleidsindicatoren, and RGS-decentraal mappings —
 * into OpenRegister for BBV-tenants (gemeente, provincie, waterschap). Idempotent
 * per (schema, deduplication-key): re-running skips records that already exist,
 * preserving operator edits per REQ-BBV-001/002/007.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports BBV stam-data catalogues into OpenRegister idempotently.
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 */
class BbvSeedService {
	/**
	 * Catalogue descriptors mapping each seed file to its root array key, target
	 * schema, and deduplication field.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const CATALOGUES = [
		[
			'file' => 'bbv-taakvelden-gemeente-2025.json',
			'key' => 'taskFields',
			'schema' => 'Taakveld',
			'dedup' => 'code',
			'secondaryDedup' => 'overheidslaag',
		],
		[
			'file' => 'bbv-taakvelden-provincia-2025.json',
			'key' => 'taskFields',
			'schema' => 'Taakveld',
			'dedup' => 'code',
			'secondaryDedup' => 'overheidslaag',
		],
		[
			'file' => 'bbv-taakvelden-waterschap-2025.json',
			'key' => 'taskFields',
			'schema' => 'Taakveld',
			'dedup' => 'code',
			'secondaryDedup' => 'overheidslaag',
		],
		['file' => 'economische-categorieen-2025.json', 'key' => 'economischeCategorieen', 'schema' => 'EconomischeCategorie', 'dedup' => 'code'],
		['file' => 'beleidsindicatoren-bbv-2025.json', 'key' => 'beleidsindicatoren', 'schema' => 'BeleidsIndicator', 'dedup' => 'code'],
		['file' => 'rgs-decentraal-2025.json', 'key' => 'mappings', 'schema' => 'BbvAccountMapping', 'dedup' => 'rgsDecentraalCode'],
	];

	/**
	 * Construct the BBV seed service.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for register-slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Seed all BBV stam-data catalogues idempotently.
	 *
	 * @return array<string,mixed> Result with success flag and per-schema counts.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
	 */
	public function seedAll(): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}

		$counts = [];
		foreach (self::CATALOGUES as $catalogue) {
			$result = $this->seedCatalogue(
				objectService: $objectService,
				catalogue: $catalogue
			);

			// Accumulate per-schema across multiple catalogue files (e.g. the three
			// Taakveld files for gemeente / provincie / waterschap all feed the same
			// schema bucket — previously the last run overwrote the earlier two).
			$schema = $catalogue['schema'];
			if (isset($counts[$schema]) === false) {
				$counts[$schema] = ['seeded' => 0, 'skipped' => 0];
			}

			$counts[$schema]['seeded'] += (int)$result['seeded'];
			$counts[$schema]['skipped'] += (int)$result['skipped'];
		}

		return ['success' => true, 'counts' => $counts];
	}//end seedAll()

	/**
	 * Import one BBV stam-data catalogue idempotently.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param array<string,string> $catalogue Catalogue descriptor (file/key/schema/dedup).
	 *
	 * @return array{seeded:int,skipped:int}
	 */
	private function seedCatalogue(object $objectService, array $catalogue): array {
		$rows = $this->loadRows(file: $catalogue['file'], key: $catalogue['key']);

		return $this->importRows(
			objectService: $objectService,
			rows: $rows,
			schema: $catalogue['schema'],
			dedupField: $catalogue['dedup'],
			secondaryDedupField: ($catalogue['secondaryDedup'] ?? null)
		);

	}//end seedCatalogue()

	/**
	 * Load and parse the rows for a catalogue seed file.
	 *
	 * @param string $file Seed filename under lib/Settings/seeds/.
	 * @param string $key Root array key holding the rows.
	 *
	 * @return array<int, array<string, mixed>> The catalogue rows (empty on error).
	 */
	private function loadRows(string $file, string $key): array {
		$seedPath = __DIR__ . '/../Settings/seeds/' . $file;
		if (file_exists($seedPath) === false) {
			$this->logger->warning('Shillinq: BBV seed file not found: ' . $file);
			return [];
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return [];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Shillinq: BBV seed parse error in ' . $file . ': ' . json_last_error_msg());
			return [];
		}

		return ($data[$key] ?? []);
	}//end loadRows()

	/**
	 * Import catalogue rows into a schema, skipping existing dedup keys.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param array<int, array<string, mixed>> $rows Catalogue rows.
	 * @param string $schema Target schema slug.
	 * @param string $dedupField Field used as the dedup key.
	 * @param string|null $secondaryDedupField Optional secondary field for the dedup key.
	 *
	 * @return array{seeded:int,skipped:int}
	 */
	private function importRows(
		object $objectService,
		array $rows,
		string $schema,
		string $dedupField,
		?string $secondaryDedupField = null,
	): array {
		$seeded = 0;
		$skipped = 0;
		$registerSlug = $this->getRegisterSlug();

		foreach ($rows as $row) {
			$dedupValue = ($row[$dedupField] ?? null);
			if ($dedupValue === null) {
				continue;
			}

			$filters = [$dedupField => $dedupValue];

			if ($secondaryDedupField !== null) {
				$secondaryValue = ($row[$secondaryDedupField] ?? null);
				if ($secondaryValue !== null) {
					$filters[$secondaryDedupField] = $secondaryValue;
				}
			}

			$existing = $objectService
				->setRegister($registerSlug)
				->setSchema($schema)
				->findAll(['filters' => $filters, 'limit' => 1]);

			if (empty($existing) === false) {
				$skipped++;
				continue;
			}

			$objectService->saveObject(object: $row, register: $registerSlug, schema: $schema);
			$seeded++;
		}//end foreach

		return ['seeded' => $seeded, 'skipped' => $skipped];
	}//end importRows()
}//end class
