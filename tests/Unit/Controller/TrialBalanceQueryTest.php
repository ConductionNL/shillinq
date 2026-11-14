<?php

/**
 * Unit tests for TrialBalanceQuery.
 *
 * The request value object for a trial-balance query: the required period and administration ids, their identifier shape, and the optional prior-period filter it emits only when well-formed.
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
 * @spec openspec/specs/bookkeeping-trial-balance/spec.md
 *
 * @covers \OCA\Shillinq\Controller\TrialBalanceQuery
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\TrialBalanceQuery;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class TrialBalanceQueryTest extends TestCase {
	private function query(array $params): TrialBalanceQuery {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		return new TrialBalanceQuery($request);
	}//end query()

	public function testRequiresPeriodThenAdministration(): void {
		$this->assertSame('period_id is required', $this->query([])->inputError());
		$this->assertSame('administration_id is required', $this->query(['period_id' => 'p1'])->inputError());
	}//end testRequiresPeriodThenAdministration()

	public function testShapeErrorsAfterPresence(): void {
		$this->assertSame('period_id must be a valid period identifier', $this->query(['period_id' => 'bad id!', 'administration_id' => 'a1'])->inputError());
		$this->assertSame('administration_id must be a valid administration identifier', $this->query(['period_id' => 'p1', 'administration_id' => 'bad id!'])->inputError());
	}//end testShapeErrorsAfterPresence()

	public function testValidQueryHasNoError(): void {
		$this->assertNull($this->query(['period_id' => 'p1', 'administration_id' => 'a1'])->inputError());
	}//end testValidQueryHasNoError()

	public function testFiltersOnlyIncludeAWellFormedPriorPeriod(): void {
		$this->assertSame([], $this->query(['period_id' => 'p1', 'administration_id' => 'a1'])->filters());
		$this->assertSame([], $this->query(['period_id' => 'p1', 'administration_id' => 'a1', 'prior_period_id' => 'bad id!'])->filters());
		$this->assertSame(['priorPeriodId' => 'p0'], $this->query(['period_id' => 'p1', 'administration_id' => 'a1', 'prior_period_id' => 'p0'])->filters());
	}//end testFiltersOnlyIncludeAWellFormedPriorPeriod()
}//end class
