<?php

/**
 * Shillinq Initialize Settings Repair Step
 *
 * Repair step that initializes Shillinq register and schemas on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Service\BbvSeedService;
use OCA\Shillinq\Service\Migration\RevenueContractRenameMigrator;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\StatementManifestService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Shillinq configuration via SettingsService.
 *
 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
 * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-15
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * Pre-existing debt (issue #506): this repair step configures every
 * register/schema the app ships, so its size scales with the app's
 * feature surface; splitting is out of scope for a mechanical phpcs/phpmd
 * cleanup. Deferred to a follow-up.
 */
class InitializeSettings implements IRepairStep {
	use \OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;

	/**
	 * Constructor for InitializeSettings.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param StatementManifestService $manifestService The statement-manifest importer
	 * @param LoggerInterface $logger The logger interface
	 * @param ContainerInterface $container The DI container
	 * @param BbvSeedService $bbvSeedService The BBV stam-data seed service
	 * @param RevenueContractRenameMigrator $revenueContractMigrator The Contract → RevenueContract object-migration core
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private StatementManifestService $manifestService,
		private LoggerInterface $logger,
		private ContainerInterface $container,
		private BbvSeedService $bbvSeedService,
		private RevenueContractRenameMigrator $revenueContractMigrator,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
	 */
	public function getName(): string {
		return 'Initialize Shillinq register and schemas via ConfigurationService';
	}//end getName()

	/**
	 * Run the repair step to initialize Shillinq configuration.
	 *
	 * Phase 1: imports the register schema from shillinq_register.json (includes
	 * Iv3Export schema, lifecycle, aggregations, and iv3-xml-transformation mapping).
	 * Phase 2: seeds the chart of accounts from the RGS template selected
	 * in app config key 'rgs_template' (default: 'mkb'). Idempotent —
	 * existing accounts are skipped on re-run, preserving operator edits.
	 * Seeding is skipped entirely when administration_id is not configured
	 * (C2: prevents "default" contamination of real tenant data).
	 * Phase 3: seeds the Archiefwet Selectielijst Gemeenten 2020 retention rules.
	 * Phase 4: registers the IV3 quarterly CBS ScheduledWorkflow if not yet present.
	 * Phase 4b: registers the FixedAssets monthly-depreciation ScheduledWorkflow per REQ-FA-005.
	 * Phase 4c: registers the BCF quarterly DigiKoppeling ScheduledWorkflow per REQ-BCF-005.
	 * Phase 4d: registers the Intercompany monthly-matching ScheduledWorkflow per REQ-ICE-003.
	 * Phase 5: seeds KOR thresholds from kor-thresholds-2026.json idempotently.
	 * Phase 6: seeds AllocationRule example records from seeds/allocation-rules/ idempotently.
	 * Phase 7: seeds RJ-270 stages and rate-card templates for consultancy project accounting.
	 * Phase 7b: seeds project-flavoured AnalyticalDimension (dimensionType=project) + CostCenter
	 * consultancy templates per REQ-CPA-110/111/112. Formerly seeded CostProject (retired by
	 * retire-cost-project per REQ-RCP-003).
	 * Phase 8: seeds ProductAttribute templates (office, it_hardware, logistics, food_beverage, clothing) per REQ-IPC-007.
	 * Phase 9: seeds ReimbursementPolicy + PassThroughMarkupRule master-data records per REQ-ERP-004 / REQ-ERP-005.
	 * Phase 10: seeds demo Barcode records (EAN/GTIN/SSCC/UPC/internal) per REQ-SKU-011.
	 * Phase 11: seeds demo InventoryStock records (Amsterdam / Rotterdam / Utrecht) per REQ-IST-009.
	 * Phase 12: seeds BBVProgramme + BudgetBBVMapping demo records (waterschappen BBV chain member 01) per REQ-BBVW-001 / REQ-BBVW-002.
	 * Phase 13: seeds the default InventoryGLConfig (RGS 3.5 MKB account
	 * routing for COGS / Inventory Asset / GR-IR / Inventory Adjustment)
	 * per REQ-CG-001 / Task 11.
	 * Phase 1b (after default-administration seeding): migrates any live
	 * IFRS-15-shaped `Contract` objects to `RevenueContract` via
	 * RevenueContractRenameMigrator, un-colliding the pre-fix merged
	 * `Contract` slug per contracts-single-home. Idempotent — a second run
	 * finds zero matching objects and no-ops.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
	 * @spec openspec/changes/add-shillinq-iv3-reporting/tasks.md#task-10
	 * @spec openspec/changes/add-shillinq-kor-kleine-ondernemersregeling/tasks.md#task-12
	 * @spec openspec/changes/add-shillinq-cost-centers-dimensions/tasks.md#task-11
	 * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-15
	 * @spec openspec/changes/inventory-product-catalog/tasks.md#task-13
	 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
	 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-11
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-14
	 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info('Initializing Shillinq configuration...');

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning(
				'OpenRegister is not installed or enabled. Skipping auto-configuration.'
			);
			$this->logger->warning(
				'Shillinq: OpenRegister not available, skipping register initialization'
			);
			return;
		}

		try {
			// NOT forced. The C8 workaround used to force this import because OpenRegister's
			// app-level gate compared only the APP version, so a shillinq_register.json change
			// silently no-op'd whenever the app version was unchanged. Forcing dodged that, but
			// `force: true` also bypasses OR's app-level fast-skip entirely — so this step
			// re-parsed the 1 MB descriptor + 146 register.d fragments and walked every
			// register/schema on EVERY upgrade, to conclude "nothing changed": 711s, ~70% of the
			// instance's whole `occ maintenance:repair`.
			//
			// OpenRegister#426 removed the reason to force: its gate is now content-aware
			// (app version NOT newer AND a sha256 of the definitional payload identical to the
			// last import). A descriptor change therefore re-imports even when the app version
			// is unchanged — exactly what C8 wanted — while a genuinely unchanged config
			// fast-skips in milliseconds. Requires an OpenRegister that ships #426; on an older
			// one this degrades to the app-version-only gate (fast, but a content change with an
			// unchanged app version would no-op again — bump the app version to force it).
			$result = $this->settingsService->loadConfiguration();

			if ($result['success'] === true) {
				$skipped = (($result['skipped'] ?? false) === true);
				$version = ($result['version'] ?? 'unknown');
				if ($skipped === true) {
					$output->info('Shillinq configuration already up-to-date (version-unchanged skip)');
				}

				if ($skipped !== true) {
					$output->info(
						'Shillinq configuration imported successfully (version: ' . $version . ')'
					);
				}
			}

			if ($result['success'] !== true) {
				$message = ($result['message'] ?? 'unknown error');
				$output->warning('Shillinq configuration import issue: ' . $message);
				// H2: skip account seed when schema import failed to avoid writing
				// accounts into an uninitialized register.
				$output->warning('Shillinq: schema import failed, skipping account seed');
				return;
			}

			// EVERY SEEDER BELOW RUNS UNDER A SYSTEM IDENTITY.
			//
			// A repair step executes during `occ upgrade`, where there is no
			// session — so OpenRegister resolves the actor as 'Anonymous' and
			// refuses `create`. Measured on a live instance before this wrap
			// existed, this one step emitted EIGHT such failures:
			//
			//   BBV stam-data seeding failed:      … schema 'Taakveld'
			//   Demo barcode seeding issue:        … schema 'Barcode'
			//   Inventory valuation example …:     … schema 'Inventory Valuation'
			//   InventoryStock example …:          … schema 'Inventory Stock'
			//   InventoryGLConfig seeding issue:   … schema 'Inventory GL Posting Configuration'
			//   BBVProgramme demo seeding issue:   … schema 'BBV Programme'
			//   Mandaat templates seeding issue:   … schema 'Mandaat'
			//   FixedAssets demo seeding issue:    … schema 'Fixed Asset'
			//
			// Each was reported with `$output->warning()`, which does NOT fail an
			// upgrade — so the upgrade said "Update successful" while eight sets
			// of reference data were never written.
			//
			// The wrap is around the WHOLE block rather than each seeder: they
			// resolve their own ObjectService instances, and one identity scope
			// covering the block is both cheaper and impossible to half-apply.
			// Resolved DEFENSIVELY, and the seeders run either way.
			//
			// An earlier revision of this fix resolved ObjectService directly
			// here. That made a resolution failure abort EVERY seeder through the
			// outer catch below — where previously each seeder handled its own
			// failure and the rest still ran. Adding an identity must not cost
			// the degradation behaviour that was already there.
			$objectService = null;
			try {
				$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Shillinq: ObjectService unavailable; seeding without a system identity',
					['exception' => $e->getMessage()]
				);
			}

			$this->withSystemIdentity(
				objectService: $objectService,
				work: function () use ($output): void {
					$this->runSeeders(output: $output);
				}
			);
		} catch (\Throwable $e) {
			$output->warning('Could not auto-configure Shillinq: ' . $e->getMessage());
			$this->logger->error(
				'Shillinq initialization failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end run()

	/**
	 * Run every seeder, under whatever identity the caller established.
	 *
	 * Split out of run() so the entire sequence sits inside ONE runAsSystem()
	 * scope. Wrapping each seeder separately would re-enter the scope a few
	 * dozen times and, worse, make it possible to add a seeder that quietly
	 * sits outside it.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 */
	private function runSeeders(IOutput $output): void {
			$this->seedDefaultAdministration(output: $output);
			$this->migrateRevenueContractObjects(output: $output);
			$this->seedChartOfAccounts(output: $output);
			$this->seedProjectData(output: $output);
			$this->seedConsultancyProjectAccountingTemplates(output: $output);
			$this->seedSelectielijstRules(output: $output);
			$this->registerIv3ScheduledWorkflow(output: $output);
			$this->registerFixedAssetsMonthlyDepreciationWorkflow(output: $output);
			$this->registerBcfQuarterlyDigikoppelingWorkflow(output: $output);
			$this->registerIntercompanyMonthlyMatchingWorkflow(output: $output);
			$this->seedKorThresholds(output: $output);
			$this->seedComplianceReferenceData(output: $output);
			// $this->seedProductAttributeTemplates(output: $output);
			// @see MigrateProductVendorMasterToPipelinq (shillinq-product-vendor-to-pipelinq change).
			// Removed: seedProductAttributeTemplates — ProductAttribute data migrated to pipelinq.
			$this->seedReimbursementPolicies(output: $output);
			$this->seedPassThroughMarkupRules(output: $output);
			$this->seedInventoryBarcodeDemo(output: $output);
			// REQ-LOT (inventory-lot-batch-expiry design.md §"Demo seed"):
			// demo inventory lots must only be seeded in development/demo
			// environments, never on production. Gate behind APP_ENV.
			if (\getenv('APP_ENV') === 'development') {
				$this->seedInventoryLotsDemo(output: $output);
			}

			$this->seedInventoryValuationExamples(output: $output);
			$this->seedInventoryStockExamples(output: $output);
			$this->seedInventoryGLConfig(output: $output);
			$this->seedBbvWaterschappenDemo(output: $output);
			$this->importStatementManifests(output: $output);
			$this->seedMandateTemplates(output: $output);
			$this->seedRetentionPolicies(output: $output);
			$this->seedStatementManifests(output: $output);
			$this->seedWmoCommercialActivities(output: $output);
			$this->seedSbrMappings(output: $output);
			$this->seedFixedAssetsDemo(output: $output);
	}//end runSeeders()

