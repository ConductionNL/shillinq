<?php

/**
 * Shillinq RetireCostProjectStep Repair Step
 *
 * Idempotent, fail-safe migration that converts every existing `CostProject`
 * object into a project-flavoured `AnalyticalDimension` (dimensionType=project)
 * per REQ-RCP-005 from change `retire-cost-project`.
 *
 * ## Field mapping (CostProject → AnalyticalDimension)
 *
 *   projectNumber          → projectNumber  + minted code = "CP-<projectNumber>"
 *   name                   → name
 *   description            → description
 *   startDate              → startDate
 *   endDate                → endDate
 *   totalBudget            → totalBudget
 *   totalEstimatedCosts    → totalEstimatedCosts
 *   costsIncurredToDate    → *dropped* (re-derived from GL as spentToDate)
 *   administrationId       → administrationId
 *   organizationId         → organizationId
 *   costCenterCode         → parentCode   (migrated project nests under its dept)
 *   lifecycleState:
 *       draft|active|on-hold → active
 *       closed|archived      → archived
 *   —                      → dimensionType = "project"
 *   —                      → externalProjectRef = null (operator links later)
 *   —                      → migratedFrom = <CostProject id>  (idempotency marker)
 *
 * ## Guarantees
 *
 *   1. Idempotent: skips any CostProject whose id already appears as a
 *      `migratedFrom` marker on an existing AnalyticalDimension row.
 *      Re-runs are no-ops.
 *
 *   2. Fail-safe: a CostProject that cannot be mapped (missing required field,
 *      irrecoverable code collision) is logged and LEFT IN PLACE — never
 *      deleted. The step deletes NO objects; source removal is a later
 *      operator-confirmed step.
 *
 *   3. Collision-safe: `code` is namespaced "CP-<projectNumber>". On collision
 *      the step appends "-N" (N=2,3,…) suffixes until a free slot is found
 *      (max 99 attempts). Collision is reported; never silently overwritten.
 *
 *   4. Fail-soft outer guard: a top-level \Throwable catch prevents this step
 *      from blocking the Nextcloud upgrade path.
 *
 * Runs on `occ maintenance:repair` and on `occ app:enable shillinq`
 * (registered in `appinfo/info.xml` repair-steps, after UnifyAnalyticalDimensions).
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
 * @spec openspec/changes/retire-cost-project/tasks.md#phase-4
 * @spec openspec/changes/retire-cost-project/specs/retire-cost-project/spec.md (REQ-RCP-005)
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
 * Repair step that migrates CostProject objects into project-flavoured
 * AnalyticalDimension rows (dimensionType=project). Idempotent,
 * fail-safe, collision-safe, never deletes source records.
 *
 * @spec openspec/changes/retire-cost-project/tasks.md#phase-4
 */
class RetireCostProjectStep implements IRepairStep {
	use ReadsSourceRowsInBatches;
	use RunsUnderSystemIdentity;

	/**
	 * Maximum suffix attempts when a minted code collides.
	 *
	 * @var int
	 */
	private const MAX_COLLISION_ATTEMPTS = 99;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (register slug).
	 * @param LoggerInterface $logger Logger.
	 * @param ContainerInterface $container DI container (lazy OR ObjectService resolution).
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
	 * @spec openspec/changes/retire-cost-project/tasks.md#phase-4
	 */
	public function getName(): string {
		return 'Shillinq: retire CostProject — convert to project-flavoured AnalyticalDimension (dimensionType=project)';
	}//end getName()

