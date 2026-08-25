<?php

/**
 * Shillinq UnifyAnalyticalDimensions Repair Step
 *
 * Idempotent migration that folds every existing `CostCenter` and
 * `KostenDrager` object into the unified `AnalyticalDimension` register
 * (per REQ-ADIM-006 / REQ-ADIM-007 from change
 * `unify-analytical-dimensions`).
 *
 * The three analytical-dimension registers (CostCenter / KostenDrager /
 * AnalyticalDimension) are collapsed into ONE canonical
 * `AnalyticalDimension` register discriminated by a `dimensionType`
 * enum (`cost-center` / `cost-object` / `custom`). This step:
 *
 *   1. Lists every `CostCenter` object via the OpenRegister ObjectService
 *      `findAll` and upserts each into `AnalyticalDimension` with
 *      `dimensionType = cost-center`, copying all properties verbatim
 *      (`code`, `name`, `description`, `status`, `budget`,
 *      `organizationId`, `parentCode`, `responsibleUser`,
 *      `lifecycleState`, `administrationId`, `ondernemingsActiviteit`).
 *
 *   2. Lists every `KostenDrager` object and upserts each into
 *      `AnalyticalDimension` with `dimensionType = cost-object`, copying
 *      `code`, `name`, `parentCode`, `responsibleUser`, `lifecycleState`,
 *      `administrationId`.
 *
 *   3. Matches on the natural key `(administrationId, code, dimensionType)`
 *      so a cost-center and a cost-object sharing the same `code` in the
 *      same administration remain distinct (both persist, neither clobbers
 *      the other).
 *
 *   4. Is idempotent: re-running on an already-migrated instance skips
 *      records already present in `AnalyticalDimension` for the matching
 *      natural key. No duplicates are created, no migrated state is
 *      mutated.
 *
 *   5. Does NOT delete the source `CostCenter` / `KostenDrager` objects.
 *      Only the register *declarations* are retired (removed from the
 *      shillinq_register.json); existing data objects remain accessible
 *      via the OR API until a future cleanup step.
 *
 *   6. Is fail-soft: per-record failures are caught and logged but never
 *      abort the whole migration. An outer `\Throwable` catch guards the
 *      full run; the result is reported via `IOutput::warning` so the
 *      Nextcloud upgrade path is never blocked.
 *
 * Runs on `occ maintenance:repair` and on `occ app:enable shillinq`
 * (registered in `appinfo/info.xml` repair-steps).
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unify-analytical-dimensions/tasks.md#phase-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that folds CostCenter + KostenDrager objects into the unified
 * AnalyticalDimension register (dimensionType = cost-center / cost-object).
 *
 * Idempotent, fail-soft, never deletes source records.
 *
 * @spec openspec/changes/unify-analytical-dimensions/tasks.md#phase-4
 */
class UnifyAnalyticalDimensions implements IRepairStep {
	use ReadsSourceRowsInBatches;
	use RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (register slug).
	 * @param LoggerInterface $logger The logger interface.
	 * @param ContainerInterface $container The DI container (lazy OR ObjectService resolution).
	 */
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
		private ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The repair-step display name shown in occ maintenance:repair output.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/unify-analytical-dimensions/tasks.md#phase-4
	 */
	public function getName(): string {
		return 'Shillinq: fold CostCenter + KostenDrager into unified AnalyticalDimension register (dimensionType discriminator)';
	}//end getName()

