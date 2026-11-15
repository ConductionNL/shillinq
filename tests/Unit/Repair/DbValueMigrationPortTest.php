<?php

/**
 * Unit tests for DbValueMigrationPort.
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

use OCA\Shillinq\Repair\DbValueMigrationPort;
use OCA\Shillinq\Repair\RenameDutchValueDecisions;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The database side of the Dutch-value migration: it lists the shard tables
 * and a table's columns from information_schema, rewrites one value with
 * bound parameters and quoted identifiers, and answers empty (or zero rows)
 * with a warning when the database refuses.
 *
 * @covers \OCA\Shillinq\Repair\DbValueMigrationPort
 * @uses \OCA\Shillinq\Repair\RenameDutchValueDecisions
 */
final class DbValueMigrationPortTest extends TestCase {

	/**
	 * The connection double.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db     = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The port.
	 *
	 * @return DbValueMigrationPort The port.
	 */
	private function port(): DbValueMigrationPort {
		return new DbValueMigrationPort($this->db, $this->logger, new RenameDutchValueDecisions());
	}//end port()

	/**
	 * A prepared statement expecting one binding and answering these rows.
	 *
	 * @param string                            $param The bound parameter.
	 * @param string                            $value The bound value.
	 * @param array<int, array<string, string>> $rows  The rows.
	 *
	 * @return IPreparedStatement The statement.
	 */
	private function statement(string $param, string $value, array $rows): IPreparedStatement {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->expects($this->once())->method('bindValue')->with($param, $value)->willReturn(true);
		$statement->expects($this->once())->method('execute');
		$statement->method('fetchAll')->willReturn($rows);

		return $statement;
	}//end statement()

	/**
	 * The shard tables are listed by the shard pattern.
	 *
	 * @return void
	 */
	public function testShardTablesAreListedByPattern(): void {
		$this->db->expects($this->once())
			->method('prepare')
			->with('SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern')
			->willReturn($this->statement('pattern', '%openregister\_table\_%', [['table_name' => 'oc_openregister_table_7_1'], ['other' => 'x']]));

		$this->assertSame(['oc_openregister_table_7_1', ''], $this->port()->shardTables());
	}//end testShardTablesAreListedByPattern()

	/**
	 * A table's columns are listed for that table.
	 *
	 * @return void
	 */
	public function testColumnsAreListedForTheTable(): void {
		$this->db->expects($this->once())
			->method('prepare')
			->with('SELECT column_name FROM information_schema.columns WHERE table_name = :table')
			->willReturn($this->statement('table', 'oc_openregister_table_7_1', [['column_name' => 'status'], ['column_name' => 'kind']]));

		$this->assertSame(['status', 'kind'], $this->port()->columnsOf('oc_openregister_table_7_1'));
	}//end testColumnsAreListedForTheTable()

	/**
	 * A refused listing answers empty and warns.
	 *
	 * @return void
	 */
	public function testARefusedListingIsEmpty(): void {
		$this->db->method('prepare')->willThrowException(new RuntimeException('access denied'));
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->assertSame([], $this->port()->shardTables());
		$this->assertSame([], $this->port()->columnsOf('t1'));
		$this->assertSame(
			[
				['DbValueMigrationPort: could not list tables.', ['exception' => 'access denied']],
				['DbValueMigrationPort: could not read columns.', ['table' => 't1', 'exception' => 'access denied']],
			],
			$warnings
		);
	}//end testARefusedListingIsEmpty()

	/**
	 * A rewrite binds the values and quotes the identifiers, answering the rows changed.
	 *
	 * @return void
	 */
	public function testARewriteBindsTheValues(): void {
		$this->db->method('getDatabasePlatform')->willReturn(
			new class {

				/**
				 * @param string $identifier The identifier.
				 *
				 * @return string The quoted identifier.
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '`' . $identifier . '`';
				}
			}
		);
		$this->db->expects($this->once())
			->method('executeStatement')
			->with('UPDATE `t1` SET `status` = ? WHERE `status` = ?', ['paid', 'betaald'])
			->willReturn(4);

		$this->assertSame(4, $this->port()->rewrite('t1', 'status', 'betaald', 'paid'));
	}//end testARewriteBindsTheValues()

	/**
	 * A refused rewrite changes nothing and warns with the statement.
	 *
	 * @return void
	 */
	public function testARefusedRewriteChangesNothing(): void {
		$this->db->method('getDatabasePlatform')->willReturn(
			new class {

				/**
				 * @param string $identifier The identifier.
				 *
				 * @return string The identifier.
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return $identifier;
				}
			}
		);
		$this->db->method('executeStatement')->willThrowException(new RuntimeException('deadlock'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'DbValueMigrationPort: statement failed; leaving the value as it was.',
				['sql' => 'UPDATE t1 SET status = ? WHERE status = ?', 'exception' => 'deadlock']
			);

		$this->assertSame(0, $this->port()->rewrite('t1', 'status', 'betaald', 'paid'));
	}//end testARefusedRewriteChangesNothing()
}//end class
