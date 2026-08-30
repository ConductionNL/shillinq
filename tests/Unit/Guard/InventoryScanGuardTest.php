<?php

/**
 * Unit tests for InventoryScanGuard.
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
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T6.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\InventoryScanGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for InventoryScanGuard::validateOperation per REQ-SYNC-001/002 and
 * REQ-INVENTORY-001..004.
 *
 * Covers:
 * - Happy path: a well-formed receive is permitted.
 * - Missing transactionId is denied (REQ-SYNC-001).
 * - Unknown operation type is denied.
 * - Missing sku/location is denied.
 * - Transfer without a distinct toLocation is denied.
 * - Negative quantity is denied (non-negative invariant).
 * - Transfer exceeding source stock is denied (no negative on-hand).
 * - Transfer within source stock is permitted.
 * - Outbound op with no stock record skips the availability check (T1 state).
 * - Rejected/duplicate records persist (audit) when they carry a transactionId.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class InventoryScanGuardTest extends TestCase {

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
	 * @var InventoryScanGuard
	 */
	private InventoryScanGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new InventoryScanGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a fluent ObjectService stub returning records by schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Map of schema → records.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Map of schema name → record arrays.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			private array $recordsBySchema;

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;

			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
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
			 * Return stubbed records for the current schema.
			 *
			 * @param array<string, mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return ($this->recordsBySchema[$this->currentSchema] ?? []);
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * Stub the container to return the given ObjectService stub.
	 *
	 * @param object $objectService The ObjectService stub.
	 *
	 * @return void
	 */
	private function withObjectService(object $objectService): void {
		$this->container->method('get')->willReturn($objectService);

	}//end withObjectService()

	/**
	 * Build a base valid receive operation.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function operation(array $overrides = []): array {
		return array_merge(
			[
				'transactionId' => 'tx-001',
				'type' => 'receive',
				'administrationId' => 'adm-1',
				'sku' => 'WIDGET-001',
				'location' => 'WH-A1',
				'quantity' => 10,
				'state' => 'pending',
			],
			$overrides
		);

	}//end operation()

	/**
	 * Happy path: a well-formed receive is permitted.
	 *
	 * @return void
	 */
	public function testValidateOperationPermitsValidReceive(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));
		$this->assertTrue($this->guard->validateOperation($this->operation()));

	}//end testValidateOperationPermitsValidReceive()

	/**
	 * Missing transactionId is denied (REQ-SYNC-001).
	 *
	 * @return void
	 */
	public function testValidateOperationDeniesMissingTransactionId(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));
		$this->assertFalse($this->guard->validateOperation($this->operation(['transactionId' => ''])));

	}//end testValidateOperationDeniesMissingTransactionId()

	/**
	 * Unknown operation type is denied.
	 *
	 * @return void
	 */
	public function testValidateOperationDeniesUnknownType(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));
		$this->assertFalse($this->guard->validateOperation($this->operation(['type' => 'teleport'])));

	}//end testValidateOperationDeniesUnknownType()

	/**
	 * Missing sku/location is denied.
	 *
	 * @return void
	 */
	public function testValidateOperationDeniesMissingSkuOrLocation(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));
		$this->assertFalse($this->guard->validateOperation($this->operation(['sku' => ''])));
		$this->assertFalse($this->guard->validateOperation($this->operation(['location' => ''])));

	}//end testValidateOperationDeniesMissingSkuOrLocation()

	/**
	 * Transfer without a distinct toLocation is denied.
	 *
	 * @return void
	 */
	public function testValidateOperationDeniesTransferWithoutDistinctDestination(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));
		$this->assertFalse($this->guard->validateOperation($this->operation(['type' => 'transfer'])));
		$this->assertFalse(
			$this->guard->validateOperation($this->operation(['type' => 'transfer', 'toLocation' => 'WH-A1']))
		);

	}//end testValidateOperationDeniesTransferWithoutDistinctDestination()

	/**
	 * Negative quantity is denied.
	 *
	 * @return void
	 */
	public function testValidateOperationDeniesNegativeQuantity(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));
		$this->assertFalse($this->guard->validateOperation($this->operation(['quantity' => -5])));

	}//end testValidateOperationDeniesNegativeQuantity()

	/**
	 * Transfer exceeding source stock is denied (no negative on-hand).
	 *
	 * @return void
	 */
	public function testValidateOperationDeniesTransferExceedingStock(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				['InventoryStock' => [['sku' => 'WIDGET-001', 'location' => 'WH-A1', 'quantity' => 3]]]
			)
		);

		$op = $this->operation(
			['type' => 'transfer', 'toLocation' => 'WH-A2', 'quantity' => 10]
		);
		$this->assertFalse($this->guard->validateOperation($op));

	}//end testValidateOperationDeniesTransferExceedingStock()

	/**
	 * Transfer within source stock is permitted.
	 *
	 * @return void
	 */
	public function testValidateOperationPermitsTransferWithinStock(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				['InventoryStock' => [['sku' => 'WIDGET-001', 'location' => 'WH-A1', 'quantity' => 45]]]
			)
		);

		$op = $this->operation(
			['type' => 'transfer', 'toLocation' => 'WH-A2', 'quantity' => 20]
		);
		$this->assertTrue($this->guard->validateOperation($op));

	}//end testValidateOperationPermitsTransferWithinStock()

	/**
	 * An outbound op with no stock record skips the availability check (T1 state).
	 *
	 * @return void
	 */
	public function testValidateOperationPermitsOutboundWhenStockUnavailable(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));
		$op = $this->operation(['type' => 'pick', 'quantity' => 99]);
		$this->assertTrue($this->guard->validateOperation($op));

	}//end testValidateOperationPermitsOutboundWhenStockUnavailable()

	/**
	 * A rejected record still persists for audit when it carries a transactionId.
	 *
	 * @return void
	 */
	public function testValidateOperationPermitsRejectedAuditRecord(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));
		$op = $this->operation(['state' => 'rejected', 'sku' => '', 'location' => '']);
		$this->assertTrue($this->guard->validateOperation($op));

	}//end testValidateOperationPermitsRejectedAuditRecord()

	/**
	 * A duplicate record without a transactionId is denied.
	 *
	 * @return void
	 */
	public function testValidateOperationDeniesDuplicateWithoutIdentity(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));
		$op = $this->operation(['state' => 'duplicate', 'transactionId' => '']);
		$this->assertFalse($this->guard->validateOperation($op));

	}//end testValidateOperationDeniesDuplicateWithoutIdentity()
}//end class