	/**
	 * Seed the default Administration on fresh install, idempotently (REQ-MA-001, REQ-MA-007).
	 *
	 * Foundational multi-administratie boundary: a single default Administration
	 * (administrationCode ADM-001) is created so single-administratie installs have a
	 * valid administrationId FK target. Deduplicated on administrationCode inside
	 * SettingsService::seedDefaultAdministration(), so re-runs are safe.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
	 */
	private function seedDefaultAdministration(IOutput $output): void {
		$output->info('Seeding default administration...');
		$result = $this->settingsService->seedDefaultAdministration();

		if (($result['success'] ?? false) !== true) {
			$output->warning('Default administration seeding issue: ' . ($result['message'] ?? 'unknown error'));
			return;
		}

		$output->info(
			'Default administration: ' . ($result['seeded'] ?? 0) . ' created, ' . ($result['skipped'] ?? 0) . ' skipped (already exists).'
		);

	}//end seedDefaultAdministration()

	/**
	 * Migrate any live IFRS-15-shaped `Contract` objects to `RevenueContract`
	 * (contracts-single-home).
	 *
	 * Un-collides the pre-fix merged `Contract` slug: before this change, the
	 * generic contract-lifecycle-management `Contract` and the IFRS-15
	 * revenue-recognition `Contract` deep-merged into one schema, so both
	 * kinds of object could be persisted under the same `Contract` slug.
	 * RevenueContractRenameMigrator's discriminator tells them apart; only
	 * IFRS-15-shaped objects (customerId / fixedConsideration / lifecycleState
	 * present, no contractType / status) are moved. A CLM-shaped object is
	 * left under `Contract` untouched even if it also carries IFRS-15
	 * leftover fields.
	 *
	 * Guarded by RevenueContractRenameMigrator::assertCountsMatch() (via
	 * migrateBatch): if the migrated batch's count does not match the source
	 * count, the migration aborts and the source `Contract` objects are left
	 * intact — nothing is deleted before every move has been confirmed.
	 * Idempotent: a second run finds zero remaining `Contract`-slugged
	 * IFRS-15-shaped objects (they are already under `RevenueContract`) and
	 * no-ops. Failure is reported but never aborts the repair run.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
	 */
	private function migrateRevenueContractObjects(IOutput $output): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();
		} catch (\Throwable $e) {
			$output->info('Shillinq: ObjectService unavailable, skipping RevenueContract migration');
			return;
		}

		$rename = $this->revenueContractMigrator->revenueContractRename();

		try {
			$sourceObjects = $objectService
				->setRegister($registerSlug)
				->setSchema($rename['from'])
				->findAll(['limit' => 1000]);
		} catch (\Throwable $e) {
			$output->info('Shillinq: Contract register not yet present, skipping RevenueContract migration');
			return;
		}

		if (empty($sourceObjects) === true) {
			$output->info('Shillinq: no Contract objects found, RevenueContract migration is a no-op');
			return;
		}

		$sourceRows = [];
		foreach ($sourceObjects as $sourceObject) {
			$sourceRows[] = $this->asArray(row: $sourceObject);
		}

		try {
			$migratedRows = $this->revenueContractMigrator->migrateBatch(
				sourceObjects: $sourceRows,
				from: $rename['from'],
				to: $rename['to']
			);
		} catch (\Throwable $e) {
			// AssertCountsMatch() throws on a count mismatch (no-row-loss guard);
			// the source Contract objects are left untouched — abort quietly.
			$output->warning('Shillinq: RevenueContract migration aborted: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: RevenueContract migration aborted',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$migrated = 0;
		$skipped = 0;
		foreach ($migratedRows as $index => $row) {
			$newSchema = ($row['@self']['schema'] ?? $rename['from']);
			if ($newSchema !== $rename['to']) {
				// CLM-shaped object; the discriminator left it under Contract.
				$skipped++;
				continue;
			}

			$objectId = (string)($sourceRows[$index]['@self']['id'] ?? $sourceRows[$index]['id'] ?? '');
			if ($objectId === '') {
				$output->warning('Shillinq: RevenueContract migration skipped a row with no object id');
				continue;
			}

			unset($row['@self']);

			try {
				$objectService->saveObject(
					object: $row,
					register: $registerSlug,
					schema: $rename['to'],
					uuid: $objectId,
					// System migration inside a no-session repair step — bypass RBAC.
					_rbac: false,
				);
				$objectService
					->setRegister($registerSlug)
					->setSchema($rename['from'])
					->deleteObject($objectId);
				$migrated++;
			} catch (\Throwable $e) {
				$output->warning(
					'Shillinq: RevenueContract migration failed for object ' . $objectId . ': ' . $e->getMessage()
				);
			}
		}//end foreach

		$output->info(
			'Shillinq: RevenueContract migration complete: ' . $migrated . ' migrated, ' . $skipped . ' left as Contract (CLM-shaped).'
		);

	}//end migrateRevenueContractObjects()

	/**
	 * Seed project accounting data (RJ-270 stages and rate-card templates), idempotently.
	 *
	 * RJ-270 stages are seeded unconditionally (not tenant-specific).
	 * Rate-card templates require a configured administrationId (C2).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-15
	 */
	private function seedProjectData(IOutput $output): void {
		$output->info('Seeding RJ-270 stages...');
		$rj270Result = $this->settingsService->seedRj270Stages();
		if (($rj270Result['success'] ?? false) === true) {
			$output->info(
				'RJ-270 stages seeded: ' . ($rj270Result['seeded'] ?? 0) . ' created, ' . ($rj270Result['skipped'] ?? 0) . ' skipped.'
			);
		}

		if (($rj270Result['success'] ?? false) !== true) {
			$output->warning('RJ-270 stages seeding issue: ' . ($rj270Result['message'] ?? 'unknown error'));
		}

		$settings = $this->settingsService->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			$output->warning(
				'Shillinq: administration_id not configured — skipping rate-card template seed.'
			);
			return;
		}

		$output->info('Seeding rate-card templates...');
		$rcResult = $this->settingsService->seedRateCardTemplates(administrationId: $administrationId);
		if ($rcResult['success'] === true) {
			$output->info(
				'Rate-card templates seeded: ' . ($rcResult['seeded'] ?? 0) . ' created, ' . ($rcResult['skipped'] ?? 0) . ' skipped.'
			);
		}

		if ($rcResult['success'] !== true) {
			$output->warning('Rate-card templates seeding issue: ' . ($rcResult['message'] ?? 'unknown error'));
		}

	}//end seedProjectData()

	/**
	 * Seed the consultancy-project-accounting templates (CostProject + CostCenter)
	 * from lib/Settings/seeds/project-templates.json and
	 * lib/Settings/seeds/cost-center-templates.json, idempotently per administration.
	 *
	 * Deduplication keys: CostProject on projectNumber + administrationId;
	 * CostCenter on code + administrationId. Re-runs preserve operator
	 * edits per REQ-CPA-110 / REQ-CPA-111 / REQ-CPA-112. Skipped when
	 * administration_id is not configured (C2 — prevents "default"
	 * contamination of real tenant data).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-consultancy-project-accounting/tasks.md#task-16
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 *       (REQ-CPA-110, REQ-CPA-111, REQ-CPA-112)
	 */
	private function seedConsultancyProjectAccountingTemplates(IOutput $output): void {
		$settings = $this->settingsService->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			$output->warning(
				'Shillinq: administration_id not configured — skipping consultancy project accounting seed (REQ-CPA-112).'
			);
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();
		} catch (\Throwable $e) {
			$output->info('Shillinq: ObjectService unavailable, skipping consultancy project accounting seed');
			return;
		}

		$this->seedConsultancyProjectTemplates(
			output: $output,
			objectService: $objectService,
			registerSlug: $registerSlug,
			administrationId: $administrationId
		);
		$this->seedConsultancyCostCenterTemplates(
			output: $output,
			objectService: $objectService,
			registerSlug: $registerSlug,
			administrationId: $administrationId
		);

	}//end seedConsultancyProjectAccountingTemplates()

	/**
	 * Import project-flavoured AnalyticalDimension seed records from
	 * project-templates.json idempotently.
	 *
	 * Formerly seeded CostProject objects; retargeted to AnalyticalDimension
	 * (dimensionType=project) by retire-cost-project per REQ-RCP-003.
	 * Deduplication key: projectNumber + administrationId on AnalyticalDimension.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 * @param object $objectService The OR ObjectService instance.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $administrationId The configured administration id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md (REQ-CPA-110)
	 * @spec openspec/changes/retire-cost-project/specs/retire-cost-project/spec.md (REQ-RCP-003)
	 */
	private function seedConsultancyProjectTemplates(
		IOutput $output,
		object $objectService,
		string $registerSlug,
		string $administrationId,
	): void {
		$seedPath = __DIR__ . '/../Settings/seeds/project-templates.json';
		if (file_exists($seedPath) === false) {
			$output->warning('Shillinq: project template seed file not found, skipping');
			return;
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			$output->warning('Shillinq: failed to read project template seed file, skipping');
			return;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$output->warning('Shillinq: failed to parse project template seed file: ' . json_last_error_msg());
			return;
		}

		$projects = ($data['projects'] ?? []);
		if (empty($projects) === true) {
			$output->info('Shillinq: project template seed file contains no projects, skipping');
			return;
		}

		$seeded = 0;
		$skipped = 0;

		foreach ($projects as $project) {
			$projectNumber = ($project['projectNumber'] ?? null);
			if ($projectNumber === null) {
				continue;
			}

			try {
				// Deduplication: check by projectNumber + administrationId on AnalyticalDimension
				// (dimensionType=project) — formerly checked CostProject (retired per REQ-RCP-003).
				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('AnalyticalDimension')
					->findAll(
						[
							'filters' => [
								'projectNumber' => $projectNumber,
								'administrationId' => $administrationId,
								'dimensionType' => 'project',
							],
							'limit' => 1,
						]
					);
			} catch (\Throwable $e) {
				$output->warning('Shillinq: project template lookup failed for ' . $projectNumber . ': ' . $e->getMessage());
				continue;
			}

			if (empty($existing) === false) {
				$skipped++;
				continue;
			}

			$project['administrationId'] = $administrationId;
			// Strip the @self envelope before persistence — @self is a seed-file convention
			// not an AnalyticalDimension schema field.
			unset($project['@self'], $project['_meta']);

			try {
				$objectService->saveObject(
					object: $project,
					register: $registerSlug,
					schema: 'AnalyticalDimension',
					// System seed inside a no-session repair step — bypass RBAC.
					_rbac: false,
				);
				$seeded++;
			} catch (\Throwable $e) {
				$output->warning('Shillinq: project template save failed for ' . $projectNumber . ': ' . $e->getMessage());
			}
		}//end foreach

		$output->info(
			'Project templates seeded (AnalyticalDimension, dimensionType=project): ' . $seeded . ' created, ' . $skipped . ' skipped (already exist).'
		);

	}//end seedConsultancyProjectTemplates()

	/**
	 * Import CostCenter seed records from cost-center-templates.json idempotently.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 * @param object $objectService The OR ObjectService instance.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $administrationId The configured administration id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md (REQ-CPA-111)
	 */
	private function seedConsultancyCostCenterTemplates(
		IOutput $output,
		object $objectService,
		string $registerSlug,
		string $administrationId,
	): void {
		$seedPath = __DIR__ . '/../Settings/seeds/cost-center-templates.json';
		if (file_exists($seedPath) === false) {
			$output->warning('Shillinq: CostCenter seed file not found, skipping');
			return;
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			$output->warning('Shillinq: failed to read CostCenter seed file, skipping');
			return;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$output->warning('Shillinq: failed to parse CostCenter seed file: ' . json_last_error_msg());
			return;
		}

		$costCenters = ($data['costCenters'] ?? []);
		if (empty($costCenters) === true) {
			$output->info('Shillinq: CostCenter seed file contains no records, skipping');
			return;
		}

		$seeded = 0;
		$skipped = 0;

		foreach ($costCenters as $costCenter) {
			$code = ($costCenter['code'] ?? null);
			if ($code === null) {
				continue;
			}

			try {
				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('AnalyticalDimension')
					->findAll(
						[
							'filters' => [
								'code' => $code,
								'administrationId' => $administrationId,
								'dimensionType' => 'cost-center',
							],
							'limit' => 1,
						]
					);
			} catch (\Throwable $e) {
				$output->warning('Shillinq: CostCenter lookup failed for ' . $code . ': ' . $e->getMessage());
				continue;
			}

			if (empty($existing) === false) {
				$skipped++;
				continue;
			}

			$costCenter['administrationId'] = $administrationId;
			$costCenter['dimensionType'] = 'cost-center';
			unset($costCenter['@self']);

			try {
				$objectService->saveObject(
					object: $costCenter,
					register: $registerSlug,
					schema: 'AnalyticalDimension',
					// System seed inside a no-session repair step — bypass RBAC.
					_rbac: false,
				);
				$seeded++;
			} catch (\Throwable $e) {
				$output->warning('Shillinq: CostCenter save failed for ' . $code . ': ' . $e->getMessage());
			}
		}//end foreach

		$output->info(
			'CostCenter templates seeded: ' . $seeded . ' created, ' . $skipped . ' skipped (already exist).'
		);

	}//end seedConsultancyCostCenterTemplates()

	/**
	 * Import the Selectielijst Gemeenten 2020 retention rules, idempotently.
	 *
	 * Calls SettingsService::seedSelectielijst() which skips already-existing
	 * seeded rules and preserves operator-authored overrides per REQ-ARC-002.
	 * Safe to call on every install/upgrade — the seed is idempotent.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
	 */
	private function seedSelectielijstRules(IOutput $output): void {
		$output->info('Seeding Archiefwet Selectielijst Gemeenten 2020 retention rules...');

		$result = $this->settingsService->seedSelectielijst();

		if (($result['success'] ?? false) === true) {
			$seeded = ($result['seeded'] ?? 0);
			$skipped = ($result['skipped'] ?? 0);
			$output->info(
				'Selectielijst retention rules seeded: ' . $seeded . ' created, ' . $skipped . ' skipped (already exist).'
			);
		}

		if (($result['success'] ?? false) !== true) {
			$message = ($result['message'] ?? 'unknown error');
			$output->warning('Selectielijst seeding issue: ' . $message);
		}

	}//end seedSelectielijstRules()

	/**
	 * Register the IV3 quarterly CBS ScheduledWorkflow if not already present.
	 *
	 * Idempotent: uses the slug 'shillinq-iv3-quarterly-cbs-submission' for deduplication.
	 * The interval defaults to 7776000 seconds (90 days / quarterly). Operators adjust
	 * via the OpenRegister ScheduledWorkflow admin UI. Per ADR-031 §"Background jobs that
	 * orchestrate external systems" + REQ-IV3-006.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-iv3-reporting/tasks.md#task-9
	 */
	private function registerIv3ScheduledWorkflow(IOutput $output): void {
		try {
			$workflowMapper = $this->container->get(
				'OCA\OpenRegister\Db\ScheduledWorkflowMapper'
			);
		} catch (\Throwable $e) {
			$output->info('Shillinq: ScheduledWorkflowMapper not available, skipping IV3 workflow registration');
			return;
		}

		$slug = 'shillinq-iv3-quarterly-cbs-submission';

		$existing = $workflowMapper->findAll();
		foreach ($existing as $workflow) {
			if ($workflow->getName() === $slug) {
				$output->info('Shillinq: IV3 quarterly CBS ScheduledWorkflow already registered, skipping');
				return;
			}
		}

		// 90 days in seconds — quarterly cadence aligned with cbs-iv3 cron 0 0 1 */3 *.
		// Operators reconfigure the interval and target via the OpenRegister admin UI
		// if CBS deadlines shift. REQ-IV3-006 / ADR-019.
		$workflowMapper->createFromArray(
			data: [
				'name' => $slug,
				'engine' => 'openconnector',
				'workflowId' => 'cbs-iv3',
				'intervalSec' => 7776000,
				'enabled' => true,
				'payload' => json_encode(
					[
						'register' => 'shillinq',
						'schema' => 'Iv3Export',
						'administrationType' => ['municipality', 'province', 'waterAuthority'],
					]
				),
			]
		);

		$output->info('Shillinq: IV3 quarterly CBS ScheduledWorkflow registered (interval: 90 days)');

	}//end registerIv3ScheduledWorkflow()

	/**
	 * Register the monthly Fixed-Assets depreciation ScheduledWorkflow if not yet present.
	 *
	 * Idempotent: uses the slug 'shillinq-fixed-assets-monthly-depreciation'
	 * for deduplication. Interval defaults to 2592000 seconds (30 days). The
	 * workflow walks every `FixedAsset` with `lifecycleState=active` and,
	 * for each asset, reads the `monthlyDepreciation` derived field and
	 * materialises a balanced `GLTransaction` (debit depreciation expense,
	 * credit accumulated depreciation). Per ADR-031 §"Background jobs that
	 * walk an object queue" path 2 — no shillinq DepreciationJob extends
	 * TimedJob ships. REQ-FA-005.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	private function registerFixedAssetsMonthlyDepreciationWorkflow(IOutput $output): void {
		try {
			$workflowMapper = $this->container->get(
				'OCA\OpenRegister\Db\ScheduledWorkflowMapper'
			);
		} catch (\Throwable $e) {
			$output->info('Shillinq: ScheduledWorkflowMapper not available, skipping FixedAssets workflow registration');
			return;
		}

		$slug = 'shillinq-fixed-assets-monthly-depreciation';

		$existing = $workflowMapper->findAll();
		foreach ($existing as $workflow) {
			if ($workflow->getName() === $slug) {
				$output->info('Shillinq: FixedAssets monthly-depreciation ScheduledWorkflow already registered, skipping');
				return;
			}
		}

		// 30 days in seconds — monthly cadence per REQ-FA-005. Operators
		// adjust the interval and target via the OpenRegister
		// ScheduledWorkflow admin UI if their close cadence differs.
		$workflowMapper->createFromArray(
			data: [
				'name' => $slug,
				'engine' => 'openconnector',
				'workflowId' => 'fixed-assets-monthly-depreciation',
				'intervalSec' => 2592000,
				'enabled' => true,
				'payload' => json_encode(
					[
						'register' => 'shillinq',
						'schema' => 'FixedAsset',
						'lifecycleState' => 'active',
						'derivedFields' => [
							'monthlyDepreciation',
							'currentBookValue',
							'commercialBookValue',
							'fiscalBookValue',
						],
					]
				),
			]
		);

		$output->info('Shillinq: FixedAssets monthly-depreciation ScheduledWorkflow registered (interval: 30 days)');

	}//end registerFixedAssetsMonthlyDepreciationWorkflow()

	/**
	 * Register the BCF quarterly DigiKoppeling ScheduledWorkflow if not already present.
	 *
	 * Idempotent: uses the slug 'shillinq-bcf-quarterly-digikoppeling-submission'
	 * for deduplication. The interval defaults to 7776000 seconds (90 days /
	 * quarterly cadence aligned with the BCF claim window; the cron equivalent
	 * fires on the first day of each quarter). Per ADR-019 + ADR-022 the
	 * submission consumes the `digikoppeling-bcf` OpenConnector source — no
	 * app-local DigiKoppeling client ships. Operators adjust the interval and
	 * target via the OpenRegister ScheduledWorkflow admin UI per REQ-BCF-005.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-bcf-vat-compensation/tasks.md#task-11
	 */
	private function registerBcfQuarterlyDigikoppelingWorkflow(IOutput $output): void {
		try {
			$workflowMapper = $this->container->get(
				'OCA\OpenRegister\Db\ScheduledWorkflowMapper'
			);
		} catch (\Throwable $e) {
			$output->info('Shillinq: ScheduledWorkflowMapper not available, skipping BCF workflow registration');
			return;
		}

		$slug = 'shillinq-bcf-quarterly-digikoppeling-submission';

		$existing = $workflowMapper->findAll();
		foreach ($existing as $workflow) {
			if ($workflow->getName() === $slug) {
				$output->info('Shillinq: BCF quarterly DigiKoppeling ScheduledWorkflow already registered, skipping');
				return;
			}
		}

		// 90 days in seconds — quarterly cadence aligned with digikoppeling-bcf
		// cron `0 0 1 */3 *`. Operators reconfigure the interval and target via the
		// OpenRegister admin UI when Belastingdienst deadlines shift.
		// REQ-BCF-005 / ADR-019.
		$workflowMapper->createFromArray(
			data: [
				'name' => $slug,
				'engine' => 'openconnector',
				'workflowId' => 'digikoppeling-bcf',
				'intervalSec' => 7776000,
				'enabled' => true,
				'payload' => json_encode(
					[
						'register' => 'shillinq',
						'schema' => 'BcfClaim',
						'administrationType' => ['municipality', 'province', 'waterAuthority'],
					]
				),
			]
		);

		$output->info('Shillinq: BCF quarterly DigiKoppeling ScheduledWorkflow registered (interval: 90 days)');

	}//end registerBcfQuarterlyDigikoppelingWorkflow()

	/**
	 * Register the Intercompany monthly-matching ScheduledWorkflow if not already present.
	 *
	 * Idempotent: uses the slug 'shillinq-intercompany-monthly-matching' for
	 * deduplication. The interval defaults to 2592000 seconds (30 days / monthly
	 * cadence per REQ-ICE-003). Operators reconfigure interval and target via the
	 * OpenRegister ScheduledWorkflow admin UI for quarterly / annual cadences or
	 * per consolidation-group overrides. Per ADR-031 §"Background jobs that walk
	 * an object queue" path 2 — no shillinq IntercompanyMatchingJob extends
	 * TimedJob ships; the OR ScheduledWorkflow primitive owns the trigger and
	 * invokes IntercompanyMatchingService::matchRelationPeriod() per relation.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-14
	 */
	private function registerIntercompanyMonthlyMatchingWorkflow(IOutput $output): void {
		try {
			$workflowMapper = $this->container->get(
				'OCA\OpenRegister\Db\ScheduledWorkflowMapper'
			);
		} catch (\Throwable $e) {
			$output->info('Shillinq: ScheduledWorkflowMapper not available, skipping Intercompany matching workflow registration');
			return;
		}

		$slug = 'shillinq-intercompany-monthly-matching';

		$existing = $workflowMapper->findAll();
		foreach ($existing as $workflow) {
			if ($workflow->getName() === $slug) {
				$output->info('Shillinq: Intercompany monthly-matching ScheduledWorkflow already registered, skipping');
				return;
			}
		}

		// 30 days in seconds — monthly cadence per REQ-ICE-003. Operators
		// reconfigure the interval (quarterly / annual) and target group via the
		// OpenRegister ScheduledWorkflow admin UI. The matching entry-point is
		// IntercompanyMatchingService::matchRelationPeriod(), invoked per
		// IntercompanyRelation by the scheduled run.
		$workflowMapper->createFromArray(
			data: [
				'name' => $slug,
				'engine' => 'openconnector',
				'workflowId' => 'intercompany-monthly-matching',
				'intervalSec' => 2592000,
				'enabled' => true,
				'payload' => json_encode(
					[
						'register' => 'shillinq',
						'schema' => 'IntercompanyRelation',
						'service' => 'OCA\Shillinq\Service\IntercompanyMatchingService',
						'method' => 'matchRelationPeriod',
						'iterateField' => 'relationId',
						'periodField' => 'periodId',
					]
				),
			]
		);

		$output->info('Shillinq: Intercompany monthly-matching ScheduledWorkflow registered (interval: 30 days)');

	}//end registerIntercompanyMonthlyMatchingWorkflow()

	/**
	 * Seed T3 NL-compliance reference data (BTW tariffs + BBV taakvelden), idempotently.
	 *
	 * Both seeds are statutory reference catalogues that are not tenant-specific,
	 * so they are seeded unconditionally. Deduplication is handled inside the
	 * SettingsService seed helpers (by code), keeping re-runs safe.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-bookkeeping-operations/tasks.md#task-311
	 */
	private function seedComplianceReferenceData(IOutput $output): void {
		$output->info('Seeding BTW tariffs...');
		$vatResult = $this->settingsService->seedBtwTariffs();
		if (($vatResult['success'] ?? false) === true) {
			$output->info(
				'BTW tariffs seeded: ' . ($vatResult['seeded'] ?? 0) . ' created, ' . ($vatResult['skipped'] ?? 0) . ' skipped.'
			);
		}

		if (($vatResult['success'] ?? false) !== true) {
			$output->warning('BTW tariffs seeding issue: ' . ($vatResult['message'] ?? 'unknown error'));
		}

		$output->info('Seeding BBV taakvelden...');
		$bbvResult = $this->settingsService->seedBbvTaakvelden();
		if (($bbvResult['success'] ?? false) === true) {
			$output->info(
				'BBV taakvelden seeded: ' . ($bbvResult['seeded'] ?? 0) . ' created, ' . ($bbvResult['skipped'] ?? 0) . ' skipped.'
			);
		}

		if (($bbvResult['success'] ?? false) !== true) {
			$output->warning('BBV taakvelden seeding issue: ' . ($bbvResult['message'] ?? 'unknown error'));
		}

		$this->seedBbvMappingsForMunicipalAdministrations(output: $output);

		$this->seedBbvStamData(output: $output);

	}//end seedComplianceReferenceData()

	/**
	 * Seed the statutory BBV stam-data catalogues via BbvSeedService.
	 *
	 * The `bookkeeping-bbv-compliance` spec (§Seed Data) requires the 53
	 * gemeente / 14 waterschap taakvelden, the economische categorieën, the 39
	 * beleidsindicatoren and the RGS-decentraal mapping to be loaded for every
	 * BBV tenant. `BbvSeedService` implements exactly that and is idempotent,
	 * but its injection into this repair step was dropped as an "unused
	 * constructor injection" (commit 8c773b6a) after the call site had already
	 * been removed (f4c8101e) — so nothing replaced it and the catalogues have
	 * not been seeded since. `ProgrammabegrotingService` and `BudgetOverrunGuard`
	 * both query the `Taakveld` schema, so the tables were being read empty.
	 *
	 * Distinct from `SettingsService::seedBbvTaakvelden()` above, which seeds a
	 * DIFFERENT schema (`BbvTaakveld`, from bbv-taakvelden-2024.json) — the two
	 * are not substitutes for one another.
	 *
	 * Failure is reported but never aborts the repair run: a missing catalogue
	 * must not block an upgrade.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
	 */
	private function seedBbvStamData(IOutput $output): void {
		$output->info('Seeding BBV stam-data catalogues...');

		try {
			$result = $this->bbvSeedService->seedAll();
		} catch (\Throwable $e) {
			$output->warning('BBV stam-data seeding failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: BBV stam-data seeding failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		if (($result['success'] ?? false) !== true) {
			$output->warning('BBV stam-data seeding issue: ' . ($result['message'] ?? 'unknown error'));
			return;
		}

		foreach (($result['counts'] ?? []) as $schema => $counts) {
			$output->info(
				'BBV ' . $schema . ' seeded: ' . ($counts['seeded'] ?? 0) . ' created, ' . ($counts['skipped'] ?? 0) . ' skipped.'
			);
		}

	}//end seedBbvStamData()

	/**
	 * Seed the default RGS → BBV account mapping for every existing municipal
	 * administration (REQ-BBV-006). The seed is per-administration scoped: a
	 * fresh `gemeente`-type administration receives the bundled defaults, but
	 * non-municipal administrations are skipped. Records the BBV gate's
	 * installation date inside SettingsService::seedBbvAccountMappings so the
	 * BbvComplianceGuard's forward-only filter has an anchor (REQ-BBV-003).
	 *
	 * Idempotent on re-run via the (administrationId, accountNumber)
	 * uniqueness key — operator overrides are preserved per REQ-BBV-006.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-006)
	 */
	private function seedBbvMappingsForMunicipalAdministrations(IOutput $output): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$output->info(
				'Shillinq: ObjectService unavailable, skipping per-administration BBV mapping seed'
			);
			return;
		}

		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$administrations = $objectService
				->setRegister($registerSlug)
				->setSchema('Administration')
				->findAll(['limit' => 200]);
		} catch (\Throwable $e) {
			$output->info(
				'Shillinq: Administration register not yet present, skipping BBV mapping seed'
			);
			return;
		}

		if (empty($administrations) === true) {
			$output->info('Shillinq: no administrations found, skipping BBV mapping seed');
			return;
		}

		$municipalTypes = ['municipality', 'province', 'waterAuthority'];
		$totalSeeded = 0;
		$totalSkipped = 0;

		foreach ($administrations as $administration) {
			// OpenRegister's findAll() returns ObjectEntity instances, not plain
			// arrays; array-indexing one directly throws "Cannot use object of
			// type ...ObjectEntity as array" (issue #508). Normalise first.
			$administrationRow = $this->asArray(row: $administration);
			$administrationType = (string)($administrationRow['administrationType'] ?? '');
			if (in_array($administrationType, $municipalTypes, true) === false) {
				continue;
			}

			$administrationId = (string)($administrationRow['administrationCode'] ?? $administrationRow['id'] ?? $administrationRow['uuid'] ?? '');
			if ($administrationId === '') {
				continue;
			}

			$output->info(
				'Seeding BBV account mappings for administration ' . $administrationId . ' (' . $administrationType . ')...'
			);
			$result = $this->settingsService->seedBbvAccountMappings(
				administrationId: $administrationId,
				administrationType: $administrationType
			);

			if (($result['success'] ?? false) === true) {
				$output->info(
					'BBV mappings seeded for ' . $administrationId . ': '
					. ($result['seeded'] ?? 0) . ' created, '
					. ($result['skipped'] ?? 0) . ' skipped.'
				);
				$totalSeeded += (int)($result['seeded'] ?? 0);
				$totalSkipped += (int)($result['skipped'] ?? 0);
				continue;
			}

			$output->warning(
				'BBV mapping seed failed for ' . $administrationId . ': '
				. ($result['message'] ?? 'unknown error')
			);
		}//end foreach

		$output->info(
			'BBV mapping seed complete: ' . $totalSeeded . ' total created, ' . $totalSkipped . ' total skipped across municipal administrations.'
		);

	}//end seedBbvMappingsForMunicipalAdministrations()

	/**
	 * Normalise an OpenRegister ObjectService row (ObjectEntity or array) to a
	 * plain array<string,mixed>.
	 *
	 * OpenRegister's findAll()/find() return ObjectEntity instances, not plain
	 * arrays; array-indexing an ObjectEntity directly (`$row['field']`) throws
	 * "Cannot use object of type OCA\OpenRegister\Db\ObjectEntity as array"
	 * (issue #508). Mirrors the `asArray()` helper used elsewhere in this app
	 * (e.g. LeasePaymentScheduleService, TrialBalanceService).
	 *
	 * @param mixed $row Raw row from ObjectService::findAll()/find().
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
	 * Seed the KOR thresholds from kor-thresholds-2026.json, idempotently.
	 *
	 * Deduplication key is fiscalYear: if a KorThreshold record with the same
	 * fiscalYear already exists in OpenRegister, the seed entry is skipped.
	 * This means re-running the repair step is safe and preserves operator edits.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-kor-kleine-ondernemersregeling/tasks.md#task-12
	 */
	private function seedKorThresholds(IOutput $output): void {
		$seedPath = __DIR__ . '/../Settings/seeds/kor-thresholds-2026.json';
		if (file_exists($seedPath) === false) {
			$output->warning('Shillinq: KOR threshold seed file not found, skipping');
			return;
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			$output->warning('Shillinq: failed to read KOR threshold seed file, skipping');
			return;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$output->warning('Shillinq: failed to parse KOR threshold seed file: ' . json_last_error_msg());
			return;
		}

		$thresholds = ($data['thresholds'] ?? []);
		if (empty($thresholds) === true) {
			$output->info('Shillinq: KOR threshold seed file contains no thresholds, skipping');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();
			$seeded = 0;
			$skipped = 0;

			foreach ($thresholds as $threshold) {
				$fiscalYear = ($threshold['fiscalYear'] ?? null);
				if ($fiscalYear === null) {
					continue;
				}

				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('KorThreshold')
					->findAll(
						[
							'filters' => ['fiscalYear' => $fiscalYear],
							'limit' => 1,
						]
					);

				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

				$objectService->saveObject(
					object: $threshold,
					register: $registerSlug,
					schema: 'KorThreshold',
					// System seeding runs inside a repair step with no user
					// session, so the acting identity is Anonymous. Bypass RBAC
					// (as OpenRegister's own config import does) — otherwise the
					// KorThreshold schema's create permission denies the seed.
					_rbac: false,
				);
				$seeded++;
			}//end foreach

			$output->info(
				'Shillinq: KOR thresholds seeded: ' . $seeded . ' created, ' . $skipped . ' skipped (already exist).'
			);
		} catch (\Throwable $e) {
			$output->warning('Shillinq: KOR threshold seeding failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: KOR threshold seeding failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end seedKorThresholds()

	/**
	 * Seed the demo Barcode records, idempotently.
	 *
	 * Calls `SettingsService::seedInventoryBarcodes()`. Idempotent: barcodes
	 * matched by `(barcode, uomCode)` are skipped, preserving operator edits
	 * per REQ-SKU-011. The demo barcodes reference inventory-product-catalog
	 * demo SKUs (DV-KAT-SENIOR-2KG, VIT-C-1000MG-100CT).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-15
	 */
	private function seedInventoryBarcodeDemo(IOutput $output): void {
		$output->info('Seeding demo barcodes...');
		$result = $this->settingsService->seedInventoryBarcodes();

		if (($result['success'] ?? false) === true) {
			$output->info(
				'Demo barcodes seeded: ' . ($result['seeded'] ?? 0) . ' created, ' . ($result['skipped'] ?? 0) . ' skipped.'
			);
			return;
		}

		$output->warning('Demo barcode seeding issue: ' . ($result['message'] ?? 'unknown error'));

	}//end seedInventoryBarcodeDemo()

	/**
	 * Seed the demo InventoryLot records, idempotently.
	 *
	 * Calls `SettingsService::seedInventoryLots()`. Idempotent: lots
	 * matched by `(administrationId, lotNumber)` are skipped, preserving
	 * operator edits per REQ-LOT-002. The seed references inventory-product-
	 * catalog demo SKUs (DV-KAT-SENIOR-2KG etc.); the lots still load when
	 * those Products are absent and become discoverable once they land.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
	 */
	private function seedInventoryLotsDemo(IOutput $output): void {
		$output->info('Seeding demo inventory lots...');
		$result = $this->settingsService->seedInventoryLots();

		if (($result['success'] ?? false) === true) {
			$output->info(
				'Demo inventory lots seeded: ' . ($result['seeded'] ?? 0) . ' created, ' . ($result['skipped'] ?? 0) . ' skipped.'
			);
			return;
		}

		$output->warning('Demo inventory lot seeding issue: ' . ($result['message'] ?? 'unknown error'));

	}//end seedInventoryLotsDemo()

	/**
	 * Seed example InventoryValuation snapshots from
	 * `inventory-valuation-examples.json`, idempotently per administration.
	 *
	 * Calls SettingsService::seedInventoryValuationExamples(). Idempotent:
	 * dedupes on (productId, warehouse, status=active, administrationId)
	 * per REQ-INV-005 so re-runs are safe and operator edits persist.
	 * Skipped when administration_id is not configured (C2).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-12
	 */
	private function seedInventoryValuationExamples(IOutput $output): void {
		$settings = $this->settingsService->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			$output->info('Shillinq: InventoryValuation example seed skipped (no default administration configured)');
			return;
		}

		$output->info('Seeding inventory valuation examples...');
		$result = $this->settingsService->seedInventoryValuationExamples(administrationId: $administrationId);

		if (($result['success'] ?? false) === true) {
			$output->info(
				'Inventory valuation examples seeded: '
				. ($result['seeded'] ?? 0) . ' created, ' . ($result['skipped'] ?? 0) . ' skipped.'
			);
			return;
		}

		$output->warning('Inventory valuation example seeding issue: ' . ($result['message'] ?? 'unknown error'));

	}//end seedInventoryValuationExamples()

	/**
	 * Seed example InventoryStock records from the three location seed files
	 * (stock-amsterdam.json, stock-rotterdam.json, stock-utrecht.json),
	 * idempotently per administration.
	 *
	 * Calls SettingsService::seedInventoryStockExamples() which deduplicates
	 * on (administrationId, productSku, locationCode) per REQ-IST-002 +
	 * REQ-IST-009 so re-runs are safe and operator edits to quantities
	 * persist across upgrades. Skipped when administration_id is not
	 * configured (C2 — prevents "default" contamination of real tenant data).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	private function seedInventoryStockExamples(IOutput $output): void {
		$settings = $this->settingsService->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			$output->info('Shillinq: InventoryStock seed skipped (no default administration configured)');
			return;
		}

		$output->info('Seeding InventoryStock examples...');
		$result = $this->settingsService->seedInventoryStockExamples(administrationId: $administrationId);

		if (($result['success'] ?? false) === true) {
			$output->info(
				'InventoryStock examples seeded: '
				. ($result['seeded'] ?? 0) . ' created, ' . ($result['skipped'] ?? 0) . ' skipped.'
			);
			return;
		}

		$output->warning('InventoryStock example seeding issue: ' . ($result['message'] ?? 'unknown error'));

	}//end seedInventoryStockExamples()

	/**
	 * Seed the default `InventoryGLConfig` record per administration,
	 * idempotently (inventory-cogs-posting REQ-CG-001 + Task 11).
	 *
	 * Calls SettingsService::seedInventoryGLConfig() which dedupes on
	 * administrationId per REQ-CG-001 (one active config per tenant)
	 * so re-runs preserve operator overrides. Skipped when
	 * administration_id is not configured (C2 — prevents "default"
	 * contamination of real tenant data).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-11
	 */
	private function seedInventoryGLConfig(IOutput $output): void {
		$settings = $this->settingsService->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			$output->info('Shillinq: InventoryGLConfig seed skipped (no default administration configured)');
			return;
		}

		$output->info('Seeding InventoryGLConfig defaults (RGS 3.5 MKB)...');
		$result = $this->settingsService->seedInventoryGLConfig(administrationId: $administrationId);

		if (($result['success'] ?? false) === true) {
			$output->info(
				'InventoryGLConfig seeded: '
				. ($result['seeded'] ?? 0) . ' created, ' . ($result['skipped'] ?? 0) . ' skipped.'
			);
			return;
		}

		$output->warning('InventoryGLConfig seeding issue: ' . ($result['message'] ?? 'unknown error'));

	}//end seedInventoryGLConfig()

	/**
	 * Seed the bookkeeping-waterschappen-bbv-variant slice-01 demo data
	 * (BBVProgramme + BudgetBBVMapping) idempotently per administration.
	 *
	 * Calls SettingsService::seedBbvProgrammes() and seedBudgetBbvMappings().
	 * Both seeders dedupe on natural keys per REQ-BBVW-001 / REQ-BBVW-002 so
	 * re-runs never create duplicates and operator edits persist across
	 * upgrades. Skipped when administration_id is not configured (C2 —
	 * prevents "default" contamination of real tenant data).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
	 */
	private function seedBbvWaterschappenDemo(IOutput $output): void {
		$settings = $this->settingsService->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			$output->info('Shillinq: BBV waterschappen demo seed skipped (no default administration configured)');
			return;
		}

		$output->info('Seeding BBVProgramme demo records...');
		$progResult = $this->settingsService->seedBbvProgrammes(administrationId: $administrationId);
		if (($progResult['success'] ?? false) === true) {
			$output->info(
				'BBVProgramme demo records seeded: '
				. ($progResult['seeded'] ?? 0) . ' created, ' . ($progResult['skipped'] ?? 0) . ' skipped.'
			);
		}

		if (($progResult['success'] ?? false) !== true) {
			$output->warning('BBVProgramme demo seeding issue: ' . ($progResult['message'] ?? 'unknown error'));
			return;
		}

		$output->info('Seeding BudgetBBVMapping demo records...');
		$mapResult = $this->settingsService->seedBudgetBbvMappings(administrationId: $administrationId);
		if (($mapResult['success'] ?? false) === true) {
			$output->info(
				'BudgetBBVMapping demo records seeded: '
				. ($mapResult['seeded'] ?? 0) . ' created, ' . ($mapResult['skipped'] ?? 0) . ' skipped.'
			);
			return;
		}

		$output->warning('BudgetBBVMapping demo seeding issue: ' . ($mapResult['message'] ?? 'unknown error'));

	}//end seedBbvWaterschappenDemo()

	/**
	 * Seed the chart of accounts from the configured RGS template, idempotently.
	 *
	 * Seeding is skipped when administration_id is not configured (C2) to prevent
	 * orphan accounts under a "default" id that contaminates real tenant data later.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function seedChartOfAccounts(IOutput $output): void {
		$settings = $this->settingsService->getSettings();
		$templateRaw = ($settings['rgs_template'] ?? '');
		$template = 'mkb';
		if ($templateRaw !== '') {
			$template = $templateRaw;
		}

		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			// C2: seeding under a hardcoded "default" administrationId contaminates
			// real tenants when the admin later configures a proper id. Skip the seed
			// entirely and surface a clear admin notice instead.
			$output->warning(
				'Shillinq: administration_id not configured — skipping chart of accounts seed. '
				. 'Go to Shillinq admin settings, set administration_id, then click '
				. '"Seed Chart of Accounts" to initialise.'
			);
			$this->logger->warning(
				'Shillinq: administration_id not configured, skipping chart of accounts seed'
			);
			return;
		}

		$output->info('Seeding chart of accounts (template: ' . $template . ')...');

		$seedResult = $this->settingsService->seedRgsTemplate(
			templateVariant: $template,
			administrationId: $administrationId
		);

		if ($seedResult['success'] === true) {
			$seeded = ($seedResult['seeded'] ?? 0);
			$skipped = ($seedResult['skipped'] ?? 0);
			$output->info(
				'Chart of accounts seeded: ' . $seeded . ' created, ' . $skipped . ' skipped (already exist).'
			);
		}

		if ($seedResult['success'] !== true) {
			$message = ($seedResult['message'] ?? 'unknown error');
			$output->warning('Chart of accounts seeding issue: ' . $message);
		}

		$allocationResult = $this->settingsService->seedAllocationRules(administrationId: $administrationId);

		if ($allocationResult['success'] === true) {
			$seeded = ($allocationResult['seeded'] ?? 0);
			$skipped = ($allocationResult['skipped'] ?? 0);
			$output->info(
				'AllocationRule examples seeded: ' . $seeded . ' created, ' . $skipped . ' skipped (already exist).'
			);
		}

		if ($allocationResult['success'] !== true) {
			$message = ($allocationResult['message'] ?? 'unknown error');
			$output->warning('AllocationRule seeding issue: ' . $message);
		}

	}//end seedChartOfAccounts()

	/**
	 * Seed the ReimbursementPolicy master-data records from
	 * reimbursement-policies-2026.json, idempotently per administration.
	 *
	 * Deduplication key is (policyId, administrationId): if a ReimbursementPolicy
	 * record with the same policyId already exists for the seeded administration
	 * it is skipped. Re-running the repair step is safe and preserves operator
	 * edits per REQ-ERP-004 (one active policy per administration).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-18
	 */
	private function seedReimbursementPolicies(IOutput $output): void {
		$seedPath = __DIR__ . '/../Settings/seeds/reimbursement-policies-2026.json';
		if (file_exists($seedPath) === false) {
			$output->warning('Shillinq: ReimbursementPolicy seed file not found, skipping');
			return;
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			$output->warning('Shillinq: failed to read ReimbursementPolicy seed file, skipping');
			return;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$output->warning('Shillinq: failed to parse ReimbursementPolicy seed file: ' . json_last_error_msg());
			return;
		}

		$policies = ($data['policies'] ?? []);
		if (empty($policies) === true) {
			$output->info('Shillinq: ReimbursementPolicy seed file contains no policies, skipping');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();
			$settings = $this->settingsService->getSettings();
			$administrationId = ($settings['administration_id'] ?? '');
			$seeded = 0;
			$skipped = 0;

			if ($administrationId === '') {
				$output->info('Shillinq: ReimbursementPolicy seed skipped (no default administration configured)');
				return;
			}

			foreach ($policies as $policy) {
				$policyId = ($policy['policyId'] ?? null);
				if ($policyId === null) {
					continue;
				}

				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('ReimbursementPolicy')
					->findAll(
						[
							'filters' => [
								'policyId' => $policyId,
								'administrationId' => $administrationId,
							],
							'limit' => 1,
						]
					);

				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

				$policy['administrationId'] = $administrationId;
				$objectService->saveObject(
					object: $policy,
					register: $registerSlug,
					schema: 'ReimbursementPolicy',
					// System seed inside a no-session repair step — bypass RBAC.
					_rbac: false,
				);
				$seeded++;
			}//end foreach

			$output->info(
				'Shillinq: ReimbursementPolicy seeded: ' . $seeded . ' created, ' . $skipped . ' skipped (already exist).'
			);
		} catch (\Throwable $e) {
			$output->warning('Shillinq: ReimbursementPolicy seeding failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: ReimbursementPolicy seeding failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end seedReimbursementPolicies()

	/**
	 * Seed the PassThroughMarkupRule master-data records from
	 * passthrough-markup-rules-2026.json, idempotently per administration.
	 *
	 * Deduplication key is (ruleId, administrationId): re-running the repair
	 * step preserves operator edits per REQ-ERP-005. Lookup priority — customer
	 * + category > customer-only > global default — is enforced declaratively
	 * by Receipt/MileageEntry/PerDiem x-openregister-calculations.markupLookup;
	 * this seeder only loads the canonical RULE-001..RULE-004 fixtures.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-19
	 */
	private function seedPassThroughMarkupRules(IOutput $output): void {
		$seedPath = __DIR__ . '/../Settings/seeds/passthrough-markup-rules-2026.json';
		if (file_exists($seedPath) === false) {
			$output->warning('Shillinq: PassThroughMarkupRule seed file not found, skipping');
			return;
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			$output->warning('Shillinq: failed to read PassThroughMarkupRule seed file, skipping');
			return;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$output->warning('Shillinq: failed to parse PassThroughMarkupRule seed file: ' . json_last_error_msg());
			return;
		}

		$rules = ($data['rules'] ?? []);
		if (empty($rules) === true) {
			$output->info('Shillinq: PassThroughMarkupRule seed file contains no rules, skipping');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();
			$settings = $this->settingsService->getSettings();
			$administrationId = ($settings['administration_id'] ?? '');
			$seeded = 0;
			$skipped = 0;

			if ($administrationId === '') {
				$output->info('Shillinq: PassThroughMarkupRule seed skipped (no default administration configured)');
				return;
			}

			foreach ($rules as $rule) {
				$ruleId = ($rule['ruleId'] ?? null);
				if ($ruleId === null) {
					continue;
				}

				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('PassThroughMarkupRule')
					->findAll(
						[
							'filters' => [
								'ruleId' => $ruleId,
								'administrationId' => $administrationId,
							],
							'limit' => 1,
						]
					);

				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

				$rule['administrationId'] = $administrationId;
				$objectService->saveObject(
					object: $rule,
					register: $registerSlug,
					schema: 'PassThroughMarkupRule',
					// System seed inside a no-session repair step — bypass RBAC.
					_rbac: false,
				);
				$seeded++;
			}//end foreach

			$output->info(
				'Shillinq: PassThroughMarkupRule seeded: ' . $seeded . ' created, ' . $skipped . ' skipped (already exist).'
			);
		} catch (\Throwable $e) {
			$output->warning('Shillinq: PassThroughMarkupRule seeding failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: PassThroughMarkupRule seeding failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end seedPassThroughMarkupRules()

	/**
	 * Import the RJ 270 statement-presentation manifests idempotently.
	 *
	 * Delegates to StatementManifestService::import(), which preserves
	 * operator edits across re-runs (REQ-FS-002). Non-fatal — a failure here
	 * logs a warning but does not abort the repair step.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-financial-statements/spec.md (REQ-FS-002)
	 */
	private function importStatementManifests(IOutput $output): void {
		$output->info('Importing RJ 270 statement presentation manifests...');

		$result = $this->manifestService->import();

		if (($result['success'] ?? false) !== true) {
			$output->warning('Statement manifest import issue: ' . ($result['message'] ?? 'unknown error'));
			return;
		}

		$output->info(
			'Statement manifests imported: ' . ($result['imported'] ?? 0) . ' created, '
			. ($result['skipped'] ?? 0) . ' skipped (operator edits preserved).'
		);

	}//end importStatementManifests()

	/**
	 * Seed mandaat (signing-authority) templates for verplichtingenadministratie, idempotently.
	 *
	 * Calls SettingsService::seedMandateTemplates() with the configured
	 * administrationId. Requires a non-empty administrationId (C2); skips with a
	 * warning when unset. Idempotent: mandates matched by mandaatcode +
	 * administrationId are skipped, preserving operator edits per REQ-VPL-002.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-2.2
	 */
	private function seedMandateTemplates(IOutput $output): void {
		$settings = $this->settingsService->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			$output->warning(
				'Shillinq: administration_id not configured — skipping mandaat template seed.'
			);
			return;
		}

		$output->info('Seeding mandaat templates...');
		$result = $this->settingsService->seedMandateTemplates(administrationId: $administrationId);

		if (($result['success'] ?? false) === true) {
			$output->info(
				'Mandate templates seeded: ' . ($result['seeded'] ?? 0) . ' created, ' . ($result['skipped'] ?? 0) . ' skipped.'
			);
		}

		if (($result['success'] ?? false) !== true) {
			$output->warning('Mandate templates seeding issue: ' . ($result['message'] ?? 'unknown error'));
		}

	}//end seedMandateTemplates()

	/**
	 * Seed the default Archiefwet retention policies, idempotently.
	 *
	 * The three organization-wide default policies (financial 5yr, tax 7yr,
	 * general 3yr) are imported via SettingsService and matched by slug so
	 * re-runs on upgrade do not duplicate or overwrite operator edits
	 * (REQ-RET-012). No administration_id is required — retention policies are
	 * organization-wide defaults.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-archiefwet-retention/spec.md (Task 13)
	 */
	private function seedRetentionPolicies(IOutput $output): void {
		$output->info('Seeding default retention policies...');

		$result = $this->settingsService->seedRetentionPolicies();

		if (($result['success'] ?? false) === true) {
			$seeded = ($result['seeded'] ?? 0);
			$skipped = ($result['skipped'] ?? 0);
			$output->info(
				'Retention policies seeded: ' . $seeded . ' created, ' . $skipped . ' skipped (already exist).'
			);
		}

		if (($result['success'] ?? false) !== true) {
			$message = ($result['message'] ?? 'unknown error');
			$output->warning('Retention policy seeding issue: ' . $message);
		}

	}//end seedRetentionPolicies()

	/**
	 * Import the RJ 270 statement presentation manifests, idempotently.
	 *
	 * Calls SettingsService::seedStatementManifests() which imports the balance
	 * sheet, P&L, and cash-flow presentation manifests into app config and skips
	 * any manifest the operator has already customised per REQ-FS-002.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-financial-statements/spec.md
	 */
	private function seedStatementManifests(IOutput $output): void {
		$output->info('Importing RJ 270 statement presentation manifests...');

		$result = $this->settingsService->seedStatementManifests();

		if (($result['success'] ?? false) === true) {
			$output->info(
				'Statement manifests imported: ' . ($result['imported'] ?? 0) . ' imported, '
				. ($result['skipped'] ?? 0) . ' skipped (already present).'
			);
		}

		if (($result['success'] ?? false) !== true) {
			$output->warning('Statement manifest import issue: ' . ($result['message'] ?? 'unknown error'));
		}

	}//end seedStatementManifests()

	/**
	 * Seed WMO (Wet Markt en Overheid) example commercial activities,
	 * ABBs and IKP records idempotently per administration (REQ-WMO-001 /
	 * REQ-WMO-002 / REQ-WMO-005).
	 *
	 * Calls SettingsService::seedWmoCommercialActivities() which iterates
	 * the four seed files under `lib/Settings/seeds/commercial-activities/`
	 * and dedupes on natural keys (code / kenmerk / (activity, periode))
	 * stamped with the configured administrationId. Records ship in paused /
	 * concept / voorlopig lifecycle states so the seed never contaminates
	 * live reporting. Skipped when administration_id is not configured (C2).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-17
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-18
	 */
	private function seedWmoCommercialActivities(IOutput $output): void {
		$settings = $this->settingsService->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			$output->info('Shillinq: WMO commercial activity seed skipped (no default administration configured)');
			return;
		}

		$output->info('Seeding WMO commercial activities, ABBs and IKP records...');
		$result = $this->settingsService->seedWmoCommercialActivities(administrationId: $administrationId);

		if (($result['success'] ?? false) === true) {
			$output->info(
				'WMO commercial activities seeded: '
				. ($result['seeded'] ?? 0) . ' created, ' . ($result['skipped'] ?? 0) . ' skipped.'
			);
			return;
		}

		$output->warning('WMO seeding issue: ' . ($result['message'] ?? 'unknown error'));

	}//end seedWmoCommercialActivities()

	/**
	 * Seed NL-taxonomie SBR/XBRL mapping templates idempotently
	 * (REQ-SBR-005, REQ-SBR-006).
	 *
	 * Iterates every file under `lib/Settings/seeds/sbr-mappings/` and seeds
	 * the contained mapping template as an OpenRegister `Mapping` record
	 * (consumed by `XbrlInstance.mappingId`). Deduplication key is the
	 * mapping slug `(entryPoint, taxonomyVersion)`; if a Mapping with the
	 * same `reference` (or `slug`) already exists in the OR MappingMapper,
	 * the seed is skipped — operator edits to the line→concept records
	 * persist across repair re-runs.
	 *
	 * The MappingMapper consumption is wrapped in a defensive Throwable
	 * catch: when the OR `MappingMapper` service is not available
	 * (OpenRegister disabled / pre-install), the seed degrades to a
	 * warning instead of fatal — same shape as the surrounding seed
	 * methods.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-sbr-xbrl-reporting/tasks.md#task-8
	 */
	private function seedSbrMappings(IOutput $output): void {
		$seedDir = __DIR__ . '/../Settings/seeds/sbr-mappings';
		if (is_dir($seedDir) === false) {
			$output->info('Shillinq: SBR/XBRL mapping seed dir not present, skipping');
			return;
		}

		$files = glob($seedDir . '/*.json');
		if ($files === false || empty($files) === true) {
			$output->info('Shillinq: SBR/XBRL mapping seed dir empty, skipping');
			return;
		}

		try {
			// OR MappingMapper is the canonical surface for Mapping CRUD;
			// it carries the findByRef() dedupe path REQ-SBR-006 expects.
			$mappingMapper = $this->container->get('OCA\OpenRegister\Db\MappingMapper');
		} catch (\Throwable $e) {
			$output->warning(
				'Shillinq: OpenRegister MappingMapper not available, skipping SBR/XBRL mapping seed ('
				. $e->getMessage() . ')'
			);
			return;
		}

		$seeded = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($files as $file) {
			$content = file_get_contents($file);
			if ($content === false) {
				$output->warning('Shillinq: failed to read SBR mapping seed ' . basename($file));
				$failed++;
				continue;
			}

			$data = json_decode($content, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$output->warning(
					'Shillinq: failed to parse SBR mapping seed ' . basename($file) . ': ' . json_last_error_msg()
				);
				$failed++;
				continue;
			}

			$mapping = ($data['mapping'] ?? null);
			if (is_array($mapping) === false) {
				$output->warning('Shillinq: SBR mapping seed ' . basename($file) . ' has no `mapping` block, skipping');
				$failed++;
				continue;
			}

			$slug = ($mapping['slug'] ?? null);
			if ($slug === null || $slug === '') {
				$output->warning('Shillinq: SBR mapping seed ' . basename($file) . ' has no slug, skipping');
				$failed++;
				continue;
			}

			try {
				// Dedupe on the slug/reference — operator-edited mappings persist.
				$existing = $mappingMapper->findByRef($slug);
				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

				$mappingMapper->createFromArray(
					[
						'reference' => $slug,
						'name' => ($mapping['name'] ?? $slug),
						'description' => 'SBR/XBRL line→concept mapping seeded by '
							. 'add-shillinq-sbr-xbrl-reporting for entry point '
							. ($mapping['entryPoint'] ?? 'unknown') . ' taxonomy '
							. ($mapping['taxonomyVersion'] ?? 'unknown') . '.',
						'mapping' => ($mapping['records'] ?? []),
					]
				);
				$seeded++;
			} catch (\Throwable $e) {
				$output->warning(
					'Shillinq: SBR mapping seed ' . basename($file) . ' import failed: ' . $e->getMessage()
				);
				$this->logger->warning(
					'Shillinq: SBR mapping seed import failed',
					[
						'file' => basename($file),
						'exception' => $e->getMessage(),
					]
				);
				$failed++;
			}//end try
		}//end foreach

		$output->info(
			'Shillinq: SBR/XBRL mappings seeded: ' . $seeded . ' created, ' . $skipped
			. ' skipped (already exist), ' . $failed . ' failed.'
		);

	}//end seedSbrMappings()

	/**
	 * Seed FixedAsset + DepreciationSchedule demo records idempotently
	 * for `bookkeeping-fixed-assets-depreciation` Task 15 (REQ-FA-001..010).
	 *
	 * Calls SettingsService::seedFixedAssetsDemo() which imports four
	 * realistic Dutch SMB scenarios from
	 * `lib/Settings/seeds/fixed-assets-demo.json` (company vehicle, office
	 * building, computer equipment, retired asset) and their 2026
	 * DepreciationSchedule records. Skipped when administration_id is not
	 * configured (C2 — prevents "default" contamination of real tenant data).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	private function seedFixedAssetsDemo(IOutput $output): void {
		$settings = $this->settingsService->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');

		if ($administrationId === '') {
			$output->info('Shillinq: FixedAssets demo seed skipped (no default administration configured)');
			return;
		}

		$output->info('Seeding FixedAsset + DepreciationSchedule demo records...');
		$result = $this->settingsService->seedFixedAssetsDemo(administrationId: $administrationId);

		if (($result['success'] ?? false) === true) {
			$output->info(
				'FixedAsset demo seeded: '
				. ($result['seededAssets'] ?? 0) . ' assets created, ' . ($result['skippedAssets'] ?? 0) . ' skipped; '
				. ($result['seededSchedules'] ?? 0) . ' schedules created, ' . ($result['skippedSchedules'] ?? 0) . ' skipped.'
			);
			return;
		}

		$output->warning('FixedAssets demo seeding issue: ' . ($result['message'] ?? 'unknown error'));

	}//end seedFixedAssetsDemo()
}//end class
