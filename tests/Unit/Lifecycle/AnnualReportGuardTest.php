<?php

/**
 * Unit tests for AnnualReportGuard.
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
 * @spec openspec/changes/bookkeeping-titel-9-jaarrekening/specs/bookkeeping-titel-9-jaarrekening/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\AnnualReportGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AnnualReportGuard.
 *
 * Covers REQ-T9-002/REQ-T9-010 (opmaak requires a balancing balans) and
 * REQ-T9-007/REQ-T9-009 (vaststellen requires an accountantsverklaring when
 * it is wettelijk verplicht).
 */
class AnnualReportGuardTest extends TestCase
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
     * @var AnnualReportGuard
     */
    private AnnualReportGuard $guard;

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

        $this->guard = new AnnualReportGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * A jaarrekening whose linked balans balances (activa = passiva) may opmaken (REQ-T9-002).
     *
     * @return void
     */
    public function testCanOpmakenWhenBalansBalances(): void
    {
        $this->container->method('get')->willReturn(
            $this->buildObjectServiceStub(records: [['reportId' => 'r-1', 'totalActiva' => 845000, 'totalPassiva' => 845000]])
        );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->canOpmaken(annualReportId: 'r-1', object: ['id' => 'r-1']));

    }//end testCanOpmakenWhenBalansBalances()

    /**
     * A jaarrekening whose balans does not balance cannot opmaken (REQ-T9-002).
     *
     * @return void
     */
    public function testCannotOpmakenWhenBalansUnbalanced(): void
    {
        $this->container->method('get')->willReturn(
            $this->buildObjectServiceStub(records: [['reportId' => 'r-2', 'totalActiva' => 845000, 'totalPassiva' => 800000]])
        );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canOpmaken(annualReportId: 'r-2', object: ['id' => 'r-2']));

    }//end testCannotOpmakenWhenBalansUnbalanced()

    /**
     * Balans balance can be derived from rubriek sums when totals are absent (REQ-T9-002).
     *
     * @return void
     */
    public function testCanOpmakenFromRubriekSums(): void
    {
        $balanceSheet = [
            'reportId'  => 'r-3',
            'rubrieken' => [
                ['rubrieckCode' => 'B.II', 'zijde' => 'activa', 'huidigJaar' => 450000],
                ['rubrieckCode' => 'C.IV', 'zijde' => 'activa', 'huidigJaar' => 95000],
                ['rubrieckCode' => 'A', 'zijde' => 'passiva', 'huidigJaar' => 400000],
                ['rubrieckCode' => 'D', 'zijde' => 'passiva', 'huidigJaar' => 145000],
            ],
        ];

        $this->container->method('get')->willReturn($this->buildObjectServiceStub(records: [$balanceSheet]));

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->canOpmaken(annualReportId: 'r-3', object: ['id' => 'r-3']));

    }//end testCanOpmakenFromRubriekSums()

    /**
     * Opmaak is denied when no linked BalanceSheet exists (fail-closed, REQ-T9-002).
     *
     * @return void
     */
    public function testCannotOpmakenWithoutBalanceSheet(): void
    {
        $this->container->method('get')->willReturn($this->buildObjectServiceStub(records: []));

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canOpmaken(annualReportId: 'r-4', object: ['id' => 'r-4']));

    }//end testCannotOpmakenWithoutBalanceSheet()

    /**
     * A zero-total balans cannot opmaken (REQ-T9-002 — a real balans has value).
     *
     * @return void
     */
    public function testZeroTotalBalansCannotOpmaken(): void
    {
        $this->container->method('get')->willReturn(
            $this->buildObjectServiceStub(records: [['reportId' => 'r-5', 'totalActiva' => 0, 'totalPassiva' => 0]])
        );

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canOpmaken(annualReportId: 'r-5', object: ['id' => 'r-5']));

    }//end testZeroTotalBalansCannotOpmaken()

    /**
     * An exception in the opmaak path fails closed (returns false, logs error).
     *
     * @return void
     */
    public function testOpmaakExceptionFailsClosed(): void
    {
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('ObjectService unavailable'));

        $this->logger->expects($this->once())->method('error');

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canOpmaken(annualReportId: 'r-6', object: ['id' => 'r-6']));

    }//end testOpmaakExceptionFailsClosed()

    /**
     * When no accountantsverklaring is verplicht, vaststellen is allowed (REQ-T9-009).
     *
     * @return void
     */
    public function testCanVaststellenWhenVerklaringNotRequired(): void
    {
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue(
            $this->guard->canVaststellen(
                annualReportId: 'r-7',
                object: ['accountantsverklaringVereist' => false]
            )
        );

    }//end testCanVaststellenWhenVerklaringNotRequired()

    /**
     * When a verklaring is verplicht and goedkeurend is attached, vaststellen is allowed (REQ-T9-007).
     *
     * @return void
     */
    public function testCanVaststellenWhenVerklaringAttached(): void
    {
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue(
            $this->guard->canVaststellen(
                annualReportId: 'r-8',
                object: [
                    'accountantsverklaringVereist' => true,
                    'accountantsverklaringStatus'  => 'goedkeurend',
                ]
            )
        );

    }//end testCanVaststellenWhenVerklaringAttached()

    /**
     * When a verklaring is verplicht but none is attached, vaststellen is denied (REQ-T9-007).
     *
     * @return void
     */
    public function testCannotVaststellenWhenVerklaringMissing(): void
    {
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse(
            $this->guard->canVaststellen(
                annualReportId: 'r-9',
                object: [
                    'accountantsverklaringVereist' => true,
                    'accountantsverklaringStatus'  => 'in-afwachting',
                ]
            )
        );

    }//end testCannotVaststellenWhenVerklaringMissing()

    /**
     * A samenstellingsverklaring satisfies a kleine-BV vaststelling (REQ-T9-007).
     *
     * @return void
     */
    public function testSamenstellingVerklaringSatisfiesVaststelling(): void
    {
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue(
            $this->guard->canVaststellen(
                annualReportId: 'r-10',
                object: [
                    'accountantsverklaringVereist' => true,
                    'accountantsverklaringStatus'  => 'samenstelling',
                ]
            )
        );

    }//end testSamenstellingVerklaringSatisfiesVaststelling()

    /**
     * Build an anonymous ObjectService stub returning the given records from findAll().
     *
     * @param array<mixed> $records Records to return.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $records): object
    {
        return new class($records) {

            /**
             * Records to return from findAll().
             *
             * @var array<mixed>
             */
            private array $records;

            /**
             * Constructor.
             *
             * @param array<mixed> $records Records to return.
             */
            public function __construct(array $records)
            {
                $this->records = $records;
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
             * Return all stubbed records.
             *
             * @param array<string,mixed> $params Query parameters (unused in stub).
             *
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                return $this->records;
            }//end findAll()
        };
    }//end buildObjectServiceStub()
}//end class
