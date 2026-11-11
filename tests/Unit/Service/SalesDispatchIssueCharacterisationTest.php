<?php

/**
 * Characterisation tests for SalesDispatchStockIssueService::issueForDelivery().
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
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Lifecycle\LotSellabilityGuard;
use OCA\Shillinq\Service\SalesDispatchStockIssueService;
use OCA\Shillinq\Sort\FefoSort;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the header guard, every line skip, the blocked-line envelope, the
 * draft payload, the direct-posted fallback without a TransitionEngine and
 * both save failure paths of issueForDelivery().
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class SalesDispatchIssueCharacterisationTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Recording ObjectService double.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The service under test (no TransitionEngine in the container).
	 *
	 * @var SalesDispatchStockIssueService
	 */
	private SalesDispatchStockIssueService $service;

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);

		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->store = new class {

			public string $schema = '';

			/** @var array<string,array<int,array<string,mixed>>> */
			public array $rows = [];

			/** @var array<int,array<string,mixed>> */
			public array $saved = [];

			/** @var array<int,array{0:string,1:array<string,mixed>}> */
			public array $updated = [];

			public string $saveMode = 'ok';

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function saveObject(array $object): array {
				if ($this->saveMode === 'throw') {
					throw new RuntimeException('store down');
				}

				$this->saved[] = $object;
				if ($this->saveMode === 'ok') {
					$object['id'] = 'sm-' . count($this->saved);
				}

				return $object;
			}

			public function updateObject(string $id, array $object, ?string $register = null, ?string $schema = null): array {
				$this->updated[] = [$id, $object];
				$object['id'] = $id;
				return $object;
			}

			public function find(string $id): ?array {
				return null;
			}

			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$this->store->rows['InventoryStock'] = [
			['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'quantity' => 5, 'reservedQuantity' => 0],
			['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-2', 'quantity' => 9, 'reservedQuantity' => 1],
			['sku' => 'sku-n', 'administrationId' => 'adm-1', 'quantity' => 9],
		];

		$this->service = new SalesDispatchStockIssueService(
			container: $container,
			appConfig: $appConfig,
			logger: $this->logger,
			lotGuard: new LotSellabilityGuard(fefoSort: new FefoSort()),
			objectService: new DuckObjectServiceAdapter($this->store),
		);

	}//end setUp()

	/**
	 * A delivery without id, administration or lines returns the empty
	 * envelope with a warning.
	 *
	 * @return void
	 */
	public function testHeaderGuard(): void {
		$this->logger->expects(self::exactly(3))->method('warning')->with(
			'SalesDispatchStockIssueService: issueForDelivery denied — missing id/administrationId/lines',
			self::anything()
		);
		$empty = ['issued' => 0, 'skipped' => 0, 'blocked' => 0, 'moves' => [], 'blockedLines' => []];
		self::assertSame($empty, $this->service->issueForDelivery(['administrationId' => 'adm-1', 'lines' => []]));
		self::assertSame($empty, $this->service->issueForDelivery(['@self' => ['id' => 'd'], 'lines' => []]));
		self::assertSame($empty, $this->service->issueForDelivery(['id' => 'd', 'administrationId' => 'adm-1', 'lines' => 'x']));

	}//end testHeaderGuard()

	/**
	 * Non-array, empty-sku, zero-quantity, non-stock and unlocatable lines are
	 * skipped. A stock line is drafted from the location with the most
	 * available stock; without a TransitionEngine the direct-posted fallback
	 * calls updateObject() with a named argument the object service contract
	 * does not declare, so the line is logged and skipped (current
	 * behaviour, pinned as is).
	 *
	 * @return void
	 */
	public function testSkipsAndDirectPostedFallback(): void {
		$this->logger->expects(self::once())->method('error')->with(
			'SalesDispatchStockIssueService: saveIssueMove failed',
			['deliveryId' => 'dn-1', 'lineIndex' => 5, 'sku' => 'sku-a', 'exception' => 'Unknown named parameter $id']
		);
		$result = $this->service->issueForDelivery(
			[
				'id' => 'dn-1',
				'administrationId' => 'adm-1',
				'lines' => [
					'x',
					['productReference' => ' ', 'quantityShipped' => 1],
					['productReference' => 'sku-a', 'quantityShipped' => 0],
					['productReference' => 'sku-z', 'quantityShipped' => 1],
					['productReference' => 'sku-n', 'quantityShipped' => 1],
					['productReference' => 'sku-a', 'quantityShipped' => 2.5],
				],
			]
		);

		self::assertSame(0, $result['issued']);
		self::assertSame(6, $result['skipped']);
		self::assertSame(0, $result['blocked']);

		$draft = $this->store->saved[0];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $draft['draftedAt']);
		$drafted = $draft['draftedAt'];
		unset($draft['draftedAt']);
		self::assertSame(
			[
				'movementNumber' => sprintf('SM-DLV-%s-%03d', substr(md5('dn-1'), 0, 8), 5),
				'itemId' => 'sku-a',
				'quantity' => 2.5,
				'unitCost' => 0,
				'movementType' => 'issue',
				'sourceLocationId' => 'loc-2',
				'destinationLocationId' => null,
				'referenceDocumentUri' => 'shillinq://delivery/dn-1#line-5',
				'movementReason' => 'normal',
				'notes' => 'Sale dispatch for delivery dn-1 / line 5',
				'postedAt' => null,
				'cancelledAt' => null,
				'administrationId' => 'adm-1',
				'locked' => false,
				'glTransactionId' => null,
				'offsetOfMoveId' => null,
				'lifecycleState' => 'draft',
			],
			$draft
		);

		self::assertSame([], $this->store->updated);
		self::assertMatchesRegularExpression('/T/', $drafted);

	}//end testSkipsAndDirectPostedFallback()

	/**
	 * A draft saved without an id, and a throwing save, skip the line with
	 * their own error logs.
	 *
	 * @return void
	 */
	public function testSaveFailuresSkipTheLine(): void {
		$messages = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);
		$delivery = [
			'id' => 'dn-2',
			'administrationId' => 'adm-1',
			'lines' => [['productReference' => 'sku-a', 'quantityShipped' => 1, 'sourceLocationId' => 'loc-9']],
		];

		$this->store->saveMode = 'no-id';
		$noId = $this->service->issueForDelivery($delivery);
		$this->store->saveMode = 'throw';
		$thrown = $this->service->issueForDelivery($delivery);

		self::assertSame([0, 1], [$noId['issued'], $noId['skipped']]);
		self::assertSame([0, 1], [$thrown['issued'], $thrown['skipped']]);
		self::assertSame('loc-9', $this->store->saved[0]['sourceLocationId']);
		self::assertSame(
			[
				[
					'SalesDispatchStockIssueService: draft StockMove save returned no id',
					['deliveryId' => 'dn-2', 'lineIndex' => 0],
				],
				[
					'SalesDispatchStockIssueService: saveIssueMove failed',
					['deliveryId' => 'dn-2', 'lineIndex' => 0, 'sku' => 'sku-a', 'exception' => 'store down'],
				],
			],
			$messages
		);

	}//end testSaveFailuresSkipTheLine()

	/**
	 * A blocked lot line logs the offending lots by name and reports them in
	 * blockedLines.
	 *
	 * @return void
	 */
	public function testBlockedLineEnvelope(): void {
		$this->store->rows['InventoryStock'][] = [
			'sku' => 'sku-l',
			'productId' => 'p-l',
			'administrationId' => 'adm-1',
			'locationId' => 'loc-1',
			'quantity' => 9,
		];
		$this->store->rows['InventoryLot'] = [
			[
				'id' => 'lot-q',
				'lotNumber' => 'LOT-Q',
				'productId' => 'p-l',
				'administrationId' => 'adm-1',
				'quantity' => 9,
				'lotStatus' => 'quarantined',
				'expiryDate' => '2099-01-01',
			],
		];
		$logged = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$result = $this->service->issueForDelivery(
			['id' => 'dn-3', 'administrationId' => 'adm-1', 'lines' => [['productReference' => 'sku-l', 'quantityShipped' => 3]]]
		);

		self::assertSame(1, $result['blocked']);
		$blocked = $result['blockedLines'][0];
		self::assertSame(
			[0, 'sku-l', 3.0, 0.0],
			[$blocked['lineIndex'], $blocked['sku'], $blocked['quantityRequired'], (float)$blocked['sellableAvailable']]
		);
		self::assertSame(
			'SalesDispatchStockIssueService: dispatch BLOCKED — no sellable lot can satisfy delivery line; unsellable stock MUST NOT be dispatched.',
			$logged[0][0]
		);
		self::assertSame(
			['deliveryId', 'lineIndex', 'sku', 'quantityRequired', 'sellableAvailable', 'offendingLots'],
			array_keys($logged[0][1])
		);
		self::assertSame('LOT-Q (' . $blocked['offendingLots'][0]['reason'] . ')', $logged[0][1]['offendingLots'][0]);
		self::assertSame([], $this->store->saved);

	}//end testBlockedLineEnvelope()
}//end class
