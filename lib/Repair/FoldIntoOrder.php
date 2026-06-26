<?php

/**
 * Shillinq FoldIntoOrder Repair Step
 *
 * Idempotent data migration that folds the legacy order-family rows onto the
 * unified Order primitive (abstract-order-primitive change). The Order base
 * carries an `orderType` discriminator (booking / sales / purchase / grant /
 * engagement / quote / blanket) and the concrete kinds compose onto it via the
 * `allOf` set by the SetOrderExtensionComposition repair step. This step only
 * migrates DATA — it creates no schemas (Grant/Order/PurchaseOrder/Quote/
 * SalesOrder/BlanketOrder already exist):
 *
 *   - Each legacy `Subsidie` row becomes a `Grant` (orderType=grant). The
 *     granted amount (verleendBedrag / awardAmount) maps to Order.totalAmount;
 *     the Dutch + English variant fields map onto Grant's English tail; the
 *     uitbetaald / teruggevorderd amounts, when present, become `Payment`
 *     rows (paymentType disbursement / reclaim).
 *   - Each `PurchaseOrder` row gets orderType=purchase + direction=incoming and
 *     its supplier / 3-way / peppol / total fields mapped onto the base Order
 *     fields it already inherits via composition.
 *   - Each bookings `Order` row (no orderType yet) gets orderType=booking and
 *     its depositAmount / depositPaymentId moved to a `Payment` row
 *     (paymentType=deposit).
 *   - Each `Quote` / `SalesOrder` / `BlanketOrder` row gets orderType set
 *     accordingly (quote / sales / blanket) plus base-field mapping.
 *
 * IDEMPOTENT: a source row already folded (detected by a stable marker —
 * `orderType` already set on an in-place row, or a Grant already carrying the
 * source `subsidieNumber`) is skipped. Source rows are NEVER deleted.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.Commenting.InlineComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Folds the legacy Subsidie / PurchaseOrder / booking-Order / Quote /
 * SalesOrder / BlanketOrder rows onto the unified Order family. Idempotent,
 * fail-soft, never deletes source rows.
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 */
class FoldIntoOrder implements IRepairStep
{
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
     */
    public function getName(): string
    {
        return 'Shillinq: fold legacy Subsidie/PurchaseOrder/booking-Order/Quote/SalesOrder/BlanketOrder rows onto the unified Order family';

    }//end getName()

    /**
     * Run the fold. Idempotent — never duplicates Grant/Payment rows and never
     * deletes source rows.
     *
     * @param IOutput $output The repair-step output (progress + warnings).
     *
     * @return void
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

        $summary['Subsidie']      = $this->foldSubsidies($objectService, $registerSlug, $admin, $output);
        $summary['PurchaseOrder'] = $this->foldInPlace($objectService, $registerSlug, $admin, $output, 'PurchaseOrder', 'purchase', 'incoming');
        $summary['Order']         = $this->foldBookingOrders($objectService, $registerSlug, $admin, $output);
        $summary['Quote']         = $this->foldInPlace($objectService, $registerSlug, $admin, $output, 'Quote', 'quote', 'outgoing');
        $summary['SalesOrder']    = $this->foldInPlace($objectService, $registerSlug, $admin, $output, 'SalesOrder', 'sales', 'outgoing');
        $summary['BlanketOrder']  = $this->foldInPlace($objectService, $registerSlug, $admin, $output, 'BlanketOrder', 'blanket', 'outgoing');

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
     * Read all rows of a schema. Returns [] when the schema is absent or empty
     * (a fresh tenant has ~0 source rows — a valid no-op).
     *
     * @param object  $objectService The OR ObjectService.
     * @param string  $registerSlug  The shillinq register slug.
     * @param string  $schema        The schema slug to read.
     * @param IOutput $output        The repair output.
     *
     * @return array<int,mixed> The list of rows (may be empty).
     */
    private function readRows(object $objectService, string $registerSlug, string $schema, IOutput $output): array
    {
        try {
            $rows = $objectService
                ->setRegister($registerSlug)
                ->setSchema($schema)
                ->findAll(
                    [
                        'limit'         => 0,
                        '_rbac'         => false,
                        '_multitenancy' => false,
                    ]
                );

            if (is_array($rows) === false) {
                return [];
            }

            return $rows;
        } catch (\Throwable $e) {
            $output->info('Shillinq: FoldIntoOrder — '.$schema.' schema not available ('.$e->getMessage().'); skipping.');
            return [];
        }//end try

    }//end readRows()

