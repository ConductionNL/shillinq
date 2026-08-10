<?php

/**
 * Unit tests for CommitmentMaterialisationService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Commitment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Commitment;

use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCA\Shillinq\Lifecycle\MandateEnforcer;
use OCA\Shillinq\Service\Commitment\CommitmentMaterialisationService;
use OCA\Shillinq\Service\Commitment\InsufficientCommitmentBudgetException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CommitmentMaterialisationService per REQ-VPL-010/011/012.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class CommitmentMaterialisationServiceTest extends TestCase
{

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock IEventDispatcher.
     *
     * @var IEventDispatcher&MockObject
     */
    private IEventDispatcher&MockObject $dispatcher;

    /**
     * The in-memory ObjectService stub instance used by the current test.
     *
     * @var object
     */
    private object $objectServiceStub;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->dispatcher = $this->createMock(IEventDispatcher::class);
        $this->container  = $this->createMock(ContainerInterface::class);

    }//end setUp()

    /**
     * Build the service under test, wired to a fresh in-memory ObjectService
     * stub seeded with the given records.
     *
     * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Seed records by schema.
     *
     * @return CommitmentMaterialisationService
     */
    private function buildService(array $recordsBySchema): CommitmentMaterialisationService
    {
        $this->objectServiceStub = $this->buildObjectServiceStub(recordsBySchema: $recordsBySchema);
        $this->container->method('get')->willReturn($this->objectServiceStub);

        $mandaat = new MandateEnforcer(container: $this->container, appConfig: $this->appConfig, logger: $this->logger);
        $budget  = new BudgetBlocker(container: $this->container, appConfig: $this->appConfig, logger: $this->logger, mandaat: $mandaat);

        return new CommitmentMaterialisationService(
            container: $this->container,
            appConfig: $this->appConfig,
            mandaat: $mandaat,
            budget: $budget,
            dispatcher: $this->dispatcher,
            logger: $this->logger,
        );

    }//end buildService()

    /**
     * Build a filter-aware, save-recording ObjectService stub.
     *
     * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $recordsBySchema): object
    {
        return new class ($recordsBySchema) {

            /**
             * Map of schema name → record arrays (seed + saved).
             *
             * @var array<string, array<int, array<string, mixed>>>
             */
            public array $recordsBySchema;

            /**
             * Every saveObject() call, in order, as [schema, object].
             *
             * @var array<int, array{0: string, 1: array<string,mixed>}>
             */
            public array $saved = [];

            /**
             * Currently active schema name.
             *
             * @var string
             */
            private string $currentSchema = '';

            /**
             * Constructor.
             *
             * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
             */
            public function __construct(array $recordsBySchema)
            {
                $this->recordsBySchema = $recordsBySchema;

            }//end __construct()

            /**
             * Fluent register setter.
             *
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;

            }//end setRegister()

            /**
             * Fluent schema setter.
             *
             * @param string $schema Schema name.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                $this->currentSchema = $schema;
                return $this;

            }//end setSchema()

            /**
             * Return stubbed records matching the exact-match filters.
             *
             * @param array<string, mixed> $params Query parameters.
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $params=[]): array
            {
                $records = ($this->recordsBySchema[$this->currentSchema] ?? []);
                $filters = ($params['filters'] ?? []);

                return array_values(
                    array_filter(
                        $records,
                        static function (array $record) use ($filters): bool {
                            foreach ($filters as $key => $value) {
                                if (($record[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );

            }//end findAll()

            /**
             * Record a write on the active schema and echo it back.
             *
             * @param array<string, mixed> $object Object body.
             *
             * @return array<string, mixed>
             */
            public function saveObject(array $object): array
            {
                $this->saved[] = [$this->currentSchema, $object];
                $this->recordsBySchema[$this->currentSchema][] = $object;
                return $object;

            }//end saveObject()
        };

    }//end buildObjectServiceStub()

    /**
     * A Budget row scoped by kostenplaats (used to resolve programma).
     *
     * @param array<string,mixed> $overrides Field overrides.
     *
     * @return array<string,mixed>
     */
    private function budget(array $overrides=[]): array
    {
        return array_merge(
            [
                'administrationId'           => 'adm-1',
                'programmaCode'              => '5.1',
                'kostenplaats'               => 'FAC-2026',
                'boekjaar'                   => 2026,
                'geautoriseerd_bedrag'       => 50000000,
                'gerealiseerd_bedrag'        => 20000000,
                'openstaande_verplichtingen' => 0,
            ],
            $overrides
        );

    }//end budget()

    /**
     * A minimal approved PurchaseOrder payload.
     *
     * @param array<string,mixed> $overrides Field overrides.
     *
     * @return array<string,mixed>
     */
    private function purchaseOrder(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'                   => 'po-uuid-1',
                'poNumber'             => 'PO-2026-0207',
                'administrationId'     => 'adm-1',
                'costCenter'           => 'FAC-2026',
                'supplierId'           => 'vendor-1',
                'expectedDeliveryDate' => '2026-05-01',
                'statusCode'           => 'approved',
            ],
            $overrides
        );

    }//end purchaseOrder()

    /**
     * A PurchaseOrderLine for the demo PO.
     *
     * @param array<string,mixed> $overrides Field overrides.
     *
     * @return array<string,mixed>
     */
    private function purchaseOrderLine(array $overrides=[]): array
    {
        return array_merge(
            [
                'poId'                 => 'po-uuid-1',
                'administrationId'     => 'adm-1',
                'lineNumber'           => 1,
                'costCenter'           => 'FAC-2026',
                'glAccount'            => '4400',
                'expectedDeliveryDate' => '2026-05-01',
                'lineTotal'            => 7500000,
            ],
            $overrides
        );

    }//end purchaseOrderLine()

    /**
     * REQ-VPL-010: an approved PO with sufficient budget materialises a
     * Commitment + regel and reserves budget via the existing guards.
     *
     * @return void
     */
    public function testPurchaseOrderApprovalMaterialisesCommitment(): void
    {
        $service = $this->buildService(
            [
                'Commitment'       => [],
                'CommitmentLine' => [],
                'Budget'             => [$this->budget()],
                'Mandaat'            => [
                    [
                        'administrationId'   => 'adm-1',
                        'mandateCode'        => 'M-INKOOP-50K',
                        'maximumbedrag'      => 10000000,
                        'commitmentType' => ['inkooporder'],
                        'is_override'        => false,
                        'geldig_van'         => '2020-01-01',
                        'geldig_tot'         => '2999-12-31',
                    ],
                ],
                'PurchaseOrderLine'  => [$this->purchaseOrderLine()],
            ]
        );

        $this->dispatcher->expects(self::once())->method('dispatch');

        $result = $service->materialiseFromPurchaseOrder(purchaseOrder: $this->purchaseOrder());

        self::assertNotNull($result);
        self::assertSame('PO-2026-0207', $result['bronReferentie']);
        self::assertSame('aangegaan', $result['status']);
        self::assertSame(7500000, $result['totaalbedrag_excl_btw']);

        $regelSaves = array_values(array_filter($this->objectServiceStub->saved, static fn ($s) => $s[0] === 'CommitmentLine'));
        self::assertCount(1, $regelSaves);
        self::assertSame('5.1', $regelSaves[0][1]['programma']);
        self::assertSame(2026, $regelSaves[0][1]['boekjaar']);
        self::assertSame(7500000, $regelSaves[0][1]['bedrag_excl_btw']);

    }//end testPurchaseOrderApprovalMaterialisesCommitment()

    /**
     * REQ-VPL-010: materialisation is idempotent — a repeated approval for
     * the same bronReferentie does not create a duplicate.
     *
     * @return void
     */
    public function testMaterialisationIsIdempotent(): void
    {
        $existing = [
            'administrationId'    => 'adm-1',
            'commitmentNumber' => 'PO-2026-0207',
            'bronReferentie'      => 'PO-2026-0207',
            'status'              => 'aangegaan',
        ];

        $service = $this->buildService(
            [
                'Commitment'      => [$existing],
                'Budget'            => [$this->budget()],
                'Mandaat'           => [],
                'PurchaseOrderLine' => [$this->purchaseOrderLine()],
            ]
        );

        $this->dispatcher->expects(self::never())->method('dispatch');

        $result = $service->materialiseFromPurchaseOrder(purchaseOrder: $this->purchaseOrder());

        self::assertSame($existing, $result);
        $verplichtingSaves = array_filter($this->objectServiceStub->saved, static fn ($s) => $s[0] === 'Commitment');
        self::assertCount(0, $verplichtingSaves);

    }//end testMaterialisationIsIdempotent()

    /**
     * REQ-VPL-010: insufficient budget and no override-mandate denies the
     * approval (fail-closed) rather than materialising an unfunded commitment.
     *
     * @return void
     */
    public function testInsufficientBudgetBlocksPurchaseOrderApproval(): void
    {
        $tightBudget = $this->budget(['geautoriseerd_bedrag' => 8000000, 'gerealiseerd_bedrag' => 7000000]);

        $service = $this->buildService(
            [
                'Commitment'      => [],
                'Budget'            => [$tightBudget],
                'Mandaat'           => [
                    [
                        'administrationId'   => 'adm-1',
                        'mandateCode'        => 'M-DIRECTEUR-250K',
                        // Ceiling comfortably covers the EUR 300.000 commitment amount
                        // so the mandate-sufficiency check passes and the flow reaches
                        // the budget check (this test is specifically about budget
                        // denial, not mandate denial).
                        'maximumbedrag'      => 100000000,
                        'commitmentType' => ['inkooporder'],
                        'is_override'        => false,
                        'geldig_van'         => '2020-01-01',
                        'geldig_tot'         => '2999-12-31',
                    ],
                ],
                // EUR 300.000 line, free room only EUR 10.000.
                'PurchaseOrderLine' => [$this->purchaseOrderLine(['lineTotal' => 30000000])],
            ]
        );

        $this->expectException(InsufficientCommitmentBudgetException::class);

        try {
            $service->materialiseFromPurchaseOrder(purchaseOrder: $this->purchaseOrder());
        } finally {
            $verplichtingSaves = array_filter($this->objectServiceStub->saved, static fn ($s) => $s[0] === 'Commitment');
            self::assertCount(0, $verplichtingSaves, 'No Commitment must be persisted when budget denies');
        }

    }//end testInsufficientBudgetBlocksPurchaseOrderApproval()

    /**
     * REQ-VPL-010 / REQ-VPL-012: a budget-exceeding PO materialises under an
     * override-mandate, records the override reason, and raises a
     * Rechtmatigheidsbevinding afwijking (REQ-RV-005 aggregation target).
     *
     * @return void
     */
    public function testOverrideMandateMaterialisesAndRecordsAfwijking(): void
    {
        $tightBudget = $this->budget(['geautoriseerd_bedrag' => 8000000, 'gerealiseerd_bedrag' => 7000000]);

        $service = $this->buildService(
            [
                'Commitment'             => [],
                'CommitmentLine'       => [],
                'Rechtmatigheidsbevinding' => [],
                'Budget'                   => [$tightBudget],
                'Mandaat'                  => [
                    [
                        'administrationId'   => 'adm-1',
                        'mandateCode'        => 'M-CFO-OVERRIDE',
                        'maximumbedrag'      => 1000000000,
                        'commitmentType' => ['inkooporder'],
                        'is_override'        => true,
                        'geldig_van'         => '2020-01-01',
                        'geldig_tot'         => '2999-12-31',
                    ],
                ],
                'PurchaseOrderLine'        => [$this->purchaseOrderLine(['lineTotal' => 30000000])],
            ]
        );

        $result = $service->materialiseFromPurchaseOrder(purchaseOrder: $this->purchaseOrder());

        self::assertNotNull($result);
        self::assertSame('aangegaan', $result['status']);
        self::assertNotEmpty($result['override_reden']);
        self::assertSame('M-CFO-OVERRIDE', $result['mandaat_toegepast']);

        $bevindingSaves = array_values(array_filter($this->objectServiceStub->saved, static fn ($s) => $s[0] === 'Rechtmatigheidsbevinding'));
        self::assertCount(1, $bevindingSaves);
        self::assertSame('fout', $bevindingSaves[0][1]['soort']);
        self::assertSame('begroting', $bevindingSaves[0][1]['criterium']);

    }//end testOverrideMandateMaterialisesAndRecordsAfwijking()

    /**
     * REQ-VPL-002 parity: no sufficient mandate routes the auto-materialised
     * commitment to in_goedkeuring without a budget check (mirrors the
     * existing manual `indienen` semantics).
     *
     * @return void
     */
    public function testNoSufficientMandateRoutesToInGoedkeuring(): void
    {
        $service = $this->buildService(
            [
                'Commitment'       => [],
                'CommitmentLine' => [],
                'Budget'             => [],
                'Mandaat'            => [],
                'PurchaseOrderLine'  => [$this->purchaseOrderLine()],
            ]
        );

        $result = $service->materialiseFromPurchaseOrder(purchaseOrder: $this->purchaseOrder());

        self::assertNotNull($result);
        self::assertSame('in_goedkeuring', $result['status']);

    }//end testNoSufficientMandateRoutesToInGoedkeuring()

    /**
     * REQ-VPL-010: a multi-year framework PO (lines dated across boekjaren)
     * materialises one regel per boekjaar, each reserving budget independently.
     *
     * @return void
     */
    public function testMultiYearFrameworkMaterialisesOneRegelPerBoekjaar(): void
    {
        $budget2026 = $this->budget(['boekjaar' => 2026, 'geautoriseerd_bedrag' => 20000000, 'gerealiseerd_bedrag' => 0]);
        $budget2027 = $this->budget(['boekjaar' => 2027, 'geautoriseerd_bedrag' => 20000000, 'gerealiseerd_bedrag' => 0]);

        $mandaat = [
            'administrationId'   => 'adm-1',
            'mandateCode'        => 'M-DIRECTEUR-250K',
            'maximumbedrag'      => 25000000,
            'commitmentType' => ['inkooporder'],
            'is_override'        => false,
            'geldig_van'         => '2020-01-01',
            'geldig_tot'         => '2999-12-31',
        ];

        $service = $this->buildService(
            [
                'Commitment'       => [],
                'CommitmentLine' => [],
                'Budget'             => [$budget2026, $budget2027],
                'Mandaat'            => [$mandaat],
                'PurchaseOrderLine'  => [
                    $this->purchaseOrderLine(['lineNumber' => 1, 'expectedDeliveryDate' => '2026-03-01', 'lineTotal' => 10000000]),
                    $this->purchaseOrderLine(['lineNumber' => 2, 'expectedDeliveryDate' => '2027-03-01', 'lineTotal' => 10000000]),
                ],
            ]
        );

        $result = $service->materialiseFromPurchaseOrder(purchaseOrder: $this->purchaseOrder(['poNumber' => 'PO-2026-0231']));

        self::assertNotNull($result);
        self::assertSame('aangegaan', $result['status']);

        $regelSaves = array_values(array_filter($this->objectServiceStub->saved, static fn ($s) => $s[0] === 'CommitmentLine'));
        self::assertCount(2, $regelSaves);
        $boekjaren = array_map(static fn ($s) => $s[1]['boekjaar'], $regelSaves);
        sort($boekjaren);
        self::assertSame([2026, 2027], $boekjaren);

    }//end testMultiYearFrameworkMaterialisesOneRegelPerBoekjaar()

    /**
     * Contract path is fail-soft: budget denial does not throw, just logs
     * and returns null.
     *
     * @return void
     */
    public function testContractActivationIsFailSoftOnBudgetDenial(): void
    {
        $tightBudget = $this->budget(['geautoriseerd_bedrag' => 1000000, 'gerealiseerd_bedrag' => 900000]);

        $service = $this->buildService(
            [
                'Commitment' => [],
                'Budget'       => [$tightBudget],
                'Mandaat'      => [
                    [
                        'administrationId'   => 'adm-1',
                        'mandateCode'        => 'M-DIRECTEUR-250K',
                        'maximumbedrag'      => 25000000,
                        'commitmentType' => ['leasing', 'overig', 'huurovereenkomst'],
                        'is_override'        => false,
                        'geldig_van'         => '2020-01-01',
                        'geldig_tot'         => '2999-12-31',
                    ],
                ],
            ]
        );

        $contract = [
            'id'                 => 'contract-uuid-1',
            'contractNumber'     => 'C-2026-007',
            'administrationId'   => 'adm-1',
            'costCenter'         => 'FAC-2026',
            'contractType'       => 'service',
            'totalContractValue' => 48000.0,
            'startDate'          => '2026-01-01',
            'endDate'            => '2026-12-31',
            'status'             => 'active',
        ];

        $result = $service->materialiseFromContract(contract: $contract);

        self::assertNull($result);
        $verplichtingSaves = array_filter($this->objectServiceStub->saved, static fn ($s) => $s[0] === 'Commitment');
        self::assertCount(0, $verplichtingSaves);

    }//end testContractActivationIsFailSoftOnBudgetDenial()

    /**
     * A multi-year Contract splits its totalContractValue evenly per
     * boekjaar (REQ-VPL-004 parity).
     *
     * @return void
     */
    public function testContractSpanningYearsSplitsPerBoekjaar(): void
    {
        $budget2026 = $this->budget(['boekjaar' => 2026, 'geautoriseerd_bedrag' => 5000000, 'gerealiseerd_bedrag' => 0]);
        $budget2027 = $this->budget(['boekjaar' => 2027, 'geautoriseerd_bedrag' => 5000000, 'gerealiseerd_bedrag' => 0]);

        $mandaat = [
            'administrationId'   => 'adm-1',
            'mandateCode'        => 'M-DIRECTEUR-250K',
            'maximumbedrag'      => 25000000,
            'commitmentType' => ['overig'],
            'is_override'        => false,
            'geldig_van'         => '2020-01-01',
            'geldig_tot'         => '2999-12-31',
        ];

        $service = $this->buildService(
            [
                'Commitment'       => [],
                'CommitmentLine' => [],
                'Budget'             => [$budget2026, $budget2027],
                'Mandaat'            => [$mandaat],
            ]
        );

        $contract = [
            'id'                 => 'contract-uuid-2',
            'contractNumber'     => 'C-2026-020',
            'administrationId'   => 'adm-1',
            'costCenter'         => 'FAC-2026',
            'contractType'       => 'service',
            'totalContractValue' => 20000.0,
            'startDate'          => '2026-06-01',
            'endDate'            => '2027-05-31',
            'status'             => 'active',
        ];

        $result = $service->materialiseFromContract(contract: $contract);

        self::assertNotNull($result);
        self::assertSame('overig', $result['soort']);
        $regelSaves = array_values(array_filter($this->objectServiceStub->saved, static fn ($s) => $s[0] === 'CommitmentLine'));
        self::assertCount(2, $regelSaves);
        // EUR 20.000,00 = 2.000.000 cents, split evenly across 2026 + 2027.
        $totalCents = array_sum(array_map(static fn ($s) => $s[1]['bedrag_excl_btw'], $regelSaves));
        self::assertSame(2000000, $totalCents);
        foreach ($regelSaves as $save) {
            self::assertSame(1000000, $save[1]['bedrag_excl_btw']);
        }

    }//end testContractSpanningYearsSplitsPerBoekjaar()
}//end class
