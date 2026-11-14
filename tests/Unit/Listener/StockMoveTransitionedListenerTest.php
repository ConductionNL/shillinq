<?php

/**
 * Characterisation tests for StockMoveTransitionedListener.
 *
 * Pins the event filter, the movement-type filter, the InventoryValuation
 * lookup (filters, row shapes, failure), the valuation-method dispatch, the
 * COGS posting conditions and the fail-soft path.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\Shillinq\Listener\StockMoveTransitionedListener;
use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Service\FifoValuationService;
use OCA\Shillinq\Service\MovingAverageValuationService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Branch-by-branch characterisation of the stock-move valuation dispatch.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class StockMoveTransitionedListenerTest extends TestCase {

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every log line.
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * Build the listener.
	 *
	 * @param mixed                $lookup      findAll() rows, or a Throwable to throw.
	 * @param array<string, mixed> $result      What the valuation services return.
	 * @param bool                 $failProcess Whether the valuation services throw.
	 *
	 * @return StockMoveTransitionedListener The listener.
	 */
	private function listener(mixed $lookup, array $result = [], bool $failProcess = false): StockMoveTransitionedListener {
		$fifo = $this->createMock(FifoValuationService::class);
		$average = $this->createMock(MovingAverageValuationService::class);
		foreach (['fifo' => $fifo, 'average' => $average] as $name => $service) {
			$service->method('processStockMove')->willReturnCallback(
				function (array $move) use ($name, $result, $failProcess): array {
					$this->calls[] = $name . ' ' . ($move['id'] ?? '');
					if ($failProcess === true) {
						throw new RuntimeException('valuation down');
					}

					return $result;
				}
			);
		}

		$cogs = $this->createMock(CogsPosterService::class);
		$cogs->method('postCogs')->willReturnCallback(
			function (array $move, array $valuation, int $cogsCents): array {
				$this->calls[] = 'cogs ' . ($move['id'] ?? '') . ' ' . json_encode($valuation) . ' ' . $cogsCents;
				return [];
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnCallback(
			function (string $register) use ($objectService) {
				$this->calls[] = 'register ' . $register;
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($lookup): array {
				$this->calls[] = 'lookup ' . json_encode($config);
				if ($lookup instanceof \Throwable) {
					throw $lookup;
				}

				return $lookup;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new StockMoveTransitionedListener($fifo, $average, $cogs, $appConfig, $logger, $objectService);

	}//end listener()

	/**
	 * A transition event for a move.
	 *
	 * @param array<string, mixed> $move   The StockMove.
	 * @param string               $to     The target state.
	 * @param string               $schema The schema.
	 *
	 * @return ObjectTransitionedEvent The event.
	 */
	private static function event(array $move, string $to = 'posted', string $schema = 'StockMove'): ObjectTransitionedEvent {
		return new ObjectTransitionedEvent(object: (new ObjectEntity())->setObject($move), move: new TransitionMove(action: 'post', from: 'draft', to: $to), scope: new RegisterSchemaRef(register: 'shillinq', schema: $schema), actor: new TransitionActor(userId: 'u'));

	}//end event()

	/**
	 * An issue move with every lookup key.
	 *
	 * @param string $type The movement type.
	 *
	 * @return array<string, mixed> The move.
	 */
	private static function move(string $type = 'issue'): array {
		return [
			'id' => 'm1',
			'movementType' => $type,
			'itemId' => 'p1',
			'sourceLocationId' => 'src',
			'destinationLocationId' => 'dst',
			'administrationId' => 'adm',
		];

	}//end move()

	/**
	 * Events, schemas, states and movement types that are ignored.
	 *
	 * @return void
	 */
	public function testIgnoredEvents(): void {
		$listener = $this->listener([]);
		$listener->handle(new Event());
		$listener->handle(self::event(self::move(), 'posted', 'Invoice'));
		$listener->handle(self::event(self::move(), 'cancelled'));
		$listener->handle(self::event(self::move('transfer')));

		self::assertSame([], $this->calls);
		self::assertSame([], $this->logs);

	}//end testIgnoredEvents()

	/**
	 * An issue with an active average snapshot: lookup on the source
	 * location, average dispatch, COGS with the result's valuation.
	 *
	 * @return void
	 */
	public function testIssueWithAverageSnapshotPostsCogs(): void {
		$this->listener([['valuationMethod' => 'average']], ['processed' => true, 'cogsCents' => '1250', 'valuation' => ['v' => 1]])
			->handle(self::event(self::move(), 'posted', 'books/StockMove'));

		self::assertSame(
			[
				'register shillinq',
				'lookup {"filters":{"productId":"p1","warehouse":"src","status":"active","administrationId":"adm"},"limit":1}',
				'average m1',
				'cogs m1 {"v":1} 1250',
			],
			$this->calls
		);

	}//end testIssueWithAverageSnapshotPostsCogs()

	/**
	 * A receipt looks up on the destination, never posts COGS.
	 *
	 * @return void
	 */
	public function testReceiptNeverPostsCogs(): void {
		$this->listener([], ['processed' => true, 'cogsCents' => 99, 'valuation' => []])->handle(self::event(self::move('receipt')));

		self::assertSame(
			[
				'register shillinq',
				'lookup {"filters":{"productId":"p1","warehouse":"dst","status":"active","administrationId":"adm"},"limit":1}',
				'fifo m1',
			],
			$this->calls
		);

	}//end testReceiptNeverPostsCogs()

	/**
	 * Row shapes and lookup outcomes that resolve to FIFO, and the COGS
	 * guards.
	 *
	 * @return void
	 */
	public function testLookupShapesAndCogsGuards(): void {
		$serialisable = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['valuationMethod' => 'average'];
			}
		};
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['valuationMethod' => 'average'];
			}
		};
		$opaque = new class {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'x';
			}
		};

		$cases = [
			'serialisable' => [[$serialisable], 'average'],
			'entity'       => [[$entity], 'average'],
			'opaque'       => [[$opaque], 'fifo'],
			'scalar row'   => [['oops'], 'fifo'],
			'array row'    => [[['valuationMethod' => 'LIFO']], 'fifo'],
			'empty method' => [[[]], 'fifo'],
		];
		foreach ($cases as $label => [$rows, $engine]) {
			$this->calls = [];
			$this->listener($rows, ['processed' => true, 'cogsCents' => 0])->handle(self::event(self::move()));
			self::assertSame($engine . ' m1', $this->calls[2], $label);
			self::assertCount(3, $this->calls, $label);
		}

		$this->calls = [];
		$this->listener([], ['processed' => false, 'cogsCents' => 500])->handle(self::event(self::move()));
		self::assertSame('fifo m1', end($this->calls));

		$this->calls = [];
		$this->listener([], ['processed' => 1, 'cogsCents' => -5])->handle(self::event(self::move()));
		self::assertSame('fifo m1', end($this->calls));

	}//end testLookupShapesAndCogsGuards()

	/**
	 * A move without a lookup key skips the lookup; a failing lookup warns
	 * and falls back to FIFO; a failing engine is logged fail-soft.
	 *
	 * @return void
	 */
	public function testMissingKeysAndFailures(): void {
		$move = self::move();
		unset($move['administrationId']);
		$this->listener([])->handle(self::event($move));
		self::assertSame(['fifo m1'], $this->calls);

		$this->calls = [];
		$this->listener(new RuntimeException('store down'))->handle(self::event(self::move()));
		self::assertSame('fifo m1', end($this->calls));
		self::assertSame([['warning', 'StockMoveTransitionedListener: lookupValuation failed', ['exception' => 'store down']]], $this->logs);

		$this->logs = [];
		$this->listener([], [], true)->handle(self::event(self::move()));
		self::assertSame([['error', 'StockMoveTransitionedListener: valuation dispatch failed (fail-soft)', ['exception' => 'valuation down']]], $this->logs);

	}//end testMissingKeysAndFailures()
}//end class
