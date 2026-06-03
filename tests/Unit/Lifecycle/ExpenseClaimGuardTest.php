<?php

/**
 * Unit tests for ExpenseClaimGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expense-capture-core/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ExpenseClaimGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ExpenseClaimGuard per REQ-EC-007.
 *
 * Covers:
 * - requireCostCentresAndItems: empty claim denied
 * - requireCostCentresAndItems: item missing costCentreCode denied
 * - requireCostCentresAndItems: all items have cost centres — permitted
 * - requireOpenPeriodAndCostCentres: fiscal year not present → permitted (T1)
 * - requireOpenPeriodAndCostCentres: fiscal year open → permitted
 * - requireOpenPeriodAndCostCentres: fiscal year closed → denied
 * - Fail-closed on exception
 */
class ExpenseClaimGuardTest extends TestCase
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
     * The guard under test.
     *
     * @var ExpenseClaimGuard
     */
    private ExpenseClaimGuard $guard;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->guard = new ExpenseClaimGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Build a fluent ObjectService stub that returns given items by schema.
     *
     * @param array<string, array> $itemsBySchema Map of schema → [item arrays].
     * @param array                $fiscalYears   FiscalYear records for isFiscalPeriodOpen.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $itemsBySchema = [], array $fiscalYears = []): object
    {
        $currentSchema = null;
        $stub          = new class ($itemsBySchema, $fiscalYears) {
            public string $currentSchema = '';

            public function __construct(
                private array $itemsBySchema,
                private array $fiscalYears,
            ) {
            }

            public function setRegister(string $register): static
            {
                return $this;
            }

            public function setSchema(string $schema): static
            {
                $this->currentSchema = $schema;
                return $this;
            }

            public function findAll(array $params = []): array
            {
                if ($this->currentSchema === 'FiscalYear') {
                    return $this->fiscalYears;
                }

                return $this->itemsBySchema[$this->currentSchema] ?? [];
            }

            public function find(string $id): ?array
            {
                $items = $this->itemsBySchema[$this->currentSchema] ?? [];
                foreach ($items as $item) {
                    if (($item['id'] ?? '') === $id) {
                        return $item;
                    }
                }

                return null;
            }
        };

        return $stub;

    }//end buildObjectServiceStub()


    /**
     * requireCostCentresAndItems denies a claim with no line items.
     *
     * @return void
     */
    public function testRequireCostCentresAndItemsDeniesEmptyClaim(): void
    {
        $claim = ['id' => 'claim-1', 'receiptIds' => [], 'mileageIds' => [], 'perDiemIds' => []];

        $result = $this->guard->requireCostCentresAndItems(claim: $claim);

        self::assertFalse(condition: $result, message: 'Empty claim must be denied');

    }//end testRequireCostCentresAndItemsDeniesEmptyClaim()


    /**
     * requireCostCentresAndItems denies a claim when a receipt is missing costCentreCode.
     *
     * @return void
     */
    public function testRequireCostCentresAndItemsDeniesItemMissingCostCentre(): void
    {
        $objectService = $this->buildObjectServiceStub(
            itemsBySchema: [
                'Receipt' => [['id' => 'rec-1', 'costCentreCode' => null]],
            ]
        );
        $this->container->method('get')->willReturn($objectService);

        $claim = ['id' => 'claim-1', 'receiptIds' => ['rec-1'], 'mileageIds' => [], 'perDiemIds' => []];

        $result = $this->guard->requireCostCentresAndItems(claim: $claim);

        self::assertFalse(condition: $result, message: 'Receipt missing costCentreCode must deny submit');

    }//end testRequireCostCentresAndItemsDeniesItemMissingCostCentre()


    /**
     * requireCostCentresAndItems permits a claim when all items have costCentreCode.
     *
     * @return void
     */
    public function testRequireCostCentresAndItemsPermitsWhenAllHaveCostCentre(): void
    {
        $objectService = $this->buildObjectServiceStub(
            itemsBySchema: [
                'Receipt'      => [['id' => 'rec-1', 'costCentreCode' => 'CC100']],
                'MileageEntry' => [['id' => 'mlg-1', 'costCentreCode' => 'CC200']],
            ]
        );
        $this->container->method('get')->willReturn($objectService);

        $claim = ['id' => 'claim-1', 'receiptIds' => ['rec-1'], 'mileageIds' => ['mlg-1'], 'perDiemIds' => []];

        $result = $this->guard->requireCostCentresAndItems(claim: $claim);

        self::assertTrue(condition: $result, message: 'All items with costCentreCode must permit submit');

    }//end testRequireCostCentresAndItemsPermitsWhenAllHaveCostCentre()


    /**
     * requireCostCentresAndItems is fail-closed on exception.
     *
     * @return void
     */
    public function testRequireCostCentresAndItemsIsFailClosedOnException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('ObjectService unavailable'));

        $claim = ['id' => 'claim-1', 'receiptIds' => ['rec-1'], 'mileageIds' => [], 'perDiemIds' => []];

        $result = $this->guard->requireCostCentresAndItems(claim: $claim);

        self::assertFalse(condition: $result, message: 'Exception must deny submit (fail-closed)');

    }//end testRequireCostCentresAndItemsIsFailClosedOnException()


    /**
     * requireOpenPeriodAndCostCentres permits posting when FiscalYear register is absent (T1 state).
     *
     * @return void
     */
    public function testRequireOpenPeriodPermitsPostingInT1State(): void
    {
        // First call (allItemsHaveCostCentres) returns object service with item data.
        // Second call (isFiscalPeriodOpen) throws (T1: FiscalYear register absent).
        $callCount     = 0;
        $objectService = $this->buildObjectServiceStub(
            itemsBySchema: [
                'Receipt' => [['id' => 'rec-1', 'costCentreCode' => 'CC100']],
            ],
            fiscalYears: []
        );

        // Simulate T1: FiscalYear findAll throws because the schema doesn't exist yet.
        $throwingStub = new class ($objectService) {
            private int $calls = 0;

            public function __construct(private object $delegate)
            {
            }

            public function setRegister(string $register): static
            {
                return $this;
            }

            public function setSchema(string $schema): static
            {
                $this->calls++;
                if ($schema === 'FiscalYear') {
                    throw new \RuntimeException('FiscalYear schema not found');
                }

                $this->delegate->setSchema(schema: $schema);
                return $this;
            }

            public function findAll(array $params = []): array
            {
                return $this->delegate->findAll(params: $params);
            }

            public function find(string $id): ?array
            {
                return $this->delegate->find(id: $id);
            }
        };

        $this->container->method('get')->willReturn($throwingStub);

        $claim = [
            'id'             => 'claim-1',
            'receiptIds'     => ['rec-1'],
            'mileageIds'     => [],
            'perDiemIds'     => [],
            'fromDate'       => '2026-06-01',
            'administrationId' => 'adm-1',
        ];

        $result = $this->guard->requireOpenPeriodAndCostCentres(claim: $claim);

        self::assertTrue(condition: $result, message: 'T1: FiscalYear absent must permit posting');

    }//end testRequireOpenPeriodPermitsPostingInT1State()


    /**
     * requireOpenPeriodAndCostCentres permits posting when the FiscalYear is open.
     *
     * @return void
     */
    public function testRequireOpenPeriodPermitsPostingWhenFiscalYearIsOpen(): void
    {
        $objectService = $this->buildObjectServiceStub(
            itemsBySchema: [
                'Receipt' => [['id' => 'rec-1', 'costCentreCode' => 'CC100']],
            ],
            fiscalYears: [
                ['id' => 'fy-2026', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'open'],
            ]
        );
        $this->container->method('get')->willReturn($objectService);

        $claim = [
            'id'              => 'claim-1',
            'receiptIds'      => ['rec-1'],
            'mileageIds'      => [],
            'perDiemIds'      => [],
            'fromDate'        => '2026-06-01',
            'administrationId' => 'adm-1',
        ];

        $result = $this->guard->requireOpenPeriodAndCostCentres(claim: $claim);

        self::assertTrue(condition: $result, message: 'Open FiscalYear must permit posting');

    }//end testRequireOpenPeriodPermitsPostingWhenFiscalYearIsOpen()


    /**
     * requireOpenPeriodAndCostCentres denies posting when the FiscalYear is closed.
     *
     * @return void
     */
    public function testRequireOpenPeriodDeniesPostingWhenFiscalYearIsClosed(): void
    {
        $objectService = $this->buildObjectServiceStub(
            itemsBySchema: [
                'Receipt' => [['id' => 'rec-1', 'costCentreCode' => 'CC100']],
            ],
            fiscalYears: [
                ['id' => 'fy-2026', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'closed'],
            ]
        );
        $this->container->method('get')->willReturn($objectService);

        $claim = [
            'id'              => 'claim-1',
            'receiptIds'      => ['rec-1'],
            'mileageIds'      => [],
            'perDiemIds'      => [],
            'fromDate'        => '2026-06-01',
            'administrationId' => 'adm-1',
        ];

        $result = $this->guard->requireOpenPeriodAndCostCentres(claim: $claim);

        self::assertFalse(condition: $result, message: 'Closed FiscalYear must deny posting');

    }//end testRequireOpenPeriodDeniesPostingWhenFiscalYearIsClosed()


}//end class
