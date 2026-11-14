<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every payroll schema is protected by an authorization block OpenRegister enforces.
 */
final class PayrollAuthorizationTest extends TestCase {
	use SchemaAuthorizationAssertions;

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function schemas(): array {
		return [
			'Deduction' => ['Deduction'],
			'DeterminationLetter' => ['DeterminationLetter'],
			'IB47Record' => ['IB47Record'],
			'LHAfdracht' => ['LHAfdracht'],
			'LoonheffingTabel2026' => ['LoonheffingTabel2026'],
			'Loonjournaalpost' => ['Loonjournaalpost'],
			'LoonPeriode' => ['LoonPeriode'],
			'LoonStrook' => ['LoonStrook'],
			'OpdrachtgeversVerklaring' => ['OpdrachtgeversVerklaring'],
			'Payroll' => ['Payroll'],
			'payrollEmployee' => ['payrollEmployee'],
			'SalarisFeed' => ['SalarisFeed'],
			'Werkgever' => ['Werkgever'],
			'Werknemer' => ['Werknemer'],
		];
	}

	#[DataProvider('schemas')]
	public function testSchemaIsAuthorized(string $name): void {
		$this->assertSchemaAuthorized($name);
	}
}
