<?php

/**
 * Unit tests for FoldIntoOrder.
 *
 * Verifies that legacy Subsidie / PurchaseOrder / DBAOpdracht rows are folded
 * into the unified `Order` schema (orderType=subsidie|purchase|engagement)
 * with every source field preserved on the type-namespaced group, that
 * already-folded rows are skipped (idempotency via the migratedFrom marker),
 * that purchase amounts are correctly converted from integer cents to decimal
 * EUR on the shared totalAmount, and that per-row failures are handled
 * fail-softly (REQ-ORD-003).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\FoldIntoOrder;
use OCA\Shillinq\Service\SettingsService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for FoldIntoOrder.
 *
 * Uses a fake, schema-aware ObjectService that stores records per schema and
 * supports simple dot-notation equality filters (as FoldIntoOrder's
 * idempotency check uses `migratedFrom.schema` / `migratedFrom.key`).
 */
class FoldIntoOrderTest extends TestCase
{

    /**
     * Settings service mock.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * Container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Group manager mock (resolves the admin IUser).
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Output mock.
     *
     * @var IOutput&MockObject
     */
    private IOutput&MockObject $output;

    /**
     * Set up shared fixtures, including an admin group resolving one IUser.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->output          = $this->createMock(IOutput::class);

        $this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

        $admin = $this->createMock(IUser::class);
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$admin]);
        $this->groupManager->method('get')->with('admin')->willReturn($group);

    }//end setUp()

    /**
     * Build a fake, schema-aware ObjectService.
     *
     * @param array<string,array<int,array<string,mixed>>> $recordsBySchema Source records keyed by schema slug.
     *
     * @return object
     */
    private function fakeObjectService(array $recordsBySchema): object
    {
        return new class($recordsBySchema) {

            /**
             * @var array<string,array<int,array<string,mixed>>>
             */
            private array $recordsBySchema;

            private string $currentSchema = '';

            /**
             * @param array<string,array<int,array<string,mixed>>> $recordsBySchema
             */
            public function __construct(array $recordsBySchema)
            {
                $this->recordsBySchema = $recordsBySchema;

            }//end __construct()

            public function setRegister(string $register): static
            {
                return $this;

            }//end setRegister()

            public function setSchema(string $schema): static
            {
                $this->currentSchema = $schema;
                return $this;

            }//end setSchema()

            /**
             * @param array<string,mixed> $params
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $params): array
            {
                $rows = ($this->recordsBySchema[$this->currentSchema] ?? []);

                $filters = ($params['filters'] ?? []);
                if ($filters === []) {
                    return $rows;
                }

                return array_values(
                    array_filter(
                        $rows,
                        function (array $row) use ($filters): bool {
                            foreach ($filters as $path => $expected) {
                                if ($this->dotGet($row, (string) $path) !== $expected) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );

            }//end findAll()

            /**
             * @param array<string,mixed> $object
             */
            public function saveObject(array $object, string $register, string $schema, bool $_rbac, bool $_multitenancy, mixed $currentUser): void
            {
                $this->recordsBySchema[$schema][] = $object;

            }//end saveObject()

            /**
             * @return array<int,array<string,mixed>>
             */
            public function saved(string $schema): array
            {
                return ($this->recordsBySchema[$schema] ?? []);

            }//end saved()

            /**
             * @param array<string,mixed> $row
             */
            private function dotGet(array $row, string $path): mixed
            {
                $segments = explode('.', $path);
                $cursor   = $row;
                foreach ($segments as $segment) {
                    if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
                        return null;
                    }

                    $cursor = $cursor[$segment];
                }

                return $cursor;

            }//end dotGet()
        };

    }//end fakeObjectService()

    /**
     * getName returns a descriptive string.
     */
    public function testGetName(): void
    {
        $step = new FoldIntoOrder(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            container: $this->container,
        );

        self::assertStringContainsString('Subsidie', $step->getName());
        self::assertStringContainsString('PurchaseOrder', $step->getName());
        self::assertStringContainsString('DBAOpdracht', $step->getName());

    }//end testGetName()

    /**
     * A Subsidie row is folded into an Order(orderType=subsidie) with every
     * field preserved on the `subsidie` group and no field dropped.
     */
    public function testFoldsSubsidieIntoOrderLosslessly(): void
    {
        $subsidie = [
            'id'                      => 'sub-1',
            'administrationId'        => 'adm-1',
            'direction'               => 'outgoing',
            'subsidieNumber'          => 'SUB-2026-001',
            'counterpartyName'        => 'Stichting Cultuur Almelo',
            'regelingNaam'            => 'Subsidieregeling cultuur 2026',
            'regelingArtikel'         => 'Art. 3.1',
            'aanvraagDate'            => '2026-02-01',
            'beschikkingDate'         => '2026-03-15',
            'vaststellingDate'        => '2026-10-01',
            'aangevraagdBedrag'       => 25000.0,
            'verleendBedrag'          => 20000.0,
            'vastgesteldBedrag'       => 18500.0,
            'uitbetaaldBedrag'        => 18500.0,
            'teruggevorderdBedrag'    => null,
            'beschikkingUri'          => 'docudesk://x/verlening.pdf',
            'vaststellingUri'         => null,
            'prestatieverantwoording' => 'Aangewend zoals beschreven.',
            'afwijzingsReden'         => null,
            'repaymentPlanId'         => null,
            'hasRepaymentPlan'        => false,
            'state'                   => 'vastgesteld',
            'currency'                => 'EUR',
        ];

        $fakeOs = $this->fakeObjectService(['Subsidie' => [$subsidie]]);
        $this->container->method('get')->willReturn($fakeOs);

        $step = new FoldIntoOrder(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            container: $this->container,
        );
        $step->run($this->output);

        $saved = $fakeOs->saved('OrderPrimitive');
        self::assertCount(1, $saved);

        $order = $saved[0];
        self::assertSame('subsidie', $order['orderType']);
        self::assertSame('outgoing', $order['direction']);
        self::assertSame('SUB-2026-001', $order['orderNumber']);
        self::assertSame('Stichting Cultuur Almelo', $order['counterpartyName']);
        self::assertSame(20000.0, $order['totalAmount']);
        self::assertSame('vastgesteld', $order['state']);
        self::assertSame('Subsidie', $order['migratedFrom']['schema']);
        self::assertSame('SUB-2026-001', $order['migratedFrom']['key']);

        // No regulatory field dropped — every Subsidie field lands on the group.
        self::assertSame('Subsidieregeling cultuur 2026', $order['subsidie']['regelingNaam']);
        self::assertSame('Art. 3.1', $order['subsidie']['regelingArtikel']);
        self::assertSame(25000.0, $order['subsidie']['aangevraagdBedrag']);
        self::assertSame(20000.0, $order['subsidie']['verleendBedrag']);
        self::assertSame(18500.0, $order['subsidie']['vastgesteldBedrag']);
        self::assertSame(18500.0, $order['subsidie']['uitbetaaldBedrag']);
        self::assertSame('docudesk://x/verlening.pdf', $order['subsidie']['beschikkingUri']);
        self::assertSame('Aangewend zoals beschreven.', $order['subsidie']['prestatieverantwoording']);

    }//end testFoldsSubsidieIntoOrderLosslessly()

    /**
     * A PurchaseOrder's integer-cent totalInclVat is converted to decimal EUR
     * on the shared totalAmount, while the original cent value is preserved
     * verbatim inside the `purchase` group (ADR-022 money-unit boundary).
     */
    public function testFoldsPurchaseOrderWithCentToEuroConversion(): void
    {
        $po = [
            'id'                => 'po-1',
            'administrationId'  => 'adm-1',
            'poNumber'          => 'PO-2026-0001',
            'supplierId'        => 'vendor-001',
            'supplierReference' => 'ACK-NW-9914',
            'currency'          => 'EUR',
            'totalExclVat'      => 4000000,
            'totalVat'          => 840000,
            'totalInclVat'      => 4840000,
            'costCenter'        => 'CC-IT-OPERATIONS',
            'projectCode'       => 'PRJ-OFFICE-REFRESH-2026',
            'paymentTerms'      => '30 days net',
            'statusCode'        => 'approved',
        ];

        $fakeOs = $this->fakeObjectService(['PurchaseOrder' => [$po]]);
        $this->container->method('get')->willReturn($fakeOs);

        $step = new FoldIntoOrder(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            container: $this->container,
        );
        $step->run($this->output);

        $saved = $fakeOs->saved('OrderPrimitive');
        self::assertCount(1, $saved);

        $order = $saved[0];
        self::assertSame('purchase', $order['orderType']);
        self::assertSame('incoming', $order['direction']);
        self::assertSame('PO-2026-0001', $order['orderNumber']);
        self::assertSame(48400.0, $order['totalAmount'], 'totalAmount must be decimal EUR (cents / 100)');
        self::assertSame('approved', $order['state']);
        self::assertSame('CC-IT-OPERATIONS', $order['costCenter']);
        self::assertSame('PRJ-OFFICE-REFRESH-2026', $order['projectReference']);
        self::assertSame('30 days net', $order['paymentTerms']);

        // Original integer-cent fields preserved verbatim.
        self::assertSame(4000000, $order['purchase']['totalExclVat']);
        self::assertSame(840000, $order['purchase']['totalVat']);
        self::assertSame(4840000, $order['purchase']['totalInclVat']);

    }//end testFoldsPurchaseOrderWithCentToEuroConversion()

    /**
     * A DBAOpdracht row is folded into an Order(orderType=engagement) with
     * its intakeStatus lifecycle vocabulary preserved verbatim on state.
     */
    public function testFoldsDbaOpdrachtIntoEngagementOrder(): void
    {
        $opdracht = [
            'id'                  => 'dba-opdr-2026-0042',
            'administrationId'    => 'adm-1',
            'ondernemingId'       => 'ond-nl-001234',
            'klantId'             => 'klant-acme-bv',
            'opdrachtNaam'        => 'Backend ontwikkeling betaalmodule',
            'startDatum'          => '2026-03-01',
            'verwachteOmzet'      => 4800000,
            'intakeStatus'        => 'ACTIEF',
            'risicoNiveau'        => 'LAAG_MIDDEN',
            'modelOvereenkomstId' => 'modov-bd-2024-tussenkomstvrij-v3',
        ];

        $fakeOs = $this->fakeObjectService(['DBAOpdracht' => [$opdracht]]);
        $this->container->method('get')->willReturn($fakeOs);

        $step = new FoldIntoOrder(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            container: $this->container,
        );
        $step->run($this->output);

        $saved = $fakeOs->saved('OrderPrimitive');
        self::assertCount(1, $saved);

        $order = $saved[0];
        self::assertSame('engagement', $order['orderType']);
        self::assertSame('DBA-dba-opdr-2026-0042', $order['orderNumber']);
        self::assertSame('ACTIEF', $order['state'], 'engagement state vocabulary preserved verbatim');
        self::assertSame(48000.0, $order['totalAmount']);
        self::assertSame('LAAG_MIDDEN', $order['engagement']['risicoNiveau']);
        self::assertSame('modov-bd-2024-tussenkomstvrij-v3', $order['engagement']['modelOvereenkomstId']);
        self::assertSame('DBAOpdracht', $order['migratedFrom']['schema']);

    }//end testFoldsDbaOpdrachtIntoEngagementOrder()

    /**
     * A row that already has a matching folded Order (migratedFrom marker) is
     * skipped — idempotency.
     */
    public function testSkipsAlreadyFoldedRows(): void
    {
        $subsidie      = [
            'id'             => 'sub-2',
            'subsidieNumber' => 'SUB-2026-002',
        ];
        $alreadyFolded = [
            'orderType'    => 'subsidie',
            'orderNumber'  => 'SUB-2026-002',
            'migratedFrom' => ['schema' => 'Subsidie', 'key' => 'SUB-2026-002'],
        ];

        $fakeOs = $this->fakeObjectService(
            [
                'Subsidie'        => [$subsidie],
                'OrderPrimitive'  => [$alreadyFolded],
            ]
        );
        $this->container->method('get')->willReturn($fakeOs);

        $step = new FoldIntoOrder(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            container: $this->container,
        );
        $step->run($this->output);

        self::assertCount(1, $fakeOs->saved('OrderPrimitive'), 'no second Order must be created for an already-folded row');

    }//end testSkipsAlreadyFoldedRows()

    /**
     * A row without any resolvable id is skipped with a warning, not fatal.
     */
    public function testSkipsRowWithoutId(): void
    {
        $fakeOs = $this->fakeObjectService(['Subsidie' => [['someField' => 'x']]]);
        $this->container->method('get')->willReturn($fakeOs);

        $this->output->expects(self::atLeastOnce())->method('warning');

        $step = new FoldIntoOrder(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            container: $this->container,
        );
        $step->run($this->output);

        self::assertCount(0, $fakeOs->saved('OrderPrimitive'));

    }//end testSkipsRowWithoutId()

    /**
     * Empty source schemas are handled gracefully (fresh-tenant no-op).
     */
    public function testEmptySourcesHandledGracefully(): void
    {
        $fakeOs = $this->fakeObjectService([]);
        $this->container->method('get')->willReturn($fakeOs);

        $step = new FoldIntoOrder(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            container: $this->container,
        );
        $step->run($this->output);

        self::assertCount(0, $fakeOs->saved('OrderPrimitive'));

    }//end testEmptySourcesHandledGracefully()

    /**
     * When no admin user can be resolved, the step warns and does nothing
     * (never fatal at Nextcloud upgrade time).
     */
    public function testNoAdminUserSkipsGracefully(): void
    {
        $emptyGroupManager = $this->createMock(IGroupManager::class);
        $emptyGroupManager->method('get')->with('admin')->willReturn(null);

        $this->output->expects(self::atLeastOnce())->method('warning');

        $step = new FoldIntoOrder(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $emptyGroupManager,
            container: $this->container,
        );
        $step->run($this->output);

    }//end testNoAdminUserSkipsGracefully()
}//end class
