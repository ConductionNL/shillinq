<?php

/**
 * Unit tests for RenameCommitmentSchemas against in-memory schema and object
 * tables: what is renamed, retitled and repointed, what is refused, and how
 * the shillinq register's schema ids are resolved.
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
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\RenameCommitmentSchemas;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The step over a database double that answers the step's query-builder
 * calls from in-memory tables.
 */
#[CoversClass(RenameCommitmentSchemas::class)]
final class RenameCommitmentSchemasRenameTest extends TestCase {

	/**
	 * The in-memory tables, by name.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $tables = [];

	/**
	 * The `schemas` column of each shillinq register row.
	 *
	 * @var array<int,string>
	 */
	private array $registerSchemas = [];

	/**
	 * Whether the objects table exists.
	 *
	 * @var bool
	 */
	private bool $objectsTable = true;

	/**
	 * Whether title updates fail.
	 *
	 * @var bool
	 */
	private bool $titleFails = false;

	/**
	 * The database double.
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
	 * Messages written to the repair output, as "level: message".
	 *
	 * @var array<int,string>
	 */
	private array $said = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('tableExists')->willReturnCallback(
			fn (string $table): bool => ($table === 'openregister_schemas' || ($table === 'openregister_objects' && $this->objectsTable === true))
		);
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params): IResult {
				$this->assertStringContainsString('openregister_registers', $sql);
				$this->assertSame(['shillinq%'], $params);
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($this->registerSchemas);
				return $result;
			}
		);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * A query builder double over the in-memory tables: update/set/select/from,
	 * where/andWhere with eq and in, executeStatement and executeQuery.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$state = (object)['table' => '', 'set' => [], 'conditions' => [], 'params' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		// A plain object, not an IExpressionBuilder double: that interface's
		// constants need Doctrine's ExpressionBuilder, which this stack lacks.
		$expr = new class {
			/**
			 * An equality condition.
			 *
			 * @param string $column The column.
			 * @param string $param  The parameter placeholder.
			 *
			 * @return string
			 */
			public function eq(string $column, string $param): string {
				return 'eq|' . $column . '|' . $param;
			}

			/**
			 * A membership condition.
			 *
			 * @param string $column The column.
			 * @param string $param  The parameter placeholder.
			 *
			 * @return string
			 */
			public function in(string $column, string $param): string {
				return 'in|' . $column . '|' . $param;
			}
		};
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function (mixed $value) use ($state): string {
				$name = ':p' . count($state->params);
				$state->params[$name] = $value;
				return $name;
			}
		);
		$qb->method('update')->willReturnCallback(
			static function (string $table) use ($state, $qb): IQueryBuilder {
				$state->table = $table;
				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			static function (string $table) use ($state, $qb): IQueryBuilder {
				$state->table = $table;
				return $qb;
			}
		);
		$qb->method('select')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('set')->willReturnCallback(
			static function (string $column, string $param) use ($state, $qb): IQueryBuilder {
				$state->set[$column] = $param;
				return $qb;
			}
		);
		$condition = static function (string $condition) use ($state, $qb): IQueryBuilder {
			$state->conditions[] = $condition;
			return $qb;
		};
		$qb->method('where')->willReturnCallback($condition);
		$qb->method('andWhere')->willReturnCallback($condition);
		$qb->method('executeStatement')->willReturnCallback(fn (): int => $this->update($state));
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($state): IResult {
				$match = $this->matching($state);
				$id = false;
				if ($match !== []) {
					$id = $this->tables[$state->table][$match[0]]['id'];
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn($id);
				return $result;
			}
		);
		return $qb;

	}//end queryBuilder()

	/**
	 * The row indexes of the state's table matching all its conditions.
	 *
	 * @param object $state The query state.
	 *
	 * @return array<int,int>
	 */
	private function matching(object $state): array {
		$matches = [];
		foreach ($this->tables[$state->table] ?? [] as $index => $row) {
			$ok = true;
			foreach ($state->conditions as $condition) {
				[$op, $column, $param] = explode('|', $condition);
				$value = $state->params[$param];
				$cell = (string)($row[$column] ?? '');
				$hit = in_array($cell, (array)$value, true);
				if ($op === 'eq') {
					$hit = ($cell === (string)$value);
				}

				$ok = ($ok && $hit);
			}

			if ($ok === true) {
				$matches[] = $index;
			}
		}

		return $matches;

	}//end matching()

	/**
	 * Apply an update to the in-memory table.
	 *
	 * @param object $state The query state.
	 *
	 * @return int Rows changed.
	 *
	 * @throws DbException When title updates are set to fail.
	 */
	private function update(object $state): int {
		if ($this->titleFails === true && isset($state->set['title']) === true) {
			throw new DbException('title column is locked');
		}

		$rows = $this->matching($state);
		foreach ($rows as $index) {
			foreach ($state->set as $column => $param) {
				$this->tables[$state->table][$index][$column] = $state->params[$param];
			}
		}

		return count($rows);

	}//end update()

	/**
	 * Run the step with an output double that records what it was told.
	 *
	 * @return void
	 */
	private function runStep(): void {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->said[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->said[] = 'warning: ' . $message;
			}
		);
		(new RenameCommitmentSchemas($this->db, $this->logger))->run($output);

	}//end runStep()

	/**
	 * The slugs, titles or object schemas in a table, by id.
	 *
	 * @param string $table  The table.
	 * @param string $column The column.
	 *
	 * @return array<string,mixed>
	 */
	private function column(string $table, string $column): array {
		$out = [];
		foreach ($this->tables[$table] as $row) {
			$out[(string)$row['id']] = $row[$column];
		}

		return $out;

	}//end column()

	/**
	 * Shillinq's Dutch schemas are renamed, a stock title follows, a custom title
	 * stays, slug-bearing objects are repointed and another app's schema is untouched.
	 *
	 * @return void
	 */
	public function testRenamesOwnSchemasRetitlesStockTitlesAndRepointsObjects(): void {
		$this->registerSchemas = ['["1","2",3]', '[3,"4"]'];
		$this->tables = [
			'openregister_schemas' => [
				['id' => 1, 'slug' => 'Verplichting', 'title' => 'Verplichting'],
				['id' => 2, 'slug' => 'Mandate', 'title' => 'Bestedingsmandaat'],
				['id' => 3, 'slug' => 'Goedkeuringsstap', 'title' => 'Goedkeuringsstap'],
				['id' => 4, 'slug' => 'Invoice', 'title' => 'Invoice'],
				['id' => 9, 'slug' => 'Verplichting', 'title' => 'Verplichting'],
			],
			'openregister_objects' => [
				['id' => 'o1', 'schema' => 'Verplichting'],
				['id' => 'o2', 'schema' => 'Verplichting'],
				['id' => 'o3', 'schema' => '1'],
				['id' => 'o4', 'schema' => 'Goedkeuringsstap'],
			],
		];
		$infos = [];
		$this->logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$infos): void {
				$infos[] = $context;
			}
		);
		$this->logger->expects($this->never())->method('warning');

		$this->runStep();

		$this->assertSame(
			['1' => 'Commitment', '2' => 'SpendingMandate', '3' => 'ApprovalStep', '4' => 'Invoice', '9' => 'Verplichting'],
			$this->column('openregister_schemas', 'slug')
		);
		$this->assertSame(
			['1' => 'Commitment', '2' => 'Bestedingsmandaat', '3' => 'ApprovalStep', '4' => 'Invoice', '9' => 'Verplichting'],
			$this->column('openregister_schemas', 'title')
		);
		$this->assertSame(
			['o1' => 'Commitment', 'o2' => 'Commitment', 'o3' => '1', 'o4' => 'ApprovalStep'],
			$this->column('openregister_objects', 'schema')
		);
		$this->assertSame(
			[
				['old' => 'Verplichting', 'new' => 'Commitment', 'rows' => 2],
				['old' => 'Goedkeuringsstap', 'new' => 'ApprovalStep', 'rows' => 1],
			],
			$infos
		);
		$this->assertContains('info: RenameCommitmentSchemas: "Verplichting" -> "Commitment".', $this->said);
		$this->assertContains('info: RenameCommitmentSchemas: "Mandate" -> "SpendingMandate".', $this->said);
		$this->assertSame('info: RenameCommitmentSchemas: renamed 3 of 8 schema slug(s).', end($this->said));

	}//end testRenamesOwnSchemasRetitlesStockTitlesAndRepointsObjects()

	/**
	 * When the old and the new slug both exist the pair is refused, not merged.
	 *
	 * @return void
	 */
	public function testRefusesToMergeWhenBothSlugsExist(): void {
		$this->registerSchemas = ['["1","2"]'];
		$this->tables = [
			'openregister_schemas' => [
				['id' => 1, 'slug' => 'Mandaat', 'title' => 'Mandaat'],
				['id' => 2, 'slug' => 'SpendingMandate', 'title' => 'SpendingMandate'],
			],
			'openregister_objects' => [['id' => 'o1', 'schema' => 'Mandaat']],
		];
		$this->logger->expects($this->once())->method('warning')->with(
			'RenameCommitmentSchemas: both the old and the new slug exist; refusing to merge.',
			['old' => 'Mandaat', 'new' => 'SpendingMandate']
		);

		$this->runStep();

		$this->assertSame(['1' => 'Mandaat', '2' => 'SpendingMandate'], $this->column('openregister_schemas', 'slug'));
		$this->assertSame(['o1' => 'Mandaat'], $this->column('openregister_objects', 'schema'));
		$this->assertContains(
			'warning: RenameCommitmentSchemas: "Mandaat" and "SpendingMandate" BOTH exist in the shillinq register. '
			. 'Refusing to merge them automatically — resolve the duplicate by hand.',
			$this->said
		);
		$this->assertSame('info: RenameCommitmentSchemas: renamed 0 of 8 schema slug(s).', end($this->said));

	}//end testRefusesToMergeWhenBothSlugsExist()

	/**
	 * A title that cannot be moved is logged and the rename still stands; without
	 * an objects table nothing is repointed.
	 *
	 * @return void
	 */
	public function testATitleFailureIsLoggedAndAMissingObjectsTableIsSkipped(): void {
		$this->registerSchemas = ['["7"]'];
		$this->titleFails = true;
		$this->objectsTable = false;
		$this->tables = [
			'openregister_schemas' => [['id' => 7, 'slug' => 'OpdrachtUitvoering', 'title' => 'OpdrachtUitvoering']],
		];
		$this->logger->expects($this->once())->method('warning')->with(
			'RenameCommitmentSchemas: could not move the schema title.',
			['old' => 'OpdrachtUitvoering', 'exception' => 'title column is locked']
		);
		$this->logger->expects($this->never())->method('info');

		$this->runStep();

		$this->assertSame(['7' => 'OrderFulfilment'], $this->column('openregister_schemas', 'slug'));
		$this->assertSame(['7' => 'OpdrachtUitvoering'], $this->column('openregister_schemas', 'title'));
		$this->assertSame('info: RenameCommitmentSchemas: renamed 1 of 8 schema slug(s).', end($this->said));

	}//end testATitleFailureIsLoggedAndAMissingObjectsTableIsSkipped()

	/**
	 * Register rows that are not JSON arrays, and empty or non-scalar ids, give no schema ids.
	 *
	 * @return void
	 */
	public function testUnusableRegisterRowsGiveNoSchemaIds(): void {
		$this->registerSchemas = ['not json', '"1"', '["", null, [2]]'];
		$this->tables = ['openregister_schemas' => [['id' => 1, 'slug' => 'Verplichting', 'title' => 'Verplichting']]];
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->runStep();

		$this->assertSame(['info: RenameCommitmentSchemas: no shillinq register found; nothing to do.'], $this->said);

	}//end testUnusableRegisterRowsGiveNoSchemaIds()

	/**
	 * A failed register lookup is logged and treated as no register.
	 *
	 * @return void
	 */
	public function testAFailedRegisterLookupIsLoggedAsNoRegister(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('executeQuery')->willThrowException(new DbException('registers table is gone'));
		$db->expects($this->never())->method('getQueryBuilder');
		$this->db = $db;
		$this->logger->expects($this->once())->method('warning')->with(
			'RenameCommitmentSchemas: could not resolve the shillinq register(s).',
			['exception' => 'registers table is gone']
		);

		$this->runStep();

		$this->assertSame(['info: RenameCommitmentSchemas: no shillinq register found; nothing to do.'], $this->said);

	}//end testAFailedRegisterLookupIsLoggedAsNoRegister()

	/**
	 * The step names itself for the upgrade log.
	 *
	 * @return void
	 */
	public function testName(): void {
		$this->assertSame(
			'Shillinq: rename the Dutch commitment schema slugs to English',
			(new RenameCommitmentSchemas($this->db, $this->logger))->getName()
		);

	}//end testName()
}//end class