	/**
	 * Run the migration. Idempotent and fail-safe.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-cost-project/specs/retire-cost-project/spec.md (REQ-RCP-005)
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function run(IOutput $output): void {
		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses every write for 'Anonymous'. Without it this retirement moves
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
	 * The retirement itself.
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

			$output->info('Shillinq: RetireCostProjectStep — scanning CostProject objects …');

			$result = $this->migrateCostProjects(
				objectService: $objectService,
				registerSlug: $registerSlug,
				output: $output
			);

			$output->info(
				'Shillinq: RetireCostProjectStep complete — '
				. $result['migrated'] . ' migrated, '
				. $result['skipped'] . ' skipped (already migrated), '
				. $result['failed'] . ' failed (left in place, see log).'
			);
		} catch (\Throwable $e) {
			// Fail-soft: migration failure MUST NOT block the NC upgrade.
			$output->warning('Shillinq: RetireCostProjectStep failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: RetireCostProjectStep outer failure',
				['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);
		}//end try

	}//end runInner()

	/**
	 * Iterate over all CostProject objects and convert each to a
	 * project-flavoured AnalyticalDimension. Returns migration counts.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param IOutput $output The repair output.
	 *
	 * @return array{migrated: int, skipped: int, failed: int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function migrateCostProjects(object $objectService, string $registerSlug, IOutput $output): array {
		$migrated = 0;
		$skipped = 0;
		$failed = 0;

		try {
			$costProjects = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'CostProject');
		} catch (\Throwable $e) {
			$output->info('Shillinq: RetireCostProjectStep — no CostProject schema or no records found (' . $e->getMessage() . '); skipping.');
			return ['migrated' => 0, 'skipped' => 0, 'failed' => 0];
		}

		if ($costProjects === []) {
			$output->info('Shillinq: RetireCostProjectStep — no CostProject records found; nothing to migrate.');
			return ['migrated' => 0, 'skipped' => 0, 'failed' => 0];
		}

		foreach ($costProjects as $costProject) {
			$arr = $this->rowPayload(row: $costProject);
			$id = (string)($arr['id'] ?? ($arr['uuid'] ?? ''));

			if ($id === '') {
				$output->warning('Shillinq: RetireCostProjectStep — CostProject record has no id; skipping.');
				$failed++;
				continue;
			}

			try {
				// Idempotency check: skip if already migrated.
				if ($this->isAlreadyMigrated(
					objectService: $objectService,
					registerSlug: $registerSlug,
					costProjectId: $id
				) === true
				) {
					$skipped++;
					continue;
				}

				$projectNumber = (string)($arr['projectNumber'] ?? '');
				if ($projectNumber === '') {
					$output->warning(
						'Shillinq: RetireCostProjectStep — CostProject id=' . $id . ' has no projectNumber; cannot mint code; skipping (left in place).'
					);
					$failed++;
					continue;
				}

				// Mint a namespaced code; disambiguate on collision.
				$code = $this->mintCode(
					objectService: $objectService,
					registerSlug: $registerSlug,
					projectNumber: $projectNumber,
					output: $output
				);

				if ($code === null) {
					$output->warning(
						'Shillinq: RetireCostProjectStep — CostProject id=' . $id . ' projectNumber=' . $projectNumber
						. ': irrecoverable code collision after ' . self::MAX_COLLISION_ATTEMPTS . ' attempts; skipping (left in place).'
					);
					$failed++;
					continue;
				}

				$dimension = $this->buildDimensionRecord(
					source: $arr,
					code: $code,
					costProjectId: $id
				);

				$objectService->saveObject(
					object: $dimension,
					register: $registerSlug,
					schema: 'AnalyticalDimension',
					_rbac: false,
					_multitenancy: false,
				);

				$migrated++;
			} catch (\Throwable $e) {
				// Per-record failure is soft — log and continue.
				$output->warning(
					'Shillinq: RetireCostProjectStep — failed to migrate CostProject id=' . $id . ': ' . $e->getMessage()
				);
				$this->logger->warning(
					'Shillinq: RetireCostProjectStep — migration failed for CostProject id=' . $id,
					['exception' => $e->getMessage()]
				);
				$failed++;
			}//end try
		}//end foreach

		return ['migrated' => $migrated, 'skipped' => $skipped, 'failed' => $failed];
	}//end migrateCostProjects()

	/**
	 * Check whether a CostProject has already been migrated by looking for
	 * an AnalyticalDimension carrying `migratedFrom = <costProjectId>`.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $costProjectId The source CostProject id.
	 *
	 * @return bool True if already migrated (skip); false if not yet migrated.
	 */
	private function isAlreadyMigrated(object $objectService, string $registerSlug, string $costProjectId): bool {
		try {
			$existing = $objectService
				->setRegister($registerSlug)
				->setSchema('AnalyticalDimension')
				->findAll(
					[
						'filters' => ['migratedFrom' => $costProjectId],
						'limit' => 1,
					]
				);

			return is_array($existing) === true && count($existing) > 0;
		} catch (\Throwable) {
			// On lookup error, conservatively assume not-yet-migrated so the
			// record is attempted (the subsequent saveObject will also fail
			// safely if there is a real OR issue).
			return false;
		}

	}//end isAlreadyMigrated()

	/**
	 * Mint a namespaced AnalyticalDimension code "CP-<projectNumber>",
	 * appending "-N" on collision until a free slot is found.
	 *
	 * Returns null if no free slot is found within MAX_COLLISION_ATTEMPTS.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $projectNumber The source CostProject.projectNumber.
	 * @param IOutput $output The repair output (collision reporting).
	 *
	 * @return string|null Free code, or null if all attempts exhausted.
	 */
	private function mintCode(object $objectService, string $registerSlug, string $projectNumber, IOutput $output): ?string {
		// AnalyticalDimension.code must match ^[a-z][a-z0-9-]*$, so the minted code
		// must be lower-case and hyphen-normalised — a raw "CP-<projectNumber>"
		// (upper-case prefix / mixed-case number) never validates. The 'cp-' prefix
		// guarantees a leading letter. Exposed by #382 live e2e once the step
		// actually read real rows.
		$slug = strtolower($projectNumber);
		$slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
		$slug = trim($slug, '-');
		$baseCode = 'cp-' . $slug;
		$candidate = $baseCode;

		for ($attempt = 2; $attempt <= self::MAX_COLLISION_ATTEMPTS + 1; $attempt++) {
			if ($this->codeExists(objectService: $objectService, registerSlug: $registerSlug, code: $candidate) === false) {
				if ($candidate !== $baseCode) {
					$output->info(
						'Shillinq: RetireCostProjectStep — code collision on "' . $baseCode . '" resolved to "' . $candidate . '".'
					);
				}

				return $candidate;
			}

			$candidate = $baseCode . '-' . $attempt;
		}

		return null;
	}//end mintCode()

