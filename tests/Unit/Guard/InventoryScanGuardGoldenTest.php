<?php

/**
 * Golden tests for InventoryScanGuard: the check order, the stock lookup, answers and log lines.
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
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\InventoryScanGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer, lookup and log line of the save precondition.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class InventoryScanGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the precondition once.
	 *
	 * @param array<string,mixed> $operation The operation.
	 * @param mixed               $stock     findAll()'s answer, or a Throwable it throws.
	 *
	 * @return bool
	 */
	private function check(array $operation, mixed $stock=[]): bool {
		$store     = new class($this, $stock) {
			/**
			 * Construct.
			 *
			 * @param InventoryScanGuardGoldenTest $test  Recorder.
			 * @param mixed                        $stock Answer.
			 */
			public function __construct(private InventoryScanGuardGoldenTest $test, private mixed $stock) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->test->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return mixed
			 */
			public function findAll(array $params=[]): mixed {
				$this->test->calls[] = ['findAll', $params];
				if ($this->stock instanceof \Throwable) {
					throw $this->stock;
				}

				return $this->stock;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return (new InventoryScanGuard($container, $appConfig, $logger))->validateOperation($operation);
	}//end check()

	/**
	 * Adjudicated records only need an identity; every refusal of a pending one has its own line, in order.
	 *
	 * @return void
	 */
	public function testRefusalsInOrder(): void {
		self::assertTrue(condition: $this->check(operation: ['state' => 'rejected', 'transactionId' => 't', 'type' => 'fly']));
		self::assertFalse(condition: $this->check(operation: ['state' => 'duplicate', 'transactionId' => ' ']));
		$base  = ['transactionId' => 't1', 'type' => 'receive', 'sku' => 'S', 'location' => 'L', 'quantity' => 1];
		$cases = [
			[['transactionId' => ''] + $base, ['info', 'InventoryScanGuard: missing transactionId — denying save', []]],
			[['type' => 'fly'] + $base, ['info', 'InventoryScanGuard: unrecognised operation type — denying save', ['type' => 'fly']]],
			[['sku' => ' '] + $base, ['info', 'InventoryScanGuard: missing sku/location — denying save', ['type' => 'receive']]],
			[[
				'type' => 'transfer',
				'toLocation' => ' L '] + $base, ['info',
				'InventoryScanGuard: transfer needs a distinct toLocation — denying save',
				[],
			]],
			[['quantity' => 'x'] + $base, ['info', 'InventoryScanGuard: missing/non-numeric quantity — denying save', []]],
			[['quantity' => '-0.5'] + $base, ['info', 'InventoryScanGuard: negative quantity — denying save', []]],
		];
		foreach ($cases as $i => [$operation, $line]) {
			$this->calls = [];
			self::assertFalse(condition: $this->check(operation: $operation), message: 'case ' . $i);
			self::assertSame(expected: [$line], actual: $this->calls, message: 'case ' . $i);
		}

		$this->calls = [];
		self::assertTrue(condition: $this->check(operation: $base));
		self::assertTrue(condition: $this->check(operation: ['type' => 'count', 'quantity' => 0] + $base));
		self::assertSame(expected: [], actual: $this->calls);
	}//end testRefusalsInOrder()

	/**
	 * Outbound operations check the source stock; unavailable stock is skipped with a warning.
	 *
	 * @return void
	 */
	public function testStockCheck(): void {
		$pick = ['transactionId' => 't1', 'type' => 'pick', 'sku' => 'S', 'location' => 'L', 'quantity' => '3'];
		self::assertFalse(condition: $this->check(operation: $pick, stock: [['quantity' => '2.5'], ['quantity' => 99]]));
		self::assertSame(
			expected: [
				['setRegister', 'shillinq'],
				['setSchema', 'InventoryStock'],
				['findAll', ['filters' => ['sku' => 'S', 'location' => 'L'], 'limit' => 1]],
				['info', 'InventoryScanGuard: insufficient stock for outbound op — denying save', ['available' => 2.5, 'requested' => 3.0]],
			],
			actual: $this->calls
		);
		self::assertTrue(condition: $this->check(operation: $pick, stock: [['quantity' => 3]]));
		$this->calls = [];
		$transfer = ['type' => 'transfer', 'toLocation' => 'M'] + $pick;
		self::assertTrue(condition: $this->check(operation: $transfer, stock: [['sku' => 'S']]));
		$warning = [
			'warning',
			'InventoryScanGuard: stock record unavailable for outbound op — skipping availability check (T1 state)',
			['sku' => 'S', 'location' => 'L'],
		];
		self::assertSame(expected: $warning, actual: end($this->calls));
		self::assertTrue(condition: $this->check(operation: $transfer, stock: 'not-an-array'));
		$this->calls = [];
		self::assertTrue(condition: $this->check(operation: $transfer, stock: new \RuntimeException('no schema')));
		self::assertSame(
			expected: [
				[
					'debug',
					'InventoryScanGuard: schema lookup unavailable (T1 state) — treating as null',
					['schema' => 'InventoryStock', 'exception' => 'no schema',
				]],
				$warning,
			],
			actual: array_slice($this->calls, -2)
		);
	}//end testStockCheck()

	/**
	 * A stock row that is not an array is a failed lookup: logged at debug, then skipped as unavailable.
	 *
	 * @return void
	 */
	public function testObjectStockRowIsUnavailable(): void {
		$pick = ['transactionId' => 't9', 'type' => 'pick', 'sku' => 'S', 'location' => 'L', 'quantity' => 1];
		self::assertTrue(condition: $this->check(operation: $pick, stock: [new \stdClass()]));
		$levels = array_column(array_slice($this->calls, -2), 0);
		self::assertSame(expected: ['debug', 'warning'], actual: $levels);
	}//end testObjectStockRowIsUnavailable()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