	/**
	 * Run the migration. Idempotent — never duplicates records and never
	 * mutates already-migrated state.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unify-analytical-dimensions/tasks.md#phase-4
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function run(IOutput $output): void {
		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses every write for 'Anonymous'. Without it this migration moves
		// nothing and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $this->resolveObjectServiceForIdentity(),
			work: function () use ($output): void {
				$this->runInner(output: $output);
			}
		);
	}//end run()

	/**
	 * OpenRegister's ObjectService, or null when it cannot be resolved.
	 *
	 * Null is not fatal: the work then runs without a system identity, exactly
	 * as it did before this wrapper existed.
	 *
	 * @return object|null The service.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function resolveObjectServiceForIdentity(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveObjectServiceForIdentity()

	/**
	 * The migration itself.
	 *
	 * @param IOutput $output The repair-step output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function runInner(IOutput $output): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();

			$output->info('Shillinq: UnifyAnalyticalDimensions — migrating CostCenter → cost-center …');
			$ccResult = $this->migrateCostCenters(
				objectService: $objectService,
				registerSlug: $registerSlug,
				output: $output
			);

			$output->info('Shillinq: UnifyAnalyticalDimensions — migrating KostenDrager → cost-object …');
			$kdResult = $this->migrateCostDragers(
				objectService: $objectService,
				registerSlug: $registerSlug,
				output: $output
			);

			$output->info(
				'Shillinq: UnifyAnalyticalDimensions complete — '
				. 'CostCenter: ' . $ccResult['created'] . ' created, ' . $ccResult['skipped'] . ' skipped; '
				. 'KostenDrager: ' . $kdResult['created'] . ' created, ' . $kdResult['skipped'] . ' skipped.'
			);
		} catch (\Throwable $e) {
			// Fail-soft: a migration failure must NOT block the NC upgrade.
			// Log + warn so an operator can re-run via occ maintenance:repair.
			$output->warning('Shillinq: UnifyAnalyticalDimensions failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: UnifyAnalyticalDimensions failed',
				['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);
		}//end try

	}//end runInner()

	/**
	 * Migrate all CostCenter objects into AnalyticalDimension with
	 * dimensionType=cost-center.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param IOutput $output The repair output.
	 *
	 * @return array{created: int, skipped: int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function migrateCostCenters(object $objectService, string $registerSlug, IOutput $output): array {
		$created = 0;
		$skipped = 0;

		$costCenters = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'CostCenter');

		if ($costCenters === []) {
			$output->info('Shillinq: no CostCenter records found — skipping cost-center migration.');
			return ['created' => 0, 'skipped' => 0];
		}

		foreach ($costCenters as $costCenter) {
			$arr = $this->rowPayload(row: $costCenter);
			$code = (string)($arr['code'] ?? '');
			$administrationId = (string)($arr['administrationId'] ?? '');

			if ($code === '') {
				$this->logger->warning('Shillinq: UnifyAnalyticalDimensions — CostCenter record missing code; skipped.', ['record' => $arr]);
				continue;
			}

			try {
				if ($this->analyticalDimensionExists(
					objectService: $objectService,
					registerSlug: $registerSlug,
					code: $code,
					administrationId: $administrationId,
					dimensionType: 'cost-center'
				) === true
				) {
					$skipped++;
					continue;
				}

				$record = $this->buildCostCenterRecord(source: $arr);

				$objectService->saveObject(
					object: $record,
					register: $registerSlug,
					schema: 'AnalyticalDimension',
					_rbac: false,
					_multitenancy: false,
				);
				$created++;
			} catch (\Throwable $e) {
				// Per-record failure is soft — log and continue.
				$output->warning('Shillinq: failed to migrate CostCenter code=' . $code . ': ' . $e->getMessage());
				$this->logger->warning(
					'Shillinq: UnifyAnalyticalDimensions — CostCenter migration failed for code=' . $code,
					['exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		return ['created' => $created, 'skipped' => $skipped];
	}//end migrateCostCenters()

	/**
	 * Migrate all KostenDrager objects into AnalyticalDimension with
	 * dimensionType=cost-object.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param IOutput $output The repair output.
	 *
	 * @return array{created: int, skipped: int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function migrateCostDragers(object $objectService, string $registerSlug, IOutput $output): array {
		$created = 0;
		$skipped = 0;

		$costDragers = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'KostenDrager');

		if ($costDragers === []) {
			$output->info('Shillinq: no KostenDrager records found — skipping cost-object migration.');
			return ['created' => 0, 'skipped' => 0];
		}

		foreach ($costDragers as $costDrager) {
			$arr = $this->rowPayload(row: $costDrager);
			$code = (string)($arr['code'] ?? '');
			$administrationId = (string)($arr['administrationId'] ?? '');

			if ($code === '') {
				$this->logger->warning('Shillinq: UnifyAnalyticalDimensions — KostenDrager record missing code; skipped.', ['record' => $arr]);
				continue;
			}

			try {
				if ($this->analyticalDimensionExists(
					objectService: $objectService,
					registerSlug: $registerSlug,
					code: $code,
					administrationId: $administrationId,
					dimensionType: 'cost-object'
				) === true
				) {
					$skipped++;
					continue;
				}

				$record = $this->buildCostDragerRecord(source: $arr);

				$objectService->saveObject(
					object: $record,
					register: $registerSlug,
					schema: 'AnalyticalDimension',
					_rbac: false,
					_multitenancy: false,
				);
				$created++;
			} catch (\Throwable $e) {
				$output->warning('Shillinq: failed to migrate KostenDrager code=' . $code . ': ' . $e->getMessage());
				$this->logger->warning(
					'Shillinq: UnifyAnalyticalDimensions — KostenDrager migration failed for code=' . $code,
					['exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		return ['created' => $created, 'skipped' => $skipped];
	}//end migrateKostenDragers()

	/**
	 * Check whether an AnalyticalDimension record already exists for the
	 * given (administrationId, code, dimensionType) natural key.
	 *
	 * This is the idempotency guard per REQ-ADIM-007.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $code The dimension code to look up.
	 * @param string $administrationId The administration scope.
	 * @param string $dimensionType The dimension type discriminator.
	 *
	 * @return bool True when a matching record already exists; false otherwise.
	 */
	private function analyticalDimensionExists(
		object $objectService,
		string $registerSlug,
		string $code,
		string $administrationId,
		string $dimensionType,
	): bool {
		$existing = $objectService
			->setRegister($registerSlug)
			->setSchema('AnalyticalDimension')
			->findAll(
				[
					'limit' => 1,
					// OpenRegister's key is 'filters' (plural); the old 'filter'
					// was IGNORED, so this returned unfiltered rows and every
					// source was reported "already exists" and skipped — 0 created
					// on any instance that had any AnalyticalDimension row
					// (#382 live e2e). All three keys are top-level (filterable).
					'filters' => [
						'code' => $code,
						'administrationId' => $administrationId,
						'dimensionType' => $dimensionType,
					],
				]
			);

		return is_array($existing) === true && count($existing) > 0;
	}//end analyticalDimensionExists()

