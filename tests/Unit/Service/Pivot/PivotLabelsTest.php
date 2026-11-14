<?php

/**
 * Unit tests for the pivot's key labels.
 *
 * An account is labelled by its number and name, an account group by the
 * parent's name or, for a type group, by the type; a customer by its name; an
 * empty key is "not set"; any other axis shows the key itself.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pivot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pivot;

use OCA\Shillinq\Service\Pivot\PivotLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Labels per axis.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(PivotLabels::class)]
final class PivotLabelsTest extends TestCase {

	/**
	 * Labels with two accounts and one customer.
	 *
	 * @return PivotLabels
	 */
	private function labels(): PivotLabels {
		return new PivotLabels(
			labels: [
				'accounts'  => ['8000' => 'Revenue', '1300' => 'Debtors'],
				'customers' => ['cust-1' => 'Acme B.V.'],
			]
		);
	}//end labels()

	/**
	 * An account shows its number and name; an unknown account its number only.
	 *
	 * @return void
	 */
	public function testAnAccountShowsItsNumberAndName(): void {
		$this->assertSame(
			[
				['key' => '8000', 'label' => '8000 Revenue'],
				['key' => '9999', 'label' => '9999'],
				['key' => '', 'label' => null],
			],
			$this->labels()->describe(keys: ['8000', '9999', ''], axis: 'account')
		);
	}//end testAnAccountShowsItsNumberAndName()

	/**
	 * A group shows the parent's name, a type group its type, and an unknown
	 * parent its number.
	 *
	 * @return void
	 */
	public function testAGroupShowsTheParentsNameOrItsType(): void {
		$this->assertSame(
			[
				['key' => '1300', 'label' => 'Debtors'],
				['key' => 'type:revenue', 'label' => 'revenue'],
				['key' => '4000', 'label' => '4000'],
			],
			$this->labels()->describe(keys: ['1300', 'type:revenue', '4000'], axis: 'accountGroup')
		);
	}//end testAGroupShowsTheParentsNameOrItsType()

	/**
	 * A customer shows its name, an unknown customer its id, and any other
	 * axis the key itself.
	 *
	 * @return void
	 */
	public function testACustomerShowsItsNameAndOtherAxesTheKey(): void {
		$labels = $this->labels();

		$this->assertSame(
			[['key' => 'cust-1', 'label' => 'Acme B.V.'], ['key' => 'cust-2', 'label' => 'cust-2']],
			$labels->describe(keys: ['cust-1', 'cust-2'], axis: 'customer')
		);
		$this->assertSame(
			[['key' => '2026-03', 'label' => '2026-03'], ['key' => '', 'label' => null]],
			$labels->describe(keys: ['2026-03', ''], axis: 'month')
		);
	}//end testACustomerShowsItsNameAndOtherAxesTheKey()
}//end class