    /**
     * Fold every legacy Subsidie row onto a Grant (orderType=grant). The
     * granted amount maps to Order.totalAmount; uitbetaald / teruggevorderd
     * amounts become Payment rows. Source Subsidie rows are NOT deleted.
     *
     * Unions the two source field sets:
     *   - operations Subsidie: subsidieNumber, subsidieName, granteeOrganization,
     *     grantProgram, purposeDescription, awardAmount, budgetYear, currency,
     *     approvingAuthority, state, hasRepaymentPlan, awardDate,
     *     disbursementDate, settlementDate, attachmentUri, notes
     *   - register Subsidie (Dutch variant): direction, counterpartyName,
     *     counterpartyId, regelingNaam, regelingArtikel, aanvraagDate,
     *     beschikkingDate, vaststellingDate, aangevraagdBedrag, verleendBedrag,
     *     vastgesteldBedrag, uitbetaaldBedrag, teruggevorderdBedrag,
     *     beschikkingUri, vaststellingUri, prestatieverantwoording,
     *     afwijzingsReden, repaymentPlanId
     *
     * @param object  $objectService The OR ObjectService.
     * @param string  $registerSlug  The shillinq register slug.
     * @param IUser   $admin         The admin user for OR writes.
     * @param IOutput $output        The repair output.
     *
     * @return array{migrated:int,skipped:int,failed:int} Per-schema counts.
     */
    private function foldSubsidies(object $objectService, string $registerSlug, IUser $admin, IOutput $output): array
    {
        $migrated = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($this->readRows($objectService, $registerSlug, 'Subsidie', $output) as $row) {
            $src = (array) $row;

            // Stable migration key: prefer the unique subsidieNumber.
            $subsidieNumber = (string) ($src['subsidieNumber'] ?? '');
            $sourceId       = (string) ($src['id'] ?? ($src['uuid'] ?? ''));
            $migrationKey   = ($subsidieNumber !== '' ? $subsidieNumber : $sourceId);
            if ($migrationKey === '') {
                $output->warning('Shillinq: FoldIntoOrder — Subsidie row without subsidieNumber or id; skipping.');
                $failed++;
                continue;
            }

            try {
                if ($this->grantExists($objectService, $registerSlug, $migrationKey) === true) {
                    $skipped++;
                    continue;
                }

                $grant = $this->buildGrantRecord($src, $migrationKey);

                $saved = $objectService->saveObject(
                    object: $grant,
                    register: $registerSlug,
                    schema: 'Grant',
                    _rbac: false,
                    _multitenancy: false,
                    currentUser: $admin,
                );

                $grantOrderId = $this->extractObjectId($saved, $grant);

                $this->materialiseGrantPayments($objectService, $registerSlug, $admin, $output, $src, $grantOrderId);

                $migrated++;
            } catch (\Throwable $e) {
                $output->warning('Shillinq: FoldIntoOrder — Subsidie "'.$migrationKey.'" fold failed: '.$e->getMessage());
                $this->logger->warning('Shillinq: FoldIntoOrder — Subsidie fold failed', ['key' => $migrationKey, 'exception' => $e->getMessage()]);
                $failed++;
            }//end try
        }//end foreach

        return ['migrated' => $migrated, 'skipped' => $skipped, 'failed' => $failed];

    }//end foldSubsidies()

