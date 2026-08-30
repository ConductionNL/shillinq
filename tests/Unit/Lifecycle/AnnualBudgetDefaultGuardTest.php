<?php

/**
 * Unit tests for AnnualBudgetDefaultGuard.
 *
 * Covers `budget-core-schema` task group 6 (REQ-BCS-006): the one-default
 * invariant on `AnnualBudget.activate` — a second `isDefault=true` budget
 * for the same administration + fiscal year is rejected, a default for a
 * different fiscal year or administration is accepted, and a budget that
 * does not claim `isDefault` needs no uniqueness check at all.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the AnnualBudget.activate one-default precondition.
 */
final class AnnualBudgetDefaultGuardTest extends TestCase {

	/**
	 * Build the guard over a seeded in-memory OpenRegister.
	 *
	 * @param array<int,array<string,mixed>> $existingBudgets Seeded AnnualBudget rows.
	 *
	 * @return AnnualBudgetDefaultGuard
	 */
	private function buildGuard(array $existingBudgets): AnnualBudgetDefaultGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new AnnualBudgetDefaultGuard(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: new InMemoryObjectServiceStub(['AnnualBudget' => $existingBudgets]),
		);
	}//end buildGuard()

	/**
	 * A second `isDefault=true` AnnualBudget for the same administration +
	 * fiscal year is rejected on activate.
	 *
	 * @return void
	 */
	public function testRejectsSecondDefaultForSameAdministrationAndFiscalYear(): void {
		$existing = [
			[
				'id' => 'ab-1',
				'administrationId' => 'adm-1',
				'fiscalYear' => 2027,
				'isDefault' => true,
				'state' => 'active',
			],
		];
		$guard = $this->buildGuard($existing);

		$candidate = [
			'id' => 'ab-2',
			'administrationId' => 'adm-1',
			'fiscalYear' => 2027,
			'isDefault' => true,
			'state' => 'draft',
		];

		$this->assertFalse($guard->isUniqueDefault($candidate));

	}//end testRejectsSecondDefaultForSameAdministrationAndFiscalYear()

	/**
	 * A default for a different fiscal year is accepted.
	 *
	 * @return void
	 */
	public function testAcceptsDefaultForDifferentFiscalYear(): void {
		$existing = [
			[
				'id' => 'ab-1',
				'administrationId' => 'adm-1',
				'fiscalYear' => 2027,
				'isDefault' => true,
				'state' => 'active',
			],
		];
		$guard = $this->buildGuard($existing);

		$candidate = [
			'id' => 'ab-2',
			'administrationId' => 'adm-1',
			'fiscalYear' => 2028,
			'isDefault' => true,
			'state' => 'draft',
		];

		$this->assertTrue($guard->isUniqueDefault($candidate));

	}//end testAcceptsDefaultForDifferentFiscalYear()

	/**
	 * A default for a different administration (same fiscal year) is
	 * accepted — the invariant is scoped per-tenant.
	 *
	 * @return void
	 */
	public function testAcceptsDefaultForDifferentAdministration(): void {
		$existing = [
			[
				'id' => 'ab-1',
				'administrationId' => 'adm-1',
				'fiscalYear' => 2027,
				'isDefault' => true,
				'state' => 'active',
			],
		];
		$guard = $this->buildGuard($existing);

		$candidate = [
			'id' => 'ab-2',
			'administrationId' => 'adm-2',
			'fiscalYear' => 2027,
			'isDefault' => true,
			'state' => 'draft',
		];

		$this->assertTrue($guard->isUniqueDefault($candidate));

	}//end testAcceptsDefaultForDifferentAdministration()

	/**
	 * A budget not claiming isDefault needs no uniqueness check — it may
	 * always activate regardless of how many default siblings exist.
	 *
	 * @return void
	 */
	public function testAcceptsNonDefaultRegardlessOfSiblings(): void {
		$existing = [
			[
				'id' => 'ab-1',
				'administrationId' => 'adm-1',
				'fiscalYear' => 2027,
				'isDefault' => true,
				'state' => 'active',
			],
		];
		$guard = $this->buildGuard($existing);

		$candidate = [
			'id' => 'ab-2',
			'administrationId' => 'adm-1',
			'fiscalYear' => 2027,
			'isDefault' => false,
			'state' => 'draft',
		];

		$this->assertTrue($guard->isUniqueDefault($candidate));

	}//end testAcceptsNonDefaultRegardlessOfSiblings()

	/**
	 * The first default for an administration + fiscal year (no siblings at
	 * all) is accepted.
	 *
	 * @return void
	 */
	public function testAcceptsWhenNoSiblingsExist(): void {
		$guard = $this->buildGuard([]);

		$candidate = [
			'id' => 'ab-1',
			'administrationId' => 'adm-1',
			'fiscalYear' => 2027,
			'isDefault' => true,
			'state' => 'draft',
		];

		$this->assertTrue($guard->isUniqueDefault($candidate));

	}//end testAcceptsWhenNoSiblingsExist()

	/**
	 * The candidate record itself, if already present among the queried
	 * rows (e.g. re-evaluating an already-saved draft), is not treated as
	 * its own conflict.
	 *
	 * @return void
	 */
	public function testDoesNotTreatItselfAsAConflict(): void {
		$existing = [
			[
				'id' => 'ab-1',
				'administrationId' => 'adm-1',
				'fiscalYear' => 2027,
				'isDefault' => true,
				'state' => 'draft',
			],
		];
		$guard = $this->buildGuard($existing);

		// Same id as the only existing row — this IS that row.
		$candidate = [
			'id' => 'ab-1',
			'administrationId' => 'adm-1',
			'fiscalYear' => 2027,
			'isDefault' => true,
			'state' => 'draft',
		];

		$this->assertTrue($guard->isUniqueDefault($candidate));

	}//end testDoesNotTreatItselfAsAConflict()
}//end class
