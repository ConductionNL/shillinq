<?php

/**
 * Unit tests for the reporting-ifrs-calculations register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-ifrs-calculations/tasks.md#task-1.1
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * The fragment marks a GLTransaction as divergent and names its adjustment rule.
 */
final class ReportingIfrsCalculationsFragmentTest extends TestCase {

	/**
	 * The fragment's GLTransaction schema.
	 *
	 * @return array<string, mixed>
	 */
	private function transactionSchema(): array {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/reporting-ifrs-calculations.json'), true);
		$this->assertIsArray($fragment);

		return $fragment['components']['schemas']['GLTransaction'];
	}//end transactionSchema()

	/**
	 * The three properties the derivation reads are declared, optional, and the version moved.
	 *
	 * @return void
	 */
	public function testTheTransactionCarriesTheDivergenceMarkAndRule(): void {
		$schema = $this->transactionSchema();

		$this->assertSame('0.7.0', $schema['version']);
		$this->assertSame(['NL-GAAP-RJ', 'IFRS-EU'], $schema['properties']['reportingFramework']['enum']);
		$this->assertContains('ECL_IFRS9', $schema['properties']['divergenceReasonCode']['enum']);
		$this->assertContains('NONE', $schema['properties']['divergenceReasonCode']['enum']);
		$this->assertSame('string', $schema['properties']['adjustmentCalculationId']['type']);
		$this->assertArrayNotHasKey('required', $schema);
	}//end testTheTransactionCarriesTheDivergenceMarkAndRule()

	/**
	 * The reason codes are the DualTransaction's, so the derived record accepts them.
	 *
	 * @return void
	 */
	public function testTheReasonCodesMatchTheDualTransactions(): void {
		$dual = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json'), true);

		$this->assertSame(
			$dual['components']['schemas']['DualTransaction']['properties']['divergenceReasonCode']['enum'],
			$this->transactionSchema()['properties']['divergenceReasonCode']['enum']
		);
	}//end testTheReasonCodesMatchTheDualTransactions()
}//end class
