<?php

/**
 * WBSO Account fixtures.
 *
 * Five canonical RGS sample accounts used by the unit tests covering the
 * Chart-of-Accounts hierarchy (REQ-WBSO-001 / REQ-WBSO-006). Mirrors the
 * synthetic seed objects loaded by the register.d fragment so the in-memory
 * test rows match the runtime seed shape exactly.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Fixtures
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-37
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Fixtures;

/**
 * Static fixture provider for the Chart of Accounts unit tests.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-37
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class WbsoAccountFixtures {

	public const SAMPLE_ADMINISTRATION = 'adm-consultancy-nl';

	/**
	 * Five RGS accounts with parent hierarchy.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function accounts(): array {
		return [
			[
				'accountNumber' => '1000',
				'name' => 'Kas en bank',
				'accountType' => 'assets',
				'parentAccountNumber' => '1',
				'status' => 'active',
				'currency' => 'EUR',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'description' => 'Cash and bank deposits.',
				'vatApplicable' => false,
			],
			[
				'accountNumber' => '4100',
				'name' => 'Omzet diensten',
				'accountType' => 'revenue',
				'parentAccountNumber' => '4',
				'status' => 'active',
				'currency' => 'EUR',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'vatApplicable' => true,
			],
			[
				'accountNumber' => '6000',
				'name' => 'Huisvestingskosten',
				'accountType' => 'expenses',
				'parentAccountNumber' => '6',
				'status' => 'active',
				'currency' => 'EUR',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'vatApplicable' => true,
			],
			[
				'accountNumber' => '2000',
				'name' => 'Eigen vermogen',
				'accountType' => 'equity',
				'parentAccountNumber' => '2',
				'status' => 'active',
				'currency' => 'EUR',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'vatApplicable' => false,
			],
			[
				'accountNumber' => '1500',
				'name' => 'Crediteuren',
				'accountType' => 'liabilities',
				'parentAccountNumber' => '1',
				'status' => 'active',
				'currency' => 'EUR',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'vatApplicable' => false,
			],
		];

	}//end accounts()
}//end class
