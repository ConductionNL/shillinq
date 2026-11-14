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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
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
					'statusCode' => 'sent',
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
		self::assertSame('partial_received', $po['statusCode']);

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
				['id' => 'po-9', 'administrationId' => 'adm-1', 'poNumber' => 'PO-X', 'statusCode' => 'sent'],
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
				['id' => 'po-7', 'administrationId' => 'adm-1', 'poNumber' => 'PO-Y', 'statusCode' => 'sent'],
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
		self::assertSame('fully_received', $po['statusCode']);

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
				['id' => 'po-empty', 'administrationId' => 'adm-1', 'statusCode' => 'sent'],
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
		self::assertSame('sent', $stub->rows('PurchaseOrder')[0]['statusCode']);

	}//end testAcceptEmptyGrnIsHarmless()

	/**
	 * Pins every field of the StockMove credits an accept posts, which lines
	 * it skips (nothing accepted, no PO line reference, a missing PO line with
	 * its warning), and the destination location fallback from line to GRN.
	 *
	 * @return void
	 */
	public function testAcceptPostsEachStockMoveFieldForField(): void {
		$stub = $this->buildObjectServiceStub($this->acceptFixture());
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$service = $this->buildServiceWithLogger(stub: $stub, logger: $logger);

		$accepted = $service->acceptGRN(administrationId: 'adm-1', grnId: 'grn-1');

		// Without a transition engine each accepted line is posted directly and
		// says so (inventory-receipt-cost-layers REQ-IRCL-001); the missing PO
		// line is still skipped with its own warning.
		$fallback = 'GoodsReceiptNoteService: StockMove posted without the transition engine; its post listeners did not run';
		self::assertSame(
			[
				[$fallback, ['stockMoveId' => 'obj-1', 'movementNumber' => 'SM-2026-adm-1-000001-LINEA']],
				[$fallback, ['stockMoveId' => 'obj-2', 'movementNumber' => 'SM-2026-adm-1-000001-LINEB']],
				['GoodsReceiptNoteService: PO line missing on accept; StockMove skipped', ['poLineId' => 'poline-gone', 'grnId' => 'grn-1']],
			],
			$this->sortedByMessage($warnings)
		);

		self::assertSame('accepted', $accepted['statusCode']);
		$moves = $stub->rows('StockMove');
		foreach ($moves as $i => $move) {
			self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)$move['draftedAt']);
			self::assertSame($move['draftedAt'], $move['postedAt']);
			unset($moves[$i]['draftedAt'], $moves[$i]['postedAt']);
		}

		$expected = static fn (string $number, float $quantity, ?string $destination, string $id): array => [
			'movementNumber' => $number,
			'itemId' => 'CHAIR-001',
			'quantity' => $quantity,
			'unitCost' => 45.5,
			'movementType' => 'receipt',
			'sourceLocationId' => null,
			'destinationLocationId' => $destination,
			'referenceDocumentUri' => 'shillinq://purchase-order/po-1',
			'movementReason' => 'normal',
			'notes' => 'Receipt for GRN-2026-adm-1-000001 / PO line poline-1',
			'cancelledAt' => null,
			'administrationId' => 'adm-1',
			'locked' => true,
			'glTransactionId' => null,
			'offsetOfMoveId' => null,
			'lifecycleState' => 'posted',
			'id' => $id,
		];
		self::assertSame(
			[
				$expected('SM-2026-adm-1-000001-LINEA', 2.5, 'WH-LINE', 'obj-1'),
				$expected('SM-2026-adm-1-000001-LINEB', 1.0, 'WH-GRN', 'obj-2'),
			],
			array_values($moves)
		);
		self::assertSame('partial_received', $stub->rows('PurchaseOrder')[0]['statusCode']);

	}//end testAcceptPostsEachStockMoveFieldForField()

	/**
	 * With the transition engine every accepted line is saved as an unlocked
	 * draft and posted through the engine, once, and no fallback warning is
	 * logged (inventory-receipt-cost-layers REQ-IRCL-001).
	 *
	 * @return void
	 */
	public function testAcceptPostsEachReceiptThroughTheTransitionEngine(): void {
		$stub = $this->buildObjectServiceStub($this->acceptFixture());
		$transitions = [];
		$engine = new class ($stub, $transitions) {
			/**
			 * Constructor.
			 *
			 * @param object            $stub        The register.
			 * @param array<int, mixed> $transitions Each transition, with the move's state when it ran.
			 */
			public function __construct(private object $stub, private array &$transitions) {
			}

			/**
			 * Record the transition and post the move.
			 *
			 * @param string $id     The move id.
			 * @param string $action The transition.
			 *
			 * @return void
			 */
			public function transition(string $id, string $action): void {
				$row = array_values(array_filter($this->stub->rows('StockMove'), static fn (array $r): bool => $r['id'] === $id))[0];
				$this->transitions[] = [$id, $action, $row['lifecycleState'], $row['locked'], $row['postedAt']];
				$row['lifecycleState'] = 'posted';
				$row['locked'] = true;
				$this->stub->setSchema('StockMove')->saveObject($row);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($engine);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'GoodsReceiptNoteService: PO line missing on accept; StockMove skipped',
			['poLineId' => 'poline-gone', 'grnId' => 'grn-1']
		);

		$this->buildServiceWithLogger(stub: $stub, logger: $logger, container: $container)->acceptGRN(administrationId: 'adm-1', grnId: 'grn-1');

		self::assertSame(
			[['obj-1', 'post', 'draft', false, null], ['obj-2', 'post', 'draft', false, null]],
			$transitions
		);
		self::assertSame(['posted', 'posted'], array_column($stub->rows('StockMove'), 'lifecycleState'));

	}//end testAcceptPostsEachReceiptThroughTheTransitionEngine()

	/**
	 * Golden for the accept guards: an inaccessible or empty administration,
	 * a GRN of another administration and a terminal GRN are refused before
	 * anything is written; the accessible draft is accepted.
	 *
	 * @return void
	 */
	public function testAcceptRefusesOutsideItsAdministrationAndOnTerminalNotes(): void {
		$refusals = [
			['adm-2', 'grn-1', 'Administration not found'],
			['', 'grn-1', 'administrationId is required'],
			['adm-1', 'grn-other', 'Goods receipt note not found'],
			['adm-1', 'grn-done', 'Goods receipt note is in a terminal state'],
		];
		foreach ($refusals as [$administrationId, $grnId, $message]) {
			$stub = $this->buildObjectServiceStub($this->acceptFixture());
			$service = $this->buildService(stub: $stub, userId: 'warehouse-01', accessibleAdministrations: ['adm-1', 'adm-3']);
			try {
				$service->acceptGRN(administrationId: $administrationId, grnId: $grnId);
				self::fail('Expected a refusal for ' . $grnId . ' in "' . $administrationId . '"');
			} catch (\RuntimeException $e) {
				self::assertSame($message, $e->getMessage());
			}

			self::assertSame([], $stub->rows('StockMove'));
			self::assertSame('received', $stub->rows('GoodsReceiptNote')[0]['statusCode']);
		}

		$stub = $this->buildObjectServiceStub($this->acceptFixture());
		$service = $this->buildService(stub: $stub, userId: 'warehouse-01', accessibleAdministrations: ['adm-1']);
		self::assertSame('accepted', $service->acceptGRN(administrationId: 'adm-1', grnId: 'grn-1')['statusCode']);

	}//end testAcceptRefusesOutsideItsAdministrationAndOnTerminalNotes()

	/**
	 * Golden for the photo upload guards: an inaccessible or empty
	 * administration and a GRN of another administration are refused before
	 * anything is written; the accessible GRN gets the merged photos.
	 *
	 * @return void
	 */
	public function testUploadPhotosRefusesOutsideItsAdministration(): void {
		$refusals = [
			['adm-2', 'grn-1', 'Administration not found'],
			['', 'grn-1', 'administrationId is required'],
			['adm-1', 'grn-other', 'Goods receipt note not found'],
		];
		foreach ($refusals as [$administrationId, $grnId, $message]) {
			$stub = $this->buildObjectServiceStub($this->acceptFixture());
			$service = $this->buildService(stub: $stub, userId: 'warehouse-01', accessibleAdministrations: ['adm-1', 'adm-3']);
			try {
				$service->uploadPhotos(administrationId: $administrationId, grnId: $grnId, photoFileIds: ['file-9']);
				self::fail('Expected a refusal for ' . $grnId . ' in "' . $administrationId . '"');
			} catch (\RuntimeException $e) {
				self::assertSame($message, $e->getMessage());
			}

			self::assertArrayNotHasKey('photos', $stub->rows('GoodsReceiptNote')[0]);
			self::assertArrayNotHasKey('photos', $stub->rows('GoodsReceiptNote')[1]);
		}

		$stub = $this->buildObjectServiceStub($this->acceptFixture());
		$service = $this->buildService(stub: $stub, userId: 'warehouse-01', accessibleAdministrations: ['adm-1']);
		$updated = $service->uploadPhotos(administrationId: 'adm-1', grnId: 'grn-1', photoFileIds: ['file-9', 'file-9']);
		self::assertSame(['file-9'], $updated['photos']);

	}//end testUploadPhotosRefusesOutsideItsAdministration()

	/**
	 * The records of the accept goldens: one received GRN with five lines, a
	 * GRN of another administration and a terminal GRN.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function acceptFixture(): array {
		$line = static fn (string $id, string $poLineId, float $accepted, array $extra = []): array => [
			'id' => $id,
			'grnId' => 'grn-1',
			'administrationId' => 'adm-1',
			'poLineId' => $poLineId,
			'quantityAccepted' => $accepted,
		] + $extra;

		return [
			'PurchaseOrder' => [['id' => 'po-1', 'administrationId' => 'adm-1', 'statusCode' => 'sent']],
			'PurchaseOrderLine' => [
				[
					'id' => 'poline-1',
					'poId' => 'po-1',
					'administrationId' => 'adm-1',
					'productOrServiceCode' => 'CHAIR-001',
					'quantityOrdered' => 10.0,
					'unitPrice' => 4550,
				],
			],
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'administrationId' => 'adm-1',
					'grnNumber' => 'GRN-2026-adm-1-000001',
					'statusCode' => 'received',
					'poIds' => ['po-1'],
					'destinationLocationId' => 'WH-GRN',
				],
				['id' => 'grn-other', 'administrationId' => 'adm-3', 'statusCode' => 'received'],
				['id' => 'grn-done', 'administrationId' => 'adm-1', 'statusCode' => 'accepted'],
			],
			'GoodsReceiptLine' => [
				$line('line-a', 'poline-1', 2.5, ['destinationLocationId' => ' WH-LINE ']),
				$line('line-b', ' poline-1 ', 1.0),
				$line('line-c', 'poline-1', 0.0),
				$line('line-d', ' ', 4.0),
				$line('line-e', 'poline-gone', 3.0),
			],
			'StockMove' => [],
		];

	}//end acceptFixture()

	/**
	 * Warnings in a stable order: by message, then in the order they came.
	 *
	 * @param array<int, array{0: string, 1: array<string, mixed>}> $warnings The warnings.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private function sortedByMessage(array $warnings): array {
		usort($warnings, static fn (array $a, array $b): int => strcmp($b[0], $a[0]));
		return $warnings;

	}//end sortedByMessage()

	/**
	 * Build the service for adm-1 with the given logger.
	 *
	 * @param object $stub The ObjectService stub.
	 * @param LoggerInterface $logger The logger.
	 * @param ContainerInterface|null $container The container the transition engine comes from, or null for none.
	 *
	 * @return GoodsReceiptNoteService
	 */
	private function buildServiceWithLogger(object $stub, LoggerInterface $logger, ?ContainerInterface $container = null): GoodsReceiptNoteService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn('warehouse-01');
		$administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);

		return new GoodsReceiptNoteService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($stub),
			container: $container,
		);

	}//end buildServiceWithLogger()

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
