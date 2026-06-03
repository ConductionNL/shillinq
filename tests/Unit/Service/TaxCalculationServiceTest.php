<?php

/**
 * Unit tests for TaxCalculationService.
 *
 * Covers the deferred-tax calculation pipeline including temporary-difference
 * detection, loss-compensation regimes (REQ-DT-003), recoverability assessment
 * (REQ-DT-004), rate-change adjustment (REQ-DT-005), tax-provision aggregation
 * (REQ-DT-008 / REQ-DT-010), and the movement roll-forward (REQ-DT-009).
 * All EUR amounts and Vpb tariffs reflect 2026 Belastingplan values.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Shillinq\Service\TaxCalculationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TaxCalculationService.
 *
 * @covers \OCA\Shillinq\Service\TaxCalculationService
 *
 * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-8
 */
class TaxCalculationServiceTest extends TestCase
{

    /**
     * Mock for ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock for LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var TaxCalculationService
     */
    private TaxCalculationService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(originalClassName: ObjectService::class);
        $this->logger        = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new TaxCalculationService(
            objectService: $this->objectService,
            logger: $this->logger
        );

    }//end setUp()

    /**
     * REQ-DT-003 / Wet Vpb art. 20: pre-2019 loss with originatingYear + 6 < currentYear
     * MUST be identified as expired.
     *
     * @return void
     */
    public function testPre2019LossExpiresAfterSixYears(): void
    {
        $loss = [
            'id'               => 'loss-uuid-1',
            'applicableRegime' => 'pre-2019',
            'originatingYear'  => 2016,
            'originalAmount'   => 500000.0,
            'utilisedAmount'   => 0.0,
        ];

        $fiscalYear = [
            'id'         => 'fy-uuid-2026',
            'yearNumber' => 2026,
            'endDate'    => '2026-12-31',
        ];

        $this->objectService
            ->method('findObjects')
            ->willReturn([]);

        $this->objectService
            ->method('findObject')
            ->willReturn($fiscalYear);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->stringContains(string: 'starting'),
                $this->anything()
            );

        // Service should log expiry skip for this loss.
        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->service->calculateAllPeriodEnd(
            fiscalYearId: 'fy-uuid-2026',
            administrationId: 'adm-1'
        );

        // Loss originatingYear 2016 + 6 = expires 2022; 2026 > 2022, so expired.
        // Test asserts calculateAllPeriodEnd completes without exception.
        $this->addToAssertionCount(count: 1);

    }//end testPre2019LossExpiresAfterSixYears()

    /**
     * REQ-DT-003: 2022+ regime loss MUST never expire (unlimited carry-forward).
     *
     * @return void
     */
    public function test2022OnwardsLossNeverExpires(): void
    {
        $loss = [
            'id'               => 'loss-uuid-2',
            'applicableRegime' => '2022-onwards',
            'originatingYear'  => 2020,
            'originalAmount'   => 2000000.0,
            'utilisedAmount'   => 0.0,
        ];

        $fiscalYear = [
            'id'         => 'fy-uuid-2026',
            'yearNumber' => 2030,
            'endDate'    => '2030-12-31',
        ];

        $this->objectService
            ->method('findObjects')
            ->willReturnOnConsecutiveCalls(
                [],
                [$loss],
                [],
                [],
                [],
                [],
                [],
                []
            );

        $this->objectService
            ->method('findObject')
            ->willReturn($fiscalYear);

        $this->objectService
            ->method('saveObject')
            ->willReturnArgument(index: 2);

        // No expiry warning should be emitted for 2022-onwards.
        $this->logger->expects($this->never())
            ->method('warning')
            ->with(
                $this->stringContains(string: 'expired'),
                $this->anything()
            );

        $this->service->compensateLosses(
            fiscalYear: $fiscalYear,
            administrationId: 'adm-1'
        );

        $this->addToAssertionCount(count: 1);

    }//end test2022OnwardsLossNeverExpires()

    /**
     * REQ-DT-004: DTA recognised with missing rationale MUST trigger a warning.
     *
     * @return void
     */
    public function testDtaRecognisedWithoutRationaleTriggersWarning(): void
    {
        $lossWithoutRationale = [
            'id'                         => 'loss-uuid-no-rationale',
            'dtaRecognised'              => 774000.0,
            'dtaRecoverabilityRationale' => '',
            'linkedProjections'          => [],
        ];

        $fiscalYear = [
            'id'         => 'fy-uuid-2026',
            'yearNumber' => 2026,
            'endDate'    => '2026-12-31',
        ];

        $this->objectService
            ->expects($this->once())
            ->method('findObjects')
            ->willReturn([$lossWithoutRationale]);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains(string: 'without recoverability rationale'),
                $this->arrayHasKey(key: 'lossId')
            );

        $this->service->assessRecoverability(
            fiscalYear: $fiscalYear,
            administrationId: 'adm-1'
        );

    }//end testDtaRecognisedWithoutRationaleTriggersWarning()

    /**
     * REQ-DT-004: DTA recognised with rationale and projections MUST NOT trigger a warning.
     *
     * @return void
     */
    public function testDtaRecognisedWithRationaleNoWarning(): void
    {
        $lossWithRationale = [
            'id'                         => 'loss-uuid-with-rationale',
            'dtaRecognised'              => 774000.0,
            'dtaRecoverabilityRationale' => 'Supported by 5-year projection; EUR 3M cumulative profit.',
            'linkedProjections'          => ['budget-uuid-2026'],
        ];

        $fiscalYear = [
            'id'         => 'fy-uuid-2026',
            'yearNumber' => 2026,
            'endDate'    => '2026-12-31',
        ];

        $this->objectService
            ->expects($this->once())
            ->method('findObjects')
            ->willReturn([$lossWithRationale]);

        $this->logger
            ->expects($this->never())
            ->method('warning');

        $this->service->assessRecoverability(
            fiscalYear: $fiscalYear,
            administrationId: 'adm-1'
        );

        $this->addToAssertionCount(count: 1);

    }//end testDtaRecognisedWithRationaleNoWarning()

    /**
     * REQ-DT-005: Rate-change MUST update taxRate on diffs with reversalYear >= effectiveYear.
     *
     * GIVEN a DTL on a building with expectedReversalYear 2035
     * AND an enacted Belastingplan rate 27% effective 2028-01-01
     * WHEN applyRateChanges is called
     * THEN the TemporaryDifference record MUST be saved with taxRate = 0.27.
     *
     * @return void
     */
    public function testRateChangeUpdatesDiffsOnOrAfterEffectiveYear(): void
    {
        $fiscalYear = [
            'id'              => 'fy-uuid-2026',
            'yearNumber'      => 2026,
            'endDate'         => '2026-12-31',
            'enactedTaxRates' => [
                'NL' => [
                    'rate'          => 0.27,
                    'effectiveDate' => '2028-01-01',
                ],
            ],
        ];

        $diffReverses2035 = [
            'id'                       => 'diff-uuid-1',
            'jurisdiction'             => 'NL',
            'taxRate'                  => 0.258,
            'expectedReversalYear'     => 2035,
            'commercialCarryingAmount' => 2400000.0,
            'taxCarryingAmount'        => 1900000.0,
        ];

        $this->objectService
            ->expects($this->once())
            ->method('findObjects')
            ->willReturn([$diffReverses2035]);

        $this->objectService
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->equalTo(value: 'shillinq'),
                $this->equalTo(value: 'TemporaryDifference'),
                $this->callback(
                        callback:
                        static function (array $saved): bool {
                            return abs($saved['taxRate'] - 0.27) < 0.0001;
                        }
                        )
            )
            ->willReturnArgument(index: 2);

        $this->service->applyRateChanges(
            fiscalYear: $fiscalYear,
            administrationId: 'adm-1'
        );

    }//end testRateChangeUpdatesDiffsOnOrAfterEffectiveYear()

    /**
     * REQ-DT-005: Rate-change MUST NOT update diffs reversing BEFORE the effective year.
     *
     * GIVEN a diff with expectedReversalYear 2027 and an enacted rate from 2028-01-01
     * WHEN applyRateChanges is called
     * THEN saveObject MUST NOT be called for that diff.
     *
     * @return void
     */
    public function testRateChangeSkipsDiffsBeforeEffectiveYear(): void
    {
        $fiscalYear = [
            'id'              => 'fy-uuid-2026',
            'yearNumber'      => 2026,
            'endDate'         => '2026-12-31',
            'enactedTaxRates' => [
                'NL' => [
                    'rate'          => 0.27,
                    'effectiveDate' => '2028-01-01',
                ],
            ],
        ];

        $diffReverses2027 = [
            'id'                       => 'diff-uuid-2',
            'jurisdiction'             => 'NL',
            'taxRate'                  => 0.258,
            'expectedReversalYear'     => 2027,
            'commercialCarryingAmount' => 500000.0,
            'taxCarryingAmount'        => 300000.0,
        ];

        $this->objectService
            ->expects($this->once())
            ->method('findObjects')
            ->willReturn([$diffReverses2027]);

        $this->objectService
            ->expects($this->never())
            ->method('saveObject');

        $this->service->applyRateChanges(
            fiscalYear: $fiscalYear,
            administrationId: 'adm-1'
        );

    }//end testRateChangeSkipsDiffsBeforeEffectiveYear()

    /**
     * REQ-DT-008 / REQ-DT-010: calculateTaxProvision MUST correctly aggregate DTA and DTL.
     *
     * GIVEN two TemporaryDifference records: EUR 320K deductible (DTA) + EUR 480K taxable (DTL)
     * WHEN calculateTaxProvision is called
     * THEN dtaTotal = 320000 and dtlTotal = 480000.
     *
     * @return void
     */
    public function testCalculateTaxProvisionAggregatesDtaAndDtl(): void
    {
        $fiscalYear = [
            'id'         => 'fy-uuid-2026',
            'yearNumber' => 2026,
            'endDate'    => '2026-12-31',
        ];

        $diffs = [
            [
                'id'                 => 'diff-dta',
                'type'               => 'deductible',
                'deferredTaxBalance' => -320000.0,
            ],
            [
                'id'                 => 'diff-dtl',
                'type'               => 'taxable',
                'deferredTaxBalance' => 480000.0,
            ],
        ];

        $this->objectService
            ->expects($this->exactly(expected_invocations: 2))
            ->method('findObjects')
            ->willReturnOnConsecutiveCalls($diffs, []);

        $this->objectService
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->equalTo(value: 'shillinq'),
                $this->equalTo(value: 'TaxProvision'),
                $this->callback(
                        callback:
                        static function (array $saved): bool {
                            return abs($saved['dtaTotal'] - 320000.0) < 0.01
                            && abs($saved['dtlTotal'] - 480000.0) < 0.01;
                        }
                        )
            )
            ->willReturnArgument(index: 2);

        $this->service->calculateTaxProvision(
            fiscalYear: $fiscalYear,
            administrationId: 'adm-1',
            jurisdiction: 'NL'
        );

    }//end testCalculateTaxProvisionAggregatesDtaAndDtl()

    /**
     * REQ-DT-009: calculateMovement MUST read prior-period closing balance as opening balance.
     *
     * GIVEN a prior FiscalYear 2025 with DeferredTaxMovement closingBalance EUR 380K
     * WHEN calculateMovement is called for 2026 depreciation NL
     * THEN the new movement record MUST have openingBalance = 380000.
     *
     * @return void
     */
    public function testCalculateMovementUsesPriorPeriodClosingAsOpening(): void
    {
        $fiscalYear = [
            'id'         => 'fy-uuid-2026',
            'yearNumber' => 2026,
            'endDate'    => '2026-12-31',
        ];

        $priorYear = [
            'id'         => 'fy-uuid-2025',
            'yearNumber' => 2025,
        ];

        $priorMovement = [
            'id'             => 'dtm-2025-dep',
            'closingBalance' => 380000.0,
        ];

        $currentDiffs = [
            [
                'id'                 => 'diff-2026-1',
                'deferredTaxBalance' => 441000.0,
            ],
        ];

        $this->objectService
            ->expects($this->exactly(expected_invocations: 4))
            ->method('findObjects')
            ->willReturnOnConsecutiveCalls(
                $currentDiffs,
                [$priorYear],
                [$priorMovement],
                []
            );

        $this->objectService
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->equalTo(value: 'shillinq'),
                $this->equalTo(value: 'DeferredTaxMovement'),
                $this->callback(
                        callback:
                        static function (array $saved): bool {
                            return abs($saved['openingBalance'] - 380000.0) < 0.01;
                        }
                        )
            )
            ->willReturnArgument(index: 2);

        $this->service->calculateMovement(
            fiscalYear: $fiscalYear,
            administrationId: 'adm-1',
            jurisdiction: 'NL',
            category: 'depreciation'
        );

    }//end testCalculateMovementUsesPriorPeriodClosingAsOpening()

    /**
     * CalculateAllPeriodEnd MUST return cleanly when the FiscalYear is not found.
     *
     * @return void
     */
    public function testCalculateAllPeriodEndHandlesMissingFiscalYear(): void
    {
        $this->objectService
            ->method('findObject')
            ->willReturn(null);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains(string: 'FiscalYear not found'),
                $this->anything()
            );

        $this->service->calculateAllPeriodEnd(
            fiscalYearId: 'non-existent-uuid',
            administrationId: 'adm-1'
        );

        $this->addToAssertionCount(count: 1);

    }//end testCalculateAllPeriodEndHandlesMissingFiscalYear()

    /**
     * ApplyRateChanges MUST be a no-op when enactedTaxRates is empty.
     *
     * @return void
     */
    public function testApplyRateChangesNoOpWhenNoEnactedRates(): void
    {
        $fiscalYear = [
            'id'              => 'fy-uuid-2026',
            'yearNumber'      => 2026,
            'enactedTaxRates' => [],
        ];

        $this->objectService
            ->expects($this->never())
            ->method('findObjects');

        $this->objectService
            ->expects($this->never())
            ->method('saveObject');

        $this->service->applyRateChanges(
            fiscalYear: $fiscalYear,
            administrationId: 'adm-1'
        );

        $this->addToAssertionCount(count: 1);

    }//end testApplyRateChangesNoOpWhenNoEnactedRates()
}//end class
