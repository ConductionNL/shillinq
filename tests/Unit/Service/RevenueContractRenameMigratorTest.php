<?php

/**
 * Unit tests for RevenueContractRenameMigrator.
 *
 * Covers the contracts-single-home migration core (tasks.md §4): the
 * CLM-vs-IFRS-15 discriminator, the object field-map (re-point only
 * IFRS-15-shaped objects to RevenueContract), the source→target count guard
 * that ABORTS with the source intact on a mismatch, and idempotency of a
 * second run.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Migration\RevenueContractRenameMigrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the pure migration core: CLM/IFRS-15 discriminator + field-map +
 * count-abort + idempotency.
 */
final class RevenueContractRenameMigratorTest extends TestCase {

	/**
	 * The migrator under test.
	 *
	 * @var RevenueContractRenameMigrator
	 */
	private RevenueContractRenameMigrator $migrator;

	/**
	 * Set up the migrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migrator = new RevenueContractRenameMigrator();

	}//end setUp()

	/**
	 * The rename topology is Contract → RevenueContract.
	 *
	 * @return void
	 */
	public function testRenameTopology(): void {
		$this->assertSame(
			['from' => 'Contract', 'to' => 'RevenueContract'],
			$this->migrator->revenueContractRename()
		);

	}//end testRenameTopology()

	/**
	 * The discriminator correctly separates a pure IFRS-15-shaped object from
	 * a pure CLM-shaped object.
	 *
	 * @return void
	 */
	public function testDiscriminatorSeparatesClmAndIfrs15Shapes(): void {
		$ifrs15Shaped = [
			'contractNumber' => 'C-2026-001',
			'customerId' => 'contact-abc-saas-bv',
			'signedAt' => '2026-01-01',
			'fixedConsideration' => 360000.0,
			'lifecycleState' => 'in-delivery',
			'administrationId' => 'adm-saas-1',
		];
		$clmShaped = [
			'contractNumber' => 'C-2026-007',
			'title' => 'Office cleaning services 2026',
			'contractType' => 'service',
			'status' => 'active',
			'administrationId' => 'adm-shillinq-demo',
		];

		$this->assertTrue($this->migrator->isIfrs15Shaped($ifrs15Shaped));
		$this->assertFalse($this->migrator->isIfrs15Shaped($clmShaped));

	}//end testDiscriminatorSeparatesClmAndIfrs15Shapes()

	/**
	 * A CLM-shaped object that ALSO carries IFRS-15 leftover fields (e.g. the
	 * semantic-invoice-consume handoff-demo seed, riding on the pre-fix merged
	 * schema) is NOT IFRS-15-shaped — CLM fields (contractType/status) take
	 * precedence over any co-present IFRS-15 fields (design.md §D3).
	 *
	 * @return void
	 */
	public function testClmFieldsTakePrecedenceOverCoPresentIfrs15Fields(): void {
		$handoffDemoShaped = [
			'contractNumber' => 'CT-2026-HANDOFF-001',
			'title' => 'Adviesdiensten Gemeente Voorbeeld (DEMO)',
			'contractType' => 'sales',
			'status' => 'draft',
			// Leftover IFRS-15 fields that used to be forced on by the merged
			// `required` list; still present on a not-yet-cleaned object.
			'customerId' => 'contact-gemeente-voorbeeld',
			'signedAt' => '2026-06-30',
			'fixedConsideration' => 48000,
			'lifecycleState' => 'draft',
		];

		$this->assertFalse($this->migrator->isIfrs15Shaped($handoffDemoShaped));

	}//end testClmFieldsTakePrecedenceOverCoPresentIfrs15Fields()

	/**
	 * An object with neither CLM nor IFRS-15 discriminator fields is not
	 * IFRS-15-shaped (fails closed — never renamed on ambiguous/insufficient
	 * evidence).
	 *
	 * @return void
	 */
	public function testNeitherShapeIsNotIfrs15Shaped(): void {
		$this->assertFalse($this->migrator->isIfrs15Shaped(['contractNumber' => 'C-2026-999']));

	}//end testNeitherShapeIsNotIfrs15Shaped()

	/**
	 * mapObjectToRenamedSchema re-points an IFRS-15-shaped matching object's
	 * @self.schema only, preserving every other field verbatim.
	 *
	 * @return void
	 */
	public function testMapRepointsIfrs15ShapedMatchingObject(): void {
		$object = [
			'@self' => ['register' => 'shillinq', 'schema' => 'Contract', 'slug' => 'ifrs15-contract-c2026-001'],
			'contractNumber' => 'C-2026-001',
			'customerId' => 'contact-abc-saas-bv',
			'fixedConsideration' => 360000.0,
			'lifecycleState' => 'in-delivery',
		];

		$migrated = $this->migrator->mapObjectToRenamedSchema($object, 'Contract', 'RevenueContract');

		$this->assertSame('RevenueContract', $migrated['@self']['schema']);
		// Every other field is preserved verbatim.
		$this->assertSame('shillinq', $migrated['@self']['register']);
		$this->assertSame('ifrs15-contract-c2026-001', $migrated['@self']['slug']);
		$this->assertSame('C-2026-001', $migrated['contractNumber']);
		$this->assertSame('contact-abc-saas-bv', $migrated['customerId']);
		$this->assertSame(360000.0, $migrated['fixedConsideration']);
		$this->assertSame('in-delivery', $migrated['lifecycleState']);

	}//end testMapRepointsIfrs15ShapedMatchingObject()

