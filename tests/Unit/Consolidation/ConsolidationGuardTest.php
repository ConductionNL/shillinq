<?php

/**
 * Unit tests for ConsolidationGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Consolidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-financial-statements/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Consolidation;

use OCA\Shillinq\Consolidation\ConsolidationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ConsolidationGuard.
 *
 * Covers:
 * - requireFiscalPeriodClosed: missing fiscalYearId denies
 * - requireFiscalPeriodClosed: FiscalYear not found permits (T1/T2 deferral)
 * - requireFiscalPeriodClosed: closed FiscalYear permits
 * - requireFiscalPeriodClosed: open FiscalYear denies
 * - requireFiscalPeriodClosed: fail-closed on exception
 * - requireAllMembersFinalised: missing fields deny
 * - requireAllMembersFinalised: group not found permits (deferral)
 * - requireAllMembersFinalised: all members final permits
 * - requireAllMembersFinalised: one member not final denies
 * - requirePublicationApproval: always returns true
 *
 * @spec openspec/changes/bookkeeping-financial-statements/tasks.md#task-9
 */
class ConsolidationGuardTest extends TestCase
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
     * @var ConsolidationGuard
     */
    private ConsolidationGuard $guard;

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

        $this->guard = new ConsolidationGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * requireFiscalPeriodClosed denies when fiscalYearId is missing.
     *
     * @return void
     */
    public function testRequireFiscalPeriodClosedDeniesMissingFiscalYearId(): void
    {
        $result = $this->guard->requireFiscalPeriodClosed(
                [
                    'id'               => 'bs-001',
                    'administrationId' => 'adm-1',
                ]
                );

        self::assertFalse($result, 'Missing fiscalYearId must deny finalise');

    }//end testRequireFiscalPeriodClosedDeniesMissingFiscalYearId()

    /**
     * requireFiscalPeriodClosed permits transition when FiscalYear schema is absent (T1/T2 deferral).
     *
     * @return void
     */
    public function testRequireFiscalPeriodClosedPermitsWhenFiscalYearAbsent(): void
    {
        $objectService = $this->buildObjectServiceStub(findObjectReturn: null, findAllReturn: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireFiscalPeriodClosed(
                [
                    'fiscalYearId'     => 'fy-2026',
                    'administrationId' => 'adm-1',
                ]
                );

        self::assertTrue($result, 'FiscalYear absent (T1/T2): finalise must be permitted by default');

    }//end testRequireFiscalPeriodClosedPermitsWhenFiscalYearAbsent()

    /**
     * requireFiscalPeriodClosed permits when FiscalYear.isClosed is true.
     *
     * @return void
     */
    public function testRequireFiscalPeriodClosedPermitsWhenClosed(): void
    {
        $fiscalYear    = ['id' => 'fy-2026', 'isClosed' => true];
        $objectService = $this->buildObjectServiceStub(findObjectReturn: $fiscalYear, findAllReturn: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireFiscalPeriodClosed(
                [
                    'fiscalYearId'     => 'fy-2026',
                    'administrationId' => 'adm-1',
                ]
                );

        self::assertTrue($result, 'Closed FiscalYear must permit finalise');

    }//end testRequireFiscalPeriodClosedPermitsWhenClosed()

    /**
     * requireFiscalPeriodClosed denies when FiscalYear.isClosed is false.
     *
     * @return void
     */
    public function testRequireFiscalPeriodClosedDenieswhenOpen(): void
    {
        $fiscalYear    = ['id' => 'fy-2026', 'isClosed' => false];
        $objectService = $this->buildObjectServiceStub(findObjectReturn: $fiscalYear, findAllReturn: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireFiscalPeriodClosed(
                [
                    'fiscalYearId'     => 'fy-2026',
                    'administrationId' => 'adm-1',
                ]
                );

        self::assertFalse($result, 'Open FiscalYear must deny finalise');

    }//end testRequireFiscalPeriodClosedDenieswhenOpen()

    /**
     * requireFiscalPeriodClosed is fail-closed on exception.
     *
     * @return void
     */
    public function testRequireFiscalPeriodClosedIsFailClosedOnException(): void
    {
        $this->container->method('get')->willThrowException(new \RuntimeException('DB error'));

        $result = $this->guard->requireFiscalPeriodClosed(
                [
                    'fiscalYearId'     => 'fy-2026',
                    'administrationId' => 'adm-1',
                ]
                );

        self::assertFalse($result, 'Exception must deny finalise (fail-closed)');

    }//end testRequireFiscalPeriodClosedIsFailClosedOnException()

    /**
     * requireAllMembersFinalised denies when required fields are missing.
     *
     * @return void
     */
    public function testRequireAllMembersFinalisedDeniesMissingFields(): void
    {
        $result = $this->guard->requireAllMembersFinalised(['reportNumber' => 'CR-001']);

        self::assertFalse($result, 'Missing consolidationGroupId/fiscalYearId must deny');

    }//end testRequireAllMembersFinalisedDeniesMissingFields()

    /**
     * requireAllMembersFinalised permits when ConsolidationGroup not found (deferral).
     *
     * @return void
     */
    public function testRequireAllMembersFinalisedPermitsWhenGroupAbsent(): void
    {
        $objectService = $this->buildObjectServiceStub(findObjectReturn: null, findAllReturn: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireAllMembersFinalised(
                [
                    'consolidationGroupId' => 'cg-001',
                    'fiscalYearId'         => 'fy-2026',
                ]
                );

        self::assertTrue($result, 'Absent ConsolidationGroup must permit by default (T2 deferral)');

    }//end testRequireAllMembersFinalisedPermitsWhenGroupAbsent()

    /**
     * requireAllMembersFinalised permits when all member administrations have a final BalanceSheet.
     *
     * @return void
     */
    public function testRequireAllMembersFinalisedPermitsWhenAllMembersFinal(): void
    {
        $group = [
            'id'                => 'cg-001',
            'administrationIds' => ['adm-1', 'adm-2'],
        ];
        // Both administrations have a final BalanceSheet.
        $balanceSheet  = [['id' => 'bs-001', 'status' => 'final']];
        $objectService = $this->buildObjectServiceStub(findObjectReturn: $group, findAllReturn: $balanceSheet);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireAllMembersFinalised(
                [
                    'consolidationGroupId' => 'cg-001',
                    'fiscalYearId'         => 'fy-2026',
                ]
                );

        self::assertTrue($result, 'All members final must permit consolidated report finalise');

    }//end testRequireAllMembersFinalisedPermitsWhenAllMembersFinal()

    /**
     * requireAllMembersFinalised denies when a member administration lacks a final BalanceSheet.
     *
     * @return void
     */
    public function testRequireAllMembersFinalisedDeniesWhenMemberNotFinal(): void
    {
        $group = [
            'id'                => 'cg-001',
            'administrationIds' => ['adm-1'],
        ];
        // No final BalanceSheet for adm-1.
        $objectService = $this->buildObjectServiceStub(findObjectReturn: $group, findAllReturn: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireAllMembersFinalised(
                [
                    'consolidationGroupId' => 'cg-001',
                    'fiscalYearId'         => 'fy-2026',
                ]
                );

        self::assertFalse($result, 'Member without final BalanceSheet must deny consolidated report finalise');

    }//end testRequireAllMembersFinalisedDeniesWhenMemberNotFinal()

    /**
     * requirePublicationApproval always returns true (role enforcement via RBAC layer).
     *
     * @return void
     */
    public function testRequirePublicationApprovalAlwaysReturnsTrue(): void
    {
        $result = $this->guard->requirePublicationApproval(['id' => 'bs-001', 'status' => 'final']);

        self::assertTrue($result, 'requirePublicationApproval must always permit (role check is in RBAC layer)');

    }//end testRequirePublicationApprovalAlwaysReturnsTrue()

    /**
     * Build an anonymous ObjectService stub implementing the fluent setRegister/setSchema interface.
     *
     * @param mixed        $findObjectReturn Value to return from findObject().
     * @param array<mixed> $findAllReturn    Value to return from findAll().
     *
     * @return object
     */
    private function buildObjectServiceStub(mixed $findObjectReturn, array $findAllReturn): object
    {
        return new class($findObjectReturn, $findAllReturn) {

            private mixed $findObjectReturn;

            private array $findAllReturn;

            public function __construct(mixed $findObjectReturn, array $findAllReturn)
            {
                $this->findObjectReturn = $findObjectReturn;
                $this->findAllReturn    = $findAllReturn;
            }//end __construct()

            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            public function findObject(string $id): mixed
            {
                return $this->findObjectReturn;
            }//end findObject()

            /**
             * @param  array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                return $this->findAllReturn;
            }//end findAll()
        };
    }//end buildObjectServiceStub()
}//end class
