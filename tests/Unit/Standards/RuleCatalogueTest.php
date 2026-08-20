<?php

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards;

use OCA\Shillinq\Standards\RuleCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the versioned static RuleCatalogue (the operative bookkeeping
 * rule corpus loaded from lib/Standards/rules/*.json).
 *
 * @covers \OCA\Shillinq\Standards\RuleCatalogue
 */
class RuleCatalogueTest extends TestCase {

	/**
	 * Reset the memoised cache before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		RuleCatalogue::reset();

	}//end setUp()

	/**
	 * The catalogue is versioned and substantively populated.
	 *
	 * @return void
	 */
	public function testVersionedAndSubstantive(): void {
		$this->assertNotEmpty(RuleCatalogue::version());
		// Wave 1 shipped 700+ rules; guard against an accidental empty/broken load.
		$this->assertGreaterThan(500, RuleCatalogue::count());

	}//end testVersionedAndSubstantive()

	/**
	 * Every loaded rule is well-formed (required keys, known severity) and ids
	 * are globally unique across all domain files.
	 *
	 * @return void
	 */
	public function testEveryRuleWellFormedAndIdsUnique(): void {
		$required = ['id', 'domain', 'jurisdiction', 'framework', 'source', 'statement', 'severity'];
		$severities = ['mandatory', 'conditional', 'recommended'];

		$ids = [];
		foreach (RuleCatalogue::all() as $rule) {
			foreach ($required as $key) {
				$this->assertArrayHasKey($key, $rule);
				$this->assertNotSame('', $rule[$key]);
			}

			$this->assertContains($rule['severity'], $severities, 'unexpected severity: ' . $rule['severity']);
			$this->assertIsBool($rule['machineCheckable'] ?? null, 'machineCheckable must be boolean for ' . $rule['id']);
			$ids[] = $rule['id'];
		}

		$this->assertSame(count($ids), count(array_unique($ids)), 'rule ids must be globally unique');

	}//end testEveryRuleWellFormedAndIdsUnique()

	/**
	 * Domain / framework filters return matching, non-empty subsets.
	 *
	 * @return void
	 */
	public function testDomainAndFrameworkFilters(): void {
		$invoicing = RuleCatalogue::byDomain('invoicing');
		$this->assertNotEmpty($invoicing);
		foreach ($invoicing as $rule) {
			$this->assertSame('invoicing', $rule['domain']);
		}

		$en16931 = RuleCatalogue::byFramework('en-16931');
		$this->assertNotEmpty($en16931);
		foreach ($en16931 as $rule) {
			$this->assertSame('en-16931', $rule['framework']);
		}

	}//end testDomainAndFrameworkFilters()

	/**
	 * Jurisdiction resolution includes EU-wide + global rules for an EU member
	 * but EU-wide rules do not leak into a US query.
	 *
	 * @return void
	 */
	public function testJurisdictionResolution(): void {
		$nl = RuleCatalogue::byJurisdiction('NL');
		$nlJuris = array_unique(array_column($nl, 'jurisdiction'));
		$this->assertContains('EU', $nlJuris);
		$this->assertContains('global', $nlJuris);

		$us = RuleCatalogue::byJurisdiction('US');
		$usJuris = array_unique(array_column($us, 'jurisdiction'));
		$this->assertNotContains('EU', $usJuris, 'EU-wide rules must not leak into a US query');

	}//end testJurisdictionResolution()

	/**
	 * machineCheckable() is a non-empty subset of all rules.
	 *
	 * @return void
	 */
	public function testMachineCheckableSubset(): void {
		$checkable = RuleCatalogue::machineCheckable();
		$this->assertNotEmpty($checkable);
		$this->assertLessThanOrEqual(RuleCatalogue::count(), count($checkable));
		foreach ($checkable as $rule) {
			$this->assertTrue($rule['machineCheckable']);
		}

	}//end testMachineCheckableSubset()

}//end class
