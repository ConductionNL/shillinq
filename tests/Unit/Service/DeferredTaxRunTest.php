<?php

/**
 * Unit tests for DeferredTaxRun.
 *
 * The run is the mutable context a deferred-tax recomputation threads through
 * the service; these pin the two filter shapes it hands the object store.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\DeferredTaxRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\DeferredTaxRun
 */
class DeferredTaxRunTest extends TestCase {

	/**
	 * The constructor fixes the period and administration and defaults the rest.
	 *
	 * @return void
	 */
	public function testConstructorFixesTheIdsAndDefaultsTheState(): void {
		$run = new DeferredTaxRun(periodId: 'p-1', administrationId: 'a-1');

		$this->assertSame('p-1', $run->periodId);
		$this->assertSame('a-1', $run->administrationId);
		$this->assertSame('', $run->jurisdiction);
		$this->assertSame(0, $run->newRateBps);
		$this->assertSame('', $run->effectiveDate);

	}//end testConstructorFixesTheIdsAndDefaultsTheState()

	/**
	 * The period filter is the period and administration only.
	 *
	 * @return void
	 */
	public function testPeriodFilters(): void {
		$run = new DeferredTaxRun(periodId: 'p-1', administrationId: 'a-1');

		$this->assertSame(['periodId' => 'p-1', 'administrationId' => 'a-1'], $run->periodFilters());

	}//end testPeriodFilters()

	/**
	 * The jurisdiction filter adds the current jurisdiction between them.
	 *
	 * @return void
	 */
	public function testJurisdictionFiltersUseTheCurrentJurisdiction(): void {
		$run = new DeferredTaxRun(periodId: 'p-1', administrationId: 'a-1');
		$run->jurisdiction = 'NL';

		$this->assertSame(
			['periodId' => 'p-1', 'jurisdiction' => 'NL', 'administrationId' => 'a-1'],
			$run->jurisdictionFilters()
		);

	}//end testJurisdictionFiltersUseTheCurrentJurisdiction()
}//end class
