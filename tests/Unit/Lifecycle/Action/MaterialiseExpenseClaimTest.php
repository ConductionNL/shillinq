<?php

/**
 * Unit tests for the expense claim posting of MaterialiseGlTransactionAction.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\ExpenseClaimPosting;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Lifecycle\PostingStamps;
use OCA\Shillinq\Service\ExpenseAccountResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-ECM-003: an approved claim posts itself on the accounts the resolver
 * answers, and a line without an account stops the post, naming the line.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(MaterialiseGlTransactionAction::class)]
#[CoversClass(ExpenseClaimPosting::class)]
#[UsesClass(ExpenseAccountResolver::class)]
#[UsesClass(ObjectIdentifier::class)]
#[UsesClass(PostingStamps::class)]
final class MaterialiseExpenseClaimTest extends TestCase {
	/**
	 * Everything saved, as [schema, object].
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The declared parameters of the claim's post transition.
	 *
	 * @var array<string, mixed>
	 */
	private const POST = ['sourceSchema' => 'ExpenseClaimEntry', 'keepBalanced' => true, 'resolver' => 'expense-account'];

	/**
	 * The design's example: S. de Vries's train, lunch and 86 km balance to
	 * EUR 100.08 owed from 2200.
	 *
	 * @return void
	 */
	public function testTheClaimOfSDeVriesPostsItself(): void {
		$result = $this->action()->execute($this->claim(), [], self::POST, MaterialiseGlTransactionAction::class);

		$transactions = $this->savedOf(schema: 'GLTransaction');
		$this->assertCount(1, $transactions);
		$this->assertSame('ExpenseClaimEntry:claim-1', $transactions[0]['sourceReference']);
		$this->assertSame('EXP-2026-0007', $transactions[0]['transactionNumber']);
		$this->assertSame('expense-claim', $transactions[0]['journalType']);
		$this->assertSame('2026-05-31', $transactions[0]['postingDate']);
		$this->assertSame('2026-05', $transactions[0]['periodId']);

		$lines = array_map(
			static fn (array $line): array => [$line['accountNumber'], $line['side'], $line['amount'], ($line['costCenterCode'] ?? '')],
			$this->savedOf(schema: 'GLLine')
		);
		$this->assertSame(
			[
				['4510', 'debit', 39.27, 'CC100'],
				['1230', 'debit', 3.53, ''],
				['4440', 'debit', 37.5, 'CC200'],
				['4530', 'debit', 19.78, 'CC100'],
				['2200', 'credit', 100.08, ''],
			],
			$lines
		);
		$this->assertSame($transactions[0]['id'], $result['glTransactionId']);
	}//end testTheClaimOfSDeVriesPostsItself()

	/**
	 * A receipt left uncategorised without a confirmed account refuses the
	 * post, naming the receipt, and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUncategorisedReceiptStopsThePost(): void {
		$claim = $this->claim();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			"The expense claim was not posted: Receipt 'Lunch met klant' (R-2) has no expense category and no confirmed account."
		);

		try {
			$this->action(lunchCategory: ExpenseAccountResolver::UNCATEGORISED)->execute($claim, [], self::POST, MaterialiseGlTransactionAction::class);
		} finally {
			$this->assertSame([], $this->saved, 'a refused post writes no transaction');
		}
	}//end testAnUncategorisedReceiptStopsThePost()

	/**
	 * A confirmed account on the receipt wins over its category's account.
	 *
	 * @return void
	 */
	public function testAConfirmedAccountWins(): void {
		$action = $this->action(lunchCategory: ExpenseAccountResolver::UNCATEGORISED, lunchAccount: '4060');
		$action->execute($this->claim(), [], self::POST, MaterialiseGlTransactionAction::class);

		$this->assertSame('4060', $this->savedOf(schema: 'GLLine')[2]['accountNumber']);
		// Without a category the VAT is deducted under the default rule (full).
		$this->assertSame(34.4, $this->savedOf(schema: 'GLLine')[2]['amount']);
	}//end testAConfirmedAccountWins()

	/**
	 * The reimbursable declaration after the plain one finds its transaction
	 * and sets its own back reference; nothing is booked twice.
	 *
	 * @return void
	 */
	public function testTheReimbursableDeclarationSetsItsBackReference(): void {
		$action = $this->action();
		$posted = $action->execute($this->claim(), [], self::POST, MaterialiseGlTransactionAction::class);
		$again = $action->execute(
			$posted,
			[],
			self::POST + ['settlementMode' => 'reimbursable', 'backReferenceField' => 'glReimbursableTransactionId'],
			MaterialiseGlTransactionAction::class
		);

		$this->assertCount(1, $this->savedOf(schema: 'GLTransaction'));
		$this->assertSame($posted['glTransactionId'], $again['glReimbursableTransactionId']);
	}//end testTheReimbursableDeclarationSetsItsBackReference()

	/**
	 * A pass-through claim is left alone by the plain declaration and refused
	 * by its own: no mapper books it yet.
	 *
	 * @return void
	 */
	public function testAPassThroughClaimIsNotPostedAsAReimbursement(): void {
		$claim = $this->claim() + ['settlementMode' => 'pass-through'];
		$action = $this->action();

		$this->assertSame($claim, $action->execute($claim, [], self::POST, MaterialiseGlTransactionAction::class));
		$this->assertSame([], $this->saved);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('A pass-through expense claim cannot be posted to the ledger yet: no posting is defined for it.');
		$action->execute($claim, [], self::POST + ['settlementMode' => 'pass-through'], MaterialiseGlTransactionAction::class);
	}//end testAPassThroughClaimIsNotPostedAsAReimbursement()

	/**
	 * Without a resolver an expense claim is refused rather than guessed.
	 *
	 * @return void
	 */
	public function testWithoutAResolverAClaimIsRefused(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$action = new MaterialiseGlTransactionAction(
			objectService: new InMemoryObjectServiceStub(data: []),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->expectExceptionMessage('An expense claim cannot be posted: no expense account resolver is available.');
		$action->execute($this->claim(), [], self::POST, MaterialiseGlTransactionAction::class);
	}//end testWithoutAResolverAClaimIsRefused()

	/**
	 * A linked receipt that does not exist refuses the post.
	 *
	 * @return void
	 */
	public function testAMissingLineRefusesThePost(): void {
		$claim = $this->claim();
		$claim['receiptIds'][] = 'r-gone';

		$this->expectExceptionMessage('The expense claim was not posted: its Receipt r-gone does not exist.');
		$this->action()->execute($claim, [], self::POST, MaterialiseGlTransactionAction::class);
	}//end testAMissingLineRefusesThePost()

	/**
	 * The approved claim of S. de Vries.
	 *
	 * @return array<string, mixed>
	 */
	private function claim(): array {
		return [
			'id' => 'claim-1',
			'claimNumber' => 'EXP-2026-0007',
			'employeeId' => 'person-sdevries',
			'toDate' => '2026-05-31',
			'administrationId' => 'adm-1',
			'currency' => 'EUR',
			'receiptIds' => ['r-train', 'r-lunch'],
			'mileageIds' => ['m-1'],
			'state' => 'posted',
		];
	}//end claim()

	/**
	 * The action over the claim's lines, categories and settings.
	 *
	 * @param string $lunchCategory The lunch receipt's category.
	 * @param string $lunchAccount  The lunch receipt's confirmed account.
	 *
	 * @return MaterialiseGlTransactionAction
	 */
	private function action(string $lunchCategory = 'meals', string $lunchAccount = ''): MaterialiseGlTransactionAction {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(
			data: [
				'Receipt' => [
					[
						'id' => 'r-train', 'receiptNumber' => 'R-1', 'description' => 'Trein Utrecht-Amsterdam', 'amount' => 42.8,
						'amountInBaseCurrency' => 42.8, 'vatAmount' => 3.53, 'category' => 'travel-public', 'costCentreCode' => 'CC100',
						'administrationId' => 'adm-1',
					],
					[
						'id' => 'r-lunch', 'receiptNumber' => 'R-2', 'description' => 'Lunch met klant', 'amount' => 37.5, 'vatAmount' => 3.1,
						'category' => $lunchCategory, 'glAccount' => $lunchAccount, 'administrationId' => 'adm-1',
					],
				],
				'MileageEntry' => [
					[
						'id' => 'm-1', 'distance' => 86, 'ratePerKm' => 0.23, 'totalAmount' => 19.78, 'purpose' => 'Klantbezoek Zwolle',
						'costCentreCode' => 'CC100', 'administrationId' => 'adm-1',
					],
				],
				'ExpenseCategory' => [
					['id' => 'c-1', 'code' => 'travel-public', 'expenseAccount' => '4510', 'vatDeductionRule' => 'full', 'administrationId' => 'adm-1'],
					[
						'id' => 'c-2', 'code' => 'meals', 'expenseAccount' => '4440', 'vatDeductionRule' => 'none', 'defaultCostCentre' => 'CC200',
						'active' => true, 'administrationId' => 'adm-1',
					],
				],
				'Administration' => [
					['id' => 'adm-1', 'mileageAccount' => '4530', 'perDiemAccount' => '4060', 'expensePayableAccount' => '2200'],
				],
			],
			saveSink: $this->saved
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new MaterialiseGlTransactionAction(
			objectService: $store,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			expenseAccounts: new ExpenseAccountResolver(objectService: $store, registerLocation: $location)
		);
	}//end action()

	/**
	 * The objects saved for one schema, in order.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function savedOf(string $schema): array {
		$out = [];
		foreach ($this->saved as $entry) {
			if ($entry['schema'] === $schema) {
				$out[] = $entry['object'];
			}
		}

		return $out;
	}//end savedOf()
}//end class