	/**
	 * Check whether an AnalyticalDimension with the given code already exists.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $code The candidate code to test.
	 *
	 * @return bool True if the code is taken; false if free.
	 */
	private function codeExists(object $objectService, string $registerSlug, string $code): bool {
		try {
			$existing = $objectService
				->setRegister($registerSlug)
				->setSchema('AnalyticalDimension')
				->findAll(
					[
						'filters' => ['code' => $code],
						'limit' => 1,
					]
				);

			return is_array($existing) === true && count($existing) > 0;
		} catch (\Throwable) {
			// On lookup error, assume the code might exist (conservative) to
			// avoid accidental overwrites.
			return true;
		}

	}//end codeExists()

	/**
	 * Build an AnalyticalDimension record from a CostProject source array,
	 * applying the field mapping table from REQ-RCP-005.
	 *
	 * @param array<string,mixed> $source The CostProject object as an array.
	 * @param string $code The minted AnalyticalDimension code.
	 * @param string $costProjectId The source CostProject id (idempotency marker).
	 *
	 * @return array<string,mixed> The AnalyticalDimension record to save.
	 *
	 * @spec openspec/changes/retire-cost-project/specs/retire-cost-project/spec.md (REQ-RCP-005)
	 */
	private function buildDimensionRecord(array $source, string $code, string $costProjectId): array {
		$record = [
			'dimensionType' => 'project',
			'code' => $code,
			'name' => (string)($source['name'] ?? $code),
			// AnalyticalDimension requires dataType; a migrated project dimension is
			// a categorical code, so it is a 'string' dimension. Omitting this made
			// every migration fail validation once the step actually read rows
			// (the limit=>0 dead-read had hidden it — #382 live e2e).
			'dataType' => 'string',
			'administrationId' => (string)($source['administrationId'] ?? ''),
			'lifecycleState' => $this->mapLifecycleState(costProjectState: (string)($source['lifecycleState'] ?? 'active')),
			'migratedFrom' => $costProjectId,
			'externalProjectRef' => null,
		];

		// Copy scalar optional fields when present.
		$optionalFields = [
			'description',
			'projectNumber',
			'organizationId',
		];
		foreach ($optionalFields as $field) {
			if (isset($source[$field]) === true && $source[$field] !== '') {
				$record[$field] = $source[$field];
			}
		}

		// Copy integer budget fields when positive.
		foreach (['totalBudget', 'totalEstimatedCosts'] as $field) {
			if (isset($source[$field]) === true) {
				$record[$field] = (int)$source[$field];
			}
		}

		// Copy date fields when non-empty.
		foreach (['startDate', 'endDate'] as $field) {
			if (isset($source[$field]) === true && $source[$field] !== '') {
				$record[$field] = (string)$source[$field];
			}
		}

		// CostCenterCode → parentCode (migrated project nests under its department).
		$costCenterCode = ($source['costCenterCode'] ?? null);
		if ($costCenterCode !== null && $costCenterCode !== '') {
			$record['parentCode'] = (string)$costCenterCode;
		}

		// CostsIncurredToDate is intentionally NOT copied — it is a GL-derived
		// read-time aggregation (spentToDate) on AnalyticalDimension and must
		// not be stored as a stale integer.
		return $record;
	}//end buildDimensionRecord()

	/**
	 * Map a CostProject lifecycleState to the AnalyticalDimension lifecycle.
	 *
	 *   Draft | active | on-hold → active
	 *   closed | archived        → archived
	 *
	 * @param string $costProjectState The source CostProject.lifecycleState.
	 *
	 * @return string The target AnalyticalDimension.lifecycleState.
	 *
	 * @spec openspec/changes/retire-cost-project/specs/retire-cost-project/spec.md (REQ-RCP-005)
	 */
	private function mapLifecycleState(string $costProjectState): string {
		return match ($costProjectState) {
			'closed', 'archived' => 'archived',
			default => 'active',
		};

	}//end mapLifecycleState()
}//end class
