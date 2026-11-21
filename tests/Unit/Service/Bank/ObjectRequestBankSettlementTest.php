<?php

/**
 * Unit tests for ObjectRequestBankSettlement: a confirmed bank match on an
 * object payment request records the settlement once, then settles the
 * linked invoice, books the receipt on the mapped revenue account, or leaves
 * it unbooked when no account is mapped.
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
 * @spec openspec/specs/fees-payments-and-the-contract-register/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Bank;

use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\Bank\ObjectRequestBankSettlement;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCA\Shillinq\Service\PaymentSettlementService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// phpcs:disable CustomSniffs.Functions.NamedParameters

#[CoversClass(ObjectRequestBankSettlement::class)]
#[UsesClass(PaymentSettlementService::class)]
#[UsesClass(PaymentRevenueAccountResolver::class)]
#[UsesClass(ObjectIdentifier::class)]
final class ObjectRequestBankSettlementTest extends TestCase {
	/**
	 * What the store saved: schema and object, in order.
	 *
	 * @var array<int,array{schema:string,object:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The invoice settlement.
	 *
	 * @var InvoiceSettlementService&MockObject
	 */
	private InvoiceSettlementService&MockObject $invoices;

	/**
	 * The receipt booking.
	 *
	 * @var ManualMatchService&MockObject
	 */
	private ManualMatchService&MockObject $manualMatch;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The settlement over a store holding the request and the bank line.
	 *
	 * @param array<string,mixed> $request  The payment request.
	 * @param array<string,mixed> $line     The bank statement line.
	 * @param string              $accounts The revenue account map as configured (JSON).
	 *
	 * @return ObjectRequestBankSettlement
	 */
	private function settlement(array $request, array $line, string $accounts = ''): ObjectRequestBankSettlement {
		$this->saved       = [];
		$this->store       = new InMemoryObjectServiceStub(['PaymentRequest' => [$request], 'BankStatementLine' => [$line]], $this->saved);
		$this->invoices    = $this->createMock(InvoiceSettlementService::class);
		$this->manualMatch = $this->createMock(ManualMatchService::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
		$location = $this->createStub(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn($accounts);

		return new ObjectRequestBankSettlement(
			$this->store,
			$location,
			new PaymentSettlementService(),
			new PaymentRevenueAccountResolver($config),
			$this->invoices,
			$this->manualMatch,
			$this->logger
		);

	}//end settlement()

	/**
	 * An open request for a parking permit of 25.00.
	 *
	 * @param array<string,mixed> $fields Fields to override or add.
	 *
	 * @return array<string,mixed>
	 */
	private static function request(array $fields = []): array {
		return array_merge(
			[
				'id'               => 'pr-1',
				'subjectKind'      => 'object',
				'amount'           => 25.0,
				'requestType'      => 'parkingPermit',
				'subject'          => ['type' => 'permit', 'id' => 'p-9'],
				'paymentReference' => 'RF18PERMIT9',
			],
			$fields
		);

	}//end request()

	/**
	 * A bank line paying 25.00 on 2 April 2026.
	 *
	 * @param array<string,mixed> $fields Fields to override or add.
	 *
	 * @return array<string,mixed>
	 */
	private static function line(array $fields = []): array {
		return array_merge(['id' => 'bsl-1', 'amount' => 25.0, 'valueDate' => '2026-04-02', 'endToEndRef' => 'E2E-1'], $fields);

	}//end line()

	/**
	 * The current request in the store.
	 *
	 * @return array<string,mixed>
	 */
	private function storedRequest(): array {
		return $this->store->setSchema('PaymentRequest')->find(id: 'pr-1')->getObject();

	}//end storedRequest()

	/**
	 * A missing request or line, or a request that is not about an object,
	 * settles nothing.
	 *
	 * @return void
	 */
	public function testAMissingOrNonObjectRequestSettlesNothing(): void {
		$match = ['matchedObjectId' => 'pr-1', 'bankStatementLineId' => 'bsl-1'];

		self::assertSame('missing', $this->settlement(self::request(['subjectKind' => 'invoice']), self::line())->settle($match));
		$unknown = ['matchedObjectId' => 'pr-x', 'bankStatementLineId' => 'bsl-1'];
		self::assertSame('missing', $this->settlement(self::request(), self::line())->settle($unknown));
		self::assertSame('missing', $this->settlement(self::request(), self::line())->settle(['matchedObjectId' => 'pr-1']));
		self::assertSame([], $this->saved);

	}//end testAMissingOrNonObjectRequestSettlesNothing()

	/**
	 * A bank transfer with the line's reference recorded before is not
	 * recorded again.
	 *
	 * @return void
	 */
	public function testAnAlreadyRecordedTransferIsNotRecordedAgain(): void {
		$request = self::request(['settlements' => [['method' => 'cash', 'reference' => 'E2E-1'], ['method' => 'bank-transfer', 'reference' => 'E2E-1']]]);
		$settlement = $this->settlement($request, self::line(), '{"parkingPermit":"8100"}');
		$this->manualMatch->expects(self::never())->method('postReceipt');

		self::assertSame('already', $settlement->settle(['matchedObjectId' => 'pr-1', 'bankStatementLineId' => 'bsl-1']));
		self::assertSame([], $this->saved);

	}//end testAnAlreadyRecordedTransferIsNotRecordedAgain()

	/**
	 * With a mapped revenue account the receipt is booked on it, and the
	 * request records the transfer, the settlement and the account.
	 *
	 * @return void
	 */
	public function testAMappedReceiptIsBookedOnTheRevenueAccount(): void {
		$settlement = $this->settlement(self::request(), self::line(), '{"parkingPermit":"8100"}');
		$this->manualMatch->expects(self::once())->method('postReceipt')->with(
			self::line(),
			'8100',
			'parkingPermit on permit p-9, reference RF18PERMIT9'
		)->willReturn('je-1');
		$this->invoices->expects(self::never())->method('settle');

		$outcome = $settlement->settle(['targetRefs' => ['pr-1'], 'bankStatementLineId' => 'bsl-1']);

		self::assertSame('settled', $outcome);
		$stored = $this->storedRequest();
		self::assertSame('8100', $stored['revenueAccount']);
		self::assertSame('bank-transfer', $stored['settledVia']);
		self::assertSame('2026-04-02T00:00:00Z', $stored['settledAt']);
		self::assertCount(1, $stored['settlements']);
		self::assertSame(
			['method' => 'bank-transfer', 'amount' => 25.0, 'reference' => 'E2E-1', 'actor' => 'system:bank-match'],
			array_intersect_key($stored['settlements'][0], ['method' => 1, 'amount' => 1, 'reference' => 1, 'actor' => 1])
		);

	}//end testAMappedReceiptIsBookedOnTheRevenueAccount()

	/**
	 * Without a mapped account the receipt is logged as unbooked; a part
	 * payment is recorded but the request is not stamped settled, and the
	 * line id is the reference when the line has no end-to-end reference.
	 *
	 * @return void
	 */
	public function testAnUnmappedPartPaymentIsRecordedButNotBooked(): void {
		$line = self::line(['endToEndRef' => '', 'lineId' => 'L-77', 'valueDate' => '2026-04-02T10:15:00Z']);
		$settlement = $this->settlement(self::request(), $line, '{"other":"8200"}');
		$this->manualMatch->expects(self::never())->method('postReceipt');
		$this->logger->expects(self::once())->method('warning')->with(
			'ObjectRequestBankSettlement: no revenue account mapped, the receipt is not booked',
			['requestType' => 'parkingPermit', 'request' => 'pr-1']
		);

		$outcome = $settlement->settle(['matchedObjectId' => 'pr-1', 'bankStatementLineId' => 'bsl-1', 'matchedAmount' => 10.004]);

		self::assertSame('unbooked', $outcome);
		$stored = $this->storedRequest();
		self::assertSame(10.0, $stored['settlements'][0]['amount']);
		self::assertSame('L-77', $stored['settlements'][0]['reference']);
		self::assertSame('2026-04-02T10:15:00Z', $stored['settlements'][0]['settledAt']);
		self::assertArrayNotHasKey('settledAt', $stored);
		self::assertArrayNotHasKey('revenueAccount', $stored);

	}//end testAnUnmappedPartPaymentIsRecordedButNotBooked()

	/**
	 * A request with an invoice settles that invoice, as a part payment when
	 * less than the requested amount arrived.
	 *
	 * @return void
	 */
	public function testARequestWithAnInvoiceSettlesTheInvoice(): void {
		$settlement = $this->settlement(self::request(['invoiceReference' => 'inv-3']), self::line(['amount' => 20.0]), '{"parkingPermit":"8100"}');
		$this->invoices->expects(self::once())->method('settle')->with(
			['matchType' => 'ar-invoice', 'targetRefs' => ['inv-3'], 'matchedAmount' => 20.0, 'partial' => true]
		)->willReturn([]);
		$this->manualMatch->expects(self::never())->method('postReceipt');

		self::assertSame('invoice', $settlement->settle(['matchedObjectId' => 'pr-1', 'bankStatementLineId' => 'bsl-1']));
		self::assertArrayNotHasKey('revenueAccount', $this->storedRequest());

	}//end testARequestWithAnInvoiceSettlesTheInvoice()

	/**
	 * A full payment of an invoiced request is not a part payment.
	 *
	 * @return void
	 */
	public function testAFullInvoicePaymentIsNotPartial(): void {
		$settlement = $this->settlement(self::request(['invoiceReference' => 'inv-3']), self::line());
		$this->invoices->expects(self::once())->method('settle')->with(
			['matchType' => 'ar-invoice', 'targetRefs' => ['inv-3'], 'matchedAmount' => 25.0, 'partial' => false]
		)->willReturn([]);

		self::assertSame('invoice', $settlement->settle(['matchedObjectId' => 'pr-1', 'bankStatementLineId' => 'bsl-1']));
		self::assertSame('bank-transfer', $this->storedRequest()['settledVia']);

	}//end testAFullInvoicePaymentIsNotPartial()
}//end class
