<?php

/**
 * Unit tests for GlLineAdministrationBackfillMigrator.
 *
 * Covers `glline-administration-scope` REQ-GLS-002: resolution of a `GLLine`'s
 * administration through its parent `GLTransaction` (by object id OR by
 * `transactionNumber`, because this repo's writers use both idioms), the
 * seen→classified count guard that ABORTS THE WHOLE BATCH — leaving every row
 * intact, including the ones that resolved cleanly — the moment a single row is
 * unclassifiable, idempotency of a re-run, and the total (never sampled)
 * completeness count that gates the SpendAnalytics filter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Migration;

use OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the pure migration core: resolve + stamp + count-abort + completeness.
 *
 * @covers \OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator
 */
final class GlLineAdministrationBackfillMigratorTest extends TestCase {

	/**
	 * The migrator under test.
	 *
	 * @var GlLineAdministrationBackfillMigrator
	 */
	private GlLineAdministrationBackfillMigrator $migrator;

	/**
	 * Set up the migrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migrator = new GlLineAdministrationBackfillMigrator();

	}//end setUp()

	/**
	 * Two transactions in two different administrations.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function transactions(): array {
		return [
			['id' => 'tx-a', 'transactionNumber' => 'GL-2026-A', 'administrationId' => 'ADM-A'],
			['id' => 'tx-b', 'transactionNumber' => 'GL-2026-B', 'administrationId' => 'ADM-B'],
		];
	}//end transactions()

	/**
	 * A GLLine resolves through its parent's object id.
	 *
	 * @return void
	 */
	public function testResolvesByObjectId(): void {
		$index = $this->migrator->indexAdministrationsByTransaction($this->transactions());

		$this->assertSame(
			'ADM-A',
			$this->migrator->resolveAdministrationId(['transactionId' => 'tx-a'], $index)
		);
	}//end testResolvesByObjectId()

	/**
	 * A GLLine resolves through its parent's transactionNumber too.
	 *
	 * RuleTestDataSeeder writes `transactionId` as the business key when the
	 * transaction has one. A migrator that indexed only object UUIDs would
	 * declare every one of those lines unclassifiable and abort the batch —
	 * which is fail-closed, but wrongly so.
	 *
	 * @return void
	 */
	public function testResolvesByTransactionNumber(): void {
		$index = $this->migrator->indexAdministrationsByTransaction($this->transactions());

		$this->assertSame(
			'ADM-B',
			$this->migrator->resolveAdministrationId(['transactionId' => 'GL-2026-B'], $index)
		);
	}//end testResolvesByTransactionNumber()

	/**
	 * A parent that carries no administration of its own indexes NOTHING.
	 *
	 * Stamping `''` would manufacture a scope that then satisfies the
	 * completeness gate while matching no filter value — the exact
	 * fake-completeness failure the gate exists to catch.
	 *
	 * @return void
	 */
	public function testUnscopedParentIndexesNothing(): void {
		$index = $this->migrator->indexAdministrationsByTransaction(
			[['id' => 'tx-x', 'administrationId' => '']]
		);

		$this->assertSame([], $index);
		$this->assertNull(
			$this->migrator->resolveAdministrationId(['transactionId' => 'tx-x'], $index)
		);
	}//end testUnscopedParentIndexesNothing()

	/**
	 * The happy path: every line is stamped with its own parent's scope, and
	 * lines of different parents get DIFFERENT scopes.
	 *
	 * @return void
	 */
	public function testBackfillsEachLineFromItsOwnParent(): void {
		$lines = [
			['id' => 'l1', 'transactionId' => 'tx-a', 'amount' => 10.0],
			['id' => 'l2', 'transactionId' => 'GL-2026-B', 'amount' => 20.0],
		];

		$report = $this->migrator->backfillBatch($lines, $this->transactions());

		$this->assertSame(2, $report['total']);
		$this->assertSame(2, $report['backfilled']);
		$this->assertSame(0, $report['unclassifiable']);
		$this->assertSame('ADM-A', $report['lines'][0]['administrationId']);
		$this->assertSame('ADM-B', $report['lines'][1]['administrationId']);
		// Every other field is preserved verbatim.
		$this->assertSame(10.0, $report['lines'][0]['amount']);
		$this->assertSame('l2', $report['lines'][1]['id']);
	}//end testBackfillsEachLineFromItsOwnParent()

