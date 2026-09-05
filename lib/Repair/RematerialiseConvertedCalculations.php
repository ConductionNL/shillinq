<?php

/**
 * Shillinq RematerialiseConvertedCalculations Repair Step
 *
 * `revive-declarative-calc-layer` converted 32 `x-openregister-calculations`
 * blocks from dead infix-string expressions to the JSON-AST dialect
 * `CalculationEvaluator` actually runs, and added `materialise: true` so
 * `CalculationOnSaveListener` persists the derived field. That listener only
 * fires **on save** — any object that already existed before this change
 * shipped carries stale/absent values for the newly-materialised fields
 * until it is next saved (design.md "Materialise on legacy objects" risk;
 * OR's own `RematerialiseCalculationsCommand` covers this generically for an
 * operator running it by hand).
 *
 * This repair step is the automatic, in-app counterpart: it re-saves every
 * existing object on each of the 17 schemas this change touched (the schemas
 * carrying a Bucket-1 or Bucket-2 per-object calc — see
 * `openspec/changes/revive-declarative-calc-layer/design.md` §"Per-calc
 * audit table"), which is a plain no-field-change UPDATE through
 * `ObjectService::saveObject()`. Re-saving triggers `CalculationOnSaveListener`
 * exactly as a genuine edit would, so every declared calc on that schema
 * (including ones outside this change's scope, e.g. the
 * `declarative-calc-refs`-owned `@ref.*`/`@aggregate.*` calcs that already
 * share some of these schemas) recomputes and materialises consistently.
 *
 * Bucket-3a (aggregation) calcs — KorRegime.ytdRevenue,
 * ZzpDeduction.ytdQualifyingHours/taxableProfit — are NOT per-object
 * materialised fields; the aggregation engine computes them at query time,
 * so they need no backfill and their schemas are not in the resave list
 * purely for that reason (ZzpDeduction IS in the list for its Bucket-1
 * fields). The 3 calcs reclassified out of this change to the guard
 * follow-up (UrenRegistratie.utilizationPercent,
 * DepreciationSchedule.bookValue/depreciationAmount) are likewise excluded
 * — they were never Bucket-1/2 conversions of this change.
 *
 * Idempotent — re-saving an object with its own unchanged field values is a
 * no-op materially (only the derived fields may change), and safe to re-run
 * on every `occ maintenance:repair` / `occ app:enable shillinq` without
 * side effects beyond recomputing the derived fields.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/revive-declarative-calc-layer/tasks.md#4-verification
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Repair step that re-saves every existing object on the 17 schemas whose
 * per-object calcs `revive-declarative-calc-layer` converted to JSON-AST +
 * `materialise: true`, so pre-existing objects backfill the newly-computed
 * derived fields exactly as OR's `RematerialiseCalculationsCommand` would.
 *
 * @spec openspec/changes/revive-declarative-calc-layer/tasks.md#4-verification
 */
