<?php

/**
 * Unit tests for ExpenseCategoryAccountCheck.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ExpenseCategoryAccountCheck;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * REQ-ECM-001: a category is saved with an expense account of its
 * administration's chart, and refused with any other account.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ExpenseCategoryAccountCheck::class)]
#[UsesClass(ObjectIdentifier::class)]
final class ExpenseCategoryAccountCheckTest extends TestCase {
	/**
	 * The check under test.
	 *
	 * @var ExpenseCategoryAccountCheck
	 */
	private ExpenseCategoryAccountCheck $check;

	/**
	 * A chart of three accounts for `adm-1`, and 4340 as a balance sheet
	 * account of `adm-2`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		$store = new InMemoryObjectServiceStub(
			data: [
				'Account' => [
					['id' => 'a-1', 'accountNumber' => '4340', 'name' => 'Software en SaaS', 'accountType' => 'expenses', 'administrationId' => 'adm-1'],
					['id' => 'a-2', 'accountNumber' => '1100', 'name' => 'Debiteuren', 'accountType' => 'assets', 'administrationId' => 'adm-1'],
					['id' => 'a-3', 'accountNumber' => '4340', 'name' => 'Anders', 'accountType' => 'liabilities', 'administrationId' => 'adm-2'],
				],
			]
		);
		$this->check = new ExpenseCategoryAccountCheck(objectService: $store, registerLocation: $location);
	}//end setUp()

	/**
	 * An expense account of the administration is accepted.
	 *
	 * @return void
	 */
	public function testAnExpenseAccountIsAccepted(): void {
		$this->assertNull($this->check->refusal(category: ['code' => 'software', 'expenseAccount' => ' 4340 ', 'administrationId' => 'adm-1']));
	}//end testAnExpenseAccountIsAccepted()

	/**
	 * A balance sheet account is refused, naming it and its type.
	 *
	 * @return void
	 */
	public function testABalanceSheetAccountIsRefused(): void {
		$this->assertSame(
			"Account 1100 Debiteuren is not an expense account (its type is 'assets').",
			$this->check->refusal(category: ['code' => 'software', 'expenseAccount' => '1100', 'administrationId' => 'adm-1'])
		);
	}//end testABalanceSheetAccountIsRefused()

	/**
	 * The account is read from the category's own administration.
	 *
	 * @return void
	 */
	public function testTheAccountOfAnotherAdministrationDoesNotCount(): void {
		$this->assertSame(
			"Account 4340 Anders is not an expense account (its type is 'liabilities').",
			$this->check->refusal(category: ['expenseAccount' => '4340', 'administrationId' => 'adm-2'])
		);
		$this->assertSame(
			'Account 4340 does not exist in the chart of accounts of this administration.',
			$this->check->refusal(category: ['expenseAccount' => '4340', 'administrationId' => 'adm-3'])
		);
	}//end testTheAccountOfAnotherAdministrationDoesNotCount()

	/**
	 * A category without an account or an administration is refused.
	 *
	 * @return void
	 */
	public function testAMissingAccountOrAdministrationIsRefused(): void {
		$this->assertSame('An expense category needs an expense account.', $this->check->refusal(category: ['administrationId' => 'adm-1']));
		$this->assertSame(
			'Account 4340 cannot be checked: the expense category belongs to no administration.',
			$this->check->refusal(category: ['expenseAccount' => '4340'])
		);
	}//end testAMissingAccountOrAdministrationIsRefused()
}//end class
