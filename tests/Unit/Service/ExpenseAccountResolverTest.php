<?php

/**
 * Unit tests for ExpenseAccountResolver.
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
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ExpenseAccountResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;

/**
 * Each branch of REQ-ECM-003: the confirmed account, the category's account,
 * the administration's mileage, per diem and payable accounts, and null with
 * a reason that names the line.
 *
 * @covers \OCA\Shillinq\Service\ExpenseAccountResolver
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class ExpenseAccountResolverTest extends TestCase {
	/**
	 * The resolver under test.
	 *
	 * @var ExpenseAccountResolver
	 */
	private ExpenseAccountResolver $resolver;

	/**
	 * Build the resolver over the seeded categories of administration `adm-1`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolver = $this->resolverOver(
			data: [
				'ExpenseCategory' => [
					['id' => 'c-1', 'code' => 'travel-public', 'expenseAccount' => '4510', 'administrationId' => 'adm-1', 'active' => true],
					['id' => 'c-2', 'code' => 'meals', 'expenseAccount' => '4440', 'administrationId' => 'adm-1', 'active' => true],
					['id' => 'c-3', 'code' => 'travel-public', 'expenseAccount' => '9999', 'administrationId' => 'adm-2', 'active' => true],
					['id' => 'c-4', 'code' => 'unmapped', 'expenseAccount' => '', 'administrationId' => 'adm-1', 'active' => true],
				],
				'Administration' => [
					['id' => 'adm-1', 'mileageAccount' => '4530', 'perDiemAccount' => '4060', 'expensePayableAccount' => '2200'],
					['id' => 'adm-3', 'mileageAccount' => ' '],
				],
			]
		);
	}//end setUp()

	/**
	 * A resolver over the given in-memory objects.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $data Objects per schema.
	 *
	 * @return ExpenseAccountResolver The resolver.
	 */
	private function resolverOver(array $data): ExpenseAccountResolver {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new ExpenseAccountResolver(objectService: new InMemoryObjectServiceStub(data: $data), registerLocation: $location);
	}//end resolverOver()

	/**
	 * An account an operator confirmed wins over the category.
	 *
	 * @return void
	 */
	public function testAConfirmedAccountWins(): void {
		$this->assertSame(
			['account' => '4340', 'source' => ExpenseAccountResolver::SOURCE_CONFIRMED, 'reason' => ''],
			$this->resolver->forReceipt(receipt: ['glAccount' => ' 4340 ', 'category' => 'travel-public', 'administrationId' => 'adm-1'])
		);
	}//end testAConfirmedAccountWins()

	/**
	 * Without a confirmed account the category of the receipt's own administration answers.
	 *
	 * @return void
	 */
	public function testTheCategoryOfTheAdministrationAnswers(): void {
		$this->assertSame(
			['account' => '4510', 'source' => ExpenseAccountResolver::SOURCE_CATEGORY, 'reason' => ''],
			$this->resolver->forReceipt(receipt: ['category' => 'travel-public', 'administrationId' => 'adm-1', 'glAccount' => ''])
		);
		$this->assertSame('9999', $this->resolver->forReceipt(receipt: ['category' => 'travel-public', 'administrationId' => 'adm-2'])['account']);
		$this->assertSame('4440', $this->resolver->forReceipt(receipt: ['category' => 'meals', 'administrationId' => 'adm-1'])['account']);
	}//end testTheCategoryOfTheAdministrationAnswers()

	/**
	 * A docudesk suggestion is never taken as the account.
	 *
	 * @return void
	 */
	public function testASuggestionIsNotAnAccount(): void {
		$answer = $this->resolver->forReceipt(receipt: ['receiptNumber' => 'R-7', 'suggestedGlAccount' => '4310', 'administrationId' => 'adm-1']);

		$this->assertSame(
			['account' => null, 'source' => ExpenseAccountResolver::SOURCE_NONE, 'reason' => 'Receipt R-7 has no expense category and no confirmed account.'],
			$answer
		);
	}//end testASuggestionIsNotAnAccount()

	/**
	 * An uncategorised receipt is refused, naming the line.
	 *
	 * @return void
	 */
	public function testAnUncategorisedReceiptHasNoAccount(): void {
		$answer = $this->resolver->forReceipt(
			receipt: ['id' => 'r-1', 'receiptNumber' => 'R-1', 'description' => 'Lunch klant', 'category' => 'uncategorised', 'administrationId' => 'adm-1']
		);

		$this->assertSame(
			['account' => null, 'source' => ExpenseAccountResolver::SOURCE_NONE, 'reason' => "Receipt 'Lunch klant' (R-1) has no expense category and no confirmed account."],
			$answer
		);
	}//end testAnUncategorisedReceiptHasNoAccount()

	/**
	 * A category the administration does not have, or without an account, has no account.
	 *
	 * @return void
	 */
	public function testAnUnknownOrUnmappedCategoryHasNoAccount(): void {
		$this->assertSame(
			"Receipt 'Taxi' has the category 'taxi', which this administration does not have.",
			$this->resolver->forReceipt(receipt: ['vendorName' => 'Taxi', 'category' => 'taxi', 'administrationId' => 'adm-1'])['reason']
		);
		$this->assertSame(
			"Receipt r-9 has the category 'meals', which this administration does not have.",
			$this->resolver->forReceipt(receipt: ['id' => 'r-9', 'category' => 'meals', 'administrationId' => 'adm-2'])['reason']
		);
		$this->assertSame(
			"A receipt has the category 'unmapped', which has no expense account.",
			$this->resolver->forReceipt(receipt: ['category' => 'unmapped', 'administrationId' => 'adm-1'])['reason']
		);
	}//end testAnUnknownOrUnmappedCategoryHasNoAccount()

	/**
	 * A categorised receipt outside any administration reads no category.
	 *
	 * @return void
	 */
	public function testAReceiptWithoutAnAdministrationHasNoAccount(): void {
		$this->assertSame(
			['account' => null, 'source' => ExpenseAccountResolver::SOURCE_NONE, 'reason' => "Receipt R-2 belongs to no administration, so its category 'meals' cannot be read."],
			$this->resolver->forReceipt(receipt: ['receiptNumber' => 'R-2', 'category' => 'meals'])
		);
	}//end testAReceiptWithoutAnAdministrationHasNoAccount()

	/**
	 * A category row of another administration or code is never taken, even
	 * when the store ignores the filters.
	 *
	 * @return void
	 */
	public function testACategoryOfAnotherAdministrationIsNeverTaken(): void {
		$store = new class (['ExpenseCategory' => [['id' => 'c-3', 'code' => 'travel-public', 'expenseAccount' => '9999', 'administrationId' => 'adm-2']]]) extends InMemoryObjectServiceStub {
			/**
			 * Ignore every filter.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         Unused.
			 * @param bool                 $_multitenancy Unused.
			 *
			 * @return array<int, mixed> Every row of the schema.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return parent::findAll(['limit' => ($config['limit'] ?? null)]);
			}//end findAll()
		};
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$resolver = new ExpenseAccountResolver(objectService: $store, registerLocation: $location);

		$this->assertNull($resolver->forReceipt(receipt: ['category' => 'travel-public', 'administrationId' => 'adm-1'])['account']);
	}//end testACategoryOfAnotherAdministrationIsNeverTaken()

	/**
	 * Mileage, per diem and payable lines take the administration's settings.
	 *
	 * @return void
	 */
	public function testTheSettingsAccountsAnswer(): void {
		$this->assertSame(
			[
				['account' => '4530', 'source' => ExpenseAccountResolver::SOURCE_SETTINGS, 'reason' => ''],
				['account' => '4060', 'source' => ExpenseAccountResolver::SOURCE_SETTINGS, 'reason' => ''],
				['account' => '2200', 'source' => ExpenseAccountResolver::SOURCE_SETTINGS, 'reason' => ''],
			],
			[
				$this->resolver->forMileage(administrationId: 'adm-1'),
				$this->resolver->forPerDiem(administrationId: 'adm-1'),
				$this->resolver->payable(administrationId: 'adm-1'),
			]
		);
	}//end testTheSettingsAccountsAnswer()

	/**
	 * A missing setting, administration or scope gives null with the reason.
	 *
	 * @return void
	 */
	public function testAMissingSettingHasNoAccount(): void {
		$this->assertSame(
			[
				"The administration 'adm-3' has no mileage account set (mileageAccount).",
				"The administration 'adm-3' has no per diem account set (perDiemAccount).",
				"The administration 'adm-9' does not exist, so it has no expense payable account.",
				'The claim belongs to no administration, so it has no expense payable account.',
			],
			[
				$this->resolver->forMileage(administrationId: 'adm-3')['reason'],
				$this->resolver->forPerDiem(administrationId: 'adm-3')['reason'],
				$this->resolver->payable(administrationId: 'adm-9')['reason'],
				$this->resolver->payable(administrationId: ' ')['reason'],
			]
		);
		$this->assertNull($this->resolver->forMileage(administrationId: 'adm-3')['account']);
	}//end testAMissingSettingHasNoAccount()
}//end class
