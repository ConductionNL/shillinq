<?php

/**
 * Shillinq RetireSubsidieSchema Repair Step
 *
 * Idempotent, fail-soft cleanup that retires the legacy `Subsidie` schema AFTER
 * the FoldIntoOrder repair step (which runs earlier in the same post-migration
 * repair list) has folded every Subsidie row onto an `Order` (orderType=subsidie).
 *
 * FoldIntoOrder stamps each new Order with the marker
 * `migratedFrom = {schema: "Subsidie", key: <subsidieNumber|id>}` (the source
 * Subsidie's stable migration key). This step uses that exact marker to verify
 * an Order exists before it removes the corresponding Subsidie object — so
 * unmigrated data is NEVER lost.
 *
 * ## Behaviour
 *
 *   1. For every remaining Subsidie object: derive its migration key (prefer
 *      subsidieNumber, else id/uuid — the same rule FoldIntoOrder used). If an
 *      Order exists carrying `migratedFrom.schema=Subsidie` +
 *      `migratedFrom.key=<key>`, delete the Subsidie object (deleteObject,
 *      _rbac:false). A Subsidie with NO corresponding Order is left in place
 *      (deleting it would lose unmigrated data).
 *
 *   2. After the loop, when ZERO Subsidie objects remain, delete the Subsidie
 *      SCHEMA row from `openregister_schemas` — but only via a SQL guard that
 *      double-checks no `openregister_objects` row still references the schema
 *      (delete-only-if-empty), mirroring the conservative pattern used by the
 *      sibling Order repair steps.
 *
 * ## Guarantees
 *
 *   - Idempotent: once the objects are gone and the schema row removed, re-runs
 *     are no-ops (no Subsidie objects to scan, no schema id to resolve).
 *   - Fail-soft: a top-level \Throwable catch + per-object catch ensure a
 *     cleanup failure NEVER blocks the Nextcloud upgrade path.
 *   - Data-safe: never deletes a Subsidie that has no folded Order; never drops
 *     the schema row while any object still references it.
 *
 * All OR reads/writes pass `_rbac:false` + `_multitenancy:false` as NAMED
 * parameters; `currentUser` is resolved to a real admin IUser object (NEVER a
 * string) via the admin group.
 *
 * Runs on `occ maintenance:repair` and on `occ app:enable shillinq`, registered
 * in `appinfo/info.xml` repair-steps AFTER FoldIntoOrder.
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
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Retires the Subsidie schema after FoldIntoOrder has folded every Subsidie row
 * onto an Order. Idempotent, fail-soft, data-safe (never removes a Subsidie
 * that lacks a corresponding Order, never drops the schema row while objects
 * remain).
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class RetireSubsidieSchema implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * The legacy schema slug being retired.
	 */
	private const SCHEMA = 'Subsidie';

	/**
	 * The target schema that Subsidie rows were folded onto.
	 *
	 * SLUG NOTE (issue #503, 2026-07-23): renamed from `Order` to
	 * `OrderPrimitive` — see FoldIntoOrder::TARGET and
	 * zz-order-primitive.json's _meta description for the collision this
	 * avoids (a live, foreign `decidesk` schema already held slug `order`,
	 * case-insensitively, in the shared organisation).
	 */
	private const TARGET = 'OrderPrimitive';

	/**
	 * The source-schema tag FoldIntoOrder stamps in migratedFrom.schema.
	 */
	private const MARKER_SCHEMA = 'Subsidie';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Provides the shillinq register slug.
	 * @param LoggerInterface $logger Logger for per-object failures.
	 * @param IGroupManager $groupManager Resolves the admin IUser for OR writes.
	 * @param IDBConnection $db Direct DB access (delete-if-empty schema row).
	 * @param ContainerInterface $container DI container (lazy OR ObjectService resolution).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly IGroupManager $groupManager,
		private readonly IDBConnection $db,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The repair-step display name shown in occ maintenance:repair output.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
	 */
	public function getName(): string {
		return 'Shillinq: retire the legacy Subsidie schema (after FoldIntoOrder has folded every Subsidie onto an Order)';
	}//end getName()

	/**
	 * Run the retirement. Idempotent, fail-soft, data-safe.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();
			$admin = $this->resolveAdminUser();
			if ($admin === null) {
				$output->warning('Shillinq: RetireSubsidieSchema — could not resolve an admin user; skipping (re-run after an admin exists).');
				return;
			}
		} catch (\Throwable $e) {
			$output->warning('Shillinq: RetireSubsidieSchema — OpenRegister ObjectService unavailable: ' . $e->getMessage());
			return;
		}

		$deleted = 0;
		$kept = 0;
		$failed = 0;

		foreach ($this->readSubsidies($objectService, $registerSlug, $output) as $row) {
			$src = $this->rowPayload(row: $row);
			$subsidyId = (string)($src['id'] ?? ($src['uuid'] ?? ''));
			$migrationKey = $this->migrationKey($src);

			if ($migrationKey === '' || $subsidyId === '') {
				$output->warning('Shillinq: RetireSubsidieSchema — Subsidie row without subsidieNumber or id; left in place.');
				$kept++;
				continue;
			}

			try {
				// Data-safety: only delete a Subsidie that was actually folded
				// (an Order carries its migration marker). No Order → keep it.
				if ($this->orderExists($objectService, $registerSlug, $migrationKey) === false) {
					$output->warning('Shillinq: RetireSubsidieSchema — Subsidie "' . $migrationKey . '" has no migrated Order; left in place (unmigrated data).');
					$kept++;
					continue;
				}

				$objectService
					->setRegister($registerSlug)
					->setSchema(self::SCHEMA)
					->deleteObject($subsidyId, _rbac: false, _multitenancy: false);

				$deleted++;
			} catch (\Throwable $e) {
				$output->warning('Shillinq: RetireSubsidieSchema — failed to delete Subsidie "' . $migrationKey . '": ' . $e->getMessage());
				$this->logger->warning(
					'Shillinq: RetireSubsidieSchema — Subsidie delete failed',
					['key' => $migrationKey, 'exception' => $e->getMessage()]
				);
				$failed++;
			}//end try
		}//end foreach

		$output->info(
			sprintf(
				'Shillinq: RetireSubsidieSchema — %d Subsidie object(s) deleted, %d kept (unmigrated), %d failed.',
				$deleted,
				$kept,
				$failed
			)
		);

		// Only drop the schema row when every Subsidie object is gone.
		if ($kept === 0 && $failed === 0) {
			$this->dropSchemaIfEmpty($output);
		} else {
			$output->info('Shillinq: RetireSubsidieSchema — Subsidie objects remain; leaving the Subsidie schema row in place.');
		}

	}//end run()

	/**
	 * Resolve the admin user as an IUser object (NEVER a string) for OR writes.
	 *
	 * @return IUser|null The first admin-group member, or null when none exists.
	 */
	private function resolveAdminUser(): ?IUser {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return null;
		}

		$users = $adminGroup->getUsers();
		if ($users === []) {
			return null;
		}

		return reset($users);
	}//end resolveAdminUser()

	/**
	 * Read every remaining Subsidie object. Returns [] when the schema is absent
	 * or already empty (a valid no-op for re-runs / fresh tenants).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param IOutput $output The repair output.
	 *
	 * @return array<int,mixed> The list of Subsidie rows (may be empty).
	 */
	private function readSubsidies(object $objectService, string $registerSlug, IOutput $output): array {
		try {
			return $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: self::SCHEMA);
		} catch (\Throwable $e) {
			$output->info('Shillinq: RetireSubsidieSchema — Subsidie schema not available (' . $e->getMessage() . '); nothing to retire.');
			return [];
		}//end try

	}//end readSubsidies()

	/**
	 * Derive the stable migration key for a Subsidie row, mirroring exactly the
	 * rule FoldIntoOrder used to stamp the Order marker: prefer the unique
	 * subsidieNumber, else fall back to the source id/uuid.
	 *
	 * @param array<string,mixed> $src The source Subsidie row.
	 *
	 * @return string The migration key (may be empty when unresolvable).
	 */
	private function migrationKey(array $src): string {
		$subsidyNumber = (string)($src['subsidyNumber'] ?? '');
		if ($subsidyNumber !== '') {
			return $subsidyNumber;
		}

		return (string)($src['id'] ?? ($src['uuid'] ?? ''));
	}//end migrationKey()

	/**
	 * Whether an Order exists carrying the given migration marker — proof that
	 * the Subsidie was folded and is therefore safe to delete.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $migrationKey The stable source marker.
	 *
	 * @return bool True when a matching Order exists.
	 */
	private function orderExists(object $objectService, string $registerSlug, string $migrationKey): bool {
		try {
			// TODO(#382): dot-path filter unsupported — filter in PHP.
			// OpenRegister does NOT support nested (dot-path) filter keys such as
			// `migratedFrom.schema`/`migratedFrom.key` — they match NOTHING, so
			// the old filtered query always found "no Order" on a live instance
			// and every Subsidie was kept (the schema never retired). Read every
			// Order once and match the migration marker in PHP instead.
			$orders = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: self::TARGET);
		} catch (\Throwable) {
			// On lookup error, conservatively report "no Order" so the Subsidie
			// is KEPT (never delete on an ambiguous read).
			return false;
		}

		foreach ($orders as $order) {
			$marker = ($this->rowPayload(row: $order)['migratedFrom'] ?? null);
			if (is_array($marker) === false) {
				continue;
			}

			if ((string)($marker['schema'] ?? '') === self::MARKER_SCHEMA
				&& (string)($marker['key'] ?? '') === $migrationKey
			) {
				return true;
			}
		}

		return false;
	}//end orderExists()

	/**
	 * Delete the Subsidie schema row from openregister_schemas — but only when
	 * no openregister_objects row still references it (delete-only-if-empty).
	 * Resolves the schema id by slug, then guards the delete on a live count of
	 * referencing objects (matching the schema column by either its id or its
	 * slug, since OR's objects.schema column can hold either form). Fail-soft.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 */
	private function dropSchemaIfEmpty(IOutput $output): void {
		try {
			if ($this->db->tableExists('openregister_schemas') === false) {
				$output->info('Shillinq: RetireSubsidieSchema — OpenRegister schemas table absent; nothing to drop.');
				return;
			}

			$schemaId = $this->schemaId(self::SCHEMA);
			if ($schemaId === null) {
				$output->info('Shillinq: RetireSubsidieSchema — Subsidie schema row already absent; nothing to drop.');
				return;
			}

			if ($this->objectsRemain($schemaId) === true) {
				$output->warning('Shillinq: RetireSubsidieSchema — objects still reference the Subsidie schema; NOT dropping the schema row.');
				return;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->delete('openregister_schemas')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($schemaId)))
				->andWhere($qb->expr()->eq('slug', $qb->createNamedParameter(self::SCHEMA)));
			$qb->executeStatement();

			$output->info('Shillinq: RetireSubsidieSchema — Subsidie schema row #' . ((string)$schemaId) . ' dropped.');
		} catch (\Throwable $e) {
			$output->warning('Shillinq: RetireSubsidieSchema — schema-row drop failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: RetireSubsidieSchema — schema-row drop failed', ['exception' => $e->getMessage()]);
		}//end try

	}//end dropSchemaIfEmpty()

	/**
	 * Resolve a schema id by slug, or null when absent.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return int|string|null The schema id, or null.
	 */
	private function schemaId(string $slug): int|string|null {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('openregister_schemas')
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
			->setMaxResults(1);
		$id = $qb->executeQuery()->fetchOne();
		return ($id === false ? null : $id);
	}//end schemaId()

	/**
	 * Whether any openregister_objects row still references the Subsidie schema.
	 * The objects.schema column may carry either the schema id or its slug, so
	 * the guard checks both forms (delete-only-if-empty correctness).
	 *
	 * @param int|string $schemaId The resolved Subsidie schema id.
	 *
	 * @return bool True when at least one referencing object remains.
	 */
	private function objectsRemain(int|string $schemaId): bool {
		if ($this->db->tableExists('openregister_objects') === false) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('openregister_objects')
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('schema', $qb->createNamedParameter((string)$schemaId)),
					$qb->expr()->eq('schema', $qb->createNamedParameter(self::SCHEMA))
				)
			)
			->setMaxResults(1);
		$found = $qb->executeQuery()->fetchOne();
		return ($found !== false);
	}//end objectsRemain()
}//end class
