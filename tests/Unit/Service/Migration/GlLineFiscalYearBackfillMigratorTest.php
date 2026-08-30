<?php

/**
 * Unit tests for GlLineFiscalYearBackfillMigrator.
 *
 * The migrator is pure — no store, no OpenRegister — so these tests exercise
 * the real resolution logic rather than a fixture that agrees with it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Migration;

use OCA\Shillinq\Service\Migration\GlLineFiscalYearBackfillMigrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * No `covers` metadata, deliberately — `beStrictAboutCoverageMetadata="true"`
 * discards the coverage of any test that touches a collaborator it did not
 * name.
 */
class GlLineFiscalYearBackfillMigratorTest extends TestCase {

	private GlLineFiscalYearBackfillMigrator $migrator;

	protected function setUp(): void {
		parent::setUp();
		$this->migrator = new GlLineFiscalYearBackfillMigrator();
	}

	/**
	 * @test
	 * A transaction carrying no fiscal year is not indexed at all.
	 *
	 * Indexing it against `''` would let classify() call a line resolved while
	 * stamping an empty year — a row that counts as backfilled and answers
	 * nothing, which is the exact failure the whole step exists to avoid.
	 */
	public function testTransactionWithoutFiscalYearIsNotIndexed(): void {
		$index = $this->migrator->indexFiscalYearsByTransaction([
			['id' => 'tx-1', 'fiscalYearId' => 'fy-2026-nl'],
			['id' => 'tx-2', 'fiscalYearId' => ''],
			['id' => 'tx-3'],
			['id' => 'tx-4', 'fiscalYearId' => '   '],
		]);

		$this->assertSame(['tx-1' => 'fy-2026-nl'], $index);
	}

	/**
	 * @test
	 * A transaction is indexed under every identity a line might reference it by.
	 */
	public function testTransactionIndexedUnderEveryIdentity(): void {
		$index = $this->migrator->indexFiscalYearsByTransaction([
			[
				'id' => 'tx-1',
				'@self' => ['id' => 'self-1'],
				'uuid' => 'uuid-1',
				'transactionNumber' => 'TX-0001',
				'fiscalYearId' => 'fy-2026-nl',
			],
		]);

		foreach (['tx-1', 'self-1', 'uuid-1', 'TX-0001'] as $identity) {
			$this->assertArrayHasKey($identity, $index, "identity $identity must resolve");
			$this->assertSame('fy-2026-nl', $index[$identity]);
		}
	}

	/**
	 * @test
	 * A line with no resolvable parent is UNRESOLVABLE, not silently stamped.
	 */
	public function testUnresolvableLineIsClassifiedNotStamped(): void {
		$index = $this->migrator->indexFiscalYearsByTransaction([
			['id' => 'tx-1', 'fiscalYearId' => 'fy-2026-nl'],
		]);

		$this->assertSame(
			GlLineFiscalYearBackfillMigrator::CLASS_UNRESOLVABLE,
			$this->migrator->classify(['transactionId' => 'tx-missing'], $index)
		);
		$this->assertSame(
			GlLineFiscalYearBackfillMigrator::CLASS_UNRESOLVABLE,
			$this->migrator->classify(['transactionId' => ''], $index)
		);
		$this->assertSame(
			GlLineFiscalYearBackfillMigrator::CLASS_RESOLVED,
			$this->migrator->classify(['transactionId' => 'tx-1'], $index)
		);
	}

	/**
	 * @test
	 * A line that already carries a fiscal year is never rewritten.
	 *
	 * Re-pointing a posted line to a different year is a bigger decision than
	 * a backfill gets to make, so the existing value wins even when the parent
	 * now disagrees.
	 */
	public function testExistingFiscalYearIsNeverOverwritten(): void {
		$line = ['transactionId' => 'tx-1', 'fiscalYearId' => 'fy-2025-nl'];

		$stamped = $this->migrator->stampFiscalYearId($line, 'fy-2026-nl');

		$this->assertSame('fy-2025-nl', $stamped['fiscalYearId']);
		$this->assertSame($line, $stamped, 'the row must come back byte-identical');
	}

