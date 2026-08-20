<?php

/**
 * Unit tests for BudgetSchemaSplitMigrator.
 *
 * Covers `budget-core-schema` task group 4 (REQ-BCS-003): classification of a
 * legacy `Budget` object into one of two target vocabularies
 * (`BbvProgrammeBudget`/`CommitmentBudget`), the object field-map, and the
 * source→target count guard that ABORTS the WHOLE batch — leaving source
 * data intact — the moment a single row is unclassifiable or the migrated
 * total does not equal the source count.
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
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Migration;

use OCA\Shillinq\Service\Migration\BudgetSchemaSplitMigrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the pure migration core: classify + field-map + count-abort.
 */
final class BudgetSchemaSplitMigratorTest extends TestCase {

	/**
	 * The migrator under test.
	 *
	 * @var BudgetSchemaSplitMigrator
	 */
	private BudgetSchemaSplitMigrator $migrator;

	/**
	 * Set up the migrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migrator = new BudgetSchemaSplitMigrator();

	}//end setUp()

	/**
	 * A BBV-vocabulary object (totalAmount + programmeStructure) classifies
	 * as BbvProgrammeBudget.
	 *
	 * @return void
	 */
	public function testClassifiesBbvVocabulary(): void {
		$object = [
			'budgetName' => 'Mobiliteit 2026',
			'totalAmount' => 500000.0,
			'programmeStructure' => 'mobiliteit',
			'status' => 'approved',
			'fiscalYear' => 2026,
			'administrationId' => 'adm-1',
		];

		$this->assertSame(
			BudgetSchemaSplitMigrator::TARGET_BBV,
			$this->migrator->classify($object)
		);

	}//end testClassifiesBbvVocabulary()

	/**
	 * A commitment-vocabulary object (authorised_amount + financialYear)
	 * classifies as CommitmentBudget.
	 *
	 * @return void
	 */
	public function testClassifiesCommitmentVocabulary(): void {
		$object = [
			'administrationId' => 'adm-1',
			'programmeCode' => '5.1',
			'financialYear' => 2026,
			'authorised_amount' => 50000000,
			'realised_amount' => 0,
		];

		$this->assertSame(
			BudgetSchemaSplitMigrator::TARGET_COMMITMENT,
			$this->migrator->classify($object)
		);

	}//end testClassifiesCommitmentVocabulary()

	/**
	 * A row carrying neither vocabulary's identifying fields classifies null
	 * (unclassifiable) rather than being guessed at.
	 *
	 * @return void
	 */
	public function testClassifiesMalformedRowAsNull(): void {
		$object = [
			'administrationId' => 'adm-1',
			'someUnrelatedField' => 'x',
		];

		$this->assertNull($this->migrator->classify($object));

	}//end testClassifiesMalformedRowAsNull()

	/**
	 * A row carrying BOTH vocabularies' identifying fields is pathological
	 * and classifies null rather than picking one arbitrarily.
	 *
	 * @return void
	 */
	public function testClassifiesAmbiguousRowAsNull(): void {
		$object = [
			'totalAmount' => 500000.0,
			'programmeStructure' => 'mobiliteit',
			'authorised_amount' => 50000000,
			'financialYear' => 2026,
		];

		$this->assertNull($this->migrator->classify($object));

	}//end testClassifiesAmbiguousRowAsNull()

	/**
	 * mapObjectToRenamedSchema re-points a matching object's @self.schema
	 * only, preserving every other field verbatim.
	 *
	 * @return void
	 */
	public function testMapRepointsMatchingObject(): void {
		$object = [
			'@self' => ['register' => 'shillinq', 'schema' => 'Budget', 'slug' => 'bbv-prov-budget-mobiliteit-2026'],
			'totalAmount' => 500000.0,
			'programmeStructure' => 'mobiliteit',
		];

		$migrated = $this->migrator->mapObjectToRenamedSchema($object, 'Budget', 'BbvProgrammeBudget');

		$this->assertSame('BbvProgrammeBudget', $migrated['@self']['schema']);
		$this->assertSame('shillinq', $migrated['@self']['register']);
		$this->assertSame('bbv-prov-budget-mobiliteit-2026', $migrated['@self']['slug']);
		$this->assertSame(500000.0, $migrated['totalAmount']);
		$this->assertSame('mobiliteit', $migrated['programmeStructure']);

	}//end testMapRepointsMatchingObject()