    /**
     * Build the Grant record from a legacy Subsidie row, unioning the Dutch +
     * English source fields onto the Grant tail. The granted amount becomes
     * Order.totalAmount; nothing from the source is dropped (amounts that do
     * not map to a Grant field — uitbetaald / teruggevorderd — are emitted as
     * Payment rows by materialiseGrantPayments).
     *
     * @param array<string,mixed> $src          The source Subsidie row.
     * @param string              $migrationKey The stable migration marker.
     *
     * @return array<string,mixed> The Grant record (orderType=grant).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function buildGrantRecord(array $src, string $migrationKey): array
    {
        // Granted amount → Order.totalAmount: prefer the Dutch verleendBedrag,
        // then the operations awardAmount.
        $grantedAmount = $src['verleendBedrag'] ?? ($src['awardAmount'] ?? null);

        $scheme = $this->firstNonEmpty([($src['regelingNaam'] ?? null), ($src['grantProgram'] ?? null), ($src['subsidieName'] ?? null)]);

        $record = [
            // ---- Order base fields (inherited via allOf composition) --------.
            'orderType'             => 'grant',
            'direction'             => (string) ($src['direction'] ?? 'outgoing'),
            'orderNumber'           => $migrationKey,
            'counterpartyId'        => $this->stringOrNull($src['counterpartyId'] ?? null),
            'counterpartyName'      => $this->firstNonEmpty([($src['counterpartyName'] ?? null), ($src['granteeOrganization'] ?? null)]),
            'totalAmount'           => ($grantedAmount === null ? null : (float) $grantedAmount),
            'currency'              => (string) ($src['currency'] ?? 'EUR'),
            'orderDate'             => $this->toDateTime($src['awardDate'] ?? ($src['beschikkingDate'] ?? null)),
            'administrationId'      => (string) ($src['administrationId'] ?? 'unknown'),
            'state'                 => $this->mapGrantState((string) ($src['state'] ?? '')),
            'description'           => $this->stringOrNull($src['purposeDescription'] ?? ($src['notes'] ?? null)),

            // ---- Grant tail (English) --------------------------------------.
            'scheme'                => $scheme,
            'schemeArticle'         => $this->firstNonEmpty([($src['regelingArtikel'] ?? null), ($src['grantProgram'] ?? null)]),
            'granteeOrganization'   => $this->stringOrNull($src['granteeOrganization'] ?? null),
            'purposeDescription'    => $this->stringOrNull($src['purposeDescription'] ?? null),
            'budgetYear'            => $this->intOrNull($src['budgetYear'] ?? null),
            'approvingAuthority'    => $this->stringOrNull($src['approvingAuthority'] ?? null),
            'applicationDate'       => $this->toDateTime($src['aanvraagDate'] ?? null),
            'decisionDate'          => $this->toDateTime($src['beschikkingDate'] ?? ($src['awardDate'] ?? null)),
            'settlementDate'        => $this->toDateTime($src['vaststellingDate'] ?? ($src['settlementDate'] ?? null)),
            'appliedAmount'         => $this->floatOrNull($src['aangevraagdBedrag'] ?? null),
            'assessedAmount'        => $this->floatOrNull($src['vastgesteldBedrag'] ?? null),
            'performanceReport'     => $this->stringOrNull($src['prestatieverantwoording'] ?? null),
            'rejectionReason'       => $this->stringOrNull($src['afwijzingsReden'] ?? null),
            'hasRepaymentPlan'      => $this->boolOrNull($src['hasRepaymentPlan'] ?? null),
            'repaymentPlanId'       => $this->stringOrNull($src['repaymentPlanId'] ?? null),
            'decisionDocumentUri'   => $this->firstNonEmpty([($src['beschikkingUri'] ?? null), ($src['attachmentUri'] ?? null)]),
            'settlementDocumentUri' => $this->stringOrNull($src['vaststellingUri'] ?? null),

            // Stable migration marker so re-runs are idempotent.
            'migratedFromSubsidie'  => $migrationKey,
        ];

        return $record;

    }//end buildGrantRecord()

    /**
     * Emit Payment rows for a folded Grant: the uitbetaald amount becomes a
     * disbursement Payment, the teruggevorderd amount a reclaim Payment. No
     * source amount is dropped — anything not mappable to a Grant field lands
     * here as a Payment. Idempotent (skips when a matching Payment exists).
     *
     * @param object              $objectService The OR ObjectService.
     * @param string              $registerSlug  The shillinq register slug.
     * @param IUser               $admin         The admin user for OR writes.
     * @param IOutput             $output        The repair output.
     * @param array<string,mixed> $src           The source Subsidie row.
     * @param string              $grantOrderId  The folded Grant/Order id (Payment.orderId).
     *
     * @return void
     */
    private function materialiseGrantPayments(object $objectService, string $registerSlug, IUser $admin, IOutput $output, array $src, string $grantOrderId): void
    {
        if ($grantOrderId === '') {
            return;
        }

        $administrationId = (string) ($src['administrationId'] ?? 'unknown');
        $currency         = (string) ($src['currency'] ?? 'EUR');

        $disbursed = $this->floatOrNull($src['uitbetaaldBedrag'] ?? null);
        if ($disbursed !== null && $disbursed > 0) {
            $this->createPaymentIfAbsent(
                $objectService,
                    $registerSlug,
                    $admin,
                    $output,
                    $grantOrderId,
                    'disbursement',
                    $disbursed,
                    $currency,
                    $administrationId,
                $this->toDateTime($src['disbursementDate'] ?? null)
            );
        }

        $reclaimed = $this->floatOrNull($src['teruggevorderdBedrag'] ?? null);
        if ($reclaimed !== null && $reclaimed > 0) {
            $this->createPaymentIfAbsent(
                $objectService,
                    $registerSlug,
                    $admin,
                    $output,
                    $grantOrderId,
                    'reclaim',
                    $reclaimed,
                    $currency,
                    $administrationId,
                null
            );
        }

    }//end materialiseGrantPayments()

