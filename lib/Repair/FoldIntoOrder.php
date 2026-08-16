<?php

/**
 * Shillinq FoldIntoOrder Repair Step
 *
 * Idempotent data migration that folds Subsidie, PurchaseOrder and DBAOpdracht
 * rows onto the unified `Order` primitive (abstract-order-primitive change).
 * Each row becomes a genuine `Order` object (orderType=subsidie|purchase|
 * engagement) carrying its full original field set, unabridged, on the
 * corresponding `subsidie`/`purchase`/`engagement` field group — no regulatory
 * or audit field is dropped (REQ-ORD-003).
 *
 * WHY A NEW `Order` OBJECT (not an in-place field patch): OpenRegister's
 * ObjectService::findAll() is schema-scoped — there is no allOf-aware
 * cross-schema query (confirmed against SchemaMapper::resolveAllOf(), which
 * only merges `properties`/`required` for validation, never object storage).
 * The 'Order workspace' manifest page (src/manifest.d/order-workspace.json)
 * therefore can only ever show literal `Order` rows, so folding must create
 * real Order objects rather than merely tagging the source schema in place.
 * Source rows (Subsidie/PurchaseOrder/DBAOpdracht) are NEVER deleted or
 * modified by this step — see RetireSubsidieSchema for the separate,
 * data-safe Subsidie cleanup that runs after this step.
 *
 * MONEY UNITS: PurchaseOrder amounts are integer EURO CENTS (ADR-022);
 * Subsidie/DBAOpdracht amounts are decimal EUR. The shared Order.totalAmount
 * is always decimal EUR — purchase.totalInclVat is divided by 100 when
 * projected onto totalAmount; the original cent value is preserved verbatim
 * inside the `purchase` group.
 *
 * IDEMPOTENT: keyed on `migratedFrom.schema` + `migratedFrom.key` (the source
 * schema name + its stable migration key — subsidieNumber/poNumber/id). A
 * second run finds the existing Order via that marker and skips it.
 * Fail-soft per row (catch \Throwable -> $output->warning).
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

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Folds Subsidie / PurchaseOrder / DBAOpdracht rows into the unified `Order`
 * schema. Idempotent, fail-soft, never deletes or mutates source rows.
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 */
class FoldIntoOrder implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * The target schema every fold writes to.
	 *
	 * SLUG NOTE (issue #503, 2026-07-23): renamed from `Order` to
	 * `OrderPrimitive`. OpenRegister's schema slug lookup is case-insensitive
	 * and (in `ImportHandler::importSchema()`) explicitly bypasses
	 * multitenancy, so a slug is unique INSTANCE-WIDE, not per-app. On 8080 a
	 * `decidesk` schema (id 1585, slug `order`) already occupied that
	 * identifier in the same organisation as shillinq's own schemas —
	 * importing literally `Order` would have matched and OVERWRITTEN it via
	 * `SchemaMapper::updateFromArray()`. See zz-order-primitive.json's _meta
	 * description for the full account.
	 */
	public const TARGET = 'OrderPrimitive';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Provides the shillinq register slug.
	 * @param LoggerInterface $logger Logger for per-row failures.
	 * @param IGroupManager $groupManager Resolves the admin IUser for OR writes.
	 * @param ContainerInterface $container DI container (lazy OR ObjectService resolution).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly IGroupManager $groupManager,
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
		return 'Shillinq: fold Subsidie/PurchaseOrder/DBAOpdracht rows onto the unified Order primitive';
	}//end getName()

	/**
	 * Run the fold. Idempotent — never duplicates Order rows and never deletes
	 * or mutates source rows.
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
				$output->warning('Shillinq: FoldIntoOrder — could not resolve an admin user; skipping (re-run after an admin exists).');
				return;
			}
		} catch (\Throwable $e) {
			$output->warning('Shillinq: FoldIntoOrder — OpenRegister ObjectService unavailable: ' . $e->getMessage());
			return;
		}

		// Read every already-folded marker ONCE, up front. This is the idempotency
		// index for the whole run: it needs no nested-property filtering (which
		// OpenRegister does not support) and no assumption that the target's
		// orderNumber equals the source migration key (it does not for
		// DBAOpdracht, whose orderNumber is prefixed "DBA-").
		$seen = $this->loadFoldedMarkers($objectService, $registerSlug, $output);

		$summary = [];
		$summary['Subsidie'] = $this->foldRows($objectService, $registerSlug, $admin, $output, 'Subsidie', $this->buildSubsidyOrder(...), $seen);
		$summary['PurchaseOrder'] = $this->foldRows($objectService, $registerSlug, $admin, $output, 'PurchaseOrder', $this->buildPurchaseOrder(...), $seen);
		$summary['DBAOpdracht'] = $this->foldRows($objectService, $registerSlug, $admin, $output, 'DBAOpdracht', $this->buildEngagementOrder(...), $seen);

		foreach ($summary as $schema => $counts) {
			$output->info(
				sprintf(
					'Shillinq: FoldIntoOrder — %s: %d migrated, %d skipped, %d failed.',
					$schema,
					$counts['migrated'],
					$counts['skipped'],
					$counts['failed']
				)
			);
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
	 * Read all rows of a source schema. Returns [] when the schema is absent
	 * or empty (a valid no-op — e.g. a fresh tenant with 0 source rows).
	 *
	 * Delegates the batched read to {@see ReadsSourceRowsInBatches::readAllRows()}
	 * (which is why 'limit' => 0 must never be used — see that trait) and keeps
	 * this step's own fail-soft "schema not available" handling.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $schema The source schema slug to read.
	 * @param IOutput $output The repair output.
	 *
	 * @return array<int,mixed> The list of rows (may be empty).
	 */
	private function readRows(object $objectService, string $registerSlug, string $schema, IOutput $output): array {
		try {
			return $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: $schema);
		} catch (\Throwable $e) {
			$output->info('Shillinq: FoldIntoOrder — ' . $schema . ' schema not available (' . $e->getMessage() . '); skipping.');
			return [];
		}
	}//end readRows()

	/**
	 * Generic fold driver: read every row of a source schema, skip rows already
	 * folded (an Order with matching migratedFrom marker exists), otherwise
	 * build + save a new Order via the supplied builder callback.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param IUser $admin The admin user for OR writes.
	 * @param IOutput $output The repair output.
	 * @param string $sourceSchema The source schema slug to read.
	 * @param callable $builder fn(array $src, string $migrationKey): array|null — builds the Order record, or null to skip an unresolvable row.
	 * @param array $seen Set of already-folded markers, updated in place so a run never folds the same source twice.
	 *
	 * @return array{migrated:int,skipped:int,failed:int} Per-schema counts.
	 */
	private function foldRows(object $objectService, string $registerSlug, IUser $admin, IOutput $output, string $sourceSchema, callable $builder, array &$seen): array {
		$migrated = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($this->readRows($objectService, $registerSlug, $sourceSchema, $output) as $row) {
			$src = $this->normaliseRow($row);
			$migrationKey = $this->migrationKey($src, $sourceSchema);

			if ($migrationKey === '') {
				$output->warning('Shillinq: FoldIntoOrder — ' . $sourceSchema . ' row without a stable id; skipping.');
				$failed++;
				continue;
			}

			try {
				if (isset($seen[$this->markerKey($sourceSchema, $migrationKey)]) === true) {
					$skipped++;
					continue;
				}

				$order = $builder($src, $migrationKey);
				if ($order === null) {
					$output->warning('Shillinq: FoldIntoOrder — ' . $sourceSchema . ' "' . $migrationKey . '": could not build an Order record; skipping.');
					$failed++;
					continue;
				}

				$objectService->saveObject(
					object: $this->pruneNulls($order),
					register: $registerSlug,
					schema: self::TARGET,
					_rbac: false,
					_multitenancy: false,
					currentUser: $admin,
				);

				$seen[$this->markerKey($sourceSchema, $migrationKey)] = true;
				$migrated++;
			} catch (\Throwable $e) {
				$output->warning('Shillinq: FoldIntoOrder — ' . $sourceSchema . ' "' . $migrationKey . '" fold failed: ' . $e->getMessage());
				$this->logger->warning(
					'Shillinq: FoldIntoOrder — fold failed',
					['schema' => $sourceSchema, 'key' => $migrationKey, 'exception' => $e->getMessage()]
				);
				$failed++;
			}//end try
		}//end foreach

		return ['migrated' => $migrated, 'skipped' => $skipped, 'failed' => $failed];
	}//end foldRows()

	/**
	 * Recursively drop null-valued keys from a built Order record.
	 *
	 * The builders emit an explicit null for every optional source field that is
	 * absent, but OpenRegister validates a present null against the property's
	 * declared type and rejects it ("Property 'subsidie.regelingArtikel' should be
	 * type 'string' but is 'null'"), failing the whole row. An ABSENT optional
	 * property is valid, so pruning is lossless — null carries no data.
	 * Live-verified: without this, every real Subsidie row failed to save.
	 *
	 * @param array<string,mixed> $record The built Order record.
	 *
	 * @return array<string,mixed> The record without null-valued keys.
	 */
	private function pruneNulls(array $record): array {
		$clean = [];

		foreach ($record as $key => $value) {
			if ($value === null) {
				continue;
			}

			if (is_array($value) === true) {
				$nested = $this->pruneNulls($value);
				if ($nested === []) {
					continue;
				}

				$clean[$key] = $nested;
				continue;
			}

			$clean[$key] = $value;
		}

		return $clean;
	}//end pruneNulls()

	/**
	 * Normalise one findAll() result row into its payload array.
	 *
	 * OpenRegister returns ObjectEntity instances, whose schema payload lives in
	 * getObject() — NOT in the object's own properties. A blind `(array) $row`
	 * cast therefore yields mangled "\0*\0prop" keys and loses every payload
	 * field, so the row reads as "no stable id" and gets skipped. Live-verified:
	 * every source row was skipped that way while the step still reported a
	 * clean summary.
	 *
	 * Nextcloud's Entity base implements its getters through __call(), so
	 * method_exists()/get_class_methods() report FALSE for getObject()/getUuid().
	 * Probe by calling, never by reflection.
	 *
	 * @param mixed $row One findAll() result row.
	 *
	 * @return array<string,mixed> The payload array (may be empty when unusable).
	 */
	private function normaliseRow(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === false) {
			return [];
		}

		$data = [];

		try {
			$payload = $row->getObject();
			if (is_array($payload) === true) {
				$data = $payload;
			}
		} catch (\Throwable $e) {
			$data = [];
		}

		if ($data === []) {
			// Not an OR entity — public properties only; never a blind cast.
			$data = get_object_vars($row);
		}

		// Guarantee a stable identifier even when the payload omits one.
		if ((string)($data['id'] ?? '') === '' && (string)($data['uuid'] ?? '') === '') {
			try {
				$uuid = (string)$row->getUuid();
				if ($uuid !== '') {
					$data['uuid'] = $uuid;
				}
			} catch (\Throwable $e) {
				// No stable id available; the caller reports and skips the row.
			}
		}

		return $data;
	}//end normaliseRow()

	/**
	 * Derive the stable migration key for a source row.
	 *
	 * @param array<string,mixed> $src The source row.
	 * @param string $schema The source schema slug.
	 *
	 * @return string The migration key (may be empty when unresolvable).
	 */
	private function migrationKey(array $src, string $schema): string {
		$preferred = match ($schema) {
			'Subsidie' => (string)($src['subsidyNumber'] ?? ''),
			'PurchaseOrder' => (string)($src['poNumber'] ?? ''),
			default => '',
		};

		if ($preferred !== '') {
			return $preferred;
		}

		return (string)($src['id'] ?? ($src['uuid'] ?? ''));
	}//end migrationKey()

	/**
	 * Build the set of already-folded source markers, read once per run.
	 *
	 * Idempotency cannot be delegated to a query here: OpenRegister's findAll()
	 * does NOT support dot-path filters on nested properties, so the original
	 * `['migratedFrom.key' => ...]` filter matched nothing and the step always
	 * concluded "no existing Order" — re-folding every source row on every run,
	 * so each `occ upgrade` added a duplicate set of financial records
	 * (live-verified: a second run produced 2 Orders for one source key).
	 * Filtering on the top-level `orderNumber` instead is also wrong, because it
	 * only equals the migration key for Subsidie/PurchaseOrder — DBAOpdracht
	 * prefixes it "DBA-" (live-verified: engagement rows duplicated that way).
	 *
	 * Reading the markers once is exact, needs no filter support, and costs one
	 * batched scan instead of a query per source row.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param IOutput $output The repair output.
	 *
	 * @return array<string,true> Set keyed by markerKey(schema, key).
	 */
	private function loadFoldedMarkers(object $objectService, string $registerSlug, IOutput $output): array {
		$seen = [];

		foreach ($this->readRows($objectService, $registerSlug, self::TARGET, $output) as $row) {
			$marker = ($this->normaliseRow($row)['migratedFrom'] ?? null);
			if (is_array($marker) === false) {
				continue;
			}

			$schema = (string)($marker['schema'] ?? '');
			$key = (string)($marker['key'] ?? '');
			if ($schema === '' || $key === '') {
				continue;
			}

			$seen[$this->markerKey($schema, $key)] = true;
		}

		return $seen;
	}//end loadFoldedMarkers()

	/**
	 * The set key identifying one folded source row.
	 *
	 * @param string $sourceSchema The source schema slug.
	 * @param string $migrationKey The stable source marker.
	 *
	 * @return string The composite set key.
	 */
	private function markerKey(string $sourceSchema, string $migrationKey): string {
		return $sourceSchema . "\0" . $migrationKey;
	}//end markerKey()

	/**
	 * Build an Order record (orderType=subsidie) from a legacy Subsidie row.
	 * Every Subsidie field is preserved verbatim on the `subsidie` group.
	 *
	 * @param array<string,mixed> $src The source Subsidie row.
	 * @param string $migrationKey The stable migration marker.
	 *
	 * @return array<string,mixed> The Order record.
	 */
	private function buildSubsidyOrder(array $src, string $migrationKey): array {
		$grantedAmount = $src['grantedAmount'] ?? ($src['awardAmount'] ?? null);

		return [
			'administrationId' => (string)($src['administrationId'] ?? 'unknown'),
			'orderType' => 'subsidy',
			'direction' => (string)($src['direction'] ?? 'outgoing'),
			'orderNumber' => $migrationKey,
			'counterpartyId' => $this->stringOrNull($src['counterpartyId'] ?? null),
			'counterpartyName' => $this->firstNonEmpty([($src['counterpartyName'] ?? null), ($src['granteeOrganization'] ?? null)]),
			'currency' => (string)($src['currency'] ?? 'EUR'),
			'orderDate' => $this->toDateTime($src['requestDate'] ?? ($src['awardDate'] ?? null)),
			'totalAmount' => ($grantedAmount === null ? null : (float)$grantedAmount),
			'description' => $this->stringOrNull($src['purposeDescription'] ?? ($src['notes'] ?? null)),
			'state' => (string)($src['state'] ?? 'request'),
			'subsidy' => [
				'subsidyNumber' => $this->stringOrNull($src['subsidyNumber'] ?? null),
				'schemeName' => $this->firstNonEmpty([($src['schemeName'] ?? null), ($src['grantProgram'] ?? null), ($src['subsidyName'] ?? null)]),
				'schemeArticle' => $this->firstNonEmpty([($src['schemeArticle'] ?? null), ($src['grantProgram'] ?? null)]),
				'subsidyScheme' => $this->stringOrNull($src['subsidyScheme'] ?? null),
				'requestDate' => $this->toDate($src['requestDate'] ?? null),
				'decisionDate' => $this->toDate($src['decisionDate'] ?? ($src['awardDate'] ?? null)),
				'determinationDate' => $this->toDate($src['determinationDate'] ?? ($src['settlementDate'] ?? null)),
				'settlementDate' => $this->toDate($src['settlementDate'] ?? null),
				'disbursementDate' => $this->toDate($src['disbursementDate'] ?? null),
				'requestedAmount' => $this->floatOrNull($src['requestedAmount'] ?? null),
				'grantedAmount' => $this->floatOrNull($src['grantedAmount'] ?? ($src['awardAmount'] ?? null)),
				'determinedAmount' => $this->floatOrNull($src['determinedAmount'] ?? null),
				'paidOutAmount' => $this->floatOrNull($src['paidOutAmount'] ?? null),
				'reclaimedAmount' => $this->floatOrNull($src['reclaimedAmount'] ?? null),
				'decisionUri' => $this->stringOrNull($src['decisionUri'] ?? null),
				'determinationUri' => $this->stringOrNull($src['determinationUri'] ?? null),
				'attachmentUri' => $this->stringOrNull($src['attachmentUri'] ?? null),
				'prestatieverantwoording' => $this->stringOrNull($src['prestatieverantwoording'] ?? null),
				'rejectionReason' => $this->stringOrNull($src['rejectionReason'] ?? null),
				'repaymentPlanId' => $this->stringOrNull($src['repaymentPlanId'] ?? null),
				'hasRepaymentPlan' => $this->boolOrNull($src['hasRepaymentPlan'] ?? null),
				'approvingAuthority' => $this->stringOrNull($src['approvingAuthority'] ?? null),
				'budgetYear' => $this->intOrNull($src['budgetYear'] ?? null),
				'grantProgram' => $this->stringOrNull($src['grantProgram'] ?? null),
				'granteeOrganization' => $this->stringOrNull($src['granteeOrganization'] ?? null),
				'notes' => $this->stringOrNull($src['notes'] ?? null),
			],
			'migratedFrom' => [
				'schema' => 'Subsidie',
				'key' => $migrationKey,
			],
		];

	}//end buildSubsidieOrder()

	/**
	 * Build an Order record (orderType=purchase) from a legacy PurchaseOrder
	 * row. Amounts stay in integer EURO CENTS inside the `purchase` group;
	 * the shared totalAmount is the decimal-EUR projection (totalInclVat/100).
	 *
	 * @param array<string,mixed> $src The source PurchaseOrder row.
	 * @param string $migrationKey The stable migration marker.
	 *
	 * @return array<string,mixed> The Order record.
	 */
	private function buildPurchaseOrder(array $src, string $migrationKey): array {
		$totalInclVat = $this->intOrNull($src['totalInclVat'] ?? null);

		return [
			'administrationId' => (string)($src['administrationId'] ?? 'unknown'),
			'orderType' => 'purchase',
			'direction' => 'incoming',
			'orderNumber' => $migrationKey,
			'counterpartyId' => $this->stringOrNull($src['supplierId'] ?? null),
			'counterpartyName' => $this->stringOrNull($src['supplierReference'] ?? null),
			'currency' => (string)($src['currency'] ?? 'EUR'),
			'orderDate' => null,
			'totalAmount' => ($totalInclVat === null ? null : ($totalInclVat / 100.0)),
			'description' => $this->stringOrNull($src['deliveryAddress'] ?? null),
			'paymentTerms' => $this->stringOrNull($src['paymentTerms'] ?? null),
			'projectReference' => $this->stringOrNull($src['projectCode'] ?? null),
			'costCenter' => $this->stringOrNull($src['costCenter'] ?? null),
			'state' => (string)($src['statusCode'] ?? 'draft'),
			'purchase' => [
				'poNumber' => $this->stringOrNull($src['poNumber'] ?? null),
				'supplierId' => $this->stringOrNull($src['supplierId'] ?? null),
				'supplierReference' => $this->stringOrNull($src['supplierReference'] ?? null),
				'requester' => $this->stringOrNull($src['requester'] ?? null),
				'requisitionId' => $this->stringOrNull($src['requisitionId'] ?? null),
				'deliveryAddress' => $this->stringOrNull($src['deliveryAddress'] ?? null),
				'expectedDeliveryDate' => $this->toDate($src['expectedDeliveryDate'] ?? null),
				'totalExclVat' => $this->intOrNull($src['totalExclVat'] ?? null),
				'totalVat' => $this->intOrNull($src['totalVat'] ?? null),
				'totalInclVat' => $totalInclVat,
				'approvalChain' => $src['approvalChain'] ?? [],
				'peppolSentAt' => $this->toDateTime($src['peppolSentAt'] ?? null),
				'peppolMessageId' => $this->stringOrNull($src['peppolMessageId'] ?? null),
				'peppolFallbackReason' => $this->stringOrNull($src['peppolFallbackReason'] ?? null),
			],
			'migratedFrom' => [
				'schema' => 'PurchaseOrder',
				'key' => $migrationKey,
			],
		];

	}//end buildPurchaseOrder()

	/**
	 * Build an Order record (orderType=engagement) from a legacy DBAOpdracht
	 * row.
	 *
	 * @param array<string,mixed> $src The source DBAOpdracht row.
	 * @param string $migrationKey The stable migration marker.
	 *
	 * @return array<string,mixed> The Order record.
	 */
	private function buildEngagementOrder(array $src, string $migrationKey): array {
		$expectedRevenue = $this->intOrNull($src['expectedRevenue'] ?? null);

		return [
			'administrationId' => (string)($src['administrationId'] ?? 'unknown'),
			'orderType' => 'engagement',
			'direction' => 'outgoing',
			'orderNumber' => 'DBA-' . $migrationKey,
			'counterpartyId' => $this->stringOrNull($src['customerId'] ?? null),
			'counterpartyName' => $this->stringOrNull($src['customerId'] ?? null),
			'currency' => 'EUR',
			'orderDate' => $this->toDateTime($src['startDate'] ?? null),
			'endDate' => $this->toDateTime($src['expectedEndDate'] ?? null),
			'totalAmount' => ($expectedRevenue === null ? null : ($expectedRevenue / 100.0)),
			'description' => $this->stringOrNull($src['assignmentName'] ?? null),
			'state' => (string)($src['intakeStatus'] ?? 'DRAFT'),
			'engagement' => [
				'enterpriseId' => $this->stringOrNull($src['enterpriseId'] ?? null),
				'customerId' => $this->stringOrNull($src['customerId'] ?? null),
				'assignmentName' => $this->stringOrNull($src['assignmentName'] ?? null),
				'expectedEndDate' => $this->toDate($src['expectedEndDate'] ?? null),
				'actualEndDate' => $this->toDate($src['actualEndDate'] ?? null),
				'expectedRevenue' => $expectedRevenue,
				'realisedRevenue' => $this->intOrNull($src['realisedRevenue'] ?? null),
				'oneOffLowThreshold' => $this->boolOrNull($src['oneOffLowThreshold'] ?? null),
				'modelAgreementId' => $this->stringOrNull($src['modelAgreementId'] ?? null),
				'intakeDate' => $this->toDate($src['intakeDate'] ?? null),
				'actueleRisicoscore' => $this->intOrNull($src['actueleRisicoscore'] ?? null),
				'riskLevel' => $this->stringOrNull($src['riskLevel'] ?? null),
				'openFlags' => $this->intOrNull($src['openFlags'] ?? null),
				'evidenceDossierId' => $this->stringOrNull($src['evidenceDossierId'] ?? null),
				'wbaAssessmentResult' => $this->stringOrNull($src['wbaAssessmentResult'] ?? null),
				'wbaValidTo' => $this->toDate($src['wbaValidTo'] ?? null),
				'intermediaryMode' => $this->boolOrNull($src['intermediaryMode'] ?? null),
				'perspective' => $this->stringOrNull($src['perspective'] ?? null),
				'retentionDeadline' => $this->toDate($src['retentionDeadline'] ?? null),
			],
			'migratedFrom' => [
				'schema' => 'DBAOpdracht',
				'key' => $migrationKey,
			],
		];

	}//end buildEngagementOrder()

	/**
	 * Return the first non-empty (non-null, non-'') value from a list, or null.
	 *
	 * @param array<int,mixed> $values Candidate values.
	 *
	 * @return string|null The first usable string value, or null.
	 */
	private function firstNonEmpty(array $values): ?string {
		foreach ($values as $value) {
			if ($value === null) {
				continue;
			}

			$str = (string)$value;
			if ($str !== '') {
				return $str;
			}
		}

		return null;
	}//end firstNonEmpty()

	/**
	 * Cast to a non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The string, or null when empty.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$str = (string)$value;
		return ($str === '' ? null : $str);
	}//end stringOrNull()

	/**
	 * Cast to int, or null when absent.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return int|null The int, or null.
	 */
	private function intOrNull(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

	/**
	 * Cast to float, or null when absent.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return float|null The float, or null.
	 */
	private function floatOrNull(mixed $value): ?float {
		if ($value === null || $value === '') {
			return null;
		}

		return (float)$value;
	}//end floatOrNull()

	/**
	 * Cast to bool, or null when absent.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return bool|null The bool, or null.
	 */
	private function boolOrNull(mixed $value): ?bool {
		if ($value === null) {
			return null;
		}

		return (bool)$value;
	}//end boolOrNull()

	/**
	 * Normalise a source value to a bare calendar date (YYYY-MM-DD).
	 *
	 * Target properties declared `format: date` reject a full ISO-8601 timestamp
	 * ("should match format 'date' but '2026-01-15T00:00:00+00:00' does not"), so
	 * date-typed fields must NOT go through toDateTime(). Live-verified: emitting
	 * ATOM into a date field failed every real Subsidie row.
	 *
	 * @param mixed $value The raw source value.
	 *
	 * @return string|null The YYYY-MM-DD date, or null when unusable.
	 */
	private function toDate(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$str = trim((string)$value);
		if ($str === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($str))->format('Y-m-d');
		} catch (\Throwable) {
			return null;
		}

	}//end toDate()

	/**
	 * Normalise a date / date-time source value to an ISO date-time string
	 * ('2026-03-01T00:00:00+00:00'), because OR validates the date-time format.
	 * A bare date ('2026-03-01') is widened to midnight UTC. Returns null when
	 * the source is empty or unparseable.
	 *
	 * Use this ONLY for properties declared `format: date-time`; properties
	 * declared `format: date` must go through toDate().
	 *
	 * @param mixed $value The raw date value.
	 *
	 * @return string|null The ISO date-time, or null.
	 */
	private function toDateTime(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$str = trim((string)$value);
		if ($str === '') {
			return null;
		}

		try {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) === 1) {
				$str .= 'T00:00:00+00:00';
			}

			return (new DateTimeImmutable($str))->format(DateTimeInterface::ATOM);
		} catch (\Throwable) {
			return null;
		}

	}//end toDateTime()
}//end class