class RematerialiseConvertedCalculations implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * Schema slugs carrying a Bucket-1 or Bucket-2 per-object calc this
	 * change converted to JSON-AST + `materialise: true`. See design.md's
	 * per-calc audit table (#1-29, minus the 3 fields reclassified to the
	 * `-guards` follow-up).
	 *
	 * @var array<int,string>
	 */
	private const SCHEMAS = [
		'BankConnection',
		'Account',
		'RetentionRule',
		'kernGegevensConfig',
		'FixedAsset',
		'RateSchedule',
		'MileageEntry',
		'PerDiem',
		'RepaymentInstallment',
		'WinstToerekening',
		'ZzpDeduction',
		'SisaReport',
		'InventoryReorderRule',
		'engagement',
		'ProjectAssignment',
		'VatReturn',
		'InnovatieboxElection',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (register slug).
	 * @param LoggerInterface $logger The logger interface.
	 * @param ContainerInterface $container The DI container (lazy OR ObjectService resolution).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
		private ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/revive-declarative-calc-layer/tasks.md#4-verification
	 */
	public function getName(): string {
		return 'Shillinq: rematerialise converted calc fields on existing objects (revive-declarative-calc-layer)';
	}//end getName()

	/**
	 * Re-save every existing object on each converted schema, triggering
	 * CalculationOnSaveListener to recompute + persist the newly-materialised
	 * derived fields. Best-effort per-schema: a failure on one schema does
	 * not prevent the others from backfilling.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/revive-declarative-calc-layer/tasks.md#4-verification
	 */
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			// Re-saving an object that carries a folder association goes through
			// the Files layer, which checks the ACTING USER's folder access — a
			// session-less repair/CLI context has none, so the save is denied
			// ("Access to folder '<n>' is denied for the acting user") and the
			// object silently fails to rematerialise. Resolve an admin IUser and
			// pass it as currentUser so the write has folder access (mirrors
			// FoldIntoOrder). Live-verified: without this, 173 real objects
			// (Account/RetentionRule/…) failed to re-save on occ maintenance:repair.
			$admin = $this->resolveAdminUser();
			$totalResaved = 0;
			foreach (self::SCHEMAS as $schema) {
				$totalResaved += $this->resaveSchema(
					objectService: $this->objectService,
					registerSlug: $registerSlug,
					schema: $schema,
					output: $output,
					admin: $admin
				);
			}

			$output->info(
				'Shillinq: calc rematerialisation complete — ' . $totalResaved . ' object(s) re-saved across '
				. count(self::SCHEMAS) . ' schema(s).'
			);
		} catch (\Throwable $e) {
			// Backfill is best-effort: failing it must NOT block the app
			// upgrade. Log + warn so an operator can re-run.
			$output->warning('Shillinq: calc rematerialisation failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: RematerialiseConvertedCalculations failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end run()

	/**
	 * Resolve the first admin-group member as an IUser (never a string) so OR
	 * writes that touch the Files/folder layer have folder access. Returns null
	 * when no admin exists (best-effort; the save then runs session-less).
	 *
	 * @return IUser|null The first admin-group member, or null.
	 */
	private function resolveAdminUser(): ?IUser {
		try {
			$groupManager = $this->container->get(IGroupManager::class);
			$adminGroup = $groupManager->get('admin');
			if ($adminGroup === null) {
				return null;
			}

			$users = $adminGroup->getUsers();
			if ($users === []) {
				return null;
			}

			return reset($users);
		} catch (\Throwable $e) {
			return null;
		}

	}//end resolveAdminUser()

	/**
	 * Re-save every existing object on one schema. Best-effort per object —
	 * a single object failing to save is logged and skipped, not fatal to
	 * the rest of the backfill.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param string $schema The schema slug being backfilled.
	 * @param IOutput $output The repair-step output.
	 * @param IUser|null $admin The acting admin user (folder access), or null.
	 *
	 * @return int The number of objects re-saved for this schema.
	 */
	private function resaveSchema(
		object $objectService,
		string $registerSlug,
		string $schema,
		IOutput $output,
		?IUser $admin,
	): int {
		try {
			$objects = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: $schema);
		} catch (\Throwable $e) {
			$output->warning('Shillinq: could not list ' . $schema . ' objects for rematerialisation: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: RematerialiseConvertedCalculations findAll failed',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return 0;
		}

		if ($objects === []) {
			return 0;
		}

		$resaved = 0;
		foreach ($objects as $object) {
			$arr = $this->rowPayload(row: $object);
			if (isset($arr['id']) === false && isset($arr['uuid']) === false) {
				// No identifiable persisted id — skip rather than risk
				// creating a duplicate via an unintended CREATE.
				continue;
			}

			try {
				$objectService->saveObject(
					object: $arr,
					register: $registerSlug,
					schema: $schema,
					_rbac: false,
					_multitenancy: false,
					currentUser: $admin,
				);
				$resaved++;
			} catch (\Throwable $e) {
				$id = (string)($arr['id'] ?? ($arr['uuid'] ?? 'unknown'));
				$output->warning('Shillinq: failed to rematerialise ' . $schema . ' ' . $id . ': ' . $e->getMessage());
				$this->logger->warning(
					'Shillinq: RematerialiseConvertedCalculations saveObject failed',
					['schema' => $schema, 'id' => $id, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return $resaved;
	}//end resaveSchema()
}//end class
