<?php

/**
 * Unit tests for PaymentSettlementService.
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
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\PaymentSettlementService;
use PHPUnit\Framework\TestCase;

/**
 * Covers what a settlement must carry, that it never overwrites the provider,
 * and how the two derive one reported state (REQ-FPCR-003).
 */
final class PaymentSettlementServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var PaymentSettlementService
	 */
	private PaymentSettlementService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new PaymentSettlementService();
	}//end setUp()

	/**
	 * A request of 245.00 with the given provider state and settlements.
	 *
	 * @param string $state The provider state.
	 * @param array<int, array<string, mixed>> $settlements Settlements already recorded.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function request(string $state = 'pending', array $settlements = []): array {
		return ['amount' => 245.0, 'currency' => 'EUR', 'state' => $state, 'settlements' => $settlements];
	}//end request()

	/**
	 * One settlement record.
	 *
	 * @param float $amount The amount.
	 *
	 * @return array<string, mixed> The settlement.
	 */
	private function settlement(float $amount): array {
		return $this->service->build(
			['method' => 'pin', 'amount' => $amount, 'reference' => 'PIN-1'],
			'clerk',
			'2026-09-18T11:00:00Z'
		);
	}//end settlement()

	/**
	 * A counter payment is recorded with the clerk, the method and the evidence.
	 *
	 * @return void
	 */
	public function testAPinPaymentCarriesItsActorMethodAndReference(): void {
		$settlement = $this->settlement(245.0);

		self::assertSame('pin', $settlement['method']);
		self::assertSame('clerk', $settlement['actor']);
		self::assertSame('PIN-1', $settlement['reference']);
		self::assertSame('2026-09-18T11:00:00Z', $settlement['settledAt']);
	}//end testAPinPaymentCarriesItsActorMethodAndReference()

	/**
	 * A settlement with no reference is refused: a bank reconciliation would have
	 * nothing to match it on, and an unmatched counter payment is the one that
	 * gets chased twice.
	 *
	 * @return void
	 */
	public function testASettlementNeedsTheEvidenceAReconciliationMatchesOn(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('reference');

		$this->service->build(['method' => 'cash', 'amount' => 245.0], 'clerk');
	}//end testASettlementNeedsTheEvidenceAReconciliationMatchesOn()

	/**
	 * A waiver has no money and therefore no bank evidence; what it needs is the
	 * reason it was granted.
	 *
	 * @return void
	 */
	public function testAWaiverNeedsItsReasonRatherThanAReference(): void {
		$waived = $this->service->build(['method' => 'waived', 'amount' => 0.0, 'reason' => 'Kwijtschelding'], 'clerk');

		self::assertSame('Kwijtschelding', $waived['reason']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('reason');

		$this->service->build(['method' => 'waived', 'amount' => 0.0], 'clerk');
	}//end testAWaiverNeedsItsReasonRatherThanAReference()

	/**
	 * An unknown method is refused rather than stored as free text nobody can
	 * report on later.
	 *
	 * @return void
	 */
	public function testAnUnknownMethodIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('method');

		$this->service->build(['method' => 'tikkie', 'amount' => 245.0, 'reference' => 'x'], 'clerk');
	}//end testAnUnknownMethodIsRefused()

	/**
	 * A settlement nobody is named for cannot be questioned later.
	 *
	 * @return void
	 */
	public function testASettlementNeedsAnActor(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('actor');

		$this->service->build(['method' => 'pin', 'amount' => 245.0, 'reference' => 'PIN-1'], '');
	}//end testASettlementNeedsAnActor()

	/**
	 * Appending NEVER touches the provider state. That is the whole design: a
	 * capture landing afterwards must still be visible.
	 *
	 * @return void
	 */
	public function testAppendingLeavesTheProviderStateAlone(): void {
		$request = $this->service->append($this->request('pending'), $this->settlement(245.0));

		self::assertSame('pending', $request['state']);
		self::assertCount(1, $request['settlements']);
	}//end testAppendingLeavesTheProviderStateAlone()

	/**
	 * A request nobody has paid reports open.
	 *
	 * @return void
	 */
	public function testAnUntouchedRequestReportsOpen(): void {
		self::assertSame(PaymentSettlementService::REPORTED_OPEN, $this->service->report($this->request())['state']);
	}//end testAnUntouchedRequestReportsOpen()

	/**
	 * A counter payment for the full amount reports paid.
	 *
	 * @return void
	 */
	public function testAFullCounterPaymentReportsPaid(): void {
		$request = $this->service->append($this->request(), $this->settlement(245.0));

		self::assertSame(PaymentSettlementService::REPORTED_PAID, $this->service->report($request)['state']);
	}//end testAFullCounterPaymentReportsPaid()

	/**
	 * A part payment reports partly paid, not paid. Rounding a short payment up
	 * to paid is how a shortfall stops being chased.
	 *
	 * @return void
	 */
	public function testAPartPaymentReportsPartlyPaid(): void {
		$request = $this->service->append($this->request(), $this->settlement(100.0));

		$report = $this->service->report($request);

		self::assertSame(PaymentSettlementService::REPORTED_PART_PAID, $report['state']);
		self::assertSame(100.0, $report['settled']);
	}//end testAPartPaymentReportsPartlyPaid()

	/**
	 * A provider capture landing after a counter payment reports an overpayment,
	 * with the amount somebody is owed back. Hiding it is how the refund never
	 * happens (REQ-FPCR-003).
	 *
	 * @return void
	 */
	public function testACaptureAfterACounterPaymentReportsTheOverpayment(): void {
		$request = $this->service->append($this->request('captured'), $this->settlement(245.0));

		$report = $this->service->report($request);

		self::assertSame(PaymentSettlementService::REPORTED_OVERPAID, $report['state']);
		self::assertSame(245.0, $report['over']);
	}//end testACaptureAfterACounterPaymentReportsTheOverpayment()

	/**
	 * A provider capture on its own reports paid, with no settlements at all: the
	 * derivation must not need a manual record to see the gateway's money.
	 *
	 * @return void
	 */
	public function testACaptureAloneReportsPaid(): void {
		self::assertSame(
			PaymentSettlementService::REPORTED_PAID,
			$this->service->report($this->request('captured'))['state']
		);
	}//end testACaptureAloneReportsPaid()

	/**
	 * A failed request nobody settled reports unpayable, which is different from
	 * open: one is waiting, the other is not coming.
	 *
	 * @return void
	 */
	public function testAFailedRequestReportsUnpayable(): void {
		self::assertSame(
			PaymentSettlementService::REPORTED_UNPAYABLE,
			$this->service->report($this->request('failed'))['state']
		);
	}//end testAFailedRequestReportsUnpayable()

	/**
	 * A failed gateway attempt that was then paid at the counter reports paid.
	 * The provider state is not the last word once money has arrived.
	 *
	 * @return void
	 */
	public function testAFailedRequestPaidAtTheCounterReportsPaid(): void {
		$request = $this->service->append($this->request('failed'), $this->settlement(245.0));

		self::assertSame(PaymentSettlementService::REPORTED_PAID, $this->service->report($request)['state']);
	}//end testAFailedRequestPaidAtTheCounterReportsPaid()
}//end class
