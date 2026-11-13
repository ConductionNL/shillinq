<?php

/**
 * Expense Account Resolver
 *
 * An expense claim posts each of its lines to an expense account and the
 * claim total to the account the business owes its staff from. No one types
 * those account numbers: a receipt takes the account an operator confirmed on
 * it, else the account of its expense category; mileage and per diem lines
 * take the administration's mileage and per diem accounts; the total goes to
 * the administration's expense payable account.
 *
 * Nothing is guessed. A line without an account resolves to null with a reason
 * that names the line, and the caller refuses the post with that reason: a
 * claim booked to the wrong account is worse than a claim that waits. The
 * docudesk suggestion on a receipt is never read here; it is shown beside the
 * resolved account and only counts once a person confirmed it as `glAccount`.
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
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Resolves the general-ledger account of each line of an expense claim.
 *
 * Every answer has the same shape: the account number or null, where it came
 * from, and why it is null when it is.
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.1
 */
final class ExpenseAccountResolver {
	/**
	 * The account an operator confirmed on the receipt.
	 *
	 * @var string
	 */
	public const SOURCE_CONFIRMED = 'confirmed';

	/**
	 * The account of the receipt's expense category.
	 *
	 * @var string
	 */
	public const SOURCE_CATEGORY = 'category';

	/**
	 * An account from the administration's expense settings.
	 *
	 * @var string
	 */
	public const SOURCE_SETTINGS = 'settings';

	/**
	 * No account.
	 *
	 * @var string
	 */
	public const SOURCE_NONE = 'none';

	/**
	 * The category code a receipt carries until someone picks one.
	 *
	 * @var string
	 */
	public const UNCATEGORISED = 'uncategorised';

	/**
	 * The administration setting holding the mileage account.
	 *
	 * @var string
	 */
	public const MILEAGE_ACCOUNT = 'mileageAccount';

	/**
	 * The administration setting holding the per diem account.
	 *
	 * @var string
	 */
	public const PER_DIEM_ACCOUNT = 'perDiemAccount';

	/**
	 * The administration setting holding the expense payable account.
	 *
	 * @var string
	 */
	public const PAYABLE_ACCOUNT = 'expensePayableAccount';

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
	 * The account a receipt line posts to: the confirmed account, else its
	 * category's account, else null with the reason.
	 *
	 * @param array<string, mixed> $receipt The receipt.
	 *
	 * @return array{account: string|null, source: string, reason: string} The answer.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.1
	 */
	public function forReceipt(array $receipt): array {
		$line = $this->lineName(receipt: $receipt);

		$confirmed = trim((string)($receipt['glAccount'] ?? ''));
		if ($confirmed !== '') {
			return $this->found(account: $confirmed, source: self::SOURCE_CONFIRMED);
		}

		$code = trim((string)($receipt['category'] ?? ''));
		if ($code === '' || $code === self::UNCATEGORISED) {
			return $this->missing(reason: "{$line} has no expense category and no confirmed account.");
		}

		$administrationId = trim((string)($receipt['administrationId'] ?? ''));
		if ($administrationId === '') {
			return $this->missing(reason: "{$line} belongs to no administration, so its category '{$code}' cannot be read.");
		}

		$category = $this->category(code: $code, administrationId: $administrationId);
		if ($category === null) {
			return $this->missing(reason: "{$line} has the category '{$code}', which this administration does not have.");
		}

		$account = trim((string)($category['expenseAccount'] ?? ''));
		if ($account === '') {
			return $this->missing(reason: "{$line} has the category '{$code}', which has no expense account.");
		}

		return $this->found(account: $account, source: self::SOURCE_CATEGORY);
	}//end forReceipt()

	/**
	 * The account a mileage line posts to.
	 *
	 * @param string $administrationId The administration of the claim.
	 *
	 * @return array{account: string|null, source: string, reason: string} The answer.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.1
	 */
	public function forMileage(string $administrationId): array {
		return $this->setting(administrationId: $administrationId, setting: self::MILEAGE_ACCOUNT, purpose: 'mileage');
	}//end forMileage()

