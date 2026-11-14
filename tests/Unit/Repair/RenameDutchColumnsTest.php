<?php

/**
 * Unit tests for RenameDutchColumns.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\RenameDutchColumns;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The step finds the shard tables of the shillinq registers and, per Dutch
 * column, renames it when the English column is absent, back-fills the
 * English column when both exist, and refuses when two Dutch columns would
 * land in one English column. A statement that fails leaves its column as
 * it was; a lookup that fails skips the run.
 *
 * @covers \OCA\Shillinq\Repair\RenameDutchColumns
 */
final class RenameDutchColumnsTest extends TestCase {

	/**
	 * Statements executed.
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * Info lines written.
	 *
	 * @var array<int, string>
	 */
	private array $info = [];

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A prepared statement answering these rows, recording what it was bound to.
	 *
	 * @param array<int, array<string, string>> $rows The rows.
	 *
	 * @return IPreparedStatement The statement.
	 */
	private function statement(array $rows): IPreparedStatement {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

		return $statement;
	}//end statement()

	/**
	 * A connection over registers, tables and per-table columns.
	 *
	 * @param array<int, string>                $registerIds The shillinq register ids.
	 * @param array<int, string>                $tables      The table names information_schema lists.
	 * @param array<string, array<int, string>> $columns     Columns per table.
	 * @param array<int, string>                $failing     Statements (substrings) that throw.
	 *
	 * @return IDBConnection The connection.
	 */
	private function connection(array $registerIds, array $tables, array $columns, array $failing = []): IDBConnection {
		$db     = $this->createMock(IDBConnection::class);
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($registerIds);
		$db->method('executeQuery')
			->with('SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?', ['shillinq%'])
			->willReturn($result);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($tables, $columns): IPreparedStatement {
				if (str_contains($sql, 'information_schema.tables') === true) {
					return $this->statement(array_map(static fn (string $name): array => ['table_name' => $name], $tables));
				}

				$statement = $this->createMock(IPreparedStatement::class);
				$bound     = '';
				$statement->method('bindValue')->willReturnCallback(
					static function ($param, $value) use (&$bound): bool {
						$bound = (string)$value;

						return true;
					}
				);
				$rows = [];
				$statement->method('fetch')->willReturnCallback(
					static function () use (&$bound, &$rows, $columns): array|false {
						if ($rows === []) {
							$rows = array_map(static fn (string $column): array => ['column_name' => $column], ($columns[$bound] ?? []));
							$rows[] = false;
						}

						return array_shift($rows);
					}
				);

				return $statement;
			}
		);
		$db->method('getDatabasePlatform')->willReturn(
			new class {

				/**
				 * @param string $identifier The identifier.
				 *
				 * @return string The quoted identifier.
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '"' . $identifier . '"';
				}
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failing): int {
				foreach ($failing as $needle) {
					if (str_contains($sql, $needle) === true) {
						throw new Exception('column is locked');
					}
				}

				$this->statements[] = $sql;

				return 1;
			}
		);

		return $db;
	}//end connection()

	/**
	 * An output that records its info lines.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->info[] = $message;
			}
		);

		return $output;
	}//end recordingOutput()

	/**
	 * The step is named.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		$step = new RenameDutchColumns($this->createMock(IDBConnection::class), $this->logger);

		$this->assertSame('Move shillinq data from the Dutch columns to the English ones', $step->getName());
	}//end testTheStepIsNamed()

	/**
	 * Only the shillinq registers' numbered shards are touched; renames and back-fills are chosen per column.
	 *
	 * @return void
	 */
	public function testColumnsAreRenamedOrBackFilled(): void {
		$db = $this->connection(
			['7'],
			['oc_openregister_table_7_12', 'oc_openregister_table_7_backup', 'oc_openregister_table_8_3', ''],
			['oc_openregister_table_7_12' => ['id', 'bedrag', 'aanslag_bedrag', 'assessment_amount']]
		);

		(new RenameDutchColumns($db, $this->logger))->run($this->recordingOutput());

		$this->assertSame(
			[
				'UPDATE "oc_openregister_table_7_12" SET "assessment_amount" = "aanslag_bedrag" WHERE "assessment_amount" IS NULL AND "aanslag_bedrag" IS NOT NULL',
				'ALTER TABLE "oc_openregister_table_7_12" RENAME COLUMN "bedrag" TO "amount"',
			],
			$this->statements
		);
		$this->assertSame(['RenameDutchColumns: 1 renamed, 1 back-filled, 0 refused, across 1 shard table(s).'], $this->info);
	}//end testColumnsAreRenamedOrBackFilled()

	/**
	 * Two Dutch columns that map onto one English column are both refused.
	 *
	 * @return void
	 */
	public function testACollisionMigratesNeither(): void {
		$db = $this->connection(['7'], ['oc_openregister_table_7_1'], ['oc_openregister_table_7_1' => ['aftrek_bedrag', 'aftrekbedrag']]);
		$this->logger->expects($this->exactly(2))
			->method('warning')
			->with('RenameDutchColumns: two sources target one destination; migrating neither.', $this->anything());

		(new RenameDutchColumns($db, $this->logger))->run($this->recordingOutput());

		$this->assertSame([], $this->statements);
		$this->assertSame(['RenameDutchColumns: 0 renamed, 0 back-filled, 2 refused, across 1 shard table(s).'], $this->info);
	}//end testACollisionMigratesNeither()

	/**
	 * A statement that fails is logged and not counted.
	 *
	 * @return void
	 */
	public function testAFailedStatementIsNotCounted(): void {
		$db = $this->connection(['7'], ['oc_openregister_table_7_1'], ['oc_openregister_table_7_1' => ['bedrag']], ['RENAME COLUMN']);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'RenameDutchColumns: statement failed; leaving the column as it was.',
				['sql' => 'ALTER TABLE "oc_openregister_table_7_1" RENAME COLUMN "bedrag" TO "amount"', 'exception' => 'column is locked']
			);

		(new RenameDutchColumns($db, $this->logger))->run($this->recordingOutput());

		$this->assertSame(['RenameDutchColumns: 0 renamed, 0 back-filled, 0 refused, across 1 shard table(s).'], $this->info);
	}//end testAFailedStatementIsNotCounted()

	/**
	 * Without shillinq registers, or when they cannot be read, nothing is done.
	 *
	 * @return void
	 */
	public function testNoRegistersMeansNothingToDo(): void {
		(new RenameDutchColumns($this->connection([], ['oc_openregister_table_7_1'], []), $this->logger))->run($this->recordingOutput());

		$failing = $this->createMock(IDBConnection::class);
		$failing->method('executeQuery')->willThrowException(new Exception('no such table'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('RenameDutchColumns: could not resolve the shillinq registers; skipping.', ['exception' => 'no such table']);
		(new RenameDutchColumns($failing, $this->logger))->run($this->recordingOutput());

		$this->assertSame([], $this->statements);
		$this->assertSame(
			[
				'RenameDutchColumns: no shillinq shard tables on this install; nothing to do.',
				'RenameDutchColumns: no shillinq shard tables on this install; nothing to do.',
			],
			$this->info
		);
	}//end testNoRegistersMeansNothingToDo()

	/**
	 * Tables that cannot be listed skip the run; columns that cannot be read skip the table.
	 *
	 * @return void
	 */
	public function testUnreadableSchemaIsSkipped(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn(['7']);
		$noList = $this->createMock(IDBConnection::class);
		$noList->method('executeQuery')->willReturn($result);
		$noList->method('prepare')->willThrowException(new \RuntimeException('access denied'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('RenameDutchColumns: could not list tables; skipping.', ['exception' => 'access denied']);

		(new RenameDutchColumns($noList, $this->logger))->run($this->recordingOutput());

		$this->assertSame(['RenameDutchColumns: no shillinq shard tables on this install; nothing to do.'], $this->info);
	}//end testUnreadableSchemaIsSkipped()

	/**
	 * A table whose columns cannot be read is skipped and the run goes on.
	 *
	 * @return void
	 */
	public function testUnreadableColumnsSkipTheTable(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn(['7']);
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);
		$db->method('prepare')->willReturnCallback(
			function (string $sql): IPreparedStatement {
				if (str_contains($sql, 'information_schema.tables') === true) {
					return $this->statement([['table_name' => 'oc_openregister_table_7_1']]);
				}

				throw new \RuntimeException('columns hidden');
			}
		);
		$db->method('getDatabasePlatform')->willReturn(
			new class {

				/**
				 * @param string $identifier The identifier.
				 *
				 * @return string The quoted identifier.
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return $identifier;
				}
			}
		);
		$db->expects($this->never())->method('executeStatement');
		$this->logger->expects($this->once())
			->method('warning')
			->with('RenameDutchColumns: could not read columns; skipping table.', ['table' => 'oc_openregister_table_7_1', 'exception' => 'columns hidden']);

		(new RenameDutchColumns($db, $this->logger))->run($this->recordingOutput());

		$this->assertSame(['RenameDutchColumns: 0 renamed, 0 back-filled, 0 refused, across 1 shard table(s).'], $this->info);
	}//end testUnreadableColumnsSkipTheTable()
}//end class
