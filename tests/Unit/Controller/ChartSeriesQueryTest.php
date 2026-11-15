<?php

/**
 * Unit tests for ChartSeriesQuery.
 *
 * The request value object for a budget-chart series: the administration-id shape, the YYYY-MM month bounds, the optional AnnualBudget override and its null-when-absent accessor.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 *
 * @covers \OCA\Shillinq\Controller\ChartSeriesQuery
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ChartSeriesQuery;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class ChartSeriesQueryTest extends TestCase {
	private function query(array $params): ChartSeriesQuery {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		return new ChartSeriesQuery($request);
	}//end query()

	public function testAdministrationValidity(): void {
		$this->assertTrue($this->query(['administrationId' => 'adm-1'])->administrationValid());
		$this->assertFalse($this->query([])->administrationValid());
		$this->assertFalse($this->query(['administrationId' => 'not a valid id!'])->administrationValid());
	}//end testAdministrationValidity()

	public function testMonthsValidity(): void {
		$this->assertTrue($this->query(['from' => '2026-01', 'to' => '2026-12'])->monthsValid());
		$this->assertFalse($this->query(['from' => '2026-1', 'to' => '2026-12'])->monthsValid());
		$this->assertFalse($this->query(['from' => '2026-01', 'to' => '2026-01-01'])->monthsValid());
		$this->assertFalse($this->query([])->monthsValid());
	}//end testMonthsValidity()

	public function testBudgetValidityAndAccessor(): void {
		$absent = $this->query([]);
		$this->assertTrue($absent->budgetValid());
		$this->assertNull($absent->annualBudget());

		$set = $this->query(['annualBudgetId' => 'ab-9']);
		$this->assertTrue($set->budgetValid());
		$this->assertSame('ab-9', $set->annualBudget());

		$this->assertFalse($this->query(['annualBudgetId' => 'bad id!'])->budgetValid());
	}//end testBudgetValidityAndAccessor()

	public function testParamsAreTrimmed(): void {
		$query = $this->query(['administrationId' => '  adm-1  ', 'from' => '  2026-01  ']);
		$this->assertTrue($query->administrationValid());
		$this->assertSame('adm-1', $query->administrationId);
		$this->assertSame('2026-01', $query->from);
	}//end testParamsAreTrimmed()
}//end class