    /**
     * Fold a bookings Order row in place: set orderType=booking and move the
     * deposit (depositAmount / depositPaymentId) onto a deposit Payment row.
     * The booking deposit amount is stored in EUR cents (minor units) on the
     * Order; the Payment.amount is the same minor-unit value converted to a
     * major-unit number for the generic Payment schema.
     *
     * @param object  $objectService The OR ObjectService.
     * @param string  $registerSlug  The shillinq register slug.
     * @param IUser   $admin         The admin user for OR writes.
     * @param IOutput $output        The repair output.
     *
     * @return array{migrated:int,skipped:int,failed:int} Per-schema counts.
     */
    private function foldBookingOrders(object $objectService, string $registerSlug, IUser $admin, IOutput $output): array
    {
        $migrated = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($this->readRows($objectService, $registerSlug, 'Order', $output) as $row) {
            $src      = (array) $row;
            $sourceId = (string) ($src['id'] ?? ($src['uuid'] ?? ''));

            if ($sourceId === '') {
                $output->warning('Shillinq: FoldIntoOrder — Order row without id; skipping.');
                $failed++;
                continue;
            }

            // Idempotent: an Order already carrying an orderType has been folded
            // (or was created post-migration). Skip.
            if (($src['orderType'] ?? '') !== '') {
                $skipped++;
                continue;
            }

            try {
                $patch = $src;
                $patch['orderType'] = 'booking';
                if (($patch['direction'] ?? '') === '') {
                    $patch['direction'] = 'incoming';
                }

                $objectService->saveObject(
                    object: $patch,
                    register: $registerSlug,
                    schema: 'Order',
                    uuid: $sourceId,
                    _rbac: false,
                    _multitenancy: false,
                    currentUser: $admin,
                );

                // Move the deposit onto a Payment row (idempotent).
                $depositAmount    = $this->floatOrNull($src['depositAmount'] ?? null);
                $depositPaymentId = (string) ($src['depositPaymentId'] ?? '');
                if ($depositAmount !== null && $depositAmount > 0) {
                    // depositAmount is minor units (cents) on the Order; express
                    // the generic Payment.amount in major units.
                    $this->createPaymentIfAbsent(
                        $objectService,
                            $registerSlug,
                            $admin,
                            $output,
                            $sourceId,
                            'deposit',
                        ($depositAmount / 100.0),
                            (string) ($src['currency'] ?? 'EUR'),
                        (string) ($src['administrationId'] ?? 'unknown'),
                            null,
                        ($depositPaymentId !== '' ? $depositPaymentId : null)
                    );
                }

                $migrated++;
            } catch (\Throwable $e) {
                $output->warning('Shillinq: FoldIntoOrder — booking Order id='.$sourceId.' fold failed: '.$e->getMessage());
                $this->logger->warning('Shillinq: FoldIntoOrder — booking Order fold failed', ['id' => $sourceId, 'exception' => $e->getMessage()]);
                $failed++;
            }//end try
        }//end foreach

        return ['migrated' => $migrated, 'skipped' => $skipped, 'failed' => $failed];

    }//end foldBookingOrders()

