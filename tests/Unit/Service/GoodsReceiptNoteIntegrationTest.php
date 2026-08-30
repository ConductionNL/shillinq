<?php

/**
 * Integration tests for GoodsReceiptNoteService (member 04 of
 * bookkeeping-purchase-order-3way).
 *
 * Exercises the full create-line-accept loop end-to-end and asserts the
 * stock-mutation invariants spelled out in the slice 04 spec delta:
 *  - accepted quantities post a StockMove credit (lifecycleState=posted,
 *    movementType=receipt, movementReason=normal, referenceDocumentUri =
 *    shillinq://purchase-order/<poId>);
 *  - rejected quantities never produce a StockMove;
 *  - the originating PO's lifecycle transitions to partial_received when
 *    less than the full quantity is accepted and to fully_received when the
 *    cumulative accepted-quantity catches up;
 *  - the manifest fragment shipped by this slice exposes the GRN Vue pages.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GoodsReceiptNoteService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * End-to-end GRN create → add lines → accept flow over an in-memory
 * ObjectService, verifying the StockMove mutation invariants.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GoodsReceiptNoteIntegrationTest extends TestCase {

	/**
	 * REQ-PO3W-003 acceptance scenario: a partial receipt of 180 of 200 chairs
	 * — quantity_accepted=180, quantity_rejected=20 ('short_shipped') — credits
	 * inventory for 180 units only, fires no StockMove for the 20 rejected
	 * units, and updates the originating PO to "partial_received".
	 *
	 * @return void
	 */
	public function testAcceptCreditsAcceptedAndSkipsRejected(): void {
		$stub = $this->buildObjectServiceStub([
			'PurchaseOrder' => [
				[
					'id' => 'po-1',
					'administrationId' => 'adm-1',
					'poNumber' => 'PO-2026-adm-1-000003',
					'lifecycleState' => 'sent',
				],
			],
			'PurchaseOrderLine' => [
				[
					'id' => 'poline-1',
					'poId' => 'po-1',
					'administrationId' => 'adm-1',
					'productOrServiceCode' => 'CHAIR-001',
					'quantityOrdered' => 200.0,
					'unitPrice' => 4500,
				],
			],
			'GoodsReceiptNote' => [],
			'GoodsReceiptLine' => [],
			'StockMove' => [],
		]);

		$service = $this->buildService(stub: $stub, userId: 'warehouse-01', accessibleAdministrations: ['adm-1']);

		$grn = $service->createGRN(
			administrationId: 'adm-1',
			payload: [
				'poIds' => ['po-1'],
				'carrier' => 'PostNL',
				'deliveryNoteReference' => 'DN-NW-2026-7791',
			]
		);
		$grnId = (string)$grn['id'];

		$service->addGRNLine(
			administrationId: 'adm-1',
			grnId: $grnId,
			payload: [
				'poLineId' => 'poline-1',
				'quantityReceived' => 200.0,
				'quantityAccepted' => 180.0,
				'quantityRejected' => 20.0,
				'rejectionReason' => 'short_shipped',
			]
		);

		$accepted = $service->acceptGRN(administrationId: 'adm-1', grnId: $grnId);

		self::assertSame('accepted', $accepted['statusCode']);

		// Exactly one StockMove must exist: the credit for the 180 accepted
		// units. The 20 rejected units must NOT produce a StockMove
		// (slice 04 design D3).
		$moves = $stub->rows('StockMove');
		self::assertCount(1, $moves);

		$move = $moves[0];
		self::assertSame('posted', $move['lifecycleState']);
		self::assertSame('receipt', $move['movementType']);
		self::assertSame('normal', $move['movementReason']);
		self::assertSame(180.0, (float)$move['quantity']);
		self::assertSame(45.0, (float)$move['unitCost']);
		self::assertSame('CHAIR-001', $move['itemId']);
		self::assertSame('shillinq://purchase-order/po-1', $move['referenceDocumentUri']);
		self::assertTrue((bool)$move['locked']);
		self::assertSame('adm-1', $move['administrationId']);

		// PO transitions to partial_received because 180 < 200 ordered.
		$po = $stub->rows('PurchaseOrder')[0];
		self::assertSame('partial_received', $po['lifecycleState']);

	}//end testAcceptCreditsAcceptedAndSkipsRejected()

	/**
	 * Spec scenario 2: a GRN with quantity_received=50, quantity_accepted=40,
	 * quantity_rejected=10 credits inventory for 40 units only — the 10
	 * rejected units are recorded on the GoodsReceiptLine but never mutate
	 * stock.
	 *
	 * @return void
	 */
	public function testRejectedQuantityDoesNotMutateInventory(): void {
		$stub = $this->buildObjectServiceStub([
			'PurchaseOrder' => [
				['id' => 'po-9', 'administrationId' => 'adm-1', 'poNumber' => 'PO-X', 'lifecycleState' => 'sent'],
			],
			'PurchaseOrderLine' => [
				[
					'id' => 'poline-9',
					'poId' => 'po-9',
					'administrationId' => 'adm-1',
					'productOrServiceCode' => 'SKU-9',
					'quantityOrdered' => 50.0,
					'unitPrice' => 10000,
				],
			],
			'GoodsReceiptNote' => [],
			'GoodsReceiptLine' => [],
			'StockMove' => [],
		]);

		$service = $this->buildService(stub: $stub, userId: 'warehouse-01', accessibleAdministrations: ['adm-1']);

		$grn = $service->createGRN(
			administrationId: 'adm-1',
			payload: [
				'poIds' => ['po-9'],
			]
		);
		$grnId = (string)$grn['id'];

		$service->addGRNLine(
			administrationId: 'adm-1',
			grnId: $grnId,
			payload: [
				'poLineId' => 'poline-9',
				'quantityReceived' => 50.0,
				'quantityAccepted' => 40.0,
				'quantityRejected' => 10.0,
				'rejectionReason' => 'schade',
			]
		);

		$service->acceptGRN(administrationId: 'adm-1', grnId: $grnId);

		$moves = $stub->rows('StockMove');
		self::assertCount(1, $moves);

		$move = $moves[0];
		self::assertSame(40.0, (float)$move['quantity']);
		self::assertSame('receipt', $move['movementType']);
		self::assertSame('posted', $move['lifecycleState']);

		// The line carries the 10 rejected units + the rejectionReason.
		$line = $stub->rows('GoodsReceiptLine')[0];
		self::assertSame(10.0, (float)$line['quantityRejected']);
		self::assertSame('schade', $line['rejectionReason']);

	}//end testRejectedQuantityDoesNotMutateInventory()

	/**
	 * When every PO line is fully accepted the PO transitions to
	 * "fully_received" (boundary of the partial/fully logic).
	 *
	 * @return void
	 */
	public function testAcceptTransitionsToFullyReceived(): void {
		$stub = $this->buildObjectServiceStub([
			'PurchaseOrder' => [
				['id' => 'po-7', 'administrationId' => 'adm-1', 'poNumber' => 'PO-Y', 'lifecycleState' => 'sent'],
			],
			'PurchaseOrderLine' => [
				[
					'id' => 'poline-7',
					'poId' => 'po-7',
					'administrationId' => 'adm-1',
					'productOrServiceCode' => 'SKU-7',
					'quantityOrdered' => 5.0,
					'unitPrice' => 5000,
				],
			],
			'GoodsReceiptNote' => [],
			'GoodsReceiptLine' => [],
			'StockMove' => [],
		]);

		$service = $this->buildService(stub: $stub, userId: 'warehouse-01', accessibleAdministrations: ['adm-1']);

		$grn = $service->createGRN(
			administrationId: 'adm-1',
			payload: ['poIds' => ['po-7']]
		);
		$grnId = (string)$grn['id'];

		$service->addGRNLine(
			administrationId: 'adm-1',
			grnId: $grnId,
			payload: [
				'poLineId' => 'poline-7',
				'quantityReceived' => 5.0,
				'quantityAccepted' => 5.0,
			]
		);

		$service->acceptGRN(administrationId: 'adm-1', grnId: $grnId);

		$po = $stub->rows('PurchaseOrder')[0];
		self::assertSame('fully_received', $po['lifecycleState']);

	}//end testAcceptTransitionsToFullyReceived()

	/**
	 * Accept-while-empty is harmless: a GRN with no lines transitions to
	 * accepted but produces no StockMove and leaves the PO alone.
	 *
	 * @return void
	 */
	public function testAcceptEmptyGrnIsHarmless(): void {
		$stub = $this->buildObjectServiceStub([
			'PurchaseOrder' => [
				['id' => 'po-empty', 'administrationId' => 'adm-1', 'lifecycleState' => 'sent'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-empty', 'poId' => 'po-empty', 'administrationId' => 'adm-1', 'quantityOrdered' => 5.0],
			],
			'GoodsReceiptNote' => [],
			'GoodsReceiptLine' => [],
			'StockMove' => [],
		]);

		$service = $this->buildService(stub: $stub, userId: 'warehouse-01', accessibleAdministrations: ['adm-1']);

		$grn = $service->createGRN(
			administrationId: 'adm-1',
			payload: ['poIds' => ['po-empty']]
		);
		$accepted = $service->acceptGRN(administrationId: 'adm-1', grnId: (string)$grn['id']);

		self::assertSame('accepted', $accepted['statusCode']);
		self::assertCount(0, $stub->rows('StockMove'));
		self::assertSame('sent', $stub->rows('PurchaseOrder')[0]['lifecycleState']);

	}//end testAcceptEmptyGrnIsHarmless()

	/**
	 * The manifest fragment shipped by this slice exposes both Vue pages and
	 * the new menu group so the Vue layer can reach the new API.
	 *
	 * @return void
	 */
	public function testManifestFragmentExposesGrnPages(): void {
		$fragmentPath = __DIR__ . '/../../../src/manifest.d/bookkeeping-purchase-order-3way-04-goods-receipt-note.json';
		self::assertFileExists($fragmentPath);

		$json = json_decode((string)file_get_contents($fragmentPath), true);
		self::assertIsArray($json);
		self::assertArrayHasKey('pages', $json);
		self::assertArrayHasKey('menu', $json);

		$pageIds = array_column($json['pages'], 'id');
		self::assertContains('GoodsReceiptNoteForm', $pageIds);
		self::assertContains('GoodsReceiptNoteDetail', $pageIds);

		foreach ($json['pages'] as $page) {
			self::assertSame('custom', $page['type'] ?? '', 'Custom pages must declare type=custom for the v2 registry resolver');
			self::assertNotEmpty($page['component'] ?? '', 'Custom pages must reference a registry component');
		}

		// GoodsReceiptNotes may appear at the top level or as a child of a parent
		// group (e.g. Purchasing). Collect all ids from all levels.
		$allMenuIds = [];
		$queue = $json['menu'];
		while ($queue !== []) {
			$entry = array_shift($queue);
			if (is_array($entry) === false) {
				continue;
			}

			if (isset($entry['id'])) {
				$allMenuIds[] = $entry['id'];
			}

			foreach ((array)($entry['children'] ?? []) as $child) {
				$queue[] = $child;
			}
		}

		self::assertContains('GoodsReceiptNotes', $allMenuIds, 'GoodsReceiptNotes MUST appear in the menu (at any level)');

	}//end testManifestFragmentExposesGrnPages()

	/**
	 * Build a service wired against the supplied stub.
	 *
	 * @param object $stub ObjectService stub.
	 * @param string $userId Authenticated uid.
	 * @param array<int,string> $accessibleAdministrations Tenants the caller may access.
	 *
	 * @return GoodsReceiptNoteService
	 */
	private function buildService(
		object $stub,
		string $userId,
		array $accessibleAdministrations,
	): GoodsReceiptNoteService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn($userId);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		return new GoodsReceiptNoteService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build a mutable in-memory ObjectService stub with a `rows()` helper for
	 * post-test assertions.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $initial Initial schema rows.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $initial): object {
		return new class($initial) {
			/**
			 * Schema => rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $initial Initial schema rows.
			 */
			public function __construct(array $initial) {
				$this->data = $initial;
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
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Resolve all rows for the active schema, applying equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Save (insert or upsert by id) into the active schema bucket.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === false || $object['id'] === '') {
					$this->idCounter++;
					$object['id'] = 'obj-' . $this->idCounter;
				} else {
					$this->data[$this->schema] = array_values(
						array_filter(
							($this->data[$this->schema] ?? []),
							static fn (array $row): bool => (($row['id'] ?? null) !== $object['id'])
						)
					);
				}

				$this->data[$this->schema][] = $object;
				return $object;
			}//end saveObject()

			/**
			 * Test-only helper: return every row for a schema (no setSchema
			 * mutation, no filtering).
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function rows(string $schema): array {
				return ($this->data[$schema] ?? []);
			}//end rows()
		};

	}//end buildObjectServiceStub()
}//end class
