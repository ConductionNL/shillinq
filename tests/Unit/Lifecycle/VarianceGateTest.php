<?php

/**
 * Unit tests for VarianceGate.
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
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\VarianceGate;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VarianceGate.
 *
 * Covers REQ-ICC-004 and REQ-ICC-008:
 * - requiresInvestigation: small variance under threshold returns false
 * - requiresInvestigation: quantity % exceeding threshold returns true
 * - requiresInvestigation: cost variance exceeding absolute threshold returns true
 * - requiresInvestigation: zero expected quantity handled without division error
 * - countScopeIsValid: full count with no filters returns true
 * - countScopeIsValid: partial count with locationFilter returns true
 * - countScopeIsValid: partial count without any filter returns false
 * - allFlaggedLinesHaveReasonCodes: all flagged lines have codes returns true
 * - allFlaggedLinesHaveReasonCodes: unfilled flagged line returns false
 * - Exception in ObjectService causes fail-closed response
 */
class VarianceGateTest extends TestCase
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
     * @var VarianceGate
     */
    private VarianceGate $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->guard = new VarianceGate(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    // -------------------------------------------------------------------------
    // requiresInvestigation() — pure computation, no ObjectService needed
    // -------------------------------------------------------------------------

    /**
     * Small variance under both thresholds does not require investigation per REQ-ICC-004.
     *
     * Given: expectedQty=100, countedQty=99 (variance=-1, -1%), unitCost=40, valueVariance=-40.
     * Thresholds: 5% qty, €500 cost.
     * Expected: requiresInvestigation = false.
     *
     * @return void
     */
    public function testSmallVarianceUnderThresholdReturnsFalse(): void
    {
        $result = $this->guard->requiresInvestigation(
            quantityVariance: -1.0,
            expectedQuantity: 100.0,
            valueVariance: -40.0,
            thresholdPercent: 5.0,
            thresholdAbsolute: 500.0,
        );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($result);

    }//end testSmallVarianceUnderThresholdReturnsFalse()

    /**
     * Quantity variance exceeding % threshold requires investigation per REQ-ICC-004.
     *
     * Given: expectedQty=100, countedQty=94 (variance=-6, -6%), unitCost=40.
     * Thresholds: 5% qty, €500 cost.
     * Expected: requiresInvestigation = true.
     *
     * @return void
     */
    public function testQtyExceedingPercentThresholdReturnsTrue(): void
    {
        $result = $this->guard->requiresInvestigation(
            quantityVariance: -6.0,
            expectedQuantity: 100.0,
            valueVariance: -240.0,
            thresholdPercent: 5.0,
            thresholdAbsolute: 500.0,
        );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($result);

    }//end testQtyExceedingPercentThresholdReturnsTrue()

    /**
     * Cost variance exceeding absolute threshold requires investigation regardless of qty %.
     *
     * Given: expectedQty=100, countedQty=50 (variance=-50, -50%), unitCost=15, valueVariance=-750.
     * Thresholds: 5% qty, €500 cost.
     * The qty variance here is above 5% too, but this test verifies cost threshold works.
     *
     * @return void
     */
    public function testCostExceedingAbsoluteThresholdReturnsTrue(): void
    {
        $result = $this->guard->requiresInvestigation(
            quantityVariance: -2.0,
            expectedQuantity: 100.0,
            valueVariance: -750.0,
            thresholdPercent: 5.0,
            thresholdAbsolute: 500.0,
        );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($result);

    }//end testCostExceedingAbsoluteThresholdReturnsTrue()

    /**
     * Zero expected quantity does not cause division by zero; falls back to cost threshold.
     *
     * @return void
     */
    public function testZeroExpectedQuantityHandledSafely(): void
    {
        $result = $this->guard->requiresInvestigation(
            quantityVariance: 0.0,
            expectedQuantity: 0.0,
            valueVariance: 100.0,
            thresholdPercent: 5.0,
            thresholdAbsolute: 500.0,
        );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($result);

    }//end testZeroExpectedQuantityHandledSafely()

    // -------------------------------------------------------------------------
    // countScopeIsValid()
    // -------------------------------------------------------------------------

    /**
     * Full count with no filters has valid scope per REQ-ICC-002.
     *
     * @return void
     */
    public function testFullCountWithNoFiltersIsValid(): void
    {
        $count = ['countType' => 'full', 'locationFilter' => null, 'categoryFilter' => null];
        $this->container->method('get')->willReturn(
                $this->buildObjectServiceStub(
            findResult: $count,
            findAllResult: [],
        )
                );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->countScopeIsValid(countId: 'cnt-001'));

    }//end testFullCountWithNoFiltersIsValid()

    /**
     * Partial count with locationFilter has valid scope per REQ-ICC-008.
     *
     * @return void
     */
    public function testPartialCountWithLocationFilterIsValid(): void
    {
        $count = ['countType' => 'partial', 'locationFilter' => 'warehouse-a', 'categoryFilter' => null];
        $this->container->method('get')->willReturn(
                $this->buildObjectServiceStub(
            findResult: $count,
            findAllResult: [],
        )
                );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->countScopeIsValid(countId: 'cnt-002'));

    }//end testPartialCountWithLocationFilterIsValid()

    /**
     * Partial count without any filter is invalid — cannot submit per REQ-ICC-002.
     *
     * @return void
     */
    public function testPartialCountWithNoFilterIsInvalid(): void
    {
        $count = ['countType' => 'partial', 'locationFilter' => null, 'categoryFilter' => null];
        $this->container->method('get')->willReturn(
                $this->buildObjectServiceStub(
            findResult: $count,
            findAllResult: [],
        )
                );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->countScopeIsValid(countId: 'cnt-003'));

    }//end testPartialCountWithNoFilterIsInvalid()

    // -------------------------------------------------------------------------
    // allFlaggedLinesHaveReasonCodes()
    // -------------------------------------------------------------------------

    /**
     * All flagged lines with reason codes present allows posting per REQ-ICC-004.
     *
     * @return void
     */
    public function testAllFlaggedLinesWithReasonCodesAllowsPosting(): void
    {
        $lines = [
            ['requiresReason' => true, 'reasonCode' => 'DMG'],
            ['requiresReason' => true, 'reasonCode' => 'OBS'],
        ];

        $this->container->method('get')->willReturn(
                $this->buildObjectServiceStub(
            findResult: null,
            findAllResult: $lines,
        )
                );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->allFlaggedLinesHaveReasonCodes(countId: 'cnt-010'));

    }//end testAllFlaggedLinesWithReasonCodesAllowsPosting()

    /**
     * One flagged line without a reason code blocks posting per REQ-ICC-004.
     *
     * @return void
     */
    public function testUnfilledFlaggedLineBlocksPosting(): void
    {
        $lines = [
            ['requiresReason' => true, 'reasonCode' => 'DMG'],
            ['requiresReason' => true, 'reasonCode' => null],
        ];

        $this->container->method('get')->willReturn(
                $this->buildObjectServiceStub(
            findResult: null,
            findAllResult: $lines,
        )
                );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->allFlaggedLinesHaveReasonCodes(countId: 'cnt-011'));

    }//end testUnfilledFlaggedLineBlocksPosting()

    /**
     * Exception in ObjectService causes fail-closed response (returns false).
     *
     * @return void
     */
    public function testExceptionCausesFailClosed(): void
    {
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('ObjectService unavailable'));

        $this->logger->expects($this->once())->method('error');

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->allFlaggedLinesHaveReasonCodes(countId: 'cnt-fail'));

    }//end testExceptionCausesFailClosed()

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an anonymous ObjectService stub returning given find/findAll results.
     *
     * @param array<string,mixed>|null $findResult    Result for find() calls.
     * @param array<mixed>             $findAllResult Result for findAll() calls.
     *
     * @return object
     */
    private function buildObjectServiceStub(?array $findResult, array $findAllResult): object
    {
        return new class($findResult, $findAllResult) {

            /**
             * Result for find() calls.
             *
             * @var array<string,mixed>|null
             */
            private ?array $findResult;

            /**
             * Result for findAll() calls.
             *
             * @var array<mixed>
             */
            private array $findAllResult;

            /**
             * Constructor.
             *
             * @param array<string,mixed>|null $findResult    find() result.
             * @param array<mixed>             $findAllResult findAll() result.
             */
            public function __construct(?array $findResult, array $findAllResult)
            {
                $this->findResult    = $findResult;
                $this->findAllResult = $findAllResult;
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
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * Return stubbed find result.
             *
             * @param string $id Object ID.
             *
             * @return array<string,mixed>|null
             */
            public function find(string $id): ?array
            {
                return $this->findResult;
            }//end find()

            /**
             * Return stubbed findAll result.
             *
             * @param array<string,mixed> $params Query parameters (unused in stub).
             *
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                return $this->findAllResult;
            }//end findAll()
        };
    }//end buildObjectServiceStub()
}//end class
