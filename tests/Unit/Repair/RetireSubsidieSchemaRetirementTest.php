<?php

/**
 * Behaviour tests for RetireSubsidieSchema's guards and schema-row drop.
 *
 * The sibling RetireSubsidieSchemaTest pins which Subsidie objects are deleted.
 * This file pins what happens around that: no admin, no OpenRegister, rows that
 * cannot be keyed, failing deletes and reads, and when the Subsidie schema row
 * itself is dropped.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\RetireSubsidieSchema;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins RetireSubsidieSchema's guards and the schema-row drop.
 *
 * @covers \OCA\Shillinq\Repair\RetireSubsidieSchema
 */
final class RetireSubsidieSchemaRetirementTest extends TestCase {

	/**
	 * Output messages, prefixed with their level.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Statements executed through the query builder, as table names.
	 *
	 * @var array<int, string>
	 */
	private array $deletedFrom = [];

	/**
	 * Logger warnings.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A store over rows per schema; a schema mapped to null fails to read, and
	 * deleting an id listed in $failDeletes throws.
	 *
	 * @param array<string, array<int, mixed>|null> $rows        Rows per schema.
	 * @param array<int, string>                    $failDeletes Ids whose delete throws.
	 *
	 * @return object The ObjectService double.
	 */
	private function store(array $rows, array $failDeletes = []): object {
		return new class($rows, $failDeletes) {

			/**
			 * Ids deleted, in order.
			 *
			 * @var array<int, string>
			 */
			public array $deleted = [];

			/**
			 * The current schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Build it.
			 *
			 * @param array<string, array<int, mixed>|null> $rows        Rows per schema.
			 * @param array<int, string>                    $failDeletes Ids whose delete throws.
			 */
			public function __construct(private array $rows, private array $failDeletes) {
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * One page of the current schema.
			 *
			 * @param array<string, mixed> $config The options.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config = []): array {
				if (array_key_exists($this->schema, $this->rows) === true && $this->rows[$this->schema] === null) {
					throw new RuntimeException('no ' . $this->schema);
				}

				if (($config['offset'] ?? 0) !== 0) {
					return [];
				}

				return ($this->rows[$this->schema] ?? []);
			}//end findAll()

			/**
			 * Delete one object.
			 *
			 * @param string $id            The id.
			 * @param bool   $_rbac         RBAC flag.
			 * @param bool   $_multitenancy Multitenancy flag.
			 *
			 * @return void
			 */
			public function deleteObject(string $id, bool $_rbac, bool $_multitenancy): void {
				if (in_array($id, $this->failDeletes, true) === true) {
					throw new RuntimeException('locked');
				}

				$this->deleted[] = $id;
			}//end deleteObject()
		};
	}//end store()

