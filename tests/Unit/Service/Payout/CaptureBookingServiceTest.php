<?php

/**
 * Unit tests for CaptureBookingService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Payout;

use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\Payout\CaptureBookingService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * REQ-RPPO-003: a capture that settles an invoice books clearing to debtors, once.
 *
 * @covers \OCA\Shillinq\Service\Payout\CaptureBookingService
 * @uses   \OCA\Shillinq\Service\PaymentRevenueAccountResolver
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
 */
final class CaptureBookingServiceTest extends TestCase {

	/**
	 * Everything the store wrote.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The transition runner.
	 *
	 * @var ObjectTransitionRunnerInterface&MockObject
	 */
	private ObjectTransitionRunnerInterface $transitions;

	/**
	 * Set up the runner.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
	}//end setUp()

	/**
	 * A captured invoice request.
	 *
	 * @param string               $id     The request id.
	 * @param array<string, mixed> $fields Fields over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(string $id, array $fields=[]): array {
		return $fields + [
			'id' => $id,
			'administrationId' => 'THV',
			'state' => 'captured',
			'providerPaymentId' => 'tr_' . $id,
			'invoiceReference' => 'inv-' . $id,
			'amount' => 145.2,
			'capturedAt' => '2026-09-28T10:15:00Z',
		];
	}//end request()

	/**
	 * The service over an in-memory store.
	 *
	 * @param array<string, list<array<string, mixed>>> $data   The store.
	 * @param array<string, string>                     $config App config values by key.
	 *
	 * @return CaptureBookingService
	 */
	private function service(array $data=[], array $config=[]): CaptureBookingService {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(data: $data, saveSink: $this->saved);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($config[$key] ?? $default)
		);

