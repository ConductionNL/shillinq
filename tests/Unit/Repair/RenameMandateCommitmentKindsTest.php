<?php

/**
 * Unit tests for RenameMandateCommitmentKinds.
 *
 * The step exists because a whole-cell equality UPDATE cannot reach a value
 * stored INSIDE a JSON array. These tests pin the translation itself — the
 * part that decides whether a stored mandate keeps matching commitments after
 * the Commitment.kind rename — plus the no-op and fail-soft behaviours that
 * make it safe to run unattended on every upgrade.
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

use OCA\Shillinq\Repair\RenameMandateCommitmentKinds;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for the Mandate.kind_commitment array rewrite.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
final class RenameMandateCommitmentKindsTest extends TestCase {

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mocked repair output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);

	}//end setUp()

	/**
	 * Call the private translate() with one stored cell.
	 *
	 * @param mixed $raw The stored cell.
	 *
	 * @return string|null The rewritten JSON, or null for "leave it alone".
	 */
	private function translate(mixed $raw): ?string {
		$step = new RenameMandateCommitmentKinds($this->db, $this->logger);
		$method = (new ReflectionClass($step))->getMethod('translate');
		$method->setAccessible(true);
		return $method->invoke($step, $raw);

	}//end translate()

	/**
	 * A Dutch member is translated and the list shape is preserved.
	 *
	 * @return void
	 */
	public function testTranslatesEveryDutchKind(): void {
		self::assertSame(
			'["purchase_order","employment_contract","grant_decision","lease_agreement"]',
			$this->translate('["inkooporder","arbeidscontract","subsidiebeschikking","huurovereenkomst"]')
		);

	}//end testTranslatesEveryDutchKind()

	/**
	 * Unknown members survive verbatim beside a translated one.
	 *
	 * `leasing` and `other` are already English members of the same enum, so a
	 * mandate mixing them with a Dutch value must keep both.
	 *
	 * @return void
	 */
	public function testPreservesUnmappedMembers(): void {
		self::assertSame(
			'["purchase_order","leasing","other"]',
			$this->translate('["inkooporder","leasing","other"]')
		);

	}//end testPreservesUnmappedMembers()

	/**
	 * An already-English list is left alone, which is what makes a re-run a no-op.
	 *
	 * @return void
	 */
	public function testAlreadyEnglishListIsUntouched(): void {
		self::assertNull($this->translate('["purchase_order","leasing"]'));

	}//end testAlreadyEnglishListIsUntouched()

	/**
	 * Shapes that are not a JSON list are skipped rather than coerced.
	 *
	 * An empty list already means "any kind" to MandateEnforcer, so rewriting
	 * one of these would change meaning rather than preserve it.
	 *
	 * @return void
	 */
	public function testNonListShapesAreSkipped(): void {
		self::assertNull($this->translate(null), 'null');
		self::assertNull($this->translate(''), 'empty string');
		self::assertNull($this->translate('[]'), 'empty list');
		self::assertNull($this->translate('not json'), 'unparseable');
		self::assertNull($this->translate('"inkooporder"'), 'bare scalar');
		self::assertNull($this->translate('{"kind":"inkooporder"}'), 'object, not a list');

	}//end testNonListShapesAreSkipped()

	/**
	 * A numerically-keyed JSON object IS treated as a list, deliberately.
	 *
	 * `{"0":"inkooporder"}` and `["inkooporder"]` both decode to the same PHP
	 * value under `json_decode($raw, true)`, so no guard can tell them apart
	 * after decoding. Rewriting it normalises the cell to a real JSON list,
	 * which is the shape OpenRegister writes anyway — recording the behaviour
	 * here so it reads as a decision rather than an accident.
	 *
	 * @return void
	 */
	public function testNumericallyKeyedObjectIsNormalisedToAList(): void {
		self::assertSame('["purchase_order"]', $this->translate('{"0":"inkooporder"}'));

	}//end testNumericallyKeyedObjectIsNormalisedToAList()

	/**
	 * The map matches the Commitment.kind rename exactly.
	 *
	 * If the scalar column and this array ever disagree, a mandate stops
	 * matching its own commitments and nothing errors — so the two vocabularies
	 * are pinned to each other here.
	 *
	 * @return void
	 */
	public function testMapMatchesTheCommitmentKindRename(): void {
		$map = (new ReflectionClass(RenameMandateCommitmentKinds::class))->getConstant('KIND_MAP');

		self::assertSame(
			[
				'inkooporder' => 'purchase_order',
				'arbeidscontract' => 'employment_contract',
				'subsidiebeschikking' => 'grant_decision',
				'huurovereenkomst' => 'lease_agreement',
			],
			$map
		);

	}//end testMapMatchesTheCommitmentKindRename()

	/**
	 * Every target value is a member of the Commitment.kind enum the fragment declares.
	 *
	 * This is the assertion that catches the two halves drifting: a target that
	 * the schema does not accept would migrate stored data to a value the
	 * register rejects.
	 *
	 * @return void
	 */
	public function testEveryTargetIsDeclaredByTheFragment(): void {
		$fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json';
		self::assertFileExists($fragmentPath);

		$fragment = json_decode((string)file_get_contents($fragmentPath), true);
		$enum = ($fragment['components']['schemas']['Commitment']['properties']['kind']['enum'] ?? []);
		self::assertNotEmpty($enum, 'Commitment.kind must declare an enum');

		$map = (new ReflectionClass(RenameMandateCommitmentKinds::class))->getConstant('KIND_MAP');
		foreach ($map as $old => $new) {
			self::assertContains($new, $enum, "Commitment.kind must declare the migration target '$new'");
			self::assertNotContains($old, $enum, "Commitment.kind must no longer declare the Dutch '$old'");
		}

	}//end testEveryTargetIsDeclaredByTheFragment()

	/**
	 * A \Throwable is swallowed so the upgrade is never blocked.
	 *
	 * @return void
	 */
	public function testFailSoftOnUnexpectedError(): void {
		$this->db->method('executeQuery')->willThrowException(new \RuntimeException('db is gone'));

		$this->logger->expects($this->once())->method('warning');
		$this->output->expects($this->once())
			->method('warning')
			->with($this->stringContains('db is gone'));

		(new RenameMandateCommitmentKinds($this->db, $this->logger))->run($this->output);

	}//end testFailSoftOnUnexpectedError()

	/**
	 * Wire the mocks for one shard table carrying the given rows.
	 *
	 * `shardTablesWithColumn()` asks the registers table for shillinq's ids,
	 * then information_schema for tables carrying the column; `rewriteTable()`
	 * then SELECTs the rows. Both go through executeQuery/prepare, so the mock
	 * dispatches on the SQL.
	 *
	 * @param array<int, string>              $registerIds Register ids to report.
	 * @param array<int, array<string, mixed>> $tableRows  information_schema rows.
	 * @param array<int, array<string, mixed>> $dataRows   Mandate rows.
	 *
	 * @return void
	 */
	private function wireDb(array $registerIds, array $tableRows, array $dataRows): void {
		$registerResult = $this->createMock(\OCP\DB\IResult::class);
		$registerResult->method('fetchAll')->willReturn($registerIds);

		$dataResult = $this->createMock(\OCP\DB\IResult::class);
		$dataResult->method('fetchAll')->willReturn($dataRows);

		$this->db->method('executeQuery')->willReturnCallback(
			static function (string $sql) use ($registerResult, $dataResult) {
				if (str_contains($sql, 'openregister_registers') === true) {
					return $registerResult;
				}

				return $dataResult;
			}
		);

		$stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$queue = $tableRows;
		$stmt->method('fetch')->willReturnCallback(
			static function () use (&$queue) {
				if ($queue === []) {
					return false;
				}

				return array_shift($queue);
			}
		);
		$this->db->method('prepare')->willReturn($stmt);

	}//end wireDb()

	/**
	 * A Dutch row is rewritten and counted.
	 *
	 * @return void
	 */
	public function testRunRewritesADutchRow(): void {
		$this->wireDb(
			['14'],
			[['table_name' => 'oc_openregister_table_14_99']],
			[['id' => 7, 'kinds' => '["inkooporder","leasing"]']]
		);

		$written = [];
		$this->db->method('executeStatement')->willReturnCallback(
			static function (string $sql, array $params) use (&$written): int {
				$written[] = $params;
				return 1;
			}
		);

		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('rewrote 1 mandate row'));

		(new RenameMandateCommitmentKinds($this->db, $this->logger))->run($this->output);

		self::assertCount(1, $written);
		self::assertSame('["purchase_order","leasing"]', $written[0][0]);
		self::assertSame(7, $written[0][1]);

	}//end testRunRewritesADutchRow()

	/**
	 * An already-English row is not written at all.
	 *
	 * This is the idempotency guarantee at the step level, not just in
	 * translate(): a re-run must issue no UPDATE.
	 *
	 * @return void
	 */
	public function testRunWritesNothingWhenAlreadyEnglish(): void {
		$this->wireDb(
			['14'],
			[['table_name' => 'oc_openregister_table_14_99']],
			[['id' => 7, 'kinds' => '["purchase_order","leasing"]']]
		);

		$this->db->expects($this->never())->method('executeStatement');
		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('rewrote 0 mandate row'));

		(new RenameMandateCommitmentKinds($this->db, $this->logger))->run($this->output);

	}//end testRunWritesNothingWhenAlreadyEnglish()

	/**
	 * A table belonging to ANOTHER register is never touched.
	 *
	 * The shard table name embeds the register id, so a same-named column in a
	 * different register must not be reachable.
	 *
	 * @return void
	 */
	public function testForeignRegisterTablesAreIgnored(): void {
		$this->wireDb(
			['14'],
			[['table_name' => 'oc_openregister_table_77_99']],
			[['id' => 7, 'kinds' => '["inkooporder"]']]
		);

		$this->db->expects($this->never())->method('executeStatement');
		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('no shillinq mandate table found'));

		(new RenameMandateCommitmentKinds($this->db, $this->logger))->run($this->output);

	}//end testForeignRegisterTablesAreIgnored()

	/**
	 * With no shillinq register the step reports and writes nothing.
	 *
	 * @return void
	 */
	public function testNoOpWhenNoShillinqRegisterExists(): void {
		$this->wireDb([], [], []);

		$this->db->expects($this->never())->method('executeStatement');
		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('no shillinq mandate table found'));

		(new RenameMandateCommitmentKinds($this->db, $this->logger))->run($this->output);

	}//end testNoOpWhenNoShillinqRegisterExists()

	/**
	 * A table that cannot be READ is logged and skipped, not fatal.
	 *
	 * One unreadable shard must not abort the others, and must not be counted
	 * as migrated.
	 *
	 * @return void
	 */
	public function testUnreadableTableIsLoggedAndSkipped(): void {
		$registerResult = $this->createMock(\OCP\DB\IResult::class);
		$registerResult->method('fetchAll')->willReturn(['14']);

		$this->db->method('executeQuery')->willReturnCallback(
			static function (string $sql) use ($registerResult) {
				if (str_contains($sql, 'openregister_registers') === true) {
					return $registerResult;
				}

				throw new \OCP\DB\Exception('table is gone');
			}
		);

		$stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$rows = [['table_name' => 'oc_openregister_table_14_99']];
		$stmt->method('fetch')->willReturnCallback(
			static function () use (&$rows) {
				return ($rows === [] ? false : array_shift($rows));
			}
		);
		$this->db->method('prepare')->willReturn($stmt);

		$this->db->expects($this->never())->method('executeStatement');
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not read a mandate table'));
		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('rewrote 0 mandate row'));

		(new RenameMandateCommitmentKinds($this->db, $this->logger))->run($this->output);

	}//end testUnreadableTableIsLoggedAndSkipped()

	/**
	 * A row that cannot be WRITTEN is logged and not counted.
	 *
	 * A partial failure must report the true number migrated — reporting the
	 * attempted count would make a half-done migration read as complete.
	 *
	 * @return void
	 */
	public function testUnwritableRowIsLoggedAndNotCounted(): void {
		$this->wireDb(
			['14'],
			[['table_name' => 'oc_openregister_table_14_99']],
			[['id' => 7, 'kinds' => '["inkooporder"]']]
		);

		$this->db->method('executeStatement')->willThrowException(new \OCP\DB\Exception('read only'));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not write a mandate row'));
		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('rewrote 0 mandate row'));

		(new RenameMandateCommitmentKinds($this->db, $this->logger))->run($this->output);

	}//end testUnwritableRowIsLoggedAndNotCounted()

	/**
	 * An unresolvable register list is logged and yields no work.
	 *
	 * @return void
	 */
	public function testUnresolvableRegistersAreLoggedAndSkipped(): void {
		$this->db->method('executeQuery')->willThrowException(new \OCP\DB\Exception('no registers table'));

		$this->db->expects($this->never())->method('executeStatement');
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not resolve the shillinq register'));
		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('no shillinq mandate table found'));

		(new RenameMandateCommitmentKinds($this->db, $this->logger))->run($this->output);

	}//end testUnresolvableRegistersAreLoggedAndSkipped()

	/**
	 * A failing information_schema probe is logged and yields no work.
	 *
	 * @return void
	 */
	public function testUnlistableColumnsAreLoggedAndSkipped(): void {
		$registerResult = $this->createMock(\OCP\DB\IResult::class);
		$registerResult->method('fetchAll')->willReturn(['14']);
		$this->db->method('executeQuery')->willReturn($registerResult);
		$this->db->method('prepare')->willThrowException(new \RuntimeException('no information_schema'));

		$this->db->expects($this->never())->method('executeStatement');
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not list columns'));

		(new RenameMandateCommitmentKinds($this->db, $this->logger))->run($this->output);

	}//end testUnlistableColumnsAreLoggedAndSkipped()

	/**
	 * The step is registered, and AFTER RenameDutchValues.
	 *
	 * @return void
	 */
	public function testRegisteredAfterRenameDutchValues(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		$values = strpos($info, 'Repair\RenameDutchValues</step>');
		$kinds = strpos($info, 'Repair\RenameMandateCommitmentKinds</step>');

		self::assertNotFalse($kinds, 'RenameMandateCommitmentKinds must be registered as a repair step');
		self::assertNotFalse($values, 'RenameDutchValues must be registered as a repair step');
		self::assertGreaterThan(
			$values,
			$kinds,
			'RenameMandateCommitmentKinds must run AFTER RenameDutchValues'
		);

	}//end testRegisteredAfterRenameDutchValues()
}//end class