	/**
	 * A database double: which tables exist, and what each SELECT fetches, in order.
	 *
	 * @param array<int, string> $tables  Existing tables.
	 * @param array<int, mixed>  $fetches fetchOne() results, in query order.
	 *
	 * @return IDBConnection The double.
	 */
	private function db(array $tables, array $fetches = []): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(fn (string $t): bool => in_array($t, $tables, true));

		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$fetches): IQueryBuilder {
				$qb = $this->createMock(IQueryBuilder::class);
				// A plain object: IExpressionBuilder's constants need Doctrine, which this stack lacks.
				$expr = new class {
					/**
					 * An equality condition.
					 *
					 * @param string $column The column.
					 * @param mixed  $value  The value.
					 *
					 * @return string
					 */
					public function eq(string $column, mixed $value): string {
						return $column . '=' . (string)$value;
					}

					/**
					 * A disjunction.
					 *
					 * @param string ...$parts The conditions.
					 *
					 * @return string
					 */
					public function orX(string ...$parts): string {
						return implode(' OR ', $parts);
					}
				};
				$qb->method('expr')->willReturn($expr);
				$qb->method('createNamedParameter')->willReturnCallback(fn (mixed $v): string => (string)$v);
				$from = '';
				foreach (['select', 'where', 'andWhere', 'setMaxResults'] as $fluent) {
					$qb->method($fluent)->willReturnSelf();
				}

				$qb->method('from')->willReturnSelf();
				$qb->method('delete')->willReturnCallback(
					function (string $table) use ($qb, &$from): IQueryBuilder {
						$from = $table;
						return $qb;
					}
				);
				$qb->method('executeStatement')->willReturnCallback(
					function () use (&$from): int {
						$this->deletedFrom[] = $from;
						return 1;
					}
				);
				$qb->method('executeQuery')->willReturnCallback(
					function () use (&$fetches): IResult {
						$next = array_shift($fetches);
						if ($next instanceof \Throwable) {
							throw $next;
						}

						$result = $this->createMock(IResult::class);
						$result->method('fetchOne')->willReturn($next);
						return $result;
					}
				);
				return $qb;
			}
		);

		return $db;
	}//end db()

	/**
	 * Build the step.
	 *
	 * @param object|null        $store  The ObjectService, or null when OpenRegister is absent.
	 * @param IDBConnection|null $db     The database double.
	 * @param array<int, mixed>|null $admins Admin users (['default'] for one admin), or null for no admin group.
	 *
	 * @return RetireSubsidieSchema The step.
	 */
	private function step(?object $store, ?IDBConnection $db = null, ?array $admins = ['default']): RetireSubsidieSchema {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		$container = $this->createMock(ContainerInterface::class);
		if ($store === null) {
			$container->method('get')->willThrowException(new RuntimeException('not installed'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($store);
		}

		$groups = $this->createMock(IGroupManager::class);
		if ($admins === null) {
			$groups->method('get')->with('admin')->willReturn(null);
		} else {
			if ($admins === ['default']) {
				$admins = [$this->createMock(IUser::class)];
			}

			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn($admins);
			$groups->method('get')->with('admin')->willReturn($group);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->warnings[] = (string)$message;
			}
		);

		return new RetireSubsidieSchema(
			settingsService: $location,
			logger: $logger,
			groupManager: $groups,
			db: ($db ?? $this->db(tables: [])),
			container: $container,
		);
	}//end step()

	/**
	 * An output double recording every message.
	 *
	 * @return IOutput The double.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function ($message): void {
				$this->messages[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function ($message): void {
				$this->messages[] = 'warning: ' . $message;
			}
		);
		return $output;
	}//end recordingOutput()

	/**
	 * A Subsidie with its folded Order.
	 *
	 * @return array<string, array<int, mixed>> Rows per schema.
	 */
	private function foldedSubsidie(): array {
		return [
			'Subsidie' => [['id' => 's-1', 'subsidyNumber' => 'SUB-1']],
			'OrderPrimitive' => [['migratedFrom' => ['schema' => 'Subsidie', 'key' => 'SUB-1']]],
		];
	}//end foldedSubsidie()

	/**
	 * Without an admin group the step skips before touching anything.
	 *
	 * @return void
	 */
	public function testNoAdminGroupSkips(): void {
		$store = $this->store($this->foldedSubsidie());
		$this->step(store: $store, admins: null)->run($this->recordingOutput());

		self::assertSame([], $store->deleted);
		self::assertSame(
			['warning: Shillinq: RetireSubsidieSchema — could not resolve an admin user; skipping (re-run after an admin exists).'],
			$this->messages
		);
	}//end testNoAdminGroupSkips()

	/**
	 * An admin group with no members also skips.
	 *
	 * @return void
	 */
	public function testAnEmptyAdminGroupSkips(): void {
		$store = $this->store($this->foldedSubsidie());
		$this->step(store: $store, admins: [])->run($this->recordingOutput());

		self::assertSame([], $store->deleted);
		self::assertStringContainsString('could not resolve an admin user', $this->messages[0]);
	}//end testAnEmptyAdminGroupSkips()

	/**
	 * Without OpenRegister the step warns and stops.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepStops(): void {
		$this->step(store: null)->run($this->recordingOutput());

		self::assertSame(
			['warning: Shillinq: RetireSubsidieSchema — OpenRegister ObjectService unavailable: not installed'],
			$this->messages
		);
	}//end testWithoutOpenRegisterTheStepStops()

	/**
	 * An unreadable Subsidie schema means nothing to retire; the schema-row drop still runs.
	 *
	 * @return void
	 */
	public function testAnUnreadableSubsidieSchemaRetiresNothing(): void {
		$this->step(store: $this->store(['Subsidie' => null]))->run($this->recordingOutput());

		self::assertSame(
			[
				'info: Shillinq: RetireSubsidieSchema — Subsidie schema not available (no Subsidie); nothing to retire.',
				'info: Shillinq: RetireSubsidieSchema — 0 Subsidie object(s) deleted, 0 kept (unmigrated), 0 failed.',
				'info: Shillinq: RetireSubsidieSchema — OpenRegister schemas table absent; nothing to drop.',
			],
			$this->messages
		);
	}//end testAnUnreadableSubsidieSchemaRetiresNothing()

	/**
	 * A row without an id is kept, and kept rows leave the schema row in place.
	 *
	 * @return void
	 */
	public function testARowWithoutAnIdIsKept(): void {
		$store = $this->store(['Subsidie' => [['subsidyNumber' => 'SUB-1']]]);
		$this->step(store: $store)->run($this->recordingOutput());

		self::assertSame([], $store->deleted);
		self::assertSame(
			[
				'warning: Shillinq: RetireSubsidieSchema — Subsidie row without subsidieNumber or id; left in place.',
				'info: Shillinq: RetireSubsidieSchema — 0 Subsidie object(s) deleted, 1 kept (unmigrated), 0 failed.',
				'info: Shillinq: RetireSubsidieSchema — Subsidie objects remain; leaving the Subsidie schema row in place.',
			],
			$this->messages
		);
	}//end testARowWithoutAnIdIsKept()

	/**
	 * Without a subsidyNumber the id is the migration key; an unreadable Order schema keeps the row.
	 *
	 * @return void
	 */
	public function testAnUnreadableOrderSchemaKeepsTheSubsidie(): void {
		$store = $this->store(['Subsidie' => [['uuid' => 'u-7']], 'OrderPrimitive' => null]);
		$this->step(store: $store)->run($this->recordingOutput());

		self::assertSame([], $store->deleted);
		self::assertSame(
			'warning: Shillinq: RetireSubsidieSchema — Subsidie "u-7" has no migrated Order; left in place (unmigrated data).',
			$this->messages[0]
		);
	}//end testAnUnreadableOrderSchemaKeepsTheSubsidie()

	/**
	 * Orders without an array marker are ignored, and the id still matches as the key.
	 *
	 * @return void
	 */
	public function testTheIdIsTheKeyWhenThereIsNoSubsidyNumber(): void {
		$store = $this->store(
			[
				'Subsidie' => [['id' => 's-9']],
				'OrderPrimitive' => [['migratedFrom' => 'Subsidie'], ['migratedFrom' => ['schema' => 'Subsidie', 'key' => 's-9']]],
			]
		);
		$this->step(store: $store)->run($this->recordingOutput());

		self::assertSame(['s-9'], $store->deleted);
	}//end testTheIdIsTheKeyWhenThereIsNoSubsidyNumber()

	/**
	 * A failing delete is counted, logged and leaves the schema row.
	 *
	 * @return void
	 */
	public function testAFailingDeleteIsCounted(): void {
		$store = $this->store($this->foldedSubsidie(), failDeletes: ['s-1']);
		$this->step(store: $store)->run($this->recordingOutput());

		self::assertSame(
			[
				'warning: Shillinq: RetireSubsidieSchema — failed to delete Subsidie "SUB-1": locked',
				'info: Shillinq: RetireSubsidieSchema — 0 Subsidie object(s) deleted, 0 kept (unmigrated), 1 failed.',
				'info: Shillinq: RetireSubsidieSchema — Subsidie objects remain; leaving the Subsidie schema row in place.',
			],
			$this->messages
		);
		self::assertSame(['Shillinq: RetireSubsidieSchema — Subsidie delete failed'], $this->warnings);
	}//end testAFailingDeleteIsCounted()

	/**
	 * An absent schema row is reported and nothing is deleted.
	 *
	 * @return void
	 */
	public function testAnAbsentSchemaRowIsNotDropped(): void {
		$db = $this->db(tables: ['openregister_schemas'], fetches: [false]);
		$this->step(store: $this->store($this->foldedSubsidie()), db: $db)->run($this->recordingOutput());

		self::assertSame([], $this->deletedFrom);
		self::assertSame('info: Shillinq: RetireSubsidieSchema — Subsidie schema row already absent; nothing to drop.', end($this->messages));
	}//end testAnAbsentSchemaRowIsNotDropped()

	/**
	 * Objects still on the schema keep the row.
	 *
	 * @return void
	 */
	public function testRemainingObjectsKeepTheSchemaRow(): void {
		$db = $this->db(tables: ['openregister_schemas', 'openregister_objects'], fetches: [12, 99]);
		$this->step(store: $this->store($this->foldedSubsidie()), db: $db)->run($this->recordingOutput());

		self::assertSame([], $this->deletedFrom);
		self::assertSame(
			'warning: Shillinq: RetireSubsidieSchema — objects still reference the Subsidie schema; NOT dropping the schema row.',
			end($this->messages)
		);
	}//end testRemainingObjectsKeepTheSchemaRow()

	/**
	 * With no objects left the schema row is dropped.
	 *
	 * @return void
	 */
	public function testAnEmptySchemaRowIsDropped(): void {
		$db = $this->db(tables: ['openregister_schemas', 'openregister_objects'], fetches: [12, false]);
		$store = $this->store($this->foldedSubsidie());
		$this->step(store: $store, db: $db)->run($this->recordingOutput());

		self::assertSame(['s-1'], $store->deleted);
		self::assertSame(['openregister_schemas'], $this->deletedFrom);
		self::assertSame('info: Shillinq: RetireSubsidieSchema — Subsidie schema row #12 dropped.', end($this->messages));
	}//end testAnEmptySchemaRowIsDropped()

	/**
	 * Without an objects table nothing can remain, so the row is dropped.
	 *
	 * @return void
	 */
	public function testWithoutAnObjectsTableTheRowIsDropped(): void {
		$db = $this->db(tables: ['openregister_schemas'], fetches: ['7']);
		$this->step(store: $this->store([]), db: $db)->run($this->recordingOutput());

		self::assertSame(['openregister_schemas'], $this->deletedFrom);
		self::assertSame('info: Shillinq: RetireSubsidieSchema — Subsidie schema row #7 dropped.', end($this->messages));
	}//end testWithoutAnObjectsTableTheRowIsDropped()

	/**
	 * A failing drop is reported and logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingDropIsReported(): void {
		$db = $this->db(tables: ['openregister_schemas'], fetches: [new RuntimeException('db gone')]);
		$this->step(store: $this->store([]), db: $db)->run($this->recordingOutput());

		self::assertSame([], $this->deletedFrom);
		self::assertSame('warning: Shillinq: RetireSubsidieSchema — schema-row drop failed: db gone', end($this->messages));
		self::assertSame(['Shillinq: RetireSubsidieSchema — schema-row drop failed'], $this->warnings);
	}//end testAFailingDropIsReported()
}//end class
