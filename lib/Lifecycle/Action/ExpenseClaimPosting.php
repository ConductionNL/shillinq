<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Expense Claim Posting
 *
 * The posting lines of an approved expense claim, for
 * MaterialiseGlTransactionAction. Every account comes from
 * ExpenseAccountResolver: a receipt line takes the account an operator
 * confirmed on it, else its category's account; mileage and per diem lines
 * take the administration's mileage and per diem accounts; the claim total is
 * credited to the administration's expense payable account. A line without an
 * account refuses the whole post with the resolver's reason, which names the
 * line, so the transition aborts and the claim stays approved.
 *
 * VAT: a receipt whose category deducts VAT (`vatDeductionRule` full) books
 * its `vatAmount` to input VAT and the rest to the expense account; a
 * category that deducts none (meals in horeca) books the whole amount to the
 * expense account. A rule this change does not know yet (a partial rule)
 * deducts nothing, so no VAT is reclaimed that may not be.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\ExpenseAccountResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
use RuntimeException;

/**
 * Maps an ExpenseClaimEntry onto balanced posting lines.
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
 */
final class ExpenseClaimPosting {
	/**
	 * The VAT deduction rule that books a receipt's VAT apart.
	 *
	 * @var string
	 */
	public const DEDUCT_FULL = 'full';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService   OpenRegister's object service.
	 * @param string                 $register        The register slug.
	 * @param ExpenseAccountResolver $accounts        The account of each line.
	 * @param string                 $inputVatAccount The input VAT account.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly string $register,
		private readonly ExpenseAccountResolver $accounts,
		private readonly string $inputVatAccount,
	) {
	}//end __construct()

	/**
	 * The header and lines of the claim's posting.
	 *
	 * @param array<string, mixed> $claim    The ExpenseClaimEntry.
	 * @param string               $sourceId The claim id.
	 *
	 * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
	 *
	 * @throws RuntimeException When a line has no account or a linked line does not exist.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
	 */
	public function map(array $claim, string $sourceId): array {
		$administrationId = (string)($claim['administrationId'] ?? '');
		$label = 'Expense claim ' . (string)($claim['claimNumber'] ?? $sourceId);

		$lines = [];
		foreach ($this->linked(claim: $claim, field: 'receiptIds', schema: 'Receipt') as $receipt) {
			$receipt['administrationId'] = (string)($receipt['administrationId'] ?? $administrationId);
			foreach ($this->receiptLines(receipt: $receipt, label: $label) as $line) {
				$lines[] = $line;
			}
		}

		foreach ($this->linked(claim: $claim, field: 'mileageIds', schema: 'MileageEntry') as $mileage) {
			$lines[] = $this->debit(
				answer: $this->accounts->forMileage(administrationId: $administrationId),
				amount: ($mileage['totalAmount'] ?? 0),
				description: $this->describe(item: $mileage, fallback: $label . ' mileage'),
				costCentre: (string)($mileage['costCentreCode'] ?? '')
			);
		}

		foreach ($this->linked(claim: $claim, field: 'perDiemIds', schema: 'PerDiem') as $perDiem) {
			$lines[] = $this->debit(
				answer: $this->accounts->forPerDiem(administrationId: $administrationId),
				amount: ($perDiem['allowanceAmount'] ?? 0),
				description: $this->describe(item: $perDiem, fallback: $label . ' per diem'),
				costCentre: (string)($perDiem['costCentreCode'] ?? '')
			);
		}

		if ($lines === []) {
			throw new RuntimeException("{$label} has no lines to post.");
		}

		$total = 0;
		foreach ($lines as $line) {
			$total += (int)$line['cents'];
		}

		$lines[] = [
			'accountNumber' => $this->account(answer: $this->accounts->payable(administrationId: $administrationId)),
			'side' => 'credit',
			'cents' => $total,
			'description' => $label,
		];

		return [
			'header' => [
				'transactionNumber' => (string)($claim['claimNumber'] ?? ('EXP-' . $sourceId)),
				'postingDate' => (string)($claim['toDate'] ?? date('Y-m-d')),
				'description' => $label,
				'journalType' => 'expense-claim',
			],
			'lines' => $lines,
		];
	}//end map()

	/**
	 * The expense line of a receipt, and its input VAT line when its category
	 * deducts VAT.
	 *
	 * @param array<string, mixed> $receipt The receipt.
	 * @param string               $label   The claim label.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
	 */
	private function receiptLines(array $receipt, string $label): array {
		$account = $this->account(answer: $this->accounts->forReceipt(receipt: $receipt));
		$category = $this->category(receipt: $receipt);

		$gross = $this->cents(amount: ($receipt['amountInBaseCurrency'] ?? ($receipt['amount'] ?? 0)));
		$vat = 0;
		if ((string)($category['vatDeductionRule'] ?? self::DEDUCT_FULL) === self::DEDUCT_FULL) {
			$vat = min($gross, max(0, $this->cents(amount: ($receipt['vatAmount'] ?? 0))));
		}

		$costCentre = trim((string)($receipt['costCentreCode'] ?? ''));
		if ($costCentre === '') {
			$costCentre = trim((string)($category['defaultCostCentre'] ?? ''));
		}

		$description = $this->describe(item: $receipt, fallback: $label);
		$lines = [$this->line(account: $account, cents: ($gross - $vat), description: $description, costCentre: $costCentre)];
		if ($vat > 0) {
			$lines[] = $this->line(account: $this->inputVatAccount, cents: $vat, description: $description . ' VAT', costCentre: '');
		}

		return $lines;
	}//end receiptLines()

	/**
	 * A debit line on a resolved account.
	 *
	 * @param array{account: string|null, source: string, reason: string} $answer      The resolver's answer.
	 * @param mixed                                                       $amount      The amount in euros.
	 * @param string                                                      $description The line description.
	 * @param string                                                      $costCentre  The cost centre, or ''.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
	 */
	private function debit(array $answer, mixed $amount, string $description, string $costCentre): array {
		return $this->line(
			account: $this->account(answer: $answer),
			cents: $this->cents(amount: $amount),
			description: $description,
			costCentre: $costCentre
		);
	}//end debit()

	/**
	 * One debit posting line.
	 *
	 * @param string $account     The account number.
	 * @param int    $cents       The amount in cents.
	 * @param string $description The line description.
	 * @param string $costCentre  The cost centre, or ''.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
	 */
	private function line(string $account, int $cents, string $description, string $costCentre): array {
		$line = [
			'accountNumber' => $account,
			'side' => 'debit',
			'cents' => $cents,
			'description' => $description,
		];
		if ($costCentre !== '') {
			$line['costCenterCode'] = $costCentre;
		}

		return $line;
	}//end line()

	/**
	 * The account of a resolver answer; no account refuses the post with the
	 * resolver's reason.
	 *
	 * @param array{account: string|null, source: string, reason: string} $answer The answer.
	 *
	 * @return string The account number.
	 *
	 * @throws RuntimeException When the answer has no account.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
	 */
	private function account(array $answer): string {
		$account = $answer['account'];
		if ($account === null || $account === '') {
			throw new RuntimeException('The expense claim was not posted: ' . $answer['reason']);
		}

		return $account;
	}//end account()

	/**
	 * The records a claim field links, in order; a missing one refuses the post.
	 *
	 * @param array<string, mixed> $claim  The claim.
	 * @param string               $field  The claim field listing the ids.
	 * @param string               $schema The linked schema.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When a linked record does not exist.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
	 */
	private function linked(array $claim, string $field, string $schema): array {
		$records = [];
		foreach ((array)($claim[$field] ?? []) as $id) {
			$id = trim((string)$id);
			if ($id === '') {
				continue;
			}

			$record = ObjectIdentifier::findOne(scoped: $this->objectService->setRegister($this->register)->setSchema($schema), id: $id);
			if ($record === null) {
				throw new RuntimeException("The expense claim was not posted: its {$schema} {$id} does not exist.");
			}

			$records[] = $record;
		}

		return $records;
	}//end linked()

	/**
	 * The receipt's expense category, or [] when it has none or it is not found.
	 *
	 * @param array<string, mixed> $receipt The receipt.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
	 */
	private function category(array $receipt): array {
		$code = trim((string)($receipt['category'] ?? ''));
		$administrationId = (string)($receipt['administrationId'] ?? '');
		if ($code === '' || $code === ExpenseAccountResolver::UNCATEGORISED || $administrationId === '') {
			return [];
		}

		$rows = $this->objectService->setRegister($this->register)->setSchema('ExpenseCategory')->findAll(
			['filters' => ['administrationId' => $administrationId, 'code' => $code], 'limit' => 2]
		);
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)($record['code'] ?? '') === $code && (string)($record['administrationId'] ?? '') === $administrationId) {
				return $record;
			}
		}

		return [];
	}//end category()

	/**
	 * A line description from the item, else the fallback.
	 *
	 * @param array<string, mixed> $item     The receipt, mileage entry or per diem.
	 * @param string               $fallback The fallback.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
	 */
	private function describe(array $item, string $fallback): string {
		$text = trim((string)($item['description'] ?? ($item['purpose'] ?? ($item['vendorName'] ?? ''))));
		if ($text === '') {
			return $fallback;
		}

		return $text;
	}//end describe()

	/**
	 * An amount in euros as integer cents.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
	 */
	private function cents(mixed $amount): int {
		if (is_numeric($amount) === false) {
			return 0;
		}

		return (int)round((float)$amount * 100);
	}//end cents()
}//end class