	/**
	 * Build an AnalyticalDimension record from a CostCenter source array.
	 *
	 * Copies all cost-center-specific properties verbatim.
	 * Sets dimensionType = cost-center.
	 *
	 * @param array<string,mixed> $source The CostCenter object as an array.
	 *
	 * @return array<string,mixed> The AnalyticalDimension record to save.
	 */
	private function buildCostCenterRecord(array $source): array {
		$record = [
			'dimensionType' => 'cost-center',
			'code' => (string)($source['code'] ?? ''),
			'name' => (string)($source['name'] ?? ''),
			// AnalyticalDimension requires dataType; a folded cost-center is a
			// categorical code -> 'string'. Omitting it failed every migration
			// once the step actually read rows (#382 live e2e).
			'dataType' => 'string',
			'administrationId' => (string)($source['administrationId'] ?? ''),
			'lifecycleState' => (string)($source['lifecycleState'] ?? 'active'),
		];

		// Copy optional cost-center-specific fields when present.
		foreach (['description', 'status', 'organizationId', 'parentCode', 'responsibleUser'] as $field) {
			if (isset($source[$field]) === true && $source[$field] !== '') {
				$record[$field] = $source[$field];
			}
		}

		if (isset($source['budget']) === true) {
			$record['budget'] = $source['budget'];
		}

		$record['enterpriseActivity'] = (bool)($source['enterpriseActivity'] ?? false);

		return $record;
	}//end buildCostCenterRecord()

	/**
	 * Build an AnalyticalDimension record from a KostenDrager source array.
	 *
	 * KostenDrager carries only: code, name, parentCode, responsibleUser,
	 * lifecycleState, administrationId. Budget and ondernemingsActiviteit
	 * were never built on KostenDrager and are intentionally omitted.
	 *
	 * @param array<string,mixed> $source The KostenDrager object as an array.
	 *
	 * @return array<string,mixed> The AnalyticalDimension record to save.
	 */
	private function buildCostDragerRecord(array $source): array {
		$record = [
			'dimensionType' => 'cost-object',
			'code' => (string)($source['code'] ?? ''),
			'name' => (string)($source['name'] ?? ''),
			// AnalyticalDimension requires dataType (see buildCostCenterRecord).
			'dataType' => 'string',
			'administrationId' => (string)($source['administrationId'] ?? ''),
			'lifecycleState' => (string)($source['lifecycleState'] ?? 'active'),
		];

		// Copy optional fields when present.
		foreach (['description', 'parentCode', 'responsibleUser'] as $field) {
			if (isset($source[$field]) === true && $source[$field] !== '') {
				$record[$field] = $source[$field];
			}
		}

		return $record;
	}//end buildKostenDragerRecord()
}//end class