	/**
	 * @test
	 * Stamping preserves every other field on the row.
	 */
	public function testStampPreservesEveryOtherField(): void {
		$line = [
			'transactionId' => 'tx-1',
			'amount' => 125.50,
			'side' => 'debit',
			'accountNumber' => '4000',
		];

		$stamped = $this->migrator->stampFiscalYearId($line, 'fy-2026-nl');

		$this->assertSame('fy-2026-nl', $stamped['fiscalYearId']);
		foreach ($line as $key => $value) {
			$this->assertSame($value, $stamped[$key], "field $key must survive");
		}
	}

	/**
	 * @test
	 * One unresolvable row does NOT abort the batch.
	 *
	 * This is the deliberate difference from the administration backfill, where
	 * an unclassifiable row aborts everything because a half-scoped ledger makes
	 * a tenant filter return a silent zero. A fiscal year is a GROUPING key: an
	 * unresolved row shows up as a null bucket, which is visible. Aborting here
	 * would trade a visible gap for no backfill at all.
	 */
	public function testUnresolvableRowDoesNotAbortTheBatch(): void {
		$report = $this->migrator->backfillBatch(
			[
				['transactionId' => 'tx-1'],
				['transactionId' => 'tx-orphan'],
				['transactionId' => 'tx-1', 'fiscalYearId' => 'fy-2024-nl'],
			],
			[['id' => 'tx-1', 'fiscalYearId' => 'fy-2026-nl']]
		);

		$this->assertSame(3, $report['seen']);
		$this->assertSame(1, $report['stamped']);
		$this->assertSame(1, $report['alreadyStamped']);
		$this->assertSame(1, $report['unresolvable']);

		// Only the resolvable row is returned for writing, keyed by its source
		// offset so the caller can pair it with the object it came from.
		$this->assertSame([0], array_keys($report['lines']));
		$this->assertSame('fy-2026-nl', $report['lines'][0]['fiscalYearId']);
	}

	/**
	 * @test
	 * A parent that disagrees with an already-stamped line is REPORTED.
	 *
	 * Silently keeping either value hides a real inconsistency in posted books.
	 */
	public function testDisagreementWithParentIsReported(): void {
		$report = $this->migrator->backfillBatch(
			[['transactionId' => 'tx-1', 'fiscalYearId' => 'fy-2024-nl']],
			[['id' => 'tx-1', 'fiscalYearId' => 'fy-2026-nl']]
		);

		$this->assertSame(1, $report['alreadyStamped']);
		$this->assertCount(1, $report['disagreements']);
		$this->assertStringContainsString('fy-2024-nl', $report['disagreements'][0]);
		$this->assertStringContainsString('fy-2026-nl', $report['disagreements'][0]);
		$this->assertSame([], $report['lines'], 'nothing may be rewritten');
	}

	/**
	 * @test
	 * A second run over already-backfilled rows writes nothing.
	 */
	public function testSecondRunIsANoOp(): void {
		$lines = [['transactionId' => 'tx-1']];
		$transactions = [['id' => 'tx-1', 'fiscalYearId' => 'fy-2026-nl']];

		$first = $this->migrator->backfillBatch($lines, $transactions);
		$this->assertSame(1, $first['stamped']);

		$second = $this->migrator->backfillBatch(array_values($first['lines']), $transactions);

		$this->assertSame(0, $second['stamped'], 'a re-run must write nothing');
		$this->assertSame(1, $second['alreadyStamped']);
		$this->assertSame([], $second['lines']);
	}

	/**
	 * @test
	 * countMissingFiscalYearId() reports a TOTAL, including blank strings.
	 */
	public function testCountMissingCountsBlanksAsMissing(): void {
		$this->assertSame(3, $this->migrator->countMissingFiscalYearId([
			['fiscalYearId' => 'fy-2026-nl'],
			['fiscalYearId' => ''],
			['fiscalYearId' => '   '],
			[],
		]));
	}

	/**
	 * @test
	 * A report that accounts for fewer rows than were seen is refused.
	 */
	public function testCountMismatchThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/classified/');

		$this->migrator->assertCountsMatch(sourceCount: 10, classifiedCount: 9);
	}
}//end class
