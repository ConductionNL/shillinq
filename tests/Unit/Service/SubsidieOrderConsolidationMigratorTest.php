<?php

/**
 * Unit tests for SubsidieOrderConsolidationMigrator.
 *
 * Covers the consolidate-order-subsidie-collisions migration core (task 3.1):
 * the object field-map (re-point a renamed schema's objects) and the
 * source→target count guard that ABORTS with the source intact on a mismatch —
 * the no-row-loss invariant of REQ "Existing data is migrated with no row loss".
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
 * @spec openspec/specs/schema-consolidation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Migration\SubsidieOrderConsolidationMigrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the pure migration core: field-map + count-abort.
 */
final class SubsidieOrderConsolidationMigratorTest extends TestCase {

	/**
	 * The migrator under test.
	 *
	 * @var SubsidieOrderConsolidationMigrator
	 */
	private SubsidieOrderConsolidationMigrator $migrator;

	/**
	 * Set up the migrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migrator = new SubsidieOrderConsolidationMigrator();

	}//end setUp()

	/**
	 * The booking rename is Order → BookingOrder; Subsidie needs no object move.
	 *
	 * @return void
	 */
	public function testConsolidationTopology(): void {
		$this->assertSame(
			['from' => 'Order', 'to' => 'BookingOrder'],
			$this->migrator->bookingOrderRename()
		);
		$this->assertFalse($this->migrator->subsidieMigrationRequired());

	}//end testConsolidationTopology()

	/**
	 * mapObjectToRenamedSchema re-points a matching object's @self.schema only.
	 *
	 * @return void
	 */
	public function testMapRepointsMatchingObject(): void {
		$object = [
			'@self' => ['register' => 'shillinq', 'schema' => 'Order', 'slug' => 'ord-1001'],
			'administrationId' => 'adm-1',
			'orderId' => 'ord-1001',
			'grossAmount' => 1210.00,
		];

		$migrated = $this->migrator->mapObjectToRenamedSchema($object, 'Order', 'BookingOrder');

		$this->assertSame('BookingOrder', $migrated['@self']['schema']);
		// Every other field is preserved verbatim.
		$this->assertSame('shillinq', $migrated['@self']['register']);
		$this->assertSame('ord-1001', $migrated['@self']['slug']);
		$this->assertSame('adm-1', $migrated['administrationId']);
		$this->assertSame('ord-1001', $migrated['orderId']);
		$this->assertSame(1210.00, $migrated['grossAmount']);

	}//end testMapRepointsMatchingObject()

	/**
	 * A non-matching object (different schema) passes through unchanged.
	 *
	 * @return void
	 */
	public function testMapLeavesNonMatchingObjectUnchanged(): void {
		$object = ['@self' => ['schema' => 'SalesOrder'], 'orderId' => 'so-1'];
		$migrated = $this->migrator->mapObjectToRenamedSchema($object, 'Order', 'BookingOrder');

		$this->assertSame('SalesOrder', $migrated['@self']['schema']);
		$this->assertSame($object, $migrated);

	}//end testMapLeavesNonMatchingObjectUnchanged()

	/**
	 * migrateBatch re-points every source object and preserves the count.
	 *
	 * @return void
	 */
	public function testMigrateBatchRepointsEveryObject(): void {
		$source = [
			['@self' => ['schema' => 'Order'], 'orderId' => 'ord-1'],
			['@self' => ['schema' => 'Order'], 'orderId' => 'ord-2'],
			['@self' => ['schema' => 'Order'], 'orderId' => 'ord-3'],
		];

		$migrated = $this->migrator->migrateBatch($source, 'Order', 'BookingOrder');

		$this->assertCount(3, $migrated);
		foreach ($migrated as $object) {
			$this->assertSame('BookingOrder', $object['@self']['schema']);
		}

	}//end testMigrateBatchRepointsEveryObject()

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