	/**
	 * The account a per diem line posts to.
	 *
	 * @param string $administrationId The administration of the claim.
	 *
	 * @return array{account: string|null, source: string, reason: string} The answer.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.1
	 */
	public function forPerDiem(string $administrationId): array {
		return $this->setting(administrationId: $administrationId, setting: self::PER_DIEM_ACCOUNT, purpose: 'per diem');
	}//end forPerDiem()

	/**
	 * The account the claim total is owed from.
	 *
	 * @param string $administrationId The administration of the claim.
	 *
	 * @return array{account: string|null, source: string, reason: string} The answer.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.1
	 */
	public function payable(string $administrationId): array {
		return $this->setting(administrationId: $administrationId, setting: self::PAYABLE_ACCOUNT, purpose: 'expense payable');
	}//end payable()

	/**
	 * One account from the administration's expense settings.
	 *
	 * @param string $administrationId The administration.
	 * @param string $setting          The setting's property name.
	 * @param string $purpose          What the account is for, for the reason.
	 *
	 * @return array{account: string|null, source: string, reason: string} The answer.
	 */
	private function setting(string $administrationId, string $setting, string $purpose): array {
		$administrationId = trim($administrationId);
		if ($administrationId === '') {
			return $this->missing(reason: "The claim belongs to no administration, so it has no {$purpose} account.");
		}

		$administration = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'Administration'), id: $administrationId);
		if ($administration === null) {
			return $this->missing(reason: "The administration '{$administrationId}' does not exist, so it has no {$purpose} account.");
		}

		$account = trim((string)($administration[$setting] ?? ''));
		if ($account === '') {
			return $this->missing(reason: "The administration '{$administrationId}' has no {$purpose} account set ({$setting}).");
		}

		return $this->found(account: $account, source: self::SOURCE_SETTINGS);
	}//end setting()

	/**
	 * The administration's expense category with this code, or null.
	 *
	 * @param string $code             The category code.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>|null The category.
	 */
	private function category(string $code, string $administrationId): ?array {
		$rows = $this->scoped(schema: 'ExpenseCategory')->findAll(
			['filters' => ['administrationId' => $administrationId, 'code' => $code], 'limit' => 2]
		);

		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			// A filter the store ignores must not hand back another
			// administration's category, or a category with another code.
			if ($record !== null
				&& (string)($record['administrationId'] ?? '') === $administrationId
				&& (string)($record['code'] ?? '') === $code
			) {
				return $record;
			}
		}

		return null;
	}//end category()

	/**
	 * How a reason names a receipt line.
	 *
	 * @param array<string, mixed> $receipt The receipt.
	 *
	 * @return string The name.
	 */
	private function lineName(array $receipt): string {
		$label = trim((string)($receipt['description'] ?? $receipt['vendorName'] ?? ''));
		$id = trim((string)($receipt['receiptNumber'] ?? ''));
		if ($id === '') {
			$id = ObjectIdentifier::resolve(saved: $receipt);
		}

		if ($label !== '' && $id !== '') {
			return "Receipt '{$label}' ({$id})";
		}

		if ($label !== '') {
			return "Receipt '{$label}'";
		}

		if ($id !== '') {
			return "Receipt {$id}";
		}

		return 'A receipt';
	}//end lineName()

	/**
	 * A resolved account.
	 *
	 * @param string $account The account number.
	 * @param string $source  Where it came from.
	 *
	 * @return array{account: string, source: string, reason: string} The answer.
	 */
	private function found(string $account, string $source): array {
		return ['account' => $account, 'source' => $source, 'reason' => ''];
	}//end found()

	/**
	 * No account, and why.
	 *
	 * @param string $reason The reason, naming the line.
	 *
	 * @return array{account: null, source: string, reason: string} The answer.
	 */
	private function missing(string $reason): array {
		return ['account' => null, 'source' => self::SOURCE_NONE, 'reason' => $reason];
	}//end missing()

	/**
	 * The object service scoped to this app's register and one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface The scoped service.
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
