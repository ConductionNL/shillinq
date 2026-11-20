<?php

/**
 * Golden tests for OrdersAuditCommand: the reads, the per-schema lines and the verdict.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Command;

use OCA\Shillinq\Command\OrdersAuditCommand;
use OCA\Shillinq\Service\RegisterLocation;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pins the command's reads, its exact output and its exit code.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OrdersAuditCommandGoldenTest extends TestCase {

	/**
	 * Recorded reads in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the command once.
	 *
	 * @param array<string,mixed>|\Throwable $rows Rows per schema (a Throwable per schema fails that read), or the container failure.
	 *
	 * @return array{0:int,1:string}
	 */
	private function audit(array|\Throwable $rows): array {
		$store     = new class($this, $rows) {
			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param OrdersAuditCommandGoldenTest $test Recorder.
			 * @param mixed                        $rows Rows per schema.
			 */
			public function __construct(private OrdersAuditCommandGoldenTest $test, private mixed $rows) {
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
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Record and answer one page.
			 *
			 * @param array<string,mixed> $config Query.
			 *
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				$this->test->calls[] = ['findAll', $this->schema, $config];
				$rows = ($this->rows[$this->schema] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return array_slice($rows, $config['offset'], $config['limit']);
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		if ($rows instanceof \Throwable) {
			$container->method('get')->willThrowException($rows);
		} else {
			$container->method('get')->willReturn($store);
		}

		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('reg-i');
		$tester = new CommandTester(new OrdersAuditCommand($settings, $container));
		$code   = $tester->execute([]);

		return [$code, $tester->getDisplay()];
	}//end audit()

	/**
	 * An object row read through getObject().
	 *
	 * @param mixed $payload What getObject() answers, or a Throwable it throws.
	 *
	 * @return object
	 */
	private static function entity(mixed $payload): object {
		return new class($payload) {
			/**
			 * Construct.
			 *
			 * @param mixed $payload Payload.
			 */
			public function __construct(private mixed $payload) {
			}//end __construct()

			/**
			 * Answer the payload.
			 *
			 * @return mixed
			 */
			public function getObject(): mixed {
				if ($this->payload instanceof \Throwable) {
					throw $this->payload;
				}

				return $this->payload;
			}//end getObject()
		};
	}//end entity()

	/**
	 * Without the ObjectService the command fails with one line.
	 *
	 * @return void
	 */
	public function testNoObjectService(): void {
		self::assertSame(
			expected: [1, "Shillinq: orders:audit — OpenRegister ObjectService unavailable: no OR\n"],
			actual: $this->audit(rows: new \RuntimeException('no OR'))
		);
	}//end testNoObjectService()

	/**
	 * Mixed row shapes, a missing source schema, a mismatch and the exact output.
	 *
	 * @return void
	 */
	public function testMixedRowsAndMismatch(): void {
		$orders = [
			['migratedFrom' => ['schema' => 'Subsidie']],
			self::entity(payload: ['migratedFrom' => ['schema' => 'Subsidie']]),
			self::entity(payload: new \RuntimeException('broken')),
			self::entity(payload: 'scalar'),
			['migratedFrom' => ['schema' => 'PurchaseOrder']],
			['migratedFrom' => 'Subsidie'],
			'junk',
		];
		$rows = [
			'Subsidie' => [['id' => 1], ['id' => 2]],
			'PurchaseOrder' => [['id' => 3], ['id' => 4]],
			'DBAOpdracht' => new \RuntimeException('absent'),
			'OrderPrimitive' => $orders,
		];
		[$code, $display] = $this->audit(rows: $rows);
		self::assertSame(expected: 1, actual: $code);
		self::assertSame(
			expected: "Shillinq Order-fold count-equality audit\n"
				. "  Subsidie       (orderType=subsidy   ) source=2    migrated=2    OK\n"
				. "  PurchaseOrder  (orderType=purchase  ) source=2    migrated=1    MISMATCH\n"
				. "  DBAOpdracht    (orderType=engagement) source=0    migrated=0    OK\n"
				. "FAIL — one or more source schemas have unmigrated rows. Run `occ maintenance:repair` to fold them.\n",
			actual: $display
		);
		$reads = array_values(array_filter($this->calls, fn (array $c): bool => $c[0] === 'findAll'));
		self::assertSame(
			expected: ['Subsidie', 'OrderPrimitive', 'PurchaseOrder', 'OrderPrimitive', 'DBAOpdracht', 'OrderPrimitive'],
			actual: array_column($reads, 1)
		);
		self::assertSame(expected: ['limit' => 200, 'offset' => 0, '_rbac' => false, '_multitenancy' => false], actual: $reads[0][2]);
		self::assertSame(expected: ['setRegister', 'reg-i'], actual: $this->calls[0]);
	}//end testMixedRowsAndMismatch()

	/**
	 * Every schema matched (more migrated than source counts too) passes with exit code 0.
	 *
	 * @return void
	 */
	public function testPass(): void {
		$orders = array_merge(
			array_fill(0, 3, ['migratedFrom' => ['schema' => 'PurchaseOrder']]),
			array_fill(0, 201, ['migratedFrom' => ['schema' => 'Subsidie']])
		);
		[$code, $display] = $this->audit(rows: ['Subsidie' => array_fill(0, 201, []), 'PurchaseOrder' => [[]], 'OrderPrimitive' => $orders]);
		self::assertSame(expected: 0, actual: $code);
		self::assertStringContainsString(needle: "  Subsidie       (orderType=subsidy   ) source=201  migrated=201  OK\n", haystack: $display);
		self::assertStringContainsString(needle: "  PurchaseOrder  (orderType=purchase  ) source=1    migrated=3    OK\n", haystack: $display);
		self::assertStringEndsWith(suffix: "PASS — every source row has a matching folded Order.\n", string: $display);
	}//end testPass()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
