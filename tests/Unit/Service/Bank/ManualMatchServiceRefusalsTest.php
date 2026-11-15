<?php

/**
 * Unit tests for the refusals of ManualMatchService: an unknown line, an empty
 * selection, a line larger than its selection, an invoice that is not open,
 * a payment plan paid with money out or without invoices, a ledger booking
 * without an account or without a VAT account, and a posted journal entry
 * that carries no GL transaction.
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

use OCA\Shillinq\Kernel\Exception\ManualMatchRefusedException;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// phpcs:disable CustomSniffs.Functions.NamedParameters

#[CoversClass(ManualMatchService::class)]
#[UsesClass(ManualMatchRefusedException::class)]
#[UsesClass(InvoiceSettlementService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class ManualMatchServiceRefusalsTest extends TestCase {
	/**
	 * What the store saved: schema and object, in order.
	 *
	 * @var array<int,array{schema:string,object:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * The transition runner.
	 *
	 * @var ObjectTransitionRunner&MockObject
	 */
	private ObjectTransitionRunner&MockObject $transitions;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service over a store with one statement, its bank account and two
	 * receivable invoices.
	 *
	 * @return ManualMatchService
	 */
	private function service(): ManualMatchService {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(
			[
				'BankStatementLine' => [self::line()],
				'BankStatement'     => [
					['id' => 'stmt-1', 'statementId' => 'stmt-1', 'bankAccountIban' => 'NL91BANK0417164300', 'lifecycleState' => 'in-progress'],
				],
				'BankAccount'       => [['id' => 'ba-1', 'iban' => 'NL91BANK0417164300', 'ledgerAccountNumber' => '1100', 'administrationId' => 'adm-1']],
				'ARInvoice'         => [
					['id' => 'ar-open', 'invoiceNumber' => 'VF-1', 'lifecycleState' => 'issued', 'amountDue' => 60.0, 'administrationId' => 'adm-1'],
					['id' => 'ar-paid', 'invoiceNumber' => 'VF-2', 'lifecycleState' => 'paid', 'grossAmount' => 40.0, 'administrationId' => 'adm-1'],
				],
			],
			$this->saved
		);
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
		$location = $this->createStub(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new ManualMatchService($store, $this->transitions, $location, $this->logger);

	}//end service()

	/**
	 * An unmatched bank line of 100.00 received on statement stmt-1.
	 *
	 * @param array<string,mixed> $fields Fields to override or add.
	 *
	 * @return array<string,mixed>
	 */
	private static function line(array $fields = []): array {
		return array_merge(
			[
				'id'               => 'line-1',
				'lineId'           => 'L-1',
				'statementId'      => 'stmt-1',
				'valueDate'        => '2026-09-15',
				'amount'           => 100.0,
				'currency'         => 'EUR',
				'status'           => 'unmatched',
				'matchState'       => 'unmatched',
				'administrationId' => 'adm-1',
			],
			$fields
		);

	}//end line()

	/**
	 * The message of the refusal a call raises.
	 *
	 * @param callable $call The call.
	 *
	 * @return string
	 */
	private static function refusal(callable $call): string {
		try {
			$call();
		} catch (ManualMatchRefusedException $e) {
			return $e->getMessage();
		}

		self::fail('Expected a refusal.');

	}//end refusal()

	/**
	 * An unknown line is not found, by id nor by line id.
	 *
	 * @return void
	 */
	public function testAnUnknownLineIsNotFound(): void {
		$service = $this->service();
		self::assertSame('line-1', $service->findLine('L-1')['id']);

		$this->expectException(OutOfBoundsException::class);
		$this->expectExceptionMessage('bank line nope not found');
		$service->findLine('nope');

	}//end testAnUnknownLineIsNotFound()

	/**
	 * A selection without invoice ids is refused.
	 *
	 * @return void
	 */
	public function testAnEmptySelectionIsRefused(): void {
		$service = $this->service();

		self::assertSame('Select at least one invoice.', self::refusal(static fn () => $service->matchInvoices(self::line(), ['', ''], 'anna')));
		self::assertSame([], $this->saved);

	}//end testAnEmptySelectionIsRefused()

	/**
	 * A line larger than the selected invoices is refused with the excess.
	 *
	 * @return void
	 */
	public function testALineLargerThanTheSelectionIsRefused(): void {
		$service = $this->service();
		$this->transitions->expects(self::never())->method('run');

		self::assertSame(
			'The bank line exceeds the selection by EUR 40.00.',
			self::refusal(static fn () => $service->matchInvoices(self::line(), ['ar-open'], 'anna'))
		);

	}//end testALineLargerThanTheSelectionIsRefused()

	/**
	 * An invoice that is not open for payment is refused by its number; a
	 * line without a statement skips the statement check.
	 *
	 * @return void
	 */
	public function testAnInvoiceThatIsNotOpenIsRefused(): void {
		$service = $this->service();

		self::assertSame(
			'Invoice VF-2 is not open for payment.',
			self::refusal(static fn () => $service->matchInvoices(self::line(['statementId' => '']), ['ar-open', 'ar-paid'], 'anna'))
		);

	}//end testAnInvoiceThatIsNotOpenIsRefused()

	/**
	 * A payment plan is paid only with money received, and only for invoices.
	 *
	 * @return void
	 */
	public function testAPaymentPlanNeedsMoneyInAndInvoices(): void {
		$service = $this->service();

		self::assertSame(
			'Only money received can pay a payment plan.',
			self::refusal(static fn () => $service->matchPaymentPlan(self::line(['amount' => -100.0]), ['invoiceIds' => ['ar-open']], 'anna', 'plan'))
		);
		self::assertSame(
			'Select at least one invoice.',
			self::refusal(static fn () => $service->matchPaymentPlan(self::line(), ['invoiceIds' => []], 'anna', 'plan'))
		);

	}//end testAPaymentPlanNeedsMoneyInAndInvoices()

	/**
	 * A ledger booking needs an account, and a VAT account when VAT applies.
	 *
	 * @return void
	 */
	public function testALedgerBookingNeedsItsAccounts(): void {
		$service = $this->service();

		self::assertSame(
			'Choose a ledger account.',
			self::refusal(static fn () => $service->bookToLedger(self::line(), ['accountNumber' => '  '], 'anna'))
		);
		self::assertSame(
			'Choose the VAT account for the VAT amount.',
			self::refusal(static fn () => $service->bookToLedger(self::line(), ['accountNumber' => '8000', 'vatRate' => 21], 'anna'))
		);
		self::assertSame([], $this->saved);

	}//end testALedgerBookingNeedsItsAccounts()

	/**
	 * A posted journal entry without a GL transaction is logged, and the match
	 * then points at the journal entry itself.
	 *
	 * @return void
	 */
	public function testAJournalEntryWithoutGlTransactionIsLoggedAndUsed(): void {
		$service = $this->service();
		$ran = [];
		$this->transitions->method('run')->willReturnCallback(
			static function (string $objectId, string $action) use (&$ran): void {
				$ran[] = $action;
			}
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'ManualMatchService: posted journal entry carries no glTransactionId',
			self::callback(static fn (array $context): bool => ($context['journalEntryId'] ?? '') !== '')
		);

		$match = $service->bookToLedger(self::line(), ['accountNumber' => '8000'], 'anna');

		self::assertSame($match['journalEntryId'], $match['glTransactionId']);
		self::assertSame([$match['journalEntryId']], $match['targetRefs']);
		self::assertSame('Booked by hand to 8000', $match['resolutionReason']);
		self::assertSame('postDirect', $ran[0]);

	}//end testAJournalEntryWithoutGlTransactionIsLoggedAndUsed()
}//end class
