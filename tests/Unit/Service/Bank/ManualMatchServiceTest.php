<?php

/**
 * Matching a bank line by hand, end to end through the declared lifecycles.
 *
 * The store is seeded with the design's test data for Gemeente Voorbeeld. The
 * engine refuses any transition the effective register does not declare, and
 * when a match is confirmed it hands the real ObjectTransitionedEvent to the
 * real settlement listener, so "DV-7781 shows paid" is proven through the
 * same wiring production uses. Every payload the service writes is validated
 * against the effective register schema.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Bank
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Bank;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\ReconciliationMatchSettlementListener;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\Bank\ManualMatchRefusedException;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-BMM-001, REQ-BMM-002, REQ-BMM-003.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ManualMatchServiceTest extends TestCase {
	/**
	 * The shared store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The engine.
	 *
	 * @var LifecycleFaithfulTransitionEngine
	 */
	private LifecycleFaithfulTransitionEngine $engine;

	/**
	 * Every save, for schema validation.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Seed Gemeente Voorbeeld.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$line = static fn (string $id, float $amount, string $remittance): array => [
			'id' => $id, 'lineId' => 'L-' . $id, 'statementId' => 'stmt-1', 'lineNumber' => 1, 'valueDate' => '2026-09-15',
			'amount' => $amount, 'currency' => 'EUR', 'remittanceInfo' => $remittance, 'counterpartyName' => 'Tegenpartij',
			'status' => 'unmatched', 'matchState' => 'unmatched', 'administrationId' => 'adm-gv',
		];
		$this->store = new InMemoryObjectServiceStub(
			[
				'BankStatementLine' => [
					$line('line-devries', -2420.00, 'factuur sept'),
					$line('line-costs', -12.50, 'Kosten zakelijk pakket'),
					$line('line-part', 1000.00, 'VF-2026-0901'),
					$line('line-over', 1000.00, 'twee facturen'),
					$line('line-overdue', 605.00, 'VF-2026-0850'),
				],
				'BankStatement' => [
					['id' => 'stmt-1', 'statementId' => 'stmt-1', 'bankAccountIban' => 'NL91BANK0417164300', 'lifecycleState' => 'in-progress', 'administrationId' => 'adm-gv'],
				],
				'BankAccount' => [
					['id' => 'ba-1', 'iban' => 'NL91BANK0417164300', 'ledgerAccountNumber' => '1100', 'administrationId' => 'adm-gv'],
				],
				'APTransaction' => [
					['id' => 'ap-dv7781', 'invoiceNumber' => 'DV-7781', 'state' => 'issued', 'totalAmount' => 2420.00, 'administrationId' => 'adm-gv'],
				],
				'ARInvoice' => [
					['id' => 'ar-0901', 'invoiceNumber' => 'VF-2026-0901', 'lifecycleState' => 'issued', 'grossAmount' => 1500.00, 'administrationId' => 'adm-gv'],
					['id' => 'ar-0850', 'invoiceNumber' => 'VF-2026-0850', 'lifecycleState' => 'overdue', 'grossAmount' => 605.00, 'administrationId' => 'adm-gv'],
					['id' => 'ar-a', 'invoiceNumber' => 'VF-2026-0910', 'lifecycleState' => 'issued', 'grossAmount' => 800.00, 'administrationId' => 'adm-gv'],
					['id' => 'ar-b', 'invoiceNumber' => 'VF-2026-0911', 'lifecycleState' => 'issued', 'grossAmount' => 800.00, 'administrationId' => 'adm-gv'],
					['id' => 'ar-other', 'invoiceNumber' => 'X-1', 'lifecycleState' => 'issued', 'grossAmount' => 1000.00, 'administrationId' => 'adm-other'],
				],
			],
			$this->saved
		);

		$settlementListener = null;
		$this->engine = new LifecycleFaithfulTransitionEngine(
			store: $this->store,
			schemas: ['ReconciliationMatch', 'BankStatementLine', 'JournalEntry', 'ARInvoice', 'APTransaction'],
			onRan: function (array $record, array $object) use (&$settlementListener): void {
				if ($record['schema'] === 'JournalEntry' && $record['action'] === 'postDirect') {
					// MaterialiseGlTransactionAction writes the back reference.
					$this->store->setSchema('JournalEntry')->patchObject($record['objectId'], ['glTransactionId' => 'gl-' . $record['objectId']]);
				}

				if ($record['schema'] === 'ReconciliationMatch') {
					$entity = new ObjectEntity();
					$entity->setObject($object);
					$entity->setSchema('ReconciliationMatch');
					$entity->setRegister('shillinq');
					$settlementListener->handle(new ObjectTransitionedEvent($entity, $record['action'], $record['from'], $record['to'], 'bookkeeper', 'shillinq', 'ReconciliationMatch'));
				}
			}
		);

		$settlementListener = new ReconciliationMatchSettlementListener(
			settlement: new InvoiceSettlementService(
				objectService: $this->store,
				transitions: $this->runner(),
				settings: $this->settings(),
				logger: $this->createMock(LoggerInterface::class),
			),
			schemas: $this->createConfiguredMock(ListenerSchemaResolver::class, ['matchesSchema' => true]),
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * The runner with the faithful engine.
	 *
	 * @return ObjectTransitionRunner
	 */
	private function runner(): ObjectTransitionRunner {
		$engine = $this->engine;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(static fn (): object => $engine);
		return new ObjectTransitionRunner(container: $container);

	}//end runner()

	/**
	 * Settings answering the register slug.
	 *
	 * @return SettingsService
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		return $settings;

	}//end settings()

	/**
	 * The service under test.
	 *
	 * @return ManualMatchService
	 */
	private function service(): ManualMatchService {
		return new ManualMatchService(
			objectService: $this->store,
			transitions: $this->runner(),
			settings: $this->settings(),
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end service()

	/**
	 * A stored object.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(string $schema, string $id): array {
		return $this->store->find($id, schema: $schema)->getObject();

	}//end stored()

	/**
	 * Every write validates against the effective register schema.
	 *
	 * @return void
	 */
	private function assertSavesAreValid(): void {
		self::assertNotSame([], array_filter($this->saved, static fn (array $save): bool => $save['schema'] === 'ReconciliationMatch'), 'a match was written');
		foreach ($this->saved as $save) {
			if (in_array($save['schema'], ['ReconciliationMatch', 'JournalEntry'], true) === false) {
				continue;
			}

			self::assertSame([], RegisterSchema::errors(slug: $save['schema'], object: $save['object']), $save['schema'] . ' payload is valid');
		}

	}//end assertSavesAreValid()

	/**
	 * Scenario: a bookkeeper pairs a supplier payment with its invoice.
	 *
	 * @return void
	 */
	public function testSupplierPaymentPairsWithItsInvoice(): void {
		$service = $this->service();
		$match = $service->matchInvoices(line: $service->findLine(lineId: 'line-devries'), targetIds: ['ap-dv7781'], actor: 'bookkeeper');

		self::assertSame('confirmed', $match['status']);
		self::assertSame('confirmed', $match['state']);
		self::assertSame('bookkeeper', $match['confirmedBy']);
		self::assertFalse($match['isPartial']);
		self::assertSame('matched', $this->stored('BankStatementLine', 'line-devries')['status']);
		self::assertSame('paid', $this->stored('APTransaction', 'ap-dv7781')['state']);
		$this->assertSavesAreValid();

	}//end testSupplierPaymentPairsWithItsInvoice()

	/**
	 * Scenario: a selection larger than the line is refused.
	 *
	 * @return void
	 */
	public function testSelectionLargerThanTheLineIsRefused(): void {
		$service = $this->service();
		try {
			$service->matchInvoices(line: $service->findLine(lineId: 'line-over'), targetIds: ['ar-a', 'ar-b'], actor: 'bookkeeper');
			self::fail('refused');
		} catch (ManualMatchRefusedException $e) {
			self::assertSame('The selection exceeds the bank line by EUR 600.00.', $e->getMessage());
		}

		self::assertSame('unmatched', $this->stored('BankStatementLine', 'line-over')['status']);
		self::assertSame([], $this->engine->ran);

	}//end testSelectionLargerThanTheLineIsRefused()

	/**
	 * Scenario: a part payment is recorded as partial, the invoice stays issued.
	 *
	 * @return void
	 */
	public function testPartPaymentIsPartial(): void {
		$service = $this->service();
		$match = $service->matchInvoices(line: $service->findLine(lineId: 'line-part'), targetIds: ['ar-0901'], actor: 'bookkeeper');

		self::assertTrue($match['isPartial']);
		self::assertSame(500.0, $match['remainder']);
		self::assertSame('issued', $this->stored('ARInvoice', 'ar-0901')['lifecycleState']);
		$this->assertSavesAreValid();

	}//end testPartPaymentIsPartial()

	/**
	 * Scenario: an overdue sales invoice is paid by a matched line.
	 *
	 * @return void
	 */
	public function testOverdueInvoiceIsPaid(): void {
		$service = $this->service();
		$service->matchInvoices(line: $service->findLine(lineId: 'L-line-overdue'), targetIds: ['ar-0850'], actor: 'bookkeeper');

		self::assertSame('paid', $this->stored('ARInvoice', 'ar-0850')['lifecycleState']);

	}//end testOverdueInvoiceIsPaid()

	/**
	 * An invoice of another administration, or an already matched line, is refused.
	 *
	 * @return void
	 */
	public function testForeignInvoiceAndMatchedLineAreRefused(): void {
		$service = $this->service();
		try {
			$service->matchInvoices(line: $service->findLine(lineId: 'line-over'), targetIds: ['ar-other'], actor: 'bookkeeper');
			self::fail('foreign invoice refused');
		} catch (ManualMatchRefusedException $e) {
			self::assertStringContainsString('not an open invoice of this administration', $e->getMessage());
		}

		$service->matchInvoices(line: $service->findLine(lineId: 'line-devries'), targetIds: ['ap-dv7781'], actor: 'bookkeeper');
		$this->expectException(ManualMatchRefusedException::class);
		$service->matchInvoices(line: $service->findLine(lineId: 'line-devries'), targetIds: ['ap-dv7781'], actor: 'bookkeeper');

	}//end testForeignInvoiceAndMatchedLineAreRefused()

	/**
	 * Scenario: monthly bank costs are booked from the statement.
	 *
	 * @return void
	 */
	public function testBankCostsAreBookedToTheLedger(): void {
		$service = $this->service();
		$match = $service->bookToLedger(line: $service->findLine(lineId: 'line-costs'), ledger: ['accountNumber' => '4910'], actor: 'bookkeeper');

		$journal = $this->stored('JournalEntry', $match['journalEntryId']);
		self::assertSame('posted', $journal['state']);
		self::assertSame('bank', $journal['sourceApp'] ?? null, 'The bank ledger may post its VAT line (ledger-booking-rules REQ-LBR-002).');
		self::assertSame(
			[['4910', 'debit', 12.5], ['1100', 'credit', 12.5]],
			array_map(static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount']], $journal['lines'])
		);
		self::assertSame('gl-transaction', $match['targetType']);
		self::assertSame('gl-' . $match['journalEntryId'], $match['targetRefs'][0]);
		self::assertSame('matched', $this->stored('BankStatementLine', 'line-costs')['status']);
		$this->assertSavesAreValid();

	}//end testBankCostsAreBookedToTheLedger()

	/**
	 * A booking with 21% VAT splits the VAT out and stays balanced.
	 *
	 * @return void
	 */
	public function testVatIsSplitOut(): void {
		$journal = $this->service()->buildJournalEntry(
			line: ['id' => 'x', 'amount' => -121.00, 'administrationId' => 'adm-gv', 'valueDate' => '2026-09-15'],
			bankAccount: '1100',
			ledger: ['accountNumber' => '4500', 'vatRate' => 21, 'vatAccountNumber' => '1520', 'description' => 'Software']
		);

		self::assertSame(
			[['4500', 'debit', 100.0], ['1520', 'debit', 21.0], ['1100', 'credit', 121.0]],
			array_map(static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount']], $journal['lines'])
		);
		self::assertSame([], RegisterSchema::errors(slug: 'JournalEntry', object: $journal));

	}//end testVatIsSplitOut()

	/**
	 * A bank account without a ledger account refuses a ledger booking by name.
	 *
	 * @return void
	 */
	public function testBankAccountWithoutLedgerAccountIsRefused(): void {
		$this->store->setSchema('BankAccount')->patchObject('ba-1', ['ledgerAccountNumber' => '']);
		$service = $this->service();

		$this->expectExceptionMessage('Set the ledger account of bank account NL91BANK0417164300 first.');
		$service->bookToLedger(line: $service->findLine(lineId: 'line-costs'), ledger: ['accountNumber' => '4910'], actor: 'bookkeeper');

	}//end testBankAccountWithoutLedgerAccountIsRefused()

	/**
	 * A line on a reconciled statement cannot be matched.
	 *
	 * @return void
	 */
	public function testReconciledStatementIsRefused(): void {
		$this->store->setSchema('BankStatement')->patchObject('stmt-1', ['lifecycleState' => 'reconciled']);
		$service = $this->service();

		$this->expectExceptionMessage('The bank statement of this line is already reconciled.');
		$service->matchInvoices(line: $service->findLine(lineId: 'line-devries'), targetIds: ['ap-dv7781'], actor: 'bookkeeper');

	}//end testReconciledStatementIsRefused()

	/**
	 * The ledger account property the booking reads is declared on the effective BankAccount.
	 *
	 * @return void
	 */
	public function testLedgerAccountNumberIsDeclared(): void {
		self::assertArrayHasKey('ledgerAccountNumber', RegisterSchema::schema(slug: 'BankAccount')['properties']);

	}//end testLedgerAccountNumberIsDeclared()
}//end class
