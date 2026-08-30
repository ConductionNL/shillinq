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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\CreditLimitGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
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
class CreditLimitGuardTest extends TestCase {

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
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(customers: [], invoices: [])
		);

	}//end setUp()

	/**
	 * Build the guard over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the guard is built — parking it on the
	 * container after the fact leaves the guard reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return CreditLimitGuard
	 */
	private function buildGuard(object $store): CreditLimitGuard {
		return new CreditLimitGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildGuard()

	/**
	 * No credit limit configured → unlimited credit; issue permitted.
	 *
	 * @return void
	 */
	public function testPermitsWhenNoCreditLimitSet(): void {
		$customer = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => null]];
		$objectService = $this->buildObjectServiceStub(customers: $customer, invoices: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireWithinCreditLimit(
			[
				'invoiceNumber' => 'INV-9',
				'customerNumber' => 'CUST-1',
				'administrationId' => 'adm-1',
				'totalCents' => 999999999,
			]
		);

		self::assertTrue(condition: $result, message: 'No credit limit means unlimited credit');

	}//end testPermitsWhenNoCreditLimitSet()

	/**
	 * Outstanding + this invoice within the limit → permitted.
	 *
	 * @return void
	 */
	public function testPermitsWhenWithinLimit(): void {
		$customer = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => 100000]];
		$invoices = [
			['invoiceNumber' => 'INV-1', 'status' => 'issued', 'totalCents' => 30000],
			['invoiceNumber' => 'INV-9', 'status' => 'draft', 'totalCents' => 40000],
		];
		$objectService = $this->buildObjectServiceStub(customers: $customer, invoices: $invoices);
		$this->guard = $this->buildGuard(store: $objectService);

		// Outstanding excluding INV-9 = 30000; + this invoice 40000 = 70000 <= 100000.
		$result = $this->guard->requireWithinCreditLimit(
			[
				'invoiceNumber' => 'INV-9',
				'customerNumber' => 'CUST-1',
				'administrationId' => 'adm-1',
				'totalCents' => 40000,
			]
		);

		self::assertTrue(condition: $result);

	}//end testPermitsWhenWithinLimit()

	/**
	 * Outstanding + this invoice exceeds the limit → denied.
	 *
	 * @return void
	 */
	public function testDeniesWhenExceedsLimit(): void {
		$customer = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => 100000]];
		$invoices = [
			['invoiceNumber' => 'INV-1', 'status' => 'issued', 'totalCents' => 80000],
		];
		$objectService = $this->buildObjectServiceStub(customers: $customer, invoices: $invoices);
		$this->guard = $this->buildGuard(store: $objectService);

		// 80000 outstanding + 30000 this invoice = 110000 > 100000.
		$result = $this->guard->requireWithinCreditLimit(
			[
				'invoiceNumber' => 'INV-9',
				'customerNumber' => 'CUST-1',
				'administrationId' => 'adm-1',
				'totalCents' => 30000,
			]
		);

		self::assertFalse(condition: $result);

	}//end testDeniesWhenExceedsLimit()

	/**
	 * Exactly at the limit boundary → permitted (<= comparison).
	 *
	 * @return void
	 */
	public function testPermitsAtExactLimit(): void {
		$customer = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => 100000]];
		$invoices = [
			['invoiceNumber' => 'INV-1', 'status' => 'overdue', 'totalCents' => 60000],
		];
		$objectService = $this->buildObjectServiceStub(customers: $customer, invoices: $invoices);
		$this->guard = $this->buildGuard(store: $objectService);

		// 60000 + 40000 = 100000 == limit → permitted.
		$result = $this->guard->requireWithinCreditLimit(
			[
				'invoiceNumber' => 'INV-9',
				'customerNumber' => 'CUST-1',
				'administrationId' => 'adm-1',
				'totalCents' => 40000,
			]
		);

		self::assertTrue(condition: $result);

	}//end testPermitsAtExactLimit()

	/**
	 * Paid and written-off invoices are excluded from the outstanding sum.
	 *
	 * @return void
	 */
	public function testExcludesPaidAndWrittenOffFromOutstanding(): void {
		$customer = [['customerNumber' => 'CUST-1', 'administrationId' => 'adm-1', 'creditLimitCents' => 50000]];
		$invoices = [
			['invoiceNumber' => 'INV-1', 'status' => 'paid', 'totalCents' => 90000],
			['invoiceNumber' => 'INV-2', 'status' => 'written-off', 'totalCents' => 90000],
			['invoiceNumber' => 'INV-3', 'status' => 'issued', 'totalCents' => 10000],
		];
		$objectService = $this->buildObjectServiceStub(customers: $customer, invoices: $invoices);
		$this->guard = $this->buildGuard(store: $objectService);

		// Outstanding = 10000 (only INV-3); + 30000 this invoice = 40000 <= 50000.
		$result = $this->guard->requireWithinCreditLimit(
			[
				'invoiceNumber' => 'INV-9',
				'customerNumber' => 'CUST-1',
				'administrationId' => 'adm-1',
				'totalCents' => 30000,
			]
		);

		self::assertTrue(condition: $result, message: 'Paid and written-off invoices must not consume credit');

	}//end testExcludesPaidAndWrittenOffFromOutstanding()

	/**
	 * Customer not found → fail-closed (denied).
	 *
	 * @return void
	 */
	public function testDeniesWhenCustomerNotFound(): void {
		$objectService = $this->buildObjectServiceStub(customers: [], invoices: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireWithinCreditLimit(
			[
				'invoiceNumber' => 'INV-9',
				'customerNumber' => 'CUST-MISSING',
				'administrationId' => 'adm-1',
				'totalCents' => 1,
			]
		);

		self::assertFalse(condition: $result, message: 'Unknown customer must fail-closed');

	}//end testDeniesWhenCustomerNotFound()

	/**
	 * Missing customerNumber → fail-closed (denied) without touching the container.
	 *
	 * @return void
	 */
	public function testDeniesWhenCustomerNumberMissing(): void {
		$this->container->expects($this->never())->method('get');

		$result = $this->guard->requireWithinCreditLimit(
			[
				'invoiceNumber' => 'INV-9',
				'administrationId' => 'adm-1',
				'totalCents' => 1,
			]
		);

		self::assertFalse(condition: $result);

	}//end testDeniesWhenCustomerNumberMissing()

	/**
	 * Exception during resolution → fail-closed (denied).
	 *
	 * @return void
	 */
	public function testIsFailClosedOnException(): void {
		$this->guard = $this->buildGuard(store: $this->buildObjectServiceStubThatThrows());

		$result = $this->guard->requireWithinCreditLimit(
			[
				'invoiceNumber' => 'INV-9',
				'customerNumber' => 'CUST-1',
				'administrationId' => 'adm-1',
				'totalCents' => 1,
			]
		);

		self::assertFalse(condition: $result, message: 'Fail-closed: exception must deny the issue');

	}//end testIsFailClosedOnException()

	/**
	 * Build an anonymous ObjectService stub returning customers for CustomerMaster
	 * queries and invoices for ARInvoice queries. Implements the fluent
	 * setRegister/setSchema interface used by the guard.
	 *
	 * @param array<mixed> $customers CustomerMaster records to return.
	 * @param array<mixed> $invoices ARInvoice records to return.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $customers, array $invoices): object {
		return new class($customers, $invoices) {
			/**
			 * CustomerMaster records to return.
			 *
			 * @var array<mixed>
			 */
			private array $customers;

			/**
			 * ARInvoice records to return.
			 *
			 * @var array<mixed>
			 */
			private array $invoices;

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Construct stub with fixed return values.
			 *
			 * @param array<mixed> $customers CustomerMaster records to return.
			 * @param array<mixed> $invoices ARInvoice records to return.
			 */
			public function __construct(array $customers, array $invoices) {
				$this->customers = $customers;
				$this->invoices = $invoices;
			}//end __construct()

			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — records the active schema name.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return records for the currently active schema.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				if ($this->currentSchema === 'CustomerMaster') {
					return $this->customers;
				}

				return $this->invoices;
			}//end findAll()
		};
	}//end buildObjectServiceStub()

	/**
	 * Build a store whose every read throws.
	 *
	 * Before ADR-084 this scenario was expressed as
	 * `$container->method('get')->willThrowException(...)`. The container is no
	 * longer consulted, so the refusal has to come from the store itself, which
	 * is what the guard's fail-closed arm is there to catch.
	 *
	 * @return object
	 */
	private function buildObjectServiceStubThatThrows(): object {
		return new class {
			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse every list query.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('DB error');
			}//end findAll()

			/**
			 * Refuse every single-object lookup.
			 *
			 * @param string|int $id Object ID.
			 *
			 * @return object|null
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string|int $id): ?object {
				throw new \RuntimeException('DB error');
			}//end find()
		};
	}//end buildObjectServiceStubThatThrows()
}//end class
