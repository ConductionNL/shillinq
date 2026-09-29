<?php

/**
 * Unit tests for MaterialiseGlTransactionAction.
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
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Standards\RuleEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A posted source becomes exactly one balanced, posted GLTransaction.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MaterialiseGlTransactionActionTest extends TestCase {

	/**
	 * The store behind the ObjectService mock.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * Build the action over a fresh store, with every app-config value unset.
	 *
	 * @return MaterialiseGlTransactionAction
	 */
	private function action(): MaterialiseGlTransactionAction {
		$this->store = new InMemoryObjectStore();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return new MaterialiseGlTransactionAction(
			$this->store->mock($this),
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);
	}//end action()

	/**
	 * The memorial entry of the design's seed data: 4000 against 1100.
	 *
	 * @param float $credit The credit amount.
	 *
	 * @return array<string,mixed>
	 */
	private function journalEntry(float $credit = 1200.0): array {
		return [
			'id' => 'je-1',
			'journalNumber' => 'MEM-2026-0001',
			'entryDate' => '2026-03-14',
			'description' => 'Huur maart',
			'administrationId' => 'adm-1',
			'state' => 'posted',
			'lines' => [
				['accountNumber' => '4000', 'side' => 'debit', 'amount' => 1200.0, 'description' => 'Huisvesting'],
				['accountNumber' => '1100', 'side' => 'credit', 'amount' => $credit, 'description' => 'Bank'],
			],
		];
	}//end journalEntry()

	/**
	 * A balanced journal entry posts one GLTransaction with its lines 1:1.
	 *
	 * @return void
	 */
	public function testABalancedJournalEntryPostsOneTransaction(): void {
		$parameters = ['sourceSchema' => 'JournalEntry', 'keepBalanced' => true];
		$result = $this->action()->execute($this->journalEntry(), [], $parameters, MaterialiseGlTransactionAction::class);

		$transactions = $this->store->savedOf('GLTransaction');
		self::assertCount(1, $transactions);
		self::assertSame('posted', $transactions[0]['state']);
		self::assertSame('JournalEntry:je-1', $transactions[0]['sourceReference']);
		self::assertSame('je-1', $transactions[0]['journalEntryId']);
		self::assertSame('2026-03', $transactions[0]['periodId']);
		self::assertSame('adm-1', $transactions[0]['administrationId']);

		$lines = $this->store->savedOf('GLLine');
		self::assertCount(2, $lines);
		self::assertSame(['4000', 'debit', 1200.0], [$lines[0]['accountNumber'], $lines[0]['side'], $lines[0]['amount']]);
		self::assertSame(['1100', 'credit', 1200.0], [$lines[1]['accountNumber'], $lines[1]['side'], $lines[1]['amount']]);
		self::assertSame($transactions[0]['id'], $lines[0]['transactionId']);

		self::assertSame($transactions[0]['id'], $result['glTransactionId'], 'The back reference is returned so it saves with the transition.');
	}//end testABalancedJournalEntryPostsOneTransaction()

	/**
	 * A transaction this handler writes as posted carries the posting stamps,
	 * so it meets the same mandatory ledger rules a posted entry from the
	 * ledger page does (REQ-LPP-004).
	 *
	 * @return void
	 */
	public function testAMaterialisedTransactionMeetsTheMandatoryLedgerRules(): void {
		$parameters = ['sourceSchema' => 'JournalEntry', 'keepBalanced' => true];
		$this->action()->execute($this->journalEntry(), [], $parameters, MaterialiseGlTransactionAction::class);

		$transaction = $this->store->savedOf('GLTransaction')[0];
		self::assertTrue($transaction['postingLocked']);
		self::assertSame('2036-12-31', $transaction['retentionUntil']);
		self::assertSame('post', $transaction['auditTrail'][0]['action']);
		$header = $transaction;
		unset($header['id']);
		self::assertSame([], RegisterSchema::errors(slug: 'GLTransaction', object: $header), 'the stamped header validates against the merged register');

		$transaction['lines'] = $this->store->savedOf('GLLine');
		$mandatory = [];
		foreach (RuleEngine::evaluate('GLTransaction', $transaction, ['jurisdiction' => 'NL']) as $violation) {
			if ($violation->severity === 'mandatory') {
				$mandatory[] = $violation->ruleId;
			}
		}

		self::assertSame([], $mandatory);
	}//end testAMaterialisedTransactionMeetsTheMandatoryLedgerRules()

	/**
	 * An unbalanced entry is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnbalancedJournalEntryIsRefusedAndWritesNothing(): void {
		$action = $this->action();

		try {
			$parameters = ['sourceSchema' => 'JournalEntry', 'keepBalanced' => true];
			$action->execute($this->journalEntry(1000.0), [], $parameters, MaterialiseGlTransactionAction::class);
			self::fail('An unbalanced entry must not post.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('not balanced', $e->getMessage());
			self::assertStringContainsString('1200.00', $e->getMessage());
			self::assertStringContainsString('1000.00', $e->getMessage());
		}

		self::assertSame([], $this->store->saved);
	}//end testAnUnbalancedJournalEntryIsRefusedAndWritesNothing()

	/**
	 * A repeated run, or a source already carrying its back reference, books nothing twice.
	 *
	 * @return void
	 */
	public function testARepeatedRunDoesNotPostTwice(): void {
		$action = $this->action();
		$entry = $this->journalEntry();

		$first = $action->execute($entry, [], ['sourceSchema' => 'JournalEntry'], MaterialiseGlTransactionAction::class);
		// Same source, back reference not yet saved: found by sourceReference.
		$second = $action->execute($entry, [], ['sourceSchema' => 'JournalEntry'], MaterialiseGlTransactionAction::class);
		// Back reference saved: returned untouched.
		$third = $action->execute($first, [], ['sourceSchema' => 'JournalEntry'], MaterialiseGlTransactionAction::class);

		self::assertCount(1, $this->store->savedOf('GLTransaction'));
		self::assertSame($first['glTransactionId'], $second['glTransactionId']);
		self::assertSame($first, $third);
	}//end testARepeatedRunDoesNotPostTwice()

	/**
	 * An issued invoice of EUR 1,210 books 1,210 on receivables, 1,000 on
	 * revenue and 210 on VAT (REQ-LPP-007).
	 *
	 * @return void
	 */
	public function testAnIssuedSalesInvoiceBooksReceivablesRevenueAndVat(): void {
		$invoice = [
			'id' => 'ar-1',
			'invoiceNumber' => '2026-0042',
			'invoiceDate' => '2026-06-30',
			'periodId' => '2026-06',
			'administrationId' => 'adm-1',
			'currency' => 'EUR',
			'grossAmount' => 1210.0,
			'netAmount' => 1000.0,
			'vatAmount' => 210.0,
			'invoiceLines' => [
				['itemName' => 'Advies', 'netAmount' => 600.0],
				['itemName' => 'Training', 'netAmount' => 400.0],
			],
			'lifecycleState' => 'issued',
		];

		$result = $this->action()->execute($invoice, [], ['sourceSchema' => 'ARInvoice'], MaterialiseGlTransactionAction::class);

		$byAccount = [];
		foreach ($this->store->savedOf('GLLine') as $line) {
			$byAccount[$line['accountNumber']][] = [$line['side'], $line['amount']];
		}

		self::assertSame([['debit', 1210.0]], $byAccount['1100'], 'receivables');
		self::assertSame([['credit', 600.0], ['credit', 400.0]], $byAccount['8000'], 'revenue per line');
		self::assertSame([['credit', 210.0]], $byAccount['2110'], 'output VAT');

		$transaction = $this->store->savedOf('GLTransaction')[0];
		self::assertSame('ar-invoice', $transaction['journalType']);
		self::assertSame('2026-06', $transaction['periodId']);
		self::assertSame($transaction['id'], $result['glTransactionId']);
	}//end testAnIssuedSalesInvoiceBooksReceivablesRevenueAndVat()

	/**
	 * Post an invoice and group its GL lines by account.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice.
	 *
	 * @return array<string,list<array{0: string, 1: float}>>
	 */
	private function postedByAccount(array $invoice): array {
		$this->action()->execute($invoice, [], ['sourceSchema' => 'ARInvoice'], MaterialiseGlTransactionAction::class);

		$byAccount = [];
		foreach ($this->store->savedOf('GLLine') as $line) {
			$byAccount[$line['accountNumber']][] = [$line['side'], $line['amount']];
		}

		return $byAccount;
	}//end postedByAccount()

	/**
	 * The spec scenario of REQ-SDP-002: the down payment of EUR 5,445 credits
	 * 4,500 to 2310 and 945 to VAT, and nothing to revenue.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function testADownPaymentIsBookedAsAnAdvanceNotAsRevenue(): void {
		$byAccount = $this->postedByAccount(
			[
				'id' => 'ar-dp', 'invoiceNumber' => '2026-0412', 'invoiceDate' => '2026-06-01', 'administrationId' => 'adm-kvl',
				'grossAmount' => 5445.0, 'netAmount' => 4500.0, 'vatAmount' => 945.0, 'invoiceTypeCode' => '386',
				'invoiceLines' => [['itemName' => 'Down payment on order Keuken Eiland 2026-117', 'netAmount' => 4500.0, 'vatRate' => 0.21]],
				'downPayment' => ['kind' => 'down-payment', 'orderReference' => 'order-117'],
			]
		);

		self::assertSame([['debit', 5445.0]], $byAccount['1100'], 'receivables');
		self::assertSame([['credit', 4500.0]], $byAccount['2310'], 'advances received');
		self::assertSame([['credit', 945.0]], $byAccount['2110'], 'output VAT');
		self::assertArrayNotHasKey('8000', $byAccount, 'nothing on revenue');
	}//end testADownPaymentIsBookedAsAnAdvanceNotAsRevenue()

	/**
	 * REQ-SDP-003: the final invoice books the full revenue, and the deduction
	 * line debits the advances account.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function testAFinalInvoiceBooksFullRevenueAndReleasesTheAdvance(): void {
		$byAccount = $this->postedByAccount(
			[
				'id' => 'ar-kitchen', 'invoiceNumber' => '2026-0587', 'invoiceDate' => '2026-11-02', 'administrationId' => 'adm-kvl',
				'grossAmount' => 12705.0, 'netAmount' => 10500.0, 'vatAmount' => 2205.0,
				'invoiceLines' => [
					['itemName' => 'Keuken Eiland', 'netAmount' => 15000.0, 'vatRate' => 0.21],
					['itemName' => 'Down payment 2026-0412 deducted', 'netAmount' => -4500.0, 'vatRate' => 0.21, 'downPaymentInvoiceId' => 'ar-dp'],
				],
				'downPayment' => ['kind' => 'final', 'orderReference' => 'order-117'],
			]
		);

		self::assertSame([['debit', 12705.0]], $byAccount['1100'], 'receivables: the amount due');
		self::assertSame([['credit', 15000.0]], $byAccount['8000'], 'the full revenue');
		self::assertSame([['debit', 4500.0]], $byAccount['2310'], 'the advance released');
		self::assertSame([['credit', 2205.0]], $byAccount['2110'], 'VAT: the order VAT less what the down payment charged');
	}//end testAFinalInvoiceBooksFullRevenueAndReleasesTheAdvance()

	/**
	 * An invoice whose lines do not add up to its total is refused.
	 *
	 * @return void
	 */
	public function testASalesInvoiceThatDoesNotAddUpIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not balanced');

		$this->action()->execute(
			[
				'id' => 'ar-2', 'invoiceNumber' => '2026-0043', 'invoiceDate' => '2026-06-30', 'administrationId' => 'adm-1',
				'grossAmount' => 1250.0, 'netAmount' => 1000.0, 'vatAmount' => 210.0,
			],
			[],
			['sourceSchema' => 'ARInvoice'],
			MaterialiseGlTransactionAction::class
		);
	}//end testASalesInvoiceThatDoesNotAddUpIsRefused()

	/**
	 * A purchase invoice debits expense per line and input VAT, credits payables.
	 *
	 * @return void
	 */
	public function testAPurchaseInvoiceBooksExpenseVatAndPayables(): void {
		$this->action()->execute(
			[
				'id' => 'ap-1', 'invoiceNumber' => 'INK-7', 'invoiceDate' => '2026-05-02', 'administrationId' => 'adm-1',
				'totalAmount' => 1815.0, 'taxAmount' => 315.0,
				'lines' => [
					['accountNumber' => '4500', 'amount' => 1000.0, 'description' => 'Laptops'],
					['accountNumber' => '4510', 'amount' => 500.0, 'description' => 'Software'],
				],
				'state' => 'posted',
			],
			[],
			['sourceSchema' => 'APInvoice'],
			MaterialiseGlTransactionAction::class
		);

		$lines = array_map(static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount']], $this->store->savedOf('GLLine'));
		self::assertSame(
			[['4500', 'debit', 1000.0], ['4510', 'debit', 500.0], ['1230', 'debit', 315.0], ['2000', 'credit', 1815.0]],
			$lines
		);
		self::assertSame('ap', $this->store->savedOf('GLLine')[3]['subLedgerType']);
	}//end testAPurchaseInvoiceBooksExpenseVatAndPayables()

	/**
	 * A source schema without a mapper is refused by name, never guessed at.
	 *
	 * @return void
	 */
	public function testASchemaWithoutAMapperIsRefusedByName(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('"InventoryValuation"');

		$source = ['id' => 'iv-1', 'administrationId' => 'adm-1'];
		$this->action()->execute($source, [], ['sourceSchema' => 'InventoryValuation'], MaterialiseGlTransactionAction::class);
	}//end testASchemaWithoutAMapperIsRefusedByName()

	/**
	 * An expense claim is refused by name until expenses-category-mapping
	 * adds its mapper, and nothing is written (REQ-LPP-006).
	 *
	 * @return void
	 */
	public function testAnExpenseClaimIsRefusedUntilItsAccountsResolve(): void {
		$action = $this->action();
		$source = ['id' => 'ece-1', 'administrationId' => 'adm-1', 'lines' => [['amount' => 100.08]]];
		try {
			$action->execute($source, [], ['sourceSchema' => 'ExpenseClaimEntry'], MaterialiseGlTransactionAction::class);
			self::fail('An expense claim must not post without its account mapping.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('"ExpenseClaimEntry"', $e->getMessage());
		}

		self::assertSame([], $this->store->savedOf('GLTransaction'));
	}//end testAnExpenseClaimIsRefusedUntilItsAccountsResolve()

	/**
	 * A line that fails to save withdraws the transaction it belonged to.
	 *
	 * @return void
	 */
	public function testAFailedLineWithdrawsTheTransaction(): void {
		$action = $this->action();
		$this->store->failOnSchema = 'GLLine';

		try {
			$action->execute($this->journalEntry(), [], ['sourceSchema' => 'JournalEntry'], MaterialiseGlTransactionAction::class);
			self::fail('A failed line must abort the post.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('could not be written', $e->getMessage());
		}

		$transactionId = $this->store->savedOf('GLTransaction')[0]['id'];
		self::assertContains(['GLTransaction', $transactionId], $this->store->deleted);
	}//end testAFailedLineWithdrawsTheTransaction()
}//end class
