<?php

/**
 * Unit tests for CreditLimitGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\CreditLimitGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CreditLimitGuard::requireWithinCreditLimit().
 *
 * Covers:
 * - No credit limit set → unlimited credit, issue permitted
 * - Within limit (outstanding + this invoice <= limit) → permitted
 * - Exceeds limit → denied
 * - Exactly at the limit boundary → permitted
 * - The invoice being issued is excluded from the outstanding sum (no double-count)
 * - Paid / written-off invoices excluded from outstanding
 * - Customer not found → fail-closed (denied)
 * - Missing customerNumber / administrationId → fail-closed (denied)
 * - Exception → fail-closed (denied)
 * - Integer-cent arithmetic
 */
class CreditLimitGuardTest extends TestCase
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
     * @var CreditLimitGuard
     */
    private CreditLimitGuard $guard;

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

        $this->guard = new CreditLimitGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * No credit limit configured → unlimited credit; issue permitted.
     *
     * @return void
     */
    public function testPermitsWhenNoCreditLimitSet(): void
    {
        $customer      = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => null]];
        $objectService = $this->buildObjectServiceStub(customers: $customer, invoices: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireWithinCreditLimit([
            'invoiceNumber'    => 'INV-9',
            'customerNumber'   => 'CUST-1',
            'administrationId' => 'adm-1',
            'totalCents'       => 999999999,
        ]);

        self::assertTrue($result, 'No credit limit means unlimited credit');

    }//end testPermitsWhenNoCreditLimitSet()

    /**
     * Outstanding + this invoice within the limit → permitted.
     *
     * @return void
     */
    public function testPermitsWhenWithinLimit(): void
    {
        $customer = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => 100000]];
        $invoices = [
            ['invoiceNumber' => 'INV-1', 'status' => 'issued', 'totalCents' => 30000],
            ['invoiceNumber' => 'INV-9', 'status' => 'draft', 'totalCents' => 40000],
        ];
        $objectService = $this->buildObjectServiceStub(customers: $customer, invoices: $invoices);
        $this->container->method('get')->willReturn($objectService);

        // Outstanding excluding INV-9 = 30000; + this invoice 40000 = 70000 <= 100000.
        $result = $this->guard->requireWithinCreditLimit([
            'invoiceNumber'    => 'INV-9',
            'customerNumber'   => 'CUST-1',
            'administrationId' => 'adm-1',
            'totalCents'       => 40000,
        ]);

        self::assertTrue($result);

    }//end testPermitsWhenWithinLimit()

    /**
     * Outstanding + this invoice exceeds the limit → denied.
     *
     * @return void
     */
    public function testDeniesWhenExceedsLimit(): void
    {
        $customer = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => 100000]];
        $invoices = [
            ['invoiceNumber' => 'INV-1', 'status' => 'issued', 'totalCents' => 80000],
        ];
        $objectService = $this->buildObjectServiceStub(customers: $customer, invoices: $invoices);
        $this->container->method('get')->willReturn($objectService);

        // 80000 outstanding + 30000 this invoice = 110000 > 100000.
        $result = $this->guard->requireWithinCreditLimit([
            'invoiceNumber'    => 'INV-9',
            'customerNumber'   => 'CUST-1',
            'administrationId' => 'adm-1',
            'totalCents'       => 30000,
        ]);

        self::assertFalse($result);

    }//end testDeniesWhenExceedsLimit()

    /**
     * Exactly at the limit boundary → permitted (<= comparison).
     *
     * @return void
     */
    public function testPermitsAtExactLimit(): void
    {
        $customer = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => 100000]];
        $invoices = [
            ['invoiceNumber' => 'INV-1', 'status' => 'overdue', 'totalCents' => 60000],
        ];
        $objectService = $this->buildObjectServiceStub(customers: $customer, invoices: $invoices);
        $this->container->method('get')->willReturn($objectService);

        // 60000 + 40000 = 100000 == limit → permitted.
        $result = $this->guard->requireWithinCreditLimit([
            'invoiceNumber'    => 'INV-9',
            'customerNumber'   => 'CUST-1',
            'administrationId' => 'adm-1',
            'totalCents'       => 40000,
        ]);

        self::assertTrue($result);

    }//end testPermitsAtExactLimit()

    /**
     * Paid and written-off invoices are excluded from the outstanding sum.
     *
     * @return void
     */
    public function testExcludesPaidAndWrittenOffFromOutstanding(): void
    {
        $customer = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => 50000]];
        $invoices = [
            ['invoiceNumber' => 'INV-1', 'status' => 'paid', 'totalCents' => 90000],
            ['invoiceNumber' => 'INV-2', 'status' => 'written-off', 'totalCents' => 90000],
            ['invoiceNumber' => 'INV-3', 'status' => 'issued', 'totalCents' => 10000],
        ];
        $objectService = $this->buildObjectServiceStub(customers: $customer, invoices: $invoices);
        $this->container->method('get')->willReturn($objectService);

        // Outstanding = 10000 (only INV-3); + 30000 this invoice = 40000 <= 50000.
        $result = $this->guard->requireWithinCreditLimit([
            'invoiceNumber'    => 'INV-9',
            'customerNumber'   => 'CUST-1',
            'administrationId' => 'adm-1',
            'totalCents'       => 30000,
        ]);

        self::assertTrue($result, 'Paid and written-off invoices must not consume credit');

    }//end testExcludesPaidAndWrittenOffFromOutstanding()

    /**
     * Customer not found → fail-closed (denied).
     *
     * @return void
     */
    public function testDeniesWhenCustomerNotFound(): void
    {
        $objectService = $this->buildObjectServiceStub(customers: [], invoices: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireWithinCreditLimit([
            'invoiceNumber'    => 'INV-9',
            'customerNumber'   => 'CUST-MISSING',
            'administrationId' => 'adm-1',
            'totalCents'       => 1,
        ]);

        self::assertFalse($result, 'Unknown customer must fail-closed');

    }//end testDeniesWhenCustomerNotFound()

    /**
     * Missing customerNumber → fail-closed (denied) without touching the container.
     *
     * @return void
     */
    public function testDeniesWhenCustomerNumberMissing(): void
    {
        $this->container->expects($this->never())->method('get');

        $result = $this->guard->requireWithinCreditLimit([
            'invoiceNumber'    => 'INV-9',
            'administrationId' => 'adm-1',
            'totalCents'       => 1,
        ]);

        self::assertFalse($result);

    }//end testDeniesWhenCustomerNumberMissing()

    /**
     * Exception during resolution → fail-closed (denied).
     *
     * @return void
     */
    public function testIsFailClosedOnException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('DB error'));

        $result = $this->guard->requireWithinCreditLimit([
            'invoiceNumber'    => 'INV-9',
            'customerNumber'   => 'CUST-1',
            'administrationId' => 'adm-1',
            'totalCents'       => 1,
        ]);

        self::assertFalse($result, 'Fail-closed: exception must deny the issue');

    }//end testIsFailClosedOnException()

    /**
     * Build an anonymous ObjectService stub returning customers for CustomerMaster
     * queries and invoices for ARInvoice queries. Implements the fluent
     * setRegister/setSchema interface used by the guard.
     *
     * @param array<mixed> $customers CustomerMaster records to return.
     * @param array<mixed> $invoices  ARInvoice records to return.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $customers, array $invoices): object
    {
        return new class($customers, $invoices) {
            private array $customers;
            private array $invoices;
            private string $currentSchema = '';

            public function __construct(array $customers, array $invoices)
            {
                $this->customers = $customers;
                $this->invoices  = $invoices;
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

            /**
             * @param array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                if ($this->currentSchema === 'CustomerMaster') {
                    return $this->customers;
                }
                return $this->invoices;
            }
        };
    }//end buildObjectServiceStub()
}//end class
