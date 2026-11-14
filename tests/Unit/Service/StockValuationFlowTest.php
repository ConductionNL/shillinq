<?php

/**
 * Characterisation tests for FifoValuationService and
 * MovingAverageValuationService::processStockMove().
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\FifoValuationService;
use OCA\Shillinq\Service\MovingAverageValuationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * Pins the refusal envelopes, the idempotent-retry log and envelope, the
 * snapshot created for a first move, the full saved valuation (keys and key
 * order) and the shortfall / clamp warnings of both valuation services.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class StockValuationFlowTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * In-memory store.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $os;

	/**
	 * Set up shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->os = new InMemoryObjectService();

	}//end setUp()

	/**
	 * Build a valuation service of the given class.
	 *
	 * @param class-string $class The service class.
	 *
	 * @return object The service.
	 */
	private function service(string $class): object {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		return new $class(appConfig: $appConfig, logger: $this->logger, objectService: new DuckObjectServiceAdapter($this->os));

	}//end service()

	/**
	 * A stock move of the given type.
	 *
	 * @param string              $type      receipt or issue.
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed> The move.
	 */
	private function move(string $type, array $overrides): array {
		return array_merge(
			[
				'@self' => ['uuid' => 'u-' . $type],
				'movementType' => $type,
				'itemId' => 'SKU',
				'destinationLocationId' => 'WH',
				'sourceLocationId' => 'WH',
				'quantity' => 4.0,
				'unitCost' => 2.5,
				'draftedAt' => '2026-03-01',
				'administrationId' => 'adm',
			],
			$overrides
		);

	}//end move()

	/**
	 * A posted StockMove row of item SKU in administration adm.
	 *
	 * @param array<string,mixed> $fields The row's own fields.
	 *
	 * @return array<string,mixed> The row.
	 */
	private function postedMove(array $fields): array {
		$defaults = ['itemId' => 'SKU', 'destinationLocationId' => 'WH', 'sourceLocationId' => 'WH'];
		return $fields + $defaults + ['lifecycleState' => 'posted', 'administrationId' => 'adm'];

	}//end postedMove()

	/**
	 * Both services refuse non-trigger and incomplete moves with their own
	 * messages.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$fifo = $this->service(FifoValuationService::class);
		$avg = $this->service(MovingAverageValuationService::class);
		$incomplete = ['processed' => false, 'message' => 'StockMove missing itemId / location / administrationId / uuid'];

		self::assertSame(
			['processed' => false, 'message' => 'movementType transfer is not a FIFO trigger'],
			$fifo->processStockMove(['movementType' => 'transfer'])
		);
		self::assertSame(['processed' => false, 'message' => 'movementType  is not an average trigger'], $avg->processStockMove([]));
		self::assertSame($incomplete, $fifo->processStockMove($this->move('receipt', ['destinationLocationId' => ''])));
		self::assertSame($incomplete, $avg->processStockMove($this->move('issue', ['@self' => []])));
		self::assertSame(
			['processed' => false, 'message' => 'outbound quantity is non-positive'],
			$fifo->processStockMove($this->move('issue', ['quantity' => 0]))
		);
		self::assertSame([], $this->os->dump(schema: 'InventoryValuation'));

	}//end testRefusals()

	/**
	 * A first FIFO receipt creates the full snapshot; a retry of the same
	 * move is logged and skipped.
	 *
	 * @return void
	 */
	public function testFifoReceiptSnapshotAndRetry(): void {
		$this->os->seed(
			schema: 'StockMove',
			rows: [
				$this->postedMove(['uuid' => 'r1', 'movementType' => 'receipt', 'quantity' => 3, 'unitCost' => 2, 'postedAt' => '2026-02-01']),
				$this->postedMove(['uuid' => 'r0', 'movementType' => 'receipt', 'quantity' => 1, 'unitCost' => 5, 'draftedAt' => '2026-01-01']),
				$this->postedMove(['uuid' => 'i0', 'movementType' => 'issue', 'quantity' => 2]),
			]
		);
		$fifo = $this->service(FifoValuationService::class);

		$result = $fifo->processStockMove($this->move('receipt', ['postedAt' => '2026-03-02']));
		$saved = $result['valuation'];
		unset($saved['id']);
		self::assertSame(
			[
				'productId' => 'SKU',
				'warehouse' => 'WH',
				'quantity' => 2.0,
				'unitCost' => 2.0,
				'totalValue' => 4.0,
				'valuationMethod' => 'FIFO',
				'date' => '2026-03-02',
				'status' => 'active',
				'administrationId' => 'adm',
				'pendingCogs' => false,
				'lastStockMoveUuid' => 'u-receipt',
			],
			$saved
		);
		self::assertSame(['processed', 'valuation', 'cogsCents', 'cogsBasis', 'message'], array_keys($result));
		self::assertSame('receipt processed', $result['message']);

		$this->logger->expects(self::once())->method('info')->with(
			'FifoValuationService: idempotent retry — skipping',
			['productId' => 'SKU', 'warehouse' => 'WH', 'moveUuid' => 'u-receipt']
		);
		$retry = $fifo->processStockMove($this->move('receipt', []));
		self::assertSame(
			['processed' => false, 'valuation' => $result['valuation'], 'cogsCents' => 0, 'cogsBasis' => [], 'message' => 'idempotent retry'],
			$retry
		);

	}//end testFifoReceiptSnapshotAndRetry()

	/**
	 * A FIFO issue larger than the lots warns and allocates what there is.
	 *
	 * @return void
	 */
	public function testFifoIssueShortfall(): void {
		$this->os->seed(
			schema: 'StockMove',
			rows: [
				$this->postedMove(['uuid' => 'r1', 'movementType' => 'receipt', 'quantity' => 3, 'unitCost' => 2, 'postedAt' => '2026-02-01']),
				$this->postedMove(['uuid' => 'u-issue', 'movementType' => 'issue', 'quantity' => 4]),
			]
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'FifoValuationService: outbound exceeds available FIFO lots — partial allocation',
			['productId' => 'SKU', 'warehouse' => 'WH', 'outboundQty' => 4.0, 'totalAvailable' => 3.0]
		);

		$result = $this->service(FifoValuationService::class)->processStockMove($this->move('issue', []));

		self::assertSame(600, $result['cogsCents']);
		self::assertSame([['lotCost' => 2.0, 'quantity' => 3.0]], $result['cogsBasis']);
		$valuation = $result['valuation'];
		self::assertSame([0.0, 0.0, 0.0], [$valuation['quantity'], $valuation['unitCost'], $valuation['totalValue']]);
		self::assertSame('2026-03-01', $result['valuation']['date']);
		self::assertSame('issue processed', $result['message']);

	}//end testFifoIssueShortfall()

	/**
	 * Moving average: the first receipt sets the snapshot, a retry is
	 * logged, an over-issue is clamped to zero with a warning.
	 *
	 * @return void
	 */
	public function testMovingAverageFlow(): void {
		$avg = $this->service(MovingAverageValuationService::class);

		$receipt = $avg->processStockMove($this->move('receipt', []));
		$saved = $receipt['valuation'];
		unset($saved['id']);
		self::assertSame(
			[
				'productId' => 'SKU',
				'warehouse' => 'WH',
				'quantity' => 4.0,
				'unitCost' => 2.5,
				'totalValue' => 10.0,
				'valuationMethod' => 'average',
				'date' => '2026-03-01',
				'status' => 'active',
				'administrationId' => 'adm',
				'pendingCogs' => false,
				'lastStockMoveUuid' => 'u-receipt',
			],
			$saved
		);
		self::assertSame(
			['processed' => true, 'cogsCents' => 0, 'message' => 'receipt processed (moving-average recalculated)'],
			array_diff_key($receipt, ['valuation' => 1])
		);

		$this->logger->expects(self::once())->method('info')->with(
			'MovingAverageValuationService: idempotent retry — skipping',
			['productId' => 'SKU', 'warehouse' => 'WH', 'moveUuid' => 'u-receipt']
		);
		self::assertSame(
			['processed' => false, 'valuation' => $receipt['valuation'], 'cogsCents' => 0, 'message' => 'idempotent retry'],
			$avg->processStockMove($this->move('receipt', []))
		);

		$this->logger->expects(self::once())->method('warning')->with(
			'MovingAverageValuationService: outbound exceeds current quantity — clamped to zero',
			['productId' => 'SKU', 'warehouse' => 'WH', 'curQty' => 4.0, 'outboundQty' => 6.0]
		);
		$issue = $avg->processStockMove($this->move('issue', ['quantity' => 6.0, 'postedAt' => '2026-03-05']));
		self::assertSame(1500, $issue['cogsCents']);
		self::assertSame('issue processed (COGS at current average)', $issue['message']);
		self::assertSame([0.0, 2.5, 0.0, '2026-03-05', 'u-issue'], [
			$issue['valuation']['quantity'],
			$issue['valuation']['unitCost'],
			$issue['valuation']['totalValue'],
			$issue['valuation']['date'],
			$issue['valuation']['lastStockMoveUuid'],
		]);

	}//end testMovingAverageFlow()
}//end class