	/**
	 * A non-matching object (different schema) passes through unchanged.
	 *
	 * @return void
	 */
	public function testMapLeavesNonMatchingObjectUnchanged(): void {
		$object = ['@self' => ['schema' => 'BudgetBBVMapping'], 'x' => 1];
		$migrated = $this->migrator->mapObjectToRenamedSchema($object, 'Budget', 'BbvProgrammeBudget');

		$this->assertSame($object, $migrated);

	}//end testMapLeavesNonMatchingObjectUnchanged()

	/**
	 * migrateBatch classifies and re-points every classifiable object,
	 * splitting the result into BBV and Commitment buckets.
	 *
	 * @return void
	 */
	public function testMigrateBatchSplitsByVocabulary(): void {
		$source = [
			[
				'@self' => ['schema' => 'Budget', 'slug' => 'bbv-1'],
				'totalAmount' => 500000.0,
				'programmeStructure' => 'mobiliteit',
			],
			[
				'@self' => ['schema' => 'Budget', 'slug' => 'bbv-2'],
				'totalAmount' => 300000.0,
				'programmeStructure' => 'water',
			],
			[
				'@self' => ['schema' => 'Budget', 'slug' => 'commitment-1'],
				'authorised_amount' => 50000000,
				'financialYear' => 2026,
			],
		];

		$migrated = $this->migrator->migrateBatch($source);

		$this->assertCount(2, $migrated[BudgetSchemaSplitMigrator::TARGET_BBV]);
		$this->assertCount(1, $migrated[BudgetSchemaSplitMigrator::TARGET_COMMITMENT]);

		foreach ($migrated[BudgetSchemaSplitMigrator::TARGET_BBV] as $object) {
			$this->assertSame('BbvProgrammeBudget', $object['@self']['schema']);
		}

		foreach ($migrated[BudgetSchemaSplitMigrator::TARGET_COMMITMENT] as $object) {
			$this->assertSame('CommitmentBudget', $object['@self']['schema']);
		}

	}//end testMigrateBatchSplitsByVocabulary()

	/**
	 * A single unclassifiable row aborts the WHOLE batch — including the
	 * rows that classified cleanly — via assertCountsMatch's count mismatch.
	 *
	 * @return void
	 */
	public function testMigrateBatchAbortsOnUnclassifiableRow(): void {
		$source = [
			[
				'@self' => ['schema' => 'Budget', 'slug' => 'bbv-1'],
				'totalAmount' => 500000.0,
				'programmeStructure' => 'mobiliteit',
			],
			[
				'@self' => ['schema' => 'Budget', 'slug' => 'malformed-1'],
				'someUnrelatedField' => 'x',
			],
		];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('source data left intact');

		$this->migrator->migrateBatch($source);

	}//end testMigrateBatchAbortsOnUnclassifiableRow()

	/**
	 * assertCountsMatch is a no-op on equal counts.
	 *
	 * @return void
	 */
	public function testAssertCountsMatchAcceptsEqualCounts(): void {
		$this->migrator->assertCountsMatch(5, 5);
		$this->addToAssertionCount(1);

	}//end testAssertCountsMatchAcceptsEqualCounts()

	/**
	 * assertCountsMatch ABORTS (throws) on a mismatch — no-row-loss guard.
	 *
	 * @return void
	 */
	public function testAssertCountsMatchAbortsOnMismatch(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('source data left intact');
		$this->migrator->assertCountsMatch(5, 4);

	}//end testAssertCountsMatchAbortsOnMismatch()
}//end class
