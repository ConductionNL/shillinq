<?php

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards;

use OCA\Shillinq\Standards\ComplianceCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the static, versioned ComplianceCatalogue.
 *
 * @covers \OCA\Shillinq\Standards\ComplianceCatalogue
 */
class ComplianceCatalogueTest extends TestCase {

	/**
	 * The catalogue is versioned and non-empty, and every entry is well-formed
	 * with a unique id.
	 *
	 * @return void
	 */
	public function testCatalogueIsVersionedAndWellFormed(): void {
		$this->assertNotEmpty(ComplianceCatalogue::version());

		$entries = ComplianceCatalogue::all();
		$this->assertNotEmpty($entries);

		$ids = [];
		foreach ($entries as $entry) {
			foreach (['id', 'jurisdiction', 'type', 'standard', 'name', 'status'] as $key) {
				$this->assertArrayHasKey($key, $entry);
				$this->assertNotSame('', $entry[$key]);
			}

			$this->assertArrayHasKey('effectiveDate', $entry);
			$ids[] = $entry['id'];
		}

		$this->assertSame(count($ids), count(array_unique($ids)), 'entry ids must be unique');

	}//end testCatalogueIsVersionedAndWellFormed()

	/**
	 * applicableTo() for an EU member state includes that country's own
	 * obligations AND the EU-wide ones, but not another country's.
	 *
	 * @return void
	 */
	public function testApplicableToEuMemberIncludesEuWide(): void {
		$nl = ComplianceCatalogue::applicableTo('NL');
		$juris = array_column($nl, 'jurisdiction');

		$this->assertContains('EU', $juris, 'EU-wide obligations apply to an EU member');
		$this->assertNotContains('US', $juris, 'US obligations do not apply to NL');
		$this->assertNotContains('IT', $juris, "another member's country-specific mandate does not apply to NL");

	}//end testApplicableToEuMemberIncludesEuWide()

	/**
	 * applicableTo() for a non-EU jurisdiction returns only its own entries —
	 * EU-wide obligations do NOT leak in.
	 *
	 * @return void
	 */
	public function testApplicableToNonEuExcludesEuWide(): void {
		$us = ComplianceCatalogue::applicableTo('US');
		$juris = array_unique(array_column($us, 'jurisdiction'));

		$this->assertSame(['US'], array_values($juris));
		$this->assertNotEmpty($us);

	}//end testApplicableToNonEuExcludesEuWide()

	/**
	 * byType() filters to a single obligation type.
	 *
	 * @return void
	 */
	public function testByTypeFilters(): void {
		$saft = ComplianceCatalogue::byType('saf-t');
		$this->assertNotEmpty($saft);
		foreach ($saft as $entry) {
			$this->assertSame('saf-t', $entry['type']);
		}

	}//end testByTypeFilters()

}//end class
