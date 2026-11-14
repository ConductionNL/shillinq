<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards;

use OCA\Shillinq\Standards\RuleCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RuleCatalogue counts: per-domain counts agree with the
 * domain filter and add up to the total, and the cache resets cleanly.
 *
 * @covers \OCA\Shillinq\Standards\RuleCatalogue
 */
class RuleCatalogueCountsTest extends TestCase {

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
	 * Every domain is counted as many times as the domain filter finds it, and the counts add up to the total.
	 *
	 * @return void
	 */
	public function testDomainCountsAgreeWithTheFilterAndTheTotal(): void {
		$counts = RuleCatalogue::countByDomain();

		$this->assertNotSame([], $counts);
		$this->assertSame(RuleCatalogue::count(), array_sum($counts));
		$this->assertSame(count(RuleCatalogue::all()), RuleCatalogue::count());
		foreach ($counts as $domain => $count) {
			$this->assertIsString($domain);
			$this->assertSame($count, count(RuleCatalogue::byDomain(domain: $domain)), $domain);
		}
	}//end testDomainCountsAgreeWithTheFilterAndTheTotal()

	/**
	 * The domain counts are keyed in sorted order.
	 *
	 * @return void
	 */
	public function testDomainCountsAreSortedByDomain(): void {
		$domains = array_keys(RuleCatalogue::countByDomain());
		$sorted  = $domains;
		sort($sorted);

		$this->assertSame($sorted, $domains);
		$this->assertCount(count(array_unique(array_column(RuleCatalogue::all(), 'domain'))), $domains);
	}//end testDomainCountsAreSortedByDomain()

	/**
	 * An unknown domain has no rules and no count.
	 *
	 * @return void
	 */
	public function testAnUnknownDomainHasNoRules(): void {
		$this->assertSame([], RuleCatalogue::byDomain(domain: 'no-such-domain'));
		$this->assertArrayNotHasKey('no-such-domain', RuleCatalogue::countByDomain());
	}//end testAnUnknownDomainHasNoRules()

	/**
	 * After a reset the catalogue reloads the same rules.
	 *
	 * @return void
	 */
	public function testAResetReloadsTheSameRules(): void {
		$before = RuleCatalogue::all();
		RuleCatalogue::reset();

		$this->assertSame($before, RuleCatalogue::all());
		$this->assertSame('2026-06b', RuleCatalogue::version());
	}//end testAResetReloadsTheSameRules()
}//end class
