<?php

/**
 * Unit tests for QuoteOrderInvoiceGuard's inventory-sales-issue-cogs-trigger
 * additions: the Delivery-confirm stock-availability check and the new
 * Delivery-cancel guard.
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
 * @spec openspec/changes/inventory-sales-issue-cogs-trigger/specs/inventory-sales-issue-cogs-trigger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the REQ-005 stock-availability check on canConfirmDelivery and
 * the new REQ-006 canCancelDelivery guard.
 */
class QuoteOrderInvoiceGuardStockTest extends TestCase {
	// phpcs:disable CustomSniffs.Functions.NamedParameters

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
	 * @var QuoteOrderInvoiceGuard
	 */
	private QuoteOrderInvoiceGuard $guard;

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

		$this->guard = new QuoteOrderInvoiceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->buildObjectServiceStub([])),
		);

	}//end setUp()

	/**
	 * Rebuild the guard on a fluent ObjectService stub serving the given records
	 * by schema (matching the ADR-022 setRegister/setSchema/findAll API).
	 *
	 * The store is a constructor dependency since ADR-084, so the guard has to
	 * be rebuilt whenever a test seeds different records.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $itemsBySchema Map schema -> records.
	 *
	 * @return void
	 */
	private function stubObjectService(array $itemsBySchema): void {
		$service = $this->buildObjectServiceStub($itemsBySchema);

		$this->container->method('get')->willReturn($service);

		$this->guard = new QuoteOrderInvoiceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($service),
		);

	}//end stubObjectService()

	/**
	 * Build a duck-typed ObjectService store over the given records.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $itemsBySchema Map schema -> records.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $itemsBySchema): object {
		return new class($itemsBySchema) {

			/**
			 * Records keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $itemsBySchema;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			public string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $itemsBySchema Records by schema.
			 */
			public function __construct(array $itemsBySchema) {
				$this->itemsBySchema = $itemsBySchema;

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
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return stubbed records for the active schema, applying a simple
			 * equality filter when present (mirrors OR's filters param).
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$items = ($this->itemsBySchema[$this->currentSchema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $items;
				}

				return array_values(
					array_filter(
						$items,
						static function (array $item) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($item[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * A complete delivery line referencing an order line with a positive quantity.
	 *
	 * @param string $sku Product SKU (productReference).
	 * @param float $quantity Quantity shipped.
	 *
	 * @return array<string,mixed>
	 */
	private function completeDelivery(string $sku, float $quantity): array {
		return [
			'administrationId' => 'adm-1',
			'sourceOrderReference' => 'so-1',
			'lines' => [
				[
					'orderLineReference' => 'sol-1',
					'productReference' => $sku,
					'quantityShipped' => $quantity,
				],
			],
		];

	}//end completeDelivery()

	/**
	 * Confirm is denied by default when a stock-tracked line's shipped
	 * quantity exceeds available InventoryStock (REQ-005, block policy).
	 *
	 * @return void
	 */
	public function testConfirmDeniedWhenStockInsufficientAndNegativeNotAllowed(): void {
		$this->stubObjectService(
			[
				'SalesOrder' => [['id' => 'so-1', 'customerReference' => 'cust-1']],
				'InventoryStock' => [
					['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'quantity' => 4, 'reservedQuantity' => 0],
				],
				'InventoryGLConfig' => [
					['administrationId' => 'adm-1', 'allowNegativeStockOnDispatch' => false],
				],
			]
		);

		self::assertFalse(
			$this->guard->canConfirmDelivery(
				deliveryId: 'dn-1',
				object: $this->completeDelivery(sku: 'sku-a', quantity: 10)
			)
		);

	}//end testConfirmDeniedWhenStockInsufficientAndNegativeNotAllowed()

	/**
	 * Confirm proceeds when allowNegativeStockOnDispatch is true, even with
	 * insufficient stock (REQ-005 opt-in).
	 *
	 * @return void
	 */
	public function testConfirmAllowedWhenNegativeStockExplicitlyAllowed(): void {
		$this->stubObjectService(
			[
				'SalesOrder' => [['id' => 'so-1', 'customerReference' => 'cust-1']],
				'InventoryStock' => [
					['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'quantity' => 4, 'reservedQuantity' => 0],
				],
				'InventoryGLConfig' => [
					['administrationId' => 'adm-1', 'allowNegativeStockOnDispatch' => true],
				],
			]
		);

		self::assertTrue(
			$this->guard->canConfirmDelivery(
				deliveryId: 'dn-1',
				object: $this->completeDelivery(sku: 'sku-a', quantity: 10)
			)
		);

	}//end testConfirmAllowedWhenNegativeStockExplicitlyAllowed()

	/**
	 * Confirm proceeds when available stock covers the shipped quantity.
	 *
	 * @return void
	 */
	public function testConfirmAllowedWhenStockSufficient(): void {
		$this->stubObjectService(
			[
				'SalesOrder' => [['id' => 'so-1', 'customerReference' => 'cust-1']],
				'InventoryStock' => [
					['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'quantity' => 20, 'reservedQuantity' => 0],
				],
				'InventoryGLConfig' => [],
			]
		);

		self::assertTrue(
			$this->guard->canConfirmDelivery(
				deliveryId: 'dn-1',
				object: $this->completeDelivery(sku: 'sku-a', quantity: 10)
			)
		);

	}//end testConfirmAllowedWhenStockSufficient()

	/**
	 * A line whose product has no InventoryStock row (service line, not
	 * stock-tracked) never blocks confirmation, regardless of quantity.
	 *
	 * @return void
	 */
	public function testConfirmAllowedWhenLineNotStockTracked(): void {
		$this->stubObjectService(
			[
				'SalesOrder' => [['id' => 'so-1', 'customerReference' => 'cust-1']],
				'InventoryStock' => [],
				'InventoryGLConfig' => [],
			]
		);

		self::assertTrue(
			$this->guard->canConfirmDelivery(
				deliveryId: 'dn-1',
				object: $this->completeDelivery(sku: 'sku-service', quantity: 1000)
			)
		);

	}//end testConfirmAllowedWhenLineNotStockTracked()

	/**
	 * A Delivery may be cancelled from draft or confirmed, but not once
	 * shipped (REQ-006).
	 *
	 * @return void
	 */
	public function testCancelAllowedBeforeShippedDeniedAfter(): void {
		self::assertTrue(
			$this->guard->canCancelDelivery(deliveryId: 'dn-1', object: ['status' => 'draft'])
		);
		self::assertTrue(
			$this->guard->canCancelDelivery(deliveryId: 'dn-1', object: ['status' => 'confirmed'])
		);
		self::assertFalse(
			$this->guard->canCancelDelivery(deliveryId: 'dn-1', object: ['status' => 'shipped'])
		);

	}//end testCancelAllowedBeforeShippedDeniedAfter()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
