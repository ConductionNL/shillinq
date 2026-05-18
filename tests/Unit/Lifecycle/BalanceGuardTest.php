<?php

/**
 * Unit tests for BalanceGuard.
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
 * @spec openspec/changes/spec/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BalanceGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BalanceGuard::isBalanced() — the GLTransaction post-transition precondition.
 */
class BalanceGuardTest extends TestCase
{

    /**
     * DI container mock.
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
     * The guard under test.
     *
     * @var BalanceGuard
     */
    private BalanceGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:disable CustomSn.Functions.NamedParameters
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $this->guard = new BalanceGuard(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * A transaction with no id is denied (fail-closed).
     *
     * @return void
     */
    public function testIsBalancedDeniesTransactionWithNoId(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $result = $this->guard->isBalanced(transaction: []);

        // phpcs:ignore CustomSn.Functions.NamedParameters
        self::assertFalse($result);

    }//end testIsBalancedDeniesTransactionWithNoId()

    /**
     * A transaction with fewer than 2 lines cannot be balanced.
     *
     * @return void
     */
    public function testIsBalancedDeniesTransactionWithFewerThanTwoLines(): void
    {
        $mockObjectService = $this->createMockObjectService(
            lines: [
                ['side' => 'debit', 'amount' => 100.00],
            ]
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $this->logger->expects($this->once())->method('info');

        $result = $this->guard->isBalanced(transaction: ['id' => 'tx-001']);

        // phpcs:ignore CustomSn.Functions.NamedParameters
        self::assertFalse($result);

    }//end testIsBalancedDeniesTransactionWithFewerThanTwoLines()

    /**
     * A balanced 2-line transaction (debit 100 = credit 100) is allowed.
     *
     * @return void
     */
    public function testIsBalancedPermitsBalancedTwoLineTransaction(): void
    {
        $mockObjectService = $this->createMockObjectService(
            lines: [
                ['side' => 'debit',  'amount' => 100.00],
                ['side' => 'credit', 'amount' => 100.00],
            ]
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->guard->isBalanced(transaction: ['id' => 'tx-001']);

        // phpcs:ignore CustomSn.Functions.NamedParameters
        self::assertTrue($result);

    }//end testIsBalancedPermitsBalancedTwoLineTransaction()

    /**
     * An unbalanced transaction (debit 100, credit 99.99) is denied.
     *
     * @return void
     */
    public function testIsBalancedDeniesUnbalancedTransaction(): void
    {
        $mockObjectService = $this->createMockObjectService(
            lines: [
                ['side' => 'debit',  'amount' => 100.00],
                ['side' => 'credit', 'amount' => 99.99],
            ]
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $this->logger->expects($this->once())->method('info');

        $result = $this->guard->isBalanced(transaction: ['id' => 'tx-001']);

        // phpcs:ignore CustomSn.Functions.NamedParameters
        self::assertFalse($result);

    }//end testIsBalancedDeniesUnbalancedTransaction()

    /**
     * A balanced N-line transaction (multiple debit + credit lines summing equal) is allowed.
     *
     * @return void
     */
    public function testIsBalancedPermitsBalancedNLineTransaction(): void
    {
        $mockObjectService = $this->createMockObjectService(
            lines: [
                ['side' => 'debit',  'amount' => 200.00],
                ['side' => 'credit', 'amount' => 150.00],
                ['side' => 'credit', 'amount' =>  50.00],
            ]
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->guard->isBalanced(transaction: ['id' => 'tx-002']);

        // phpcs:ignore CustomSn.Functions.NamedParameters
        self::assertTrue($result);

    }//end testIsBalancedPermitsBalancedNLineTransaction()

    /**
     * An ObjectService exception results in false (fail-closed).
     *
     * @return void
     */
    public function testIsBalancedFailsClosedWhenObjectServiceThrows(): void
    {
        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willThrowException(new \RuntimeException('service unavailable'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->guard->isBalanced(transaction: ['id' => 'tx-003']);

        // phpcs:ignore CustomSn.Functions.NamedParameters
        self::assertFalse($result);

    }//end testIsBalancedFailsClosedWhenObjectServiceThrows()

    /**
     * Build a stub ObjectService returning the given lines for any findObjects call.
     *
     * @param array<int, array<string, mixed>> $lines Line rows to return.
     *
     * @return object Stub ObjectService.
     */
    private function createMockObjectService(array $lines): object
    {
        return new class($lines) {
            /**
             * Stub constructor storing line fixtures.
             *
             * @param array<int, array<string, mixed>> $lines Line rows to return.
             */
            public function __construct(private readonly array $lines)
            {
            }//end __construct()

            /**
             * Return the fixture lines regardless of arguments.
             *
             * @param string               $register Register name (ignored).
             * @param string               $schema   Schema name (ignored).
             * @param array<string, mixed> $params   Filter params (ignored).
             *
             * @return array<int, array<string, mixed>> The fixture lines.
             */
            public function findObjects(string $register, string $schema, array $params): array
            {
                return $this->lines;
            }//end findObjects()
        };

    }//end createMockObjectService()

}//end class