    /**
     * Fold an Order-extension schema (PurchaseOrder/Quote/SalesOrder/
     * BlanketOrder) in place: set its orderType discriminator + direction and
     * map its source-specific fields onto the base Order fields it already
     * inherits via composition. Idempotent (skips rows already carrying an
     * orderType). Source rows are updated in place — never duplicated.
     *
     * @param object  $objectService The OR ObjectService.
     * @param string  $registerSlug  The shillinq register slug.
     * @param IUser   $admin         The admin user for OR writes.
     * @param IOutput $output        The repair output.
     * @param string  $schema        The extension schema slug.
     * @param string  $orderType     The discriminator value to set.
     * @param string  $direction     The order direction (incoming/outgoing).
     *
     * @return array{migrated:int,skipped:int,failed:int} Per-schema counts.
     */
    private function foldInPlace(object $objectService, string $registerSlug, IUser $admin, IOutput $output, string $schema, string $orderType, string $direction): array
    {
        $migrated = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($this->readRows($objectService, $registerSlug, $schema, $output) as $row) {
            $src      = (array) $row;
            $sourceId = (string) ($src['id'] ?? ($src['uuid'] ?? ''));

            if ($sourceId === '') {
                $output->warning('Shillinq: FoldIntoOrder — '.$schema.' row without id; skipping.');
                $failed++;
                continue;
            }

            if (($src['orderType'] ?? '') !== '') {
                $skipped++;
                continue;
            }

            try {
                $patch = $src;
                $patch['orderType'] = $orderType;
                if (($patch['direction'] ?? '') === '') {
                    $patch['direction'] = $direction;
                }

                $this->mapBaseFields($patch, $src, $schema);

                $objectService->saveObject(
                    object: $patch,
                    register: $registerSlug,
                    schema: $schema,
                    uuid: $sourceId,
                    _rbac: false,
                    _multitenancy: false,
                    currentUser: $admin,
                );

                $migrated++;
            } catch (\Throwable $e) {
                $output->warning('Shillinq: FoldIntoOrder — '.$schema.' id='.$sourceId.' fold failed: '.$e->getMessage());
                $this->logger->warning('Shillinq: FoldIntoOrder — '.$schema.' fold failed', ['id' => $sourceId, 'exception' => $e->getMessage()]);
                $failed++;
            }//end try
        }//end foreach

        return ['migrated' => $migrated, 'skipped' => $skipped, 'failed' => $failed];

    }//end foldInPlace()

    /**
     * Map source-specific fields onto the base Order fields (orderNumber,
     * counterpartyId, counterpartyName, totalAmount, paymentTerms, orderDate,
     * currency) for an in-place extension fold. Only fills base fields that are
     * not already populated, so a hand-curated row is never clobbered. The
     * source-specific fields themselves are preserved on the row (nothing is
     * dropped) — this only ADDS the base projection.
     *
     * @param array<string,mixed> $patch  The patch being built (by reference).
     * @param array<string,mixed> $src    The source row.
     * @param string              $schema The extension schema slug.
     *
     * @return void
     */
    private function mapBaseFields(array &$patch, array $src, string $schema): void
    {
        $map = match ($schema) {
            'PurchaseOrder' => [
                'orderNumber'      => ['poNumber'],
                'counterpartyId'   => ['supplierId'],
                'counterpartyName' => ['supplierReference'],
                'totalAmount'      => ['totalInclVat'],
                'paymentTerms'     => ['paymentTerms'],
                'projectReference' => ['projectCode'],
            ],
            'Quote' => [
                'orderNumber'    => ['quoteNumber'],
                'counterpartyId' => ['customerReference'],
                'paymentTerms'   => ['paymentTerms'],
            ],
            'SalesOrder' => [
                'orderNumber'    => ['orderNumber', 'orderId'],
                'counterpartyId' => ['customerReference', 'klantId'],
                'orderDate'      => ['orderDate'],
                'paymentTerms'   => ['paymentTerms'],
            ],
            'BlanketOrder' => [
                'orderNumber'    => ['blanketOrderNumber'],
                'counterpartyId' => ['customerReference'],
                'totalAmount'    => ['committedQuantity'],
            ],
            default => [],
        };//end match

        foreach ($map as $target => $candidates) {
            if (($patch[$target] ?? '') !== '' && ($patch[$target] ?? null) !== null) {
                continue;
            }

            $value = $this->firstNonEmpty(array_map(static fn ($k) => ($src[$k] ?? null), $candidates));
            if ($value === null) {
                continue;
            }

            if ($target === 'totalAmount') {
                $patch[$target] = (float) $value;
                continue;
            }

            $patch[$target] = $value;
        }

        // Currency default for the base projection.
        if (($patch['currency'] ?? '') === '') {
            $patch['currency'] = (string) ($src['currency'] ?? 'EUR');
        }

    }//end mapBaseFields()