	/**
	 * A CLM-shaped object under the same `Contract` slug is left unchanged —
	 * it is not renamed even though the slug matches, because the
	 * discriminator says it is not an IFRS-15 revenue contract.
	 *
	 * @return void
	 */
	public function testMapLeavesClmShapedObjectUnchanged(): void {
		$object = [
			'@self' => ['register' => 'shillinq', 'schema' => 'Contract', 'slug' => 'contract-cleaning-2026'],
			'contractNumber' => 'C-2026-007',
			'contractType' => 'service',
			'status' => 'expiring',
		];

		$migrated = $this->migrator->mapObjectToRenamedSchema($object, 'Contract', 'RevenueContract');

		$this->assertSame('Contract', $migrated['@self']['schema']);
		$this->assertSame($object, $migrated);

	}//end testMapLeavesClmShapedObjectUnchanged()

	/**
	 * A non-matching object (different schema entirely) passes through
	 * unchanged.
	 *
	 * @return void
	 */
	public function testMapLeavesNonMatchingSchemaObjectUnchanged(): void {
		$object = ['@self' => ['schema' => 'SalesOrder'], 'customerId' => 'x'];
		$migrated = $this->migrator->mapObjectToRenamedSchema($object, 'Contract', 'RevenueContract');

		$this->assertSame('SalesOrder', $migrated['@self']['schema']);
		$this->assertSame($object, $migrated);

	}//end testMapLeavesNonMatchingSchemaObjectUnchanged()

	/**
	 * migrateBatch re-points only the IFRS-15-shaped objects, leaves the
	 * CLM-shaped ones alone, and preserves the total count (no row loss).
	 *
	 * @return void
	 */
	public function testMigrateBatchRepointsOnlyIfrs15ShapedObjects(): void {
		$source = [
			[
				'@self' => ['schema' => 'Contract'],
				'contractNumber' => 'C-2026-001',
				'customerId' => 'contact-abc',
				'fixedConsideration' => 100.0,
				'lifecycleState' => 'draft',
			],
			[
				'@self' => ['schema' => 'Contract'],
				'contractNumber' => 'C-2026-007',
				'contractType' => 'service',
				'status' => 'active',
			],
			[
				'@self' => ['schema' => 'Contract'],
				'contractNumber' => 'CT-2026-HANDOFF-001',
				'contractType' => 'sales',
				'status' => 'draft',
				'customerId' => 'contact-gemeente-voorbeeld',
			],
		];

		$migrated = $this->migrator->migrateBatch($source, 'Contract', 'RevenueContract');

		$this->assertCount(3, $migrated, 'no object is dropped');
		$this->assertSame('RevenueContract', $migrated[0]['@self']['schema'], 'IFRS-15-shaped object renamed');
		$this->assertSame('Contract', $migrated[1]['@self']['schema'], 'CLM-shaped object left alone');
		$this->assertSame('Contract', $migrated[2]['@self']['schema'], 'CLM+leftover-fields object left alone');

	}//end testMigrateBatchRepointsOnlyIfrs15ShapedObjects()

	/**
	 * A second, idempotent run over an already-migrated batch (now all
	 * `RevenueContract` or `Contract`, no more IFRS-15-shaped `Contract`
	 * rows) is a no-op: nothing matches `$from` any more, so nothing changes.
	 *
	 * @return void
	 */
	public function testSecondRunIsIdempotentNoOp(): void {
		$alreadyMigrated = [
			['@self' => ['schema' => 'RevenueContract'], 'contractNumber' => 'C-2026-001', 'customerId' => 'contact-abc'],
			['@self' => ['schema' => 'Contract'], 'contractNumber' => 'C-2026-007', 'contractType' => 'service', 'status' => 'active'],
		];

		$migrated = $this->migrator->migrateBatch($alreadyMigrated, 'Contract', 'RevenueContract');

		$this->assertSame($alreadyMigrated, $migrated, 'nothing left to migrate; batch passes through unchanged');

	}//end testSecondRunIsIdempotentNoOp()

	/**
	 * assertCountsMatch is a no-op on equal counts.
	 *
	 * @return void
	 */
	public function testAssertCountsMatchAcceptsEqualCounts(): void {
		$this->migrator->assertCountsMatch(3, 3);
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
		$this->migrator->assertCountsMatch(3, 2);

	}//end testAssertCountsMatchAbortsOnMismatch()
}//end class
