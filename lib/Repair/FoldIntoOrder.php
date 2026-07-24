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
class FoldIntoOrder implements IRepairStep
{
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
     * How many source rows to read per findAll() page.
     *
     * Source rows are read in batches so the fold never depends on implicit
     * "unlimited" semantics and never loads an unbounded result set at once.
     */
    public const READ_BATCH_SIZE = 200;

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService Provides the shillinq register slug.
     * @param LoggerInterface    $logger          Logger for per-row failures.
     * @param IGroupManager      $groupManager    Resolves the admin IUser for OR writes.
     * @param ContainerInterface $container       DI container (lazy OR ObjectService resolution).
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
    public function getName(): string
    {
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
    public function run(IOutput $output): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settingsService->getRegisterSlug();
            $admin         = $this->resolveAdminUser();
            if ($admin === null) {
                $output->warning('Shillinq: FoldIntoOrder — could not resolve an admin user; skipping (re-run after an admin exists).');
                return;
            }
        } catch (\Throwable $e) {
            $output->warning('Shillinq: FoldIntoOrder — OpenRegister ObjectService unavailable: '.$e->getMessage());
            return;
        }

        $summary = [];
        $summary['Subsidie']      = $this->foldRows($objectService, $registerSlug, $admin, $output, 'Subsidie', $this->buildSubsidieOrder(...));
        $summary['PurchaseOrder'] = $this->foldRows($objectService, $registerSlug, $admin, $output, 'PurchaseOrder', $this->buildPurchaseOrder(...));
        $summary['DBAOpdracht']   = $this->foldRows($objectService, $registerSlug, $admin, $output, 'DBAOpdracht', $this->buildEngagementOrder(...));

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
    private function resolveAdminUser(): ?IUser
    {
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
     * Reads in explicit limit/offset batches. NEVER pass 'limit' => 0 hoping it
     * means "unlimited": OpenRegister forwards it as a literal SQL LIMIT 0, so
     * findAll() returns ZERO rows and this migration becomes a silent no-op that
     * still reports "0 migrated, 0 skipped, 0 failed" — green, and dead.
     * Live-verified on a real instance: limit=0 => 0 rows, limit=N/omitted => N.
     *
     * @param object  $objectService The OR ObjectService.
     * @param string  $registerSlug  The shillinq register slug.
     * @param string  $schema        The source schema slug to read.
     * @param IOutput $output        The repair output.
     *
     * @return array<int,mixed> The list of rows (may be empty).
     */
    private function readRows(object $objectService, string $registerSlug, string $schema, IOutput $output): array
    {
        $rows   = [];
        $offset = 0;

        try {
            while (true) {
                $page = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema($schema)
                    ->findAll(
                        [
                            'limit'         => self::READ_BATCH_SIZE,
                            'offset'        => $offset,
                            '_rbac'         => false,
                            '_multitenancy' => false,
                        ]
                    );

                if (is_array($page) === false || $page === []) {
                    break;
                }

                foreach ($page as $row) {
                    $rows[] = $row;
                }

                if (count($page) < self::READ_BATCH_SIZE) {
                    break;
                }

                $offset += self::READ_BATCH_SIZE;
            }//end while

            return $rows;
        } catch (\Throwable $e) {
            $output->info('Shillinq: FoldIntoOrder — '.$schema.' schema not available ('.$e->getMessage().'); skipping.');
            return [];
        }//end try

    }//end readRows()

    /**
     * Generic fold driver: read every row of a source schema, skip rows already
     * folded (an Order with matching migratedFrom marker exists), otherwise
     * build + save a new Order via the supplied builder callback.
     *
     * @param object   $objectService The OR ObjectService.
     * @param string   $registerSlug  The shillinq register slug.
     * @param IUser    $admin         The admin user for OR writes.
     * @param IOutput  $output        The repair output.
     * @param string   $sourceSchema  The source schema slug to read.
     * @param callable $builder       fn(array $src, string $migrationKey): array|null — builds the Order record, or null to skip an unresolvable row.
     *
     * @return array{migrated:int,skipped:int,failed:int} Per-schema counts.
     */
    private function foldRows(object $objectService, string $registerSlug, IUser $admin, IOutput $output, string $sourceSchema, callable $builder): array
    {
        $migrated = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($this->readRows($objectService, $registerSlug, $sourceSchema, $output) as $row) {
            $src          = (array) $row;
            $migrationKey = $this->migrationKey($src, $sourceSchema);

            if ($migrationKey === '') {
                $output->warning('Shillinq: FoldIntoOrder — '.$sourceSchema.' row without a stable id; skipping.');
                $failed++;
                continue;
            }

            try {
                if ($this->orderExists($objectService, $registerSlug, $sourceSchema, $migrationKey) === true) {
                    $skipped++;
                    continue;
                }

                $order = $builder($src, $migrationKey);
                if ($order === null) {
                    $output->warning('Shillinq: FoldIntoOrder — '.$sourceSchema.' "'.$migrationKey.'": could not build an Order record; skipping.');
                    $failed++;
                    continue;
                }

                $objectService->saveObject(
                    object: $order,
                    register: $registerSlug,
                    schema: self::TARGET,
                    _rbac: false,
                    _multitenancy: false,
                    currentUser: $admin,
                );

                $migrated++;
            } catch (\Throwable $e) {
                $output->warning('Shillinq: FoldIntoOrder — '.$sourceSchema.' "'.$migrationKey.'" fold failed: '.$e->getMessage());
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
     * Derive the stable migration key for a source row.
     *
     * @param array<string,mixed> $src    The source row.
     * @param string              $schema The source schema slug.
     *
     * @return string The migration key (may be empty when unresolvable).
     */
    private function migrationKey(array $src, string $schema): string
    {
        $preferred = match ($schema) {
            'Subsidie'      => (string) ($src['subsidieNumber'] ?? ''),
            'PurchaseOrder' => (string) ($src['poNumber'] ?? ''),
            default         => '',
        };

        if ($preferred !== '') {
            return $preferred;
        }

        return (string) ($src['id'] ?? ($src['uuid'] ?? ''));

    }//end migrationKey()

    /**
     * Whether an Order already exists carrying the given source marker
     * (idempotency for every fold).
     *
     * @param object $objectService The OR ObjectService.
     * @param string $registerSlug  The shillinq register slug.
     * @param string $sourceSchema  The source schema slug.
     * @param string $migrationKey  The stable source marker.
     *
     * @return bool True when a matching Order exists.
     */
    private function orderExists(object $objectService, string $registerSlug, string $sourceSchema, string $migrationKey): bool
    {
        try {
            $found = $objectService
                ->setRegister($registerSlug)
                ->setSchema(self::TARGET)
                ->findAll(
                    [
                        'filters'       => [
                            'migratedFrom.schema' => $sourceSchema,
                            'migratedFrom.key'    => $migrationKey,
                        ],
                        'limit'         => 1,
                        '_rbac'         => false,
                        '_multitenancy' => false,
                    ]
                );

            return is_array($found) === true && $found !== [];
        } catch (\Throwable) {
            return false;
        }

    }//end orderExists()

    /**
     * Build an Order record (orderType=subsidie) from a legacy Subsidie row.
     * Every Subsidie field is preserved verbatim on the `subsidie` group.
     *
     * @param array<string,mixed> $src          The source Subsidie row.
     * @param string              $migrationKey The stable migration marker.
     *
     * @return array<string,mixed> The Order record.
     */
    private function buildSubsidieOrder(array $src, string $migrationKey): array
    {
        $grantedAmount = $src['verleendBedrag'] ?? ($src['awardAmount'] ?? null);

        return [
            'administrationId' => (string) ($src['administrationId'] ?? 'unknown'),
            'orderType'        => 'subsidie',
            'direction'        => (string) ($src['direction'] ?? 'outgoing'),
            'orderNumber'      => $migrationKey,
            'counterpartyId'   => $this->stringOrNull($src['counterpartyId'] ?? null),
            'counterpartyName' => $this->firstNonEmpty([($src['counterpartyName'] ?? null), ($src['granteeOrganization'] ?? null)]),
            'currency'         => (string) ($src['currency'] ?? 'EUR'),
            'orderDate'        => $this->toDateTime($src['aanvraagDate'] ?? ($src['awardDate'] ?? null)),
            'totalAmount'      => ($grantedAmount === null ? null : (float) $grantedAmount),
            'description'      => $this->stringOrNull($src['purposeDescription'] ?? ($src['notes'] ?? null)),
            'state'            => (string) ($src['state'] ?? 'aanvraag'),
            'subsidie'         => [
                'subsidieNumber'          => $this->stringOrNull($src['subsidieNumber'] ?? null),
                'regelingNaam'            => $this->firstNonEmpty([($src['regelingNaam'] ?? null), ($src['grantProgram'] ?? null), ($src['subsidieName'] ?? null)]),
                'regelingArtikel'         => $this->firstNonEmpty([($src['regelingArtikel'] ?? null), ($src['grantProgram'] ?? null)]),
                'subsidieRegeling'        => $this->stringOrNull($src['subsidieRegeling'] ?? null),
                'aanvraagDate'            => $this->toDateTime($src['aanvraagDate'] ?? null),
                'beschikkingDate'         => $this->toDateTime($src['beschikkingDate'] ?? ($src['awardDate'] ?? null)),
                'vaststellingDate'        => $this->toDateTime($src['vaststellingDate'] ?? ($src['settlementDate'] ?? null)),
                'settlementDate'          => $this->toDateTime($src['settlementDate'] ?? null),
                'disbursementDate'        => $this->toDateTime($src['disbursementDate'] ?? null),
                'aangevraagdBedrag'       => $this->floatOrNull($src['aangevraagdBedrag'] ?? null),
                'verleendBedrag'          => $this->floatOrNull($src['verleendBedrag'] ?? ($src['awardAmount'] ?? null)),
                'vastgesteldBedrag'       => $this->floatOrNull($src['vastgesteldBedrag'] ?? null),
                'uitbetaaldBedrag'        => $this->floatOrNull($src['uitbetaaldBedrag'] ?? null),
                'teruggevorderdBedrag'    => $this->floatOrNull($src['teruggevorderdBedrag'] ?? null),
                'beschikkingUri'          => $this->stringOrNull($src['beschikkingUri'] ?? null),
                'vaststellingUri'         => $this->stringOrNull($src['vaststellingUri'] ?? null),
                'attachmentUri'           => $this->stringOrNull($src['attachmentUri'] ?? null),
                'prestatieverantwoording' => $this->stringOrNull($src['prestatieverantwoording'] ?? null),
                'afwijzingsReden'         => $this->stringOrNull($src['afwijzingsReden'] ?? null),
                'repaymentPlanId'         => $this->stringOrNull($src['repaymentPlanId'] ?? null),
                'hasRepaymentPlan'        => $this->boolOrNull($src['hasRepaymentPlan'] ?? null),
                'approvingAuthority'      => $this->stringOrNull($src['approvingAuthority'] ?? null),
                'budgetYear'              => $this->intOrNull($src['budgetYear'] ?? null),
                'grantProgram'            => $this->stringOrNull($src['grantProgram'] ?? null),
                'granteeOrganization'     => $this->stringOrNull($src['granteeOrganization'] ?? null),
                'notes'                   => $this->stringOrNull($src['notes'] ?? null),
            ],
            'migratedFrom'     => [
                'schema' => 'Subsidie',
                'key'    => $migrationKey,
            ],
        ];

    }//end buildSubsidieOrder()

    /**
     * Build an Order record (orderType=purchase) from a legacy PurchaseOrder
     * row. Amounts stay in integer EURO CENTS inside the `purchase` group;
     * the shared totalAmount is the decimal-EUR projection (totalInclVat/100).
     *
     * @param array<string,mixed> $src          The source PurchaseOrder row.
     * @param string              $migrationKey The stable migration marker.
     *
     * @return array<string,mixed> The Order record.
     */
    private function buildPurchaseOrder(array $src, string $migrationKey): array
    {
        $totalInclVat = $this->intOrNull($src['totalInclVat'] ?? null);

        return [
            'administrationId' => (string) ($src['administrationId'] ?? 'unknown'),
            'orderType'        => 'purchase',
            'direction'        => 'incoming',
            'orderNumber'      => $migrationKey,
            'counterpartyId'   => $this->stringOrNull($src['supplierId'] ?? null),
            'counterpartyName' => $this->stringOrNull($src['supplierReference'] ?? null),
            'currency'         => (string) ($src['currency'] ?? 'EUR'),
            'orderDate'        => null,
            'totalAmount'      => ($totalInclVat === null ? null : ($totalInclVat / 100.0)),
            'description'      => $this->stringOrNull($src['deliveryAddress'] ?? null),
            'paymentTerms'     => $this->stringOrNull($src['paymentTerms'] ?? null),
            'projectReference' => $this->stringOrNull($src['projectCode'] ?? null),
            'costCenter'       => $this->stringOrNull($src['costCenter'] ?? null),
            'state'            => (string) ($src['statusCode'] ?? 'draft'),
            'purchase'         => [
                'poNumber'             => $this->stringOrNull($src['poNumber'] ?? null),
                'supplierId'           => $this->stringOrNull($src['supplierId'] ?? null),
                'supplierReference'    => $this->stringOrNull($src['supplierReference'] ?? null),
                'requester'            => $this->stringOrNull($src['requester'] ?? null),
                'requisitionId'        => $this->stringOrNull($src['requisitionId'] ?? null),
                'deliveryAddress'      => $this->stringOrNull($src['deliveryAddress'] ?? null),
                'expectedDeliveryDate' => $this->toDateTime($src['expectedDeliveryDate'] ?? null),
                'totalExclVat'         => $this->intOrNull($src['totalExclVat'] ?? null),
                'totalVat'             => $this->intOrNull($src['totalVat'] ?? null),
                'totalInclVat'         => $totalInclVat,
                'approvalChain'        => $src['approvalChain'] ?? [],
                'peppolSentAt'         => $this->toDateTime($src['peppolSentAt'] ?? null),
                'peppolMessageId'      => $this->stringOrNull($src['peppolMessageId'] ?? null),
                'peppolFallbackReason' => $this->stringOrNull($src['peppolFallbackReason'] ?? null),
            ],
            'migratedFrom'     => [
                'schema' => 'PurchaseOrder',
                'key'    => $migrationKey,
            ],
        ];

    }//end buildPurchaseOrder()

    /**
     * Build an Order record (orderType=engagement) from a legacy DBAOpdracht
     * row.
     *
     * @param array<string,mixed> $src          The source DBAOpdracht row.
     * @param string              $migrationKey The stable migration marker.
     *
     * @return array<string,mixed> The Order record.
     */
    private function buildEngagementOrder(array $src, string $migrationKey): array
    {
        $verwachteOmzet = $this->intOrNull($src['verwachteOmzet'] ?? null);

        return [
            'administrationId' => (string) ($src['administrationId'] ?? 'unknown'),
            'orderType'        => 'engagement',
            'direction'        => 'outgoing',
            'orderNumber'      => 'DBA-'.$migrationKey,
            'counterpartyId'   => $this->stringOrNull($src['klantId'] ?? null),
            'counterpartyName' => $this->stringOrNull($src['klantId'] ?? null),
            'currency'         => 'EUR',
            'orderDate'        => $this->toDateTime($src['startDatum'] ?? null),
            'endDate'          => $this->toDateTime($src['verwachteEindDatum'] ?? null),
            'totalAmount'      => ($verwachteOmzet === null ? null : ($verwachteOmzet / 100.0)),
            'description'      => $this->stringOrNull($src['opdrachtNaam'] ?? null),
            'state'            => (string) ($src['intakeStatus'] ?? 'DRAFT'),
            'engagement'       => [
                'ondernemingId'           => $this->stringOrNull($src['ondernemingId'] ?? null),
                'klantId'                 => $this->stringOrNull($src['klantId'] ?? null),
                'opdrachtNaam'            => $this->stringOrNull($src['opdrachtNaam'] ?? null),
                'verwachteEindDatum'      => $this->toDateTime($src['verwachteEindDatum'] ?? null),
                'feitelijkeEindDatum'     => $this->toDateTime($src['feitelijkeEindDatum'] ?? null),
                'verwachteOmzet'          => $verwachteOmzet,
                'gerealiseerdeOmzet'      => $this->intOrNull($src['gerealiseerdeOmzet'] ?? null),
                'eenmaligLageDrempel'     => $this->boolOrNull($src['eenmaligLageDrempel'] ?? null),
                'modelOvereenkomstId'     => $this->stringOrNull($src['modelOvereenkomstId'] ?? null),
                'intakeDatum'             => $this->toDateTime($src['intakeDatum'] ?? null),
                'actueleRisicoscore'      => $this->intOrNull($src['actueleRisicoscore'] ?? null),
                'risicoNiveau'            => $this->stringOrNull($src['risicoNiveau'] ?? null),
                'openFlags'               => $this->intOrNull($src['openFlags'] ?? null),
                'evidenceDossierId'       => $this->stringOrNull($src['evidenceDossierId'] ?? null),
                'wbaBeoordelingResultaat' => $this->stringOrNull($src['wbaBeoordelingResultaat'] ?? null),
                'wbaGeldigTot'            => $this->toDateTime($src['wbaGeldigTot'] ?? null),
                'intermediairMode'        => $this->boolOrNull($src['intermediairMode'] ?? null),
                'perspectief'             => $this->stringOrNull($src['perspectief'] ?? null),
                'retentieDeadline'        => $this->toDateTime($src['retentieDeadline'] ?? null),
            ],
            'migratedFrom'     => [
                'schema' => 'DBAOpdracht',
                'key'    => $migrationKey,
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
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $str = (string) $value;
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
    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = (string) $value;
        return ($str === '' ? null : $str);

    }//end stringOrNull()

    /**
     * Cast to int, or null when absent.
     *
     * @param mixed $value The raw value.
     *
     * @return int|null The int, or null.
     */
    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;

    }//end intOrNull()

    /**
     * Cast to float, or null when absent.
     *
     * @param mixed $value The raw value.
     *
     * @return float|null The float, or null.
     */
    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;

    }//end floatOrNull()

    /**
     * Cast to bool, or null when absent.
     *
     * @param mixed $value The raw value.
     *
     * @return bool|null The bool, or null.
     */
    private function boolOrNull(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;

    }//end boolOrNull()

    /**
     * Normalise a date / date-time source value to an ISO date-time string
     * ('2026-03-01T00:00:00+00:00'), because OR validates the date-time format.
     * A bare date ('2026-03-01') is widened to midnight UTC. Returns null when
     * the source is empty or unparseable.
     *
     * @param mixed $value The raw date value.
     *
     * @return string|null The ISO date-time, or null.
     */
    private function toDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);
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
