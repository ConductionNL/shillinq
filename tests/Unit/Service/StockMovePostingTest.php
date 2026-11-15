<?php

/**
 * Unit tests for StockMovePosting: a StockMove is saved as a draft and
 * posted through the transition engine, so its post listeners run; without
 * the engine it is posted directly and a warning says so.
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
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-an-accepted-receipt-adds-its-cost-layer-req-ircl-001
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RecordStoreLog;
use OCA\Shillinq\Service\RegisterBinding;
use OCA\Shillinq\Service\SchemaRecordStore;
use OCA\Shillinq\Service\StockMovePosting;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * @covers \OCA\Shillinq\Service\StockMovePosting
 * @uses   \OCA\Shillinq\Service\RecordStoreLog
 * @uses   \OCA\Shillinq\Service\RegisterBinding
 * @uses   \OCA\Shillinq\Service\SchemaRecordStore
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class StockMovePostingTest extends TestCase {

	/**
	 * The register the moves are written to.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $os;

	/**
	 * Every warning and error logged, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * Set up the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->os = new InMemoryObjectService();
		$this->logged = [];

	}//end setUp()

	/**
	 * The posting over the in-memory register and the given container.
	 *
	 * @param ContainerInterface|null $container The container, or null for none.
	 *
	 * @return StockMovePosting
	 */
	private function posting(?ContainerInterface $container): StockMovePosting {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		$records = new SchemaRecordStore(
			binding: new RegisterBinding(objectService: new DuckObjectServiceAdapter($this->os), appConfig: $appConfig),
			log: new RecordStoreLog(logger: $logger, source: 'Test')
		);

		return new StockMovePosting(records: $records, container: $container, logger: $logger, source: 'TestService');

	}//end posting()

	/**
	 * A container holding the given transition engine.
	 *
	 * @param object $engine The engine double.
	 *
	 * @return ContainerInterface
	 */
	private function containerWith(object $engine): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === StockMovePosting::ENGINE_CLASS);
		$container->method('get')->with(StockMovePosting::ENGINE_CLASS)->willReturn($engine);
		return $container;

	}//end containerWith()

	/**
	 * A receipt move as a caller builds it, already claiming to be posted.
	 *
	 * @return array<string, mixed>
	 */
	private function receipt(): array {
		return [
			'movementNumber' => 'SM-2026-0001',
			'movementType' => 'receipt',
			'quantity' => 40.0,
			'unitCost' => 18.5,
			'draftedAt' => '2026-09-02T08:00:00Z',
			'postedAt' => '2026-09-02T08:00:00Z',
			'locked' => true,
			'lifecycleState' => 'posted',
		];

	}//end receipt()

	/**
	 * With the engine the move is saved as an unlocked draft, the engine runs
	 * `post` on it once, and the stored move is returned as the engine left it.
	 *
	 * @return void
	 */
	public function testTheMoveIsSavedAsADraftAndPostedThroughTheEngine(): void {
		$os = $this->os;
		$seen = [];
		$engine = new class ($os, $seen) {
			/**
			 * Constructor.
			 *
			 * @param InMemoryObjectService $os   The register.
			 * @param array<int, mixed>     $seen What the engine was asked, with the row's state at that moment.
			 */
			public function __construct(private InMemoryObjectService $os, private array &$seen) {
			}

			/**
			 * Post the move the way the lifecycle does: record the state it found, then post and lock it.
			 *
			 * @param string $id     The move id.
			 * @param string $action The transition.
			 *
			 * @return void
			 */
			public function transition(string $id, string $action): void {
				$row = $this->os->setSchema('StockMove')->findAll(['filters' => ['id' => $id]])[0];
				$this->seen[] = [$id, $action, $row['lifecycleState'], $row['locked'], $row['postedAt']];
				$row['lifecycleState'] = 'posted';
				$row['locked'] = true;
				$row['postedAt'] = '2026-09-02T08:00:05Z';
				$this->os->setSchema('StockMove')->saveObject($row);
			}
		};

		$posted = $this->posting($this->containerWith($engine))->post($this->receipt());

		self::assertSame([['auto-1', 'post', 'draft', false, null]], $seen);
		self::assertSame('posted', $posted['lifecycleState']);
		self::assertTrue($posted['locked']);
		self::assertSame('2026-09-02T08:00:05Z', $posted['postedAt']);
		self::assertCount(1, $this->os->dump(schema: 'StockMove'));
		self::assertSame([], $this->logged);

	}//end testTheMoveIsSavedAsADraftAndPostedThroughTheEngine()

	/**
	 * Without a container or without the engine the move is posted and locked
	 * directly, dated at its draft time, and a warning names it.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineTheMoveIsPostedDirectlyWithAWarning(): void {
		$absent = $this->createMock(ContainerInterface::class);
		$absent->method('has')->willReturn(false);
		$absent->expects(self::never())->method('get');

		foreach ([null, $absent] as $container) {
			$this->os = new InMemoryObjectService();
			$this->logged = [];

			$posted = $this->posting($container)->post($this->receipt());

			self::assertSame(['posted', true, '2026-09-02T08:00:00Z'], [$posted['lifecycleState'], $posted['locked'], $posted['postedAt']]);
			self::assertSame([$posted], $this->os->dump(schema: 'StockMove'), 'the draft is posted in place, not written twice');
			self::assertSame(
				[[
					'warning',
					'TestService: StockMove posted without the transition engine; its post listeners did not run',
					['stockMoveId' => 'auto-1', 'movementNumber' => 'SM-2026-0001'],
				]],
				$this->logged
			);
		}

	}//end testWithoutTheEngineTheMoveIsPostedDirectlyWithAWarning()

	/**
	 * An engine that throws, or one without `transition()`, is logged and the
	 * move still ends up posted.
	 *
	 * @return void
	 */
	public function testAFailingOrIncompleteEngineFallsBackToADirectPost(): void {
		$throwing = new class {
			/**
			 * Refuse the transition.
			 *
			 * @param string $id     The move id.
			 * @param string $action The transition.
			 *
			 * @return void
			 */
			public function transition(string $id, string $action): void {
				throw new RuntimeException('guard refused');
			}
		};

		$posted = $this->posting($this->containerWith($throwing))->post($this->receipt());

		self::assertSame('posted', $posted['lifecycleState']);
		self::assertSame(['error', 'TestService: StockMove post transition failed', ['stockMoveId' => 'auto-1', 'exception' => 'guard refused']], $this->logged[0]);
		self::assertSame('warning', $this->logged[1][0]);

		$this->os = new InMemoryObjectService();
		$this->logged = [];
		$posted = $this->posting($this->containerWith(new \stdClass()))->post($this->receipt());

		self::assertSame('posted', $posted['lifecycleState']);
		self::assertSame(['warning'], array_column($this->logged, 0));

	}//end testAFailingOrIncompleteEngineFallsBackToADirectPost()
}//end class
