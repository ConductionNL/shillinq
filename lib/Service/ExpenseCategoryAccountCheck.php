<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Expense Category Account Check
 *
 * An expense category names the account its receipts post to. A category
 * pointed at the wrong account books every claim after it wrongly, so a
 * category is only saved with an account that exists in its administration's
 * chart and is an expense account. A balance sheet account such as 1100
 * Debiteuren is refused with the reason.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Says why an expense category's account may not be saved, or nothing.
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
 */
final class ExpenseCategoryAccountCheck {
	/**
	 * The account type an expense category may point at.
	 *
	 * @var string
	 */
	public const EXPENSE_TYPE = 'expenses';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation The register this app's objects live in.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
	) {
	}//end __construct()

	/**
	 * Why the category's expense account is refused, or null when it is an
	 * expense account of the category's administration.
	 *
	 * @param array<string, mixed> $category The category being saved.
	 *
	 * @return string|null The refusal, naming the account.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
	 */
	public function refusal(array $category): ?string {
		$number = trim((string)($category['expenseAccount'] ?? ''));
		if ($number === '') {
			return 'An expense category needs an expense account.';
		}

		$administrationId = trim((string)($category['administrationId'] ?? ''));
		if ($administrationId === '') {
			return "Account {$number} cannot be checked: the expense category belongs to no administration.";
		}

		$account = $this->account(number: $number, administrationId: $administrationId);
		if ($account === null) {
			return "Account {$number} does not exist in the chart of accounts of this administration.";
		}

		$type = (string)($account['accountType'] ?? '');
		if ($type !== self::EXPENSE_TYPE) {
			$name = trim((string)($account['name'] ?? ''));
			return trim("Account {$number} {$name}") . " is not an expense account (its type is '{$type}').";
		}

		return null;
	}//end refusal()

	/**
	 * The administration's account with this number, or null.
	 *
	 * @param string $number           The account number.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>|null The account.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
	 */
	private function account(string $number, string $administrationId): ?array {
		$rows = $this->objectService
			->setRegister($this->registerLocation->getRegisterSlug())
			->setSchema('Account')
			->findAll(['filters' => ['accountNumber' => $number, 'administrationId' => $administrationId], 'limit' => 2]);

		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			// A filter the store ignores must not answer with another
			// administration's account or another number.
			if ($record !== null
				&& (string)($record['accountNumber'] ?? '') === $number
				&& (string)($record['administrationId'] ?? '') === $administrationId
			) {
				return $record;
			}
		}

		return null;
	}//end account()
}//end class