		return new CaptureBookingService(objectService: $store, appConfig: $appConfig, transitions: $this->transitions, logger: new NullLogger());
	}//end service()

	/**
	 * The journal debits the configured clearing account and credits debtors for the captured amount.
	 *
	 * @return void
	 */
	public function testTheJournalBooksClearingToDebtors(): void {
		$journal = $this->service(config: ['paymentRevenueAccounts' => '{"clearing":"1225"}', 'ledger_ar_control_account' => '1300'])
			->journal(request: self::request(id: '1'), invoice: ['invoiceNumber' => '2026-0601']);

		$this->assertSame('CA-tr_1', $journal['journalNumber']);
		$this->assertSame('2026-09-28', $journal['entryDate']);
		$this->assertSame('THV', $journal['administrationId']);
		$this->assertSame('draft', $journal['state']);
		$this->assertSame('Capture tr_1 of invoice 2026-0601', $journal['description']);
		$this->assertSame(
			[['1225', 'debit', 145.2], ['1300', 'credit', 145.2]],
			array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], $line['amount']], $journal['lines'])
		);
	}//end testTheJournalBooksClearingToDebtors()

	/**
	 * Without configuration: the clearing placeholder and debtors 1100; the intent id stands in for a provider id.
	 *
	 * @return void
	 */
	public function testTheDefaultsAndThePaymentIntentId(): void {
		$journal = $this->service()->journal(
			request: self::request(id: '2', fields: ['providerPaymentId' => '', 'paymentIntentId' => 'tr_intent']),
			invoice: []
		);

		$this->assertSame('CA-tr_intent', $journal['journalNumber']);
		$this->assertSame('Capture tr_intent of invoice inv-2', $journal['description']);
		$this->assertSame(['clearing', '1100'], array_column($journal['lines'], 'accountNumber'));
	}//end testTheDefaultsAndThePaymentIntentId()

	/**
	 * Nothing to book without a payment id or an amount above zero.
	 *
	 * @return void
	 */
	public function testNothingIsBookedWithoutPaymentIdOrAmount(): void {
		$this->transitions->expects($this->never())->method('run');
		$service = $this->service();

		$this->assertNull($service->journal(request: self::request(id: '3', fields: ['amount' => 0]), invoice: []));
		$this->assertNull($service->book(registerSlug: 'shillinq', request: self::request(id: '3', fields: ['providerPaymentId' => '']), invoice: []));
		$this->assertSame([], $this->saved);
	}//end testNothingIsBookedWithoutPaymentIdOrAmount()

	/**
	 * A capture is saved and posted once; booking it again answers the same entry.
	 *
	 * @return void
	 */
	public function testACaptureIsBookedOnce(): void {
		$service = $this->service();
		$this->transitions->expects($this->once())->method('run')->with('obj-1', 'postDirect');

		$first = $service->book(registerSlug: 'shillinq', request: self::request(id: '4'), invoice: []);
		$second = $service->book(registerSlug: 'shillinq', request: self::request(id: '4'), invoice: []);

		$this->assertSame('obj-1', $first);
		$this->assertSame('obj-1', $second);
		$this->assertCount(1, $this->saved);
		$this->assertSame('JournalEntry', $this->saved[0]['schema']);
	}//end testACaptureIsBookedOnce()

	/**
	 * A refused posting is logged and answers null.
	 *
	 * @return void
	 */
	public function testARefusedPostingAnswersNull(): void {
		$this->transitions->method('run')->willThrowException(new RuntimeException('period closed'));

		$this->assertNull($this->service()->book(registerSlug: 'shillinq', request: self::request(id: '5'), invoice: []));
	}//end testARefusedPostingAnswersNull()

	/**
	 * The listing holds captured invoice requests without a booking, by capture date.
	 *
	 * @return void
	 */
	public function testTheListingHoldsCapturedInvoiceRequestsWithoutABooking(): void {
		$service = $this->service(
			data: [
				'PaymentRequest' => [
					self::request(id: 'late', fields: ['capturedAt' => '2026-09-30T08:00:00Z', 'amount' => '60']),
					self::request(id: 'early', fields: ['capturedAt' => '2026-09-01T08:00:00Z']),
					self::request(id: 'booked'),
					self::request(id: 'pending', fields: ['state' => 'pending']),
					self::request(id: 'object', fields: ['invoiceReference' => '']),
					self::request(id: 'free', fields: ['amount' => 0]),
				],
				'JournalEntry' => [
					['id' => 'je-1', 'journalNumber' => 'CA-tr_booked'],
				],
			]
		);

		$this->assertSame(
			[
				['requestId' => 'early', 'paymentId' => 'tr_early', 'invoiceReference' => 'inv-early', 'amount' => 145.2, 'capturedAt' => '2026-09-01'],
				['requestId' => 'late', 'paymentId' => 'tr_late', 'invoiceReference' => 'inv-late', 'amount' => 60.0, 'capturedAt' => '2026-09-30'],
			],
			$service->unbooked(registerSlug: 'shillinq')
		);
	}//end testTheListingHoldsCapturedInvoiceRequestsWithoutABooking()

	/**
	 * A listed request is booked from its stored record and its invoice's number.
	 *
	 * @return void
	 */
	public function testBookRequestReadsTheRequestAndItsInvoice(): void {
		$service = $this->service(
			data: [
				'PaymentRequest' => [self::request(id: 'r1'), self::request(id: 'r2', fields: ['invoiceReference' => ''])],
				'ARInvoice' => [['id' => 'inv-r1', 'invoiceNumber' => '2026-0777']],
			]
		);

		$this->assertSame('obj-1', $service->bookRequest(registerSlug: 'shillinq', requestId: 'r1'));
		$this->assertSame('Capture tr_r1 of invoice 2026-0777', $this->saved[0]['object']['description']);
		$this->assertNull($service->bookRequest(registerSlug: 'shillinq', requestId: 'r2'));
		$this->assertNull($service->bookRequest(registerSlug: 'shillinq', requestId: 'missing'));
	}//end testBookRequestReadsTheRequestAndItsInvoice()
}//end class
