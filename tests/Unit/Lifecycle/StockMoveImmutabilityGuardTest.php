<?php

/**
 * Characterisation tests for StockMoveImmutabilityGuard.
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
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\StockMoveImmutabilityGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins results, log calls and the offset lookup of the stock-move guard.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class StockMoveImmutabilityGuardTest extends TestCase {

	private AbstractLogger $logger;

	private object $store;

	private int $containerGets = 0;

	private StockMoveImmutabilityGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$this->store = new class {

			public array $calls = [];

			public mixed $result = [];

			public bool $throw = false;

			public function setRegister(string $register): self {
				$this->calls[] = ['register', $register];
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->calls[] = ['schema', $schema];
				return $this;
			}

			public function findAll(array $args = []): mixed {
				$this->calls[] = ['findAll', $args];
				if ($this->throw === true) {
					throw new \RuntimeException('store down');
				}

				return $this->result;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				self::assertSame('OCA\OpenRegister\Service\ObjectService', $id);
				$this->containerGets++;
				return $this->store;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$this->guard = new StockMoveImmutabilityGuard($container, $appConfig, $this->logger);
	}

	public function testDraftAndCancelledNeverTouchTheStore(): void {
		self::assertTrue($this->guard->canCancel([]));
		self::assertTrue($this->guard->canCancel(['lifecycleState' => 'draft']));
		self::assertFalse($this->guard->canCancel(['lifecycleState' => 'cancelled']));
		self::assertSame(0, $this->containerGets);
		self::assertSame([], $this->logger->records);
	}

	public function testPostedWithoutIdsIsDeniedSilently(): void {
		self::assertFalse($this->guard->canCancel(['lifecycleState' => 'posted', 'id' => 'm1']));
		self::assertFalse($this->guard->canCancel(['lifecycleState' => 'posted', 'administrationId' => 'a1']));
		self::assertSame(0, $this->containerGets);
		self::assertSame([], $this->logger->records);
	}

	public function testPostedWithoutOffsetIsCancellable(): void {
		$this->store->result = 'not-an-array';
		self::assertTrue($this->guard->canCancel(['lifecycleState' => 'posted', 'id' => 'm1', 'administrationId' => 'a1']));
		self::assertSame(
			[
				['register', 'shillinq'],
				['schema', 'StockMove'],
				['findAll', ['filters' => ['offsetOfMoveId' => 'm1', 'administrationId' => 'a1']]],
			],
			$this->store->calls
		);
		self::assertSame([], $this->logger->records);
	}

	public function testPostedWithOffsetIsDeniedAndLogged(): void {
		$this->store->result = [['id' => 'o1'], ['id' => 'o2']];
		$move = ['lifecycleState' => 'posted', 'id' => 'm1', 'administrationId' => 'a1', 'movementNumber' => 'SM-7'];
		self::assertFalse($this->guard->canCancel($move));
		self::assertSame(
			[
				[
					'info',
					'StockMoveImmutabilityGuard: cancel denied — offset already exists',
					[
						'movementNumber' => 'SM-7',
						'offsetCount' => 2,
					],
				],
			],
			$this->logger->records
		);
	}

	public function testCancelLookupFailureFailsClosed(): void {
		$this->store->throw = true;
		self::assertFalse($this->guard->canCancel(['lifecycleState' => 'posted', 'id' => 'm1', 'administrationId' => 'a1']));
		self::assertSame(
			[
				[
					'error',
					'StockMoveImmutabilityGuard: canCancel failed — denying transition (fail-closed)',
					[
						'movementNumber' => null,
						'exception' => 'store down',
					],
				],
			],
			$this->logger->records
		);
	}

	public function testUnlockedOrUnchangedEditsArePermitted(): void {
		self::assertTrue($this->guard->rejectLockedEdit(['quantity' => 1], ['quantity' => 2]));
		self::assertTrue($this->guard->rejectLockedEdit(['locked' => true, 'quantity' => 1, 'note' => 'a'], ['note' => 'b', 'quantity' => 1]));
		self::assertTrue($this->guard->rejectLockedEdit(['locked' => true, 'unitCost' => 5], []));
		self::assertSame([], $this->logger->records);
	}

	public function testFirstChangedLoadBearingFieldIsLogged(): void {
		$current = ['locked' => 1, 'movementNumber' => 'SM-9', 'quantity' => 1, 'itemId' => 'i1'];
		self::assertFalse($this->guard->rejectLockedEdit($current, ['itemId' => 'i2', 'quantity' => '1', 'destinationLocationId' => 'x']));
		self::assertSame(
			[
				[
					'info',
					'StockMoveImmutabilityGuard: edit denied — move is locked',
					[
						'movementNumber' => 'SM-9',
						'field' => 'quantity',
					],
				],
			],
			$this->logger->records
		);
	}

	public function testANullProposalCountsAsAChange(): void {
		self::assertFalse($this->guard->rejectLockedEdit(['locked' => true, 'unitCost' => 5], ['unitCost' => null]));
		self::assertSame('unitCost', $this->logger->records[0][2]['field']);
		self::assertTrue($this->guard->rejectLockedEdit(['locked' => true], ['sourceLocationId' => null]));
	}
}
