<?php

/**
 * Schema authorization for administrations, memberships, WBSO, import and
 * app configuration.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
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

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The tenancy boundary, the WBSO administration, the import pipeline and the
 * app configuration must close the OpenRegister object API to callers
 * outside their groups.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AdministrationAuthorizationTest extends TestCase {
	use ShippedRegisterAuthorization;

	public const SCHEMAS = [
		'Administration', 'AdministrationBackupRun', 'AdministrationMembership', 'AdministrationMigration',
		'FieldRequirement', 'ImportBatch', 'ImportMapping', 'SoProject', 'SoUrenStaat', 'SoUurregistratie',
		'StandardsPolicy', 'WBSOActivityCode', 'WbsoBeschikking', 'WBSOExportLog', 'WbsoMededeling', 'WBSOTag',
	];

	/**
	 * Each schema in the domain ships an enforceable authorization block.
	 *
	 * @return void
	 */
	public function testAdministrationSchemasEnforceAuthorization(): void {
		$this->assertSchemasEnforceAuthorization(self::SCHEMAS);
	}//end testAdministrationSchemasEnforceAuthorization()

	/**
	 * Every per-administration guard trusts AdministrationMembership, so a
	 * user who could write one could let themselves into any administration.
	 * Administrations and memberships are therefore written by Nextcloud
	 * admins only.
	 *
	 * @return void
	 */
	public function testTenancyBoundaryIsAdminWritableOnly(): void {
		foreach (['Administration', 'AdministrationMembership', 'AdministrationBackupRun', 'AdministrationMigration'] as $name) {
			$this->assertAdminOnly($name, ['create', 'update', 'delete']);
		}
	}//end testTenancyBoundaryIsAdminWritableOnly()

	/**
	 * Employees write only their own S&O hours, and only while in draft.
	 *
	 * @return void
	 */
	public function testEmployeesWriteOnlyTheirOwnDraftHours(): void {
		$authorization = $this->shippedSchemas()['SoUurregistratie']['authorization'];
		$employeeUpdate = array_values(
			array_filter(
				$authorization['update'],
				static fn (mixed $rule): bool => is_array($rule) === true && ($rule['group'] ?? null) === 'shillinq_employee'
			)
		);

		self::assertSame([['group' => 'shillinq_employee', 'match' => ['employeeId' => '$userId', 'state' => 'draft']]], $employeeUpdate);
		self::assertNotContains('shillinq_employee', $authorization['create'], 'Employees may only create hours in their own name.');
	}//end testEmployeesWriteOnlyTheirOwnDraftHours()

	/**
	 * Accounting standards are app configuration: only the app admin and
	 * controllers change them.
	 *
	 * @return void
	 */
	public function testStandardsPolicyIsNotWritableByEveryUser(): void {
		$authorization = $this->shippedSchemas()['StandardsPolicy']['authorization'];
		foreach (['create', 'update', 'delete'] as $action) {
			self::assertNotContains('authenticated', ($authorization[$action] ?? []));
		}
	}//end testStandardsPolicyIsNotWritableByEveryUser()
}//end class
