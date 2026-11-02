<?php

/**
 * Shillinq per-administration BBV seeds
 *
 * Seeds the BBV records an administration starts with: the RGS to BBV
 * account mapping and the demo programmes and budget mappings, from the
 * files under lib/Settings/seeds/.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\Seed\SeedImport;
use OCA\Shillinq\Service\Seed\SeedImporter;
use OCA\Shillinq\Service\Seed\SeedMessages;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Seeds an administration's BBV records through OpenRegister's ObjectService.
 *
 * SettingsService runs these seeds and supplies the OpenRegister availability
 * check and the register slug.
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-006)
 */
class BbvAdministrationSeedService {

	/**
	 * Administration types the BBV account mapping applies to.
	 *
	 * @var array<int, string>
	 */
	private const MUNICIPAL_TYPES = ['municipality', 'provincie', 'waterschap'];

	/**
	 * Seed file with the demo programmes and budget mappings.
	 *
	 * @var string
	 */
	private const WATERSCHAP_DEMO = 'bbv-waterschappen-programmes-2026-demo.json';

	/**
	 * Result message when OpenRegister is missing.
	 *
	 * @var string
	 */
	private const NO_OPENREGISTER = 'OpenRegister is not installed or enabled.';

	/**
	 * Constructor for the BbvAdministrationSeedService.
	 *
	 * @param SettingsService $settings The settings service
	 * @param SeedImporter $importer The seed file importer
	 * @param IAppConfig $appConfig The app config, for the BBV gate install date
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settings,
		private SeedImporter $importer,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed the default RGS → BBV account mapping for a municipal administration
	 * from rgs-to-bbv-mapping.json, idempotently (REQ-BBV-006). Only runs for
	 * administrations whose `administrationType` is in {municipality, provincie,
	 * waterschap}; other types are out of BBV scope.
	 *
	 * Deduplication key is (administrationId, accountNumber) — the natural
	 * uniqueness scope per REQ-BBV-002. Existing records with `_meta.source =
	 * operator-edited` are NEVER overwritten on re-run; existing `seeded`
	 * records are left in place so the re-seed is a true no-op until an
	 * operator edits a row.
	 *
	 * Records the BBV gate's install date on app config (`bbv_gate_install_date`)
	 * the first time a successful seed runs for any administration. The
	 * BbvComplianceGuard reads this date to enforce forward-only enforcement
	 * per REQ-BBV-003.
	 *
	 * @param string $administrationId The municipal administration's id.
	 * @param string $administrationType The administration type — must be in
	 *                                   the municipal set or the seeder
	 *                                   short-circuits.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count,
	 *                             skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-006)
	 */
	public function seedBbvAccountMappings(string $administrationId, string $administrationType): array {
		if (in_array($administrationType, self::MUNICIPAL_TYPES, true) === false) {
			return [
				'success' => true,
				'seeded' => 0,
				'skipped' => 0,
				'message' => 'Administration type "' . $administrationType . '" is non-municipal; BBV mapping seed skipped per REQ-BBV-006.',
			];
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->importer->failed(message: self::NO_OPENREGISTER);
		}

		if ($administrationId === '') {
			return $this->importer->failed(message: 'administrationId must not be empty.');
		}

		$file = $this->importer->read(fileName: 'rgs-to-bbv-mapping.json');
		if (isset($file['error']) === true) {
			return $file['error'];
		}

		if (empty($file['data']['records'] ?? []) === true) {
			return $this->importer->failed(message: 'Seed file contains no records.');
		}

		try {
			$counts = $this->saveNewMappings(data: $file['data'], administrationId: $administrationId);
			$this->recordGateInstallDate();

			$this->logger->info(
				'Shillinq: BBV account mappings seeded',
				[
					'administrationId' => $administrationId,
					'administrationType' => $administrationType,
				] + $counts
			);

			return ['success' => true] + $counts;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: BBV account mapping seed failed',
				[
					'administrationId' => $administrationId,
					'exception' => $e->getMessage(),
				]
			);

			return $this->importer->failed(message: 'BBV mapping seed failed: ' . $e->getMessage());
		}//end try
	}//end seedBbvAccountMappings()

	/**
	 * Save the mapping records that do not exist for the administration yet.
	 *
	 * Existing records, seeded or operator-edited, are never overwritten
	 * (REQ-BBV-006); the uniqueness scope is (administrationId, accountNumber)
	 * per REQ-BBV-002.
	 *
	 * @param array<string, mixed> $data The decoded seed file.
	 * @param string $administrationId The administration the records belong to.
	 *
	 * @return array{seeded: int, skipped: int}
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-006)
	 */
	private function saveNewMappings(array $data, string $administrationId): array {
		$objectService = $this->importer->objectService();
		$registerSlug = $this->settings->getRegisterSlug();
		$counts = [
			'seeded' => 0,
			'skipped' => 0,
		];

		foreach ($data['records'] as $record) {
			$accountNumber = (string) ($record['accountNumber'] ?? '');
			if ($accountNumber === '') {
				continue;
			}

			$existing = $objectService
				->setRegister($registerSlug)
				->setSchema('BbvAccountMapping')
				->findAll(['filters' => ['administrationId' => $administrationId, 'accountNumber' => $accountNumber], 'limit' => 1]);
			if (empty($existing) === false) {
				$counts['skipped']++;
				continue;
			}

			$record['administrationId'] = $administrationId;
			$record['_meta'] = ($record['_meta'] ?? []);
			$record['_meta']['source'] = 'seeded';
			$record['_meta']['bbvVersion'] = ($data['_meta']['bbvVersion'] ?? '2024');

			$objectService->saveObject(object: $record, register: $registerSlug, schema: 'BbvAccountMapping');
			$counts['seeded']++;
		}//end foreach

		return $counts;
	}//end saveNewMappings()

	/**
	 * Record the BBV gate's install date the first time any seed runs.
	 *
	 * Read-then-write keeps the date stable across upgrades; the
	 * BbvComplianceGuard enforces forward from it (REQ-BBV-003).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-006)
	 */
	private function recordGateInstallDate(): void {
		$existingDate = $this->appConfig->getValueString(Application::APP_ID, 'bbv_gate_install_date', '');
		if ($existingDate === '') {
			$this->appConfig->setValueString(Application::APP_ID, 'bbv_gate_install_date', date('Y-m-d'));
		}
	}//end recordGateInstallDate()

	/**
	 * Seed the demo BBVProgramme records from
	 * bbv-waterschappen-programmes-2026-demo.json, idempotently per administration.
	 *
	 * Loads the 5 demo programmes (1.1.1, 1.2.1, 2.3.2, 2.4.1, 3.1.0) for fiscal
	 * year 2026 documented in the bookkeeping-waterschappen-bbv-variant-01-config
	 * -schemas-seed design and writes them to the BBVProgramme schema via
	 * ObjectService. Deduplication key is
	 * (administrationId, programmeCode, fiscalYear) per REQ-BBVW-001 so re-runs
	 * never create duplicates and operator edits persist across upgrades.
	 * Skipped when administration_id is not configured (C2 — prevents "default"
	 * contamination of real tenant data).
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string, mixed> Result with success flag, seeded + skipped counts, message.
	 *
	 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
	 */
	public function seedBbvProgrammes(string $administrationId): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->importer->failed(message: self::NO_OPENREGISTER);
		}

		if ($administrationId === '') {
			return $this->importer->failed(message: 'administrationId is required for BBVProgramme seed.');
		}

		$file = $this->importer->read(fileName: self::WATERSCHAP_DEMO);
		if (isset($file['error']) === true) {
			return $file['error'];
		}

		$programmes = ($file['data']['programmes'] ?? []);
		if (empty($programmes) === true) {
			return $this->importer->nothingSeeded(message: 'Seed file contains no programmes, nothing to import.');
		}

		// Dedup key (administrationId, programmeCode, fiscalYear) per REQ-BBVW-001.
		$match = static function ($programme) use ($administrationId): ?array {
			$programmeCode = (string) ($programme['programmeCode'] ?? '');
			$fiscalYear = ($programme['fiscalYear'] ?? null);
			if ($programmeCode === '' || $fiscalYear === null) {
				return null;
			}

			return [
				'administrationId' => $administrationId,
				'programmeCode' => $programmeCode,
				'fiscalYear' => $fiscalYear,
			];
		};

		return $this->importer->import(
			import: new SeedImport(schema: 'BBVProgramme', rows: $programmes, match: $match, administrationId: $administrationId),
			messages: new SeedMessages(
				seededLog: 'Shillinq: BBVProgramme demo records seeded',
				seededMessage: 'BBVProgramme demo records seeded successfully.',
				failedLog: 'Shillinq: BBVProgramme demo seeding failed',
			)
		);
	}//end seedBbvProgrammes()

	/**
	 * Seed the demo BudgetBBVMapping records from
	 * bbv-waterschappen-programmes-2026-demo.json, idempotently per administration.
	 *
	 * Loads the demo allocation split documented in the design: GL 4100 →
	 * 50/30/20 across programmes 1.1.1/1.2.1/2.4.1 and GL 5000 → 25/75 across
	 * 2.3.2/2.4.1, all effective for fiscal 2026. Deduplication key is
	 * (administrationId, glAccountNumber, programmeCode, effectiveFrom) per
	 * REQ-BBVW-002 so re-runs never create duplicates and operator edits
	 * persist across upgrades. Skipped when administration_id is not configured
	 * (C2 — prevents "default" contamination of real tenant data).
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string, mixed> Result with success flag, seeded + skipped counts, message.
	 *
	 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
	 */
	public function seedBudgetBbvMappings(string $administrationId): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->importer->failed(message: self::NO_OPENREGISTER);
		}

		if ($administrationId === '') {
			return $this->importer->failed(message: 'administrationId is required for BudgetBBVMapping seed.');
		}

		$file = $this->importer->read(fileName: self::WATERSCHAP_DEMO);
		if (isset($file['error']) === true) {
			return $file['error'];
		}

		$mappings = ($file['data']['mappings'] ?? []);
		if (empty($mappings) === true) {
			return $this->importer->nothingSeeded(message: 'Seed file contains no mappings, nothing to import.');
		}

		// Dedup key (administrationId, glAccountNumber, programmeCode, effectiveFrom) per REQ-BBVW-002.
		$match = static function ($mapping) use ($administrationId): ?array {
			$glAccountNumber = (string) ($mapping['glAccountNumber'] ?? '');
			$programmeCode = (string) ($mapping['programmeCode'] ?? '');
			$effectiveFrom = (string) ($mapping['effectiveFrom'] ?? '');
			if ($glAccountNumber === '' || $programmeCode === '' || $effectiveFrom === '') {
				return null;
			}

			return [
				'administrationId' => $administrationId,
				'glAccountNumber' => $glAccountNumber,
				'programmeCode' => $programmeCode,
				'effectiveFrom' => $effectiveFrom,
			];
		};

		return $this->importer->import(
			import: new SeedImport(schema: 'BudgetBBVMapping', rows: $mappings, match: $match, administrationId: $administrationId),
			messages: new SeedMessages(
				seededLog: 'Shillinq: BudgetBBVMapping demo records seeded',
				seededMessage: 'BudgetBBVMapping demo records seeded successfully.',
				failedLog: 'Shillinq: BudgetBBVMapping demo seeding failed',
			)
		);
	}//end seedBudgetBbvMappings()
}//end class