    /**
     * Create a Payment row when an equivalent one does not already exist
     * (idempotency keyed on orderId + paymentType). Fail-soft.
     *
     * @param object      $objectService    The OR ObjectService.
     * @param string      $registerSlug     The shillinq register slug.
     * @param IUser       $admin            The admin user for OR writes.
     * @param IOutput     $output           The repair output.
     * @param string      $orderId          The owning Order/Grant id.
     * @param string      $paymentType      deposit / disbursement / reclaim.
     * @param float       $amount           The payment amount (major units).
     * @param string      $currency         ISO 4217 currency.
     * @param string      $administrationId The owning administration.
     * @param string|null $paymentDate      ISO date-time, or null.
     * @param string|null $linkedDepositId  Existing deposit-payment id, or null.
     *
     * @return void
     */
    private function createPaymentIfAbsent(
        object $objectService,
        string $registerSlug,
        IUser $admin,
        IOutput $output,
        string $orderId,
        string $paymentType,
        float $amount,
        string $currency,
        string $administrationId,
        ?string $paymentDate,
        ?string $linkedDepositId=null
    ): void {
        try {
            $existing = $objectService
                ->setRegister($registerSlug)
                ->setSchema('Payment')
                ->findAll(
                    [
                        'filters'       => ['orderId' => $orderId, 'paymentType' => $paymentType],
                        'limit'         => 1,
                        '_rbac'         => false,
                        '_multitenancy' => false,
                    ]
                );

            if (is_array($existing) === true && $existing !== []) {
                return;
            }

            $payment = [
                'orderId'          => $orderId,
                'paymentType'      => $paymentType,
                'amount'           => $amount,
                'currency'         => $currency,
                'administrationId' => $administrationId,
            ];
            if ($paymentDate !== null) {
                $payment['paymentDate'] = $paymentDate;
            }

            if ($linkedDepositId !== null) {
                $payment['linkedInvoiceId'] = $linkedDepositId;
            }

            $objectService->saveObject(
                object: $payment,
                register: $registerSlug,
                schema: 'Payment',
                _rbac: false,
                _multitenancy: false,
                currentUser: $admin,
            );
        } catch (\Throwable $e) {
            $output->warning('Shillinq: FoldIntoOrder — Payment ('.$paymentType.') for order '.$orderId.' failed: '.$e->getMessage());
        }//end try

    }//end createPaymentIfAbsent()

    /**
     * Whether a Grant already exists carrying the given migration marker
     * (idempotency for the Subsidie fold).
     *
     * @param object $objectService The OR ObjectService.
     * @param string $registerSlug  The shillinq register slug.
     * @param string $migrationKey  The stable source marker.
     *
     * @return bool True when a matching Grant exists.
     */
    private function grantExists(object $objectService, string $registerSlug, string $migrationKey): bool
    {
        try {
            $found = $objectService
                ->setRegister($registerSlug)
                ->setSchema('Grant')
                ->findAll(
                    [
                        'filters'       => ['migratedFromSubsidie' => $migrationKey],
                        'limit'         => 1,
                        '_rbac'         => false,
                        '_multitenancy' => false,
                    ]
                );

            return is_array($found) === true && $found !== [];
        } catch (\Throwable) {
            return false;
        }

    }//end grantExists()

    /**
     * Map the Dutch Subsidie lifecycle state onto the Order lifecycle enum
     * (draft / pending_payment / confirmed / completed / cancelled). Unknown
     * states fall back to draft.
     *
     * @param string $state The source state.
     *
     * @return string The mapped Order state.
     */
    private function mapGrantState(string $state): string
    {
        return match ($state) {
            'aanvraag'                => 'draft',
            'verleend', 'gewijzigd'   => 'confirmed',
            'vastgesteld', 'uitbetaald', 'afgehandeld' => 'completed',
            'ingetrokken'             => 'cancelled',
            'teruggevorderd', 'afbetalingsregeling'    => 'completed',
            default                   => 'draft',
        };

    }//end mapGrantState()

    /**
     * Extract the persisted object id from a saveObject return value, falling
     * back to the source record's own id.
     *
     * @param mixed               $saved  The saveObject return (ObjectEntity).
     * @param array<string,mixed> $record The record that was saved.
     *
     * @return string The object id (may be empty when unresolvable).
     */
    private function extractObjectId(mixed $saved, array $record): string
    {
        if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
            $uuid = (string) $saved->getUuid();
            if ($uuid !== '') {
                return $uuid;
            }
        }

        if (is_object($saved) === true && method_exists($saved, 'getId') === true) {
            $id = (string) $saved->getId();
            if ($id !== '') {
                return $id;
            }
        }

        $arr     = (array) $saved;
        $fromArr = (string) ($arr['uuid'] ?? ($arr['id'] ?? ''));
        if ($fromArr !== '') {
            return $fromArr;
        }

        return (string) ($record['id'] ?? ($record['uuid'] ?? ''));

    }//end extractObjectId()

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
            // A bare YYYY-MM-DD is widened to midnight UTC.
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) === 1) {
                $str .= 'T00:00:00+00:00';
            }

            return (new \DateTimeImmutable($str))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }

    }//end toDateTime()
}//end class
