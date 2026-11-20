<?php

/**
 * Unit tests for the counter-sale step of PaymentReconciliationService.
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
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PaymentReconciliationService;
use OCA\Shillinq\Service\Payout\CaptureBookingService;
use OCA\Shillinq\Service\Payout\CounterSaleSettlement;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-RPPO-006: the capture of a counter sale issues it before it is settled; a failed payment cancels it.
 *
 * @covers \OCA\Shillinq\Service\PaymentReconciliationService
 * @uses   \OCA\Shillinq\Service\PaymentSettlementService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
 */
final class PaymentReconciliationCounterSaleTest extends TestCase {

	/**
	 * Everything the store wrote.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The counter-sale step.
	 *
	 * @var CounterSaleSettlement&MockObject
	 */
	private CounterSaleSettlement $counterSales;

	/**
	 * The draft tea tin sale and its pending payment on the phone.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private const DATA = [
		'PaymentRequest' => [[
			'id' => 'pr-1', 'paymentIntentId' => 'tr_cs1', 'state' => 'pending', 'invoiceReference' => 'inv-cs-1',
			'subjectKind' => 'invoice', 'amount' => 12.5, 'paymentMethod' => 'pointofsale',
		]],
		'ARInvoice' => [[
			'id' => 'inv-cs-1', 'lifecycleState' => 'draft', 'counterSale' => true, 'grossAmount' => 12.5, 'invoiceNumber' => 'CS-20261117-AB12CD34',
		]],
	];

	/**
	 * The service over the scenario's store.
	 *
	 * @return PaymentReconciliationService
	 */
	private function service(): PaymentReconciliationService {
		$this->saved = [];
		$this->counterSales = $this->createMock(CounterSaleSettlement::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new PaymentReconciliationService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new InMemoryObjectServiceStub(data: self::DATA, saveSink: $this->saved),
			transitions: $this->createMock(ObjectTransitionRunner::class),
			captureBooking: $this->createMock(CaptureBookingService::class),
			counterSales: $this->counterSales,
		);
	}//end service()

	/**
	 * The last saved row of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed>|null
	 */
	private function lastSaved(string $schema): ?array {
		$rows = array_values(array_filter($this->saved, static fn (array $write): bool => $write['schema'] === $schema));
		if ($rows === []) {
			return null;
		}

		return end($rows)['object'];
	}//end lastSaved()

	/**
	 * The customer pays: the draft is issued first, then settled as paid.
	 *
	 * @return void
	 */
	public function testACaptureIssuesTheSaleThenSettlesItPaid(): void {
		$service = $this->service();
		$this->counterSales->expects($this->once())->method('issueOnCapture')
			->with('shillinq', $this->callback(static fn (array $invoice): bool => $invoice['id'] === 'inv-cs-1' && $invoice['lifecycleState'] === 'draft'))
			->willReturn(['lifecycleState' => 'issued'] + self::DATA['ARInvoice'][0]);
		$this->counterSales->expects($this->never())->method('cancelOnFailure');

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_cs1', 'outcome' => 'captured']);

		$this->assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		$this->assertSame('paid', $this->lastSaved(schema: 'ARInvoice')['lifecycleState']);
		$this->assertSame('captured', $this->lastSaved(schema: 'PaymentRequest')['state']);
	}//end testACaptureIssuesTheSaleThenSettlesItPaid()

	/**
	 * When the sale cannot be issued the capture is surfaced as unapplied, never settled.
	 *
	 * @return void
	 */
	public function testASaleThatCannotBeIssuedLeavesTheCaptureUnapplied(): void {
		$service = $this->service();
		$this->counterSales->method('issueOnCapture')->willThrowException(new \RuntimeException('The counter sale was not issued.'));

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_cs1', 'outcome' => 'captured']);

		$this->assertSame(PaymentReconciliationService::RESULT_UNAPPLIED, $out['result']);
		$this->assertNull($this->lastSaved(schema: 'ARInvoice'));
		$this->assertSame('captured_unapplied', $this->lastSaved(schema: 'PaymentRequest')['state']);
	}//end testASaleThatCannotBeIssuedLeavesTheCaptureUnapplied()

	/**
	 * A failed or voided payment hands the request to the cancel step and records the outcome.
	 *
	 * @return void
	 */
	public function testAFailedOrVoidedPaymentCancelsTheSale(): void {
		foreach (['failed', 'voided'] as $outcome) {
			$service = $this->service();
			$this->counterSales->expects($this->once())->method('cancelOnFailure')
				->with('shillinq', $this->callback(
					static fn (array $request): bool => $request['invoiceReference'] === 'inv-cs-1' && $request['state'] === $outcome
				))
				->willReturn(true);
			$this->counterSales->expects($this->never())->method('issueOnCapture');

			$service->reconcile('mollie', ['paymentIntentId' => 'tr_cs1', 'outcome' => $outcome]);

			$this->assertSame($outcome, $this->lastSaved(schema: 'PaymentRequest')['state']);
		}
	}//end testAFailedOrVoidedPaymentCancelsTheSale()

	/**
	 * An authorisation is neither a capture nor a failure: the sale is left alone.
	 *
	 * @return void
	 */
	public function testAnAuthorisationLeavesTheSaleAlone(): void {
		$service = $this->service();
		$this->counterSales->expects($this->never())->method('cancelOnFailure');
		$this->counterSales->expects($this->never())->method('issueOnCapture');

		$service->reconcile('mollie', ['paymentIntentId' => 'tr_cs1', 'outcome' => 'authorized']);

		$this->assertSame('authorized', $this->lastSaved(schema: 'PaymentRequest')['state']);
	}//end testAnAuthorisationLeavesTheSaleAlone()
}//end class