	/**
	 * THE FAIL-CLOSED CONTROL (REQ-GLS-002). One line whose parent is missing
	 * aborts the WHOLE batch — including the line that resolved cleanly — so
	 * the ledger is never left half-scoped.
	 *
	 * @return void
	 */
	public function testOneMissingParentAbortsTheWholeBatch(): void {
		$lines = [
			['id' => 'l1', 'transactionId' => 'tx-a'],
			['id' => 'l2', 'transactionId' => 'tx-does-not-exist'],
		];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('source data left intact');

		$this->migrator->backfillBatch($lines, $this->transactions());
	}//end testOneMissingParentAbortsTheWholeBatch()

	/**
	 * A line with no transactionId at all is unclassifiable, never defaulted.
	 *
	 * @return void
	 */
	public function testOrphanLineIsNeverGuessed(): void {
		$this->expectException(RuntimeException::class);

		$this->migrator->backfillBatch([['id' => 'l1']], $this->transactions());
	}//end testOrphanLineIsNeverGuessed()

	/**
	 * Re-running over an already-backfilled register changes no row.
	 *
	 * @return void
	 */
	public function testReRunIsIdempotent(): void {
		$lines = [
			['id' => 'l1', 'transactionId' => 'tx-a', 'administrationId' => 'ADM-A'],
			['id' => 'l2', 'transactionId' => 'tx-b', 'administrationId' => 'ADM-B'],
		];

		$report = $this->migrator->backfillBatch($lines, $this->transactions());

		$this->assertSame([], $report['lines'], 'A re-run must propose no writes.');
		$this->assertSame(2, $report['unchanged']);
		$this->assertSame(0, $report['backfilled']);
	}//end testReRunIsIdempotent()

	/**
	 * An already-scoped line that disagrees with its parent is REPORTED, never
	 * silently re-pointed to another administration.
	 *
	 * @return void
	 */
	public function testDisagreeingLineIsReportedNotRewritten(): void {
		$lines = [['id' => 'l1', 'transactionId' => 'tx-a', 'administrationId' => 'ADM-B']];

		$report = $this->migrator->backfillBatch($lines, $this->transactions());

		$this->assertSame(1, $report['conflicting']);
		$this->assertSame([], $report['lines']);
	}//end testDisagreeingLineIsReportedNotRewritten()

	/**
	 * stampAdministrationId() never overwrites an existing scope.
	 *
	 * @return void
	 */
	public function testStampNeverOverwritesAnExistingScope(): void {
		$line = ['id' => 'l1', 'administrationId' => 'ADM-A'];

		$this->assertSame($line, $this->migrator->stampAdministrationId($line, 'ADM-B'));
	}//end testStampNeverOverwritesAnExistingScope()

	/**
	 * The completeness count is a TOTAL over every row handed to it, and
	 * treats a blank/whitespace scope as missing.
	 *
	 * @return void
	 */
	public function testCountMissingIsATotalOverEveryRow(): void {
		$rows = [
			['administrationId' => 'ADM-A'],
			['administrationId' => ''],
			[],
			['administrationId' => '   '],
			['administrationId' => 'ADM-B'],
		];

		$this->assertSame(3, $this->migrator->countMissingAdministrationId($rows));
		$this->assertSame(0, $this->migrator->countMissingAdministrationId([]));
	}//end testCountMissingIsATotalOverEveryRow()

	/**
	 * assertCountsMatch() is the guard itself: equal counts pass, any
	 * shortfall throws.
	 *
	 * @return void
	 */
	public function testAssertCountsMatchThrowsOnShortfall(): void {
		$this->migrator->assertCountsMatch(3, 3);

		$this->expectException(RuntimeException::class);
		$this->migrator->assertCountsMatch(3, 2);
	}//end testAssertCountsMatchThrowsOnShortfall()
}//end class
