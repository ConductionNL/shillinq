<?php

/**
 * The receipt-to-cost flow of inventory-receipt-cost-layers: receipt moves
 * posted through the transition engine reach the FIFO valuation the way the
 * `post` listener hands them over, so an issue is costed from the layers the
 * receipts added (the design's Tarwebloem example, EUR 932.50).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://shillinq.app
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-an-issue-is-costed-from-the-receipt-layers-req-ircl-002
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\FifoValuationService;
use OCA\Shillinq\Service\RecordStoreLog;
use OCA\Shillinq\Service\RegisterBinding;
use OCA\Shillinq\Service\SchemaRecordStore;
use OCA\Shillinq\Service\StockMovePosting;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * @covers \OCA\Shillinq\Service\StockMovePosting
 * @uses   \OCA\Shillinq\Service\FifoValuationService
 * @uses   \OCA\Shillinq\Service\RecordStoreLog
 * @uses   \OCA\Shillinq\Service\RegisterBinding
 * @uses   \OCA\Shillinq\Service\SchemaRecordStore
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ReceiptCostLayersFlowTest extends TestCase {

	/**
	 * The flour receipts become layers, and the 50-bag issue is costed
	 * 40 x 18.50 + 10 x 19.25 = EUR 932.50, leaving 30 bags worth EUR 577.50.
	 *
	 * @return void
	 */
	public function testAnIssueIsCostedFromTheLayersTheReceiptsAdded(): void {
		$os = new InMemoryObjectService();
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createStub(LoggerInterface::class);
		$adapter = new DuckObjectServiceAdapter($os);
		$fifo = new FifoValuationService(appConfig: $appConfig, logger: $logger, objectService: $adapter);

		// The engine posts the draft and hands the posted move to the valuation,
		// as StockMoveTransitionedListener does on the `post` transition.
		$valuations = [];
		$engine = new class ($os, $fifo, $valuations) {
			/**
			 * Constructor.
			 *
			 * @param InMemoryObjectService $os         The register.
			 * @param FifoValuationService  $fifo       The valuation engine.
			 * @param array<int, mixed>     $valuations Each valuation result, in order.
			 */
			public function __construct(private InMemoryObjectService $os, private FifoValuationService $fifo, private array &$valuations) {
			}

			/**
			 * Post the move and value it.
			 *
			 * @param string $id     The move id.
			 * @param string $action The transition.
			 *
			 * @return void
			 */
			public function transition(string $id, string $action): void {
				$row = $this->os->setSchema('StockMove')->findAll(['filters' => ['id' => $id]])[0];
				$row['lifecycleState'] = 'posted';
				$row['locked'] = true;
				$row['postedAt'] = $row['draftedAt'];
				$row['uuid'] = $id;
				$this->os->setSchema('StockMove')->saveObject($row);
				$this->valuations[] = $this->fifo->processStockMove($row);
			}
		};
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($engine);

		$posting = new StockMovePosting(
			records: new SchemaRecordStore(
				binding: new RegisterBinding(objectService: $adapter, appConfig: $appConfig),
				log: new RecordStoreLog(logger: $logger, source: 'Test')
			),
			container: $container,
			logger: $logger,
			source: 'GoodsReceiptNoteService'
		);
		$move = static fn (string $type, float $quantity, float $unitCost, string $day): array => [
			'movementType' => $type,
			'itemId' => 'TARWEBLOEM-25KG',
			'quantity' => $quantity,
			'unitCost' => $unitCost,
			'sourceLocationId' => ($type === 'issue' ? 'MAGAZIJN' : null),
			'destinationLocationId' => ($type === 'receipt' ? 'MAGAZIJN' : null),
			'draftedAt' => $day . 'T09:00:00Z',
			'administrationId' => 'bakkerij-jansen',
		];

		$posting->post($move('receipt', 40.0, 18.50, '2026-09-02'));
		self::assertSame([40.0, 740.0], [(float)$valuations[0]['valuation']['quantity'], (float)$valuations[0]['valuation']['totalValue']]);

		$posting->post($move('receipt', 40.0, 19.25, '2026-09-16'));
		$posting->post($move('issue', 50.0, 0.0, '2026-09-20'));

		$issue = $valuations[2];
		self::assertSame(93250, $issue['cogsCents']);
		self::assertSame([['lotCost' => 18.5, 'quantity' => 40.0], ['lotCost' => 19.25, 'quantity' => 10.0]], $issue['cogsBasis']);
		self::assertSame([30.0, 577.5], [(float)$issue['valuation']['quantity'], (float)$issue['valuation']['totalValue']]);

	}//end testAnIssueIsCostedFromTheLayersTheReceiptsAdded()
}//end class
