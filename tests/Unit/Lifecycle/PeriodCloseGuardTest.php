<?php

/**
 * Unit tests for PeriodCloseGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PeriodCloseGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PeriodCloseGuard covering REQ-PC-004 / REQ-TB-003.
 */
class PeriodCloseGuardTest extends TestCase
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
     * @var PeriodCloseGuard
     */
    private PeriodCloseGuard $guard;

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

        $this->guard = new PeriodCloseGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * A period whose posted GL lines balance verifies (REQ-TB-003).
     *
     * @return void
     */
    public function testBalancedPeriodVerifies(): void
    {
        $lines = [
            ['periodId' => '2026-01', 'side' => 'debit',  'amount' => 250.00],
            ['periodId' => '2026-01', 'side' => 'credit', 'amount' => 250.00],
        ];

        $this->container->method('get')->willReturn($this->buildObjectServiceStub(lines: $lines));

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->trialBalanceVerifies(periodId: '2026-01'));

    }//end testBalancedPeriodVerifies()

    /**
     * A period whose debits != credits does not verify; close is denied.
     *
     * @return void
     */
    public function testUnbalancedPeriodDenied(): void
    {
        $lines = [
            ['periodId' => '2026-02', 'side' => 'debit',  'amount' => 100.00],
            ['periodId' => '2026-02', 'side' => 'credit', 'amount' => 90.00],
        ];

        $this->container->method('get')->willReturn($this->buildObjectServiceStub(lines: $lines));

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->trialBalanceVerifies(periodId: '2026-02'));

    }//end testUnbalancedPeriodDenied()

    /**
     * Exception causes fail-closed (returns false): a period is never closed over
     * an unverifiable ledger.
     *
     * @return void
     */
    public function testExceptionFailsClosed(): void
    {
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('ObjectService unavailable'));

        $this->logger->expects($this->once())->method('error');

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->trialBalanceVerifies(periodId: '2026-03'));

    }//end testExceptionFailsClosed()

    /**
     * An empty period (no postings) trivially verifies (0 = 0).
     *
     * @return void
     */
    public function testEmptyPeriodVerifies(): void
    {
        $this->container->method('get')->willReturn($this->buildObjectServiceStub(lines: []));

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->trialBalanceVerifies(periodId: '2026-04'));

    }//end testEmptyPeriodVerifies()

    /**
     * Build an anonymous ObjectService stub returning the given lines from findAll().
     *
     * @param array<mixed> $lines GL line records to return.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $lines): object
    {
        return new class($lines) {

            /**
             * GL line records to return.
             *
             * @var array<mixed>
             */
            private array $lines;

            /**
             * Constructor.
             *
             * @param array<mixed> $lines Lines to return.
             */
            public function __construct(array $lines)
            {
                $this->lines = $lines;
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
             * Return all stubbed lines.
             *
             * @param array<string,mixed> $params Query parameters (unused).
             *
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                return $this->lines;
            }//end findAll()
        };
    }//end buildObjectServiceStub()
}//end class
