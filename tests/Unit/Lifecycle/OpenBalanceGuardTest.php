<?php

/**
 * Unit tests for OpenBalanceGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\OpenBalanceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for OpenBalanceGuard credit-limit and not-blocked preconditions
 * (REQ-AR-006, REQ-AP-008).
 */
class OpenBalanceGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The guard under test.
	 *
	 * @var OpenBalanceGuard
	 */
	private OpenBalanceGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->guard = new OpenBalanceGuard(
			container: $this->container,
			appConfig: $appConfig,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * Credit-limit check is skipped (returns true) when the customer has no limit.
	 *
	 * @return void
	 */
	public function testWithinCreditLimitSkippedWhenNoLimit(): void {
		$objectService = $this->buildObjectServiceStub(
			master: ['customerId' => 'DEB-1', 'creditLimit' => 0, 'lifecycleState' => 'active'],
			invoices: []
		);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->withinCreditLimit(
			['customerId' => 'DEB-1', 'administrationId' => 'adm-1', 'grossAmount' => 9999.00]
		);

		self::assertTrue(condition: $result, message: 'No credit limit configured → check skipped');

	}//end testWithinCreditLimitSkippedWhenNoLimit()

	/**
	 * Issuance is blocked when outstanding + candidate exceeds the credit limit.
	 *
	 * @return void
	 */
	public function testWithinCreditLimitBlocksWhenExceeded(): void {
		// Limit 5000; outstanding 4800; new invoice 500 → 5300 > 5000 → blocked.
		$objectService = $this->buildObjectServiceStub(
			master: ['customerId' => 'DEB-1', 'creditLimit' => 5000, 'lifecycleState' => 'active'],
			invoices: [
				['grossAmount' => 4800.00, 'lifecycleState' => 'issued'],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->withinCreditLimit(
			['customerId' => 'DEB-1', 'administrationId' => 'adm-1', 'grossAmount' => 500.00]
		);

		self::assertFalse(condition: $result);

	}//end testWithinCreditLimitBlocksWhenExceeded()

	/**
	 * Issuance is permitted when outstanding + candidate stays within the limit.
	 *
	 * @return void
	 */
	public function testWithinCreditLimitPermitsWhenUnderLimit(): void {
		$objectService = $this->buildObjectServiceStub(
			master: ['customerId' => 'DEB-1', 'creditLimit' => 5000, 'lifecycleState' => 'active'],
			invoices: [
				['grossAmount' => 1000.00, 'lifecycleState' => 'issued'],
				// Paid invoice must be excluded from the outstanding sum.
				['grossAmount' => 9000.00, 'lifecycleState' => 'paid'],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->withinCreditLimit(
			['customerId' => 'DEB-1', 'administrationId' => 'adm-1', 'grossAmount' => 500.00]
		);

		self::assertTrue(condition: $result, message: 'Paid invoices excluded; 1000 + 500 <= 5000');

	}//end testWithinCreditLimitPermitsWhenUnderLimit()

	/**
	 * A blocked customer is rejected at AR invoice creation.
	 *
	 * @return void
	 */
	public function testCustomerNotBlockedRejectsBlocked(): void {
		$objectService = $this->buildObjectServiceStub(
			master: ['customerId' => 'DEB-1', 'lifecycleState' => 'blocked'],
			invoices: []
		);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->customerNotBlocked(
			['customerId' => 'DEB-1', 'administrationId' => 'adm-1']
		);

		self::assertFalse(condition: $result);

	}//end testCustomerNotBlockedRejectsBlocked()

	/**
	 * An active vendor passes the not-blocked precondition.
	 *
	 * @return void
	 */
	public function testVendorNotBlockedPassesActive(): void {
		$objectService = $this->buildObjectServiceStub(
			master: ['vendorId' => 'CRD-1', 'lifecycleState' => 'active'],
			invoices: []
		);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->vendorNotBlocked(
			['vendorId' => 'CRD-1', 'administrationId' => 'adm-1']
		);

		self::assertTrue(condition: $result);

	}//end testVendorNotBlockedPassesActive()

	/**
	 * Build a fluent ObjectService stub returning a master record for master
	 * schemas and the invoice list for invoice schemas.
	 *
	 * @param array<string,mixed> $master Vendor/Customer master record.
	 * @param array<mixed> $invoices Invoice records for balance queries.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $master, array $invoices): object {
		return new class($master, $invoices) {
			/**
			 * Current schema context for routing findAll results.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor for the anonymous ObjectService stub.
			 *
			 * @param array<string,mixed> $master Vendor/Customer master record.
			 * @param array<mixed> $invoices Invoice records for balance queries.
			 *
			 * @return void
			 */
			public function __construct(
				private array $master,
				private array $invoices,
			) {
			}//end __construct()

			/**
			 * Set the register (no-op stub).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Set the schema context.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Find all records for the current schema.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				if (in_array($this->schema, ['VendorMaster', 'CustomerMaster'], true) === true) {
					return [$this->master];
				}

				return $this->invoices;
			}//end findAll()
		};

	}//end buildObjectServiceStub()
}//end class
