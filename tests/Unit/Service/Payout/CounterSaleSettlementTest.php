<?php

/**
 * Unit tests for CounterSaleSettlement.
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
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Payout;

use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\Payout\CounterSaleSettlement;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-RPPO-006: a captured counter sale is issued, a failed one cancelled.
 *
 * @covers \OCA\Shillinq\Service\Payout\CounterSaleSettlement
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 */
final class CounterSaleSettlementTest extends TestCase {

	/**
	 * The transition runner.
	 *
	 * @var ObjectTransitionRunnerInterface&MockObject
	 */
	private ObjectTransitionRunnerInterface $transitions;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The settlement over a store holding these invoices.
	 *
	 * @param list<array<string, mixed>> $invoices ARInvoice rows.
	 *
	 * @return CounterSaleSettlement
	 */
	private function settlement(array $invoices): CounterSaleSettlement {
		return new CounterSaleSettlement(
			objectService: new InMemoryObjectServiceStub(data: ['ARInvoice' => $invoices]),
			transitions: $this->transitions,
			logger: $this->logger,
		);
	}//end settlement()

	/**
	 * The tea tin sale of the scenario, in the given state.
	 *
	 * @param string $state The lifecycle state.
	 *
	 * @return array<string, mixed>
	 */
	private static function sale(string $state='draft'): array {
		return [
			'id' => 'inv-cs-1', 'invoiceNumber' => 'CS-20261117-AB12CD34', 'lifecycleState' => $state,
			'counterSale' => true, 'simplifiedInvoice' => true, 'grossAmount' => 12.5, 'administrationId' => 'THV',
		];
	}//end sale()

	/**
	 * A capture runs `issue` as the system and settles against the issued invoice.
	 *
	 * @return void
	 */
	public function testACapturedDraftSaleIsIssued(): void {
		$issued = self::sale(state: 'issued');
		$this->transitions->expects($this->once())->method('runAsSystem')->with('inv-cs-1', 'issue');

		$result = $this->settlement(invoices: [$issued])->issueOnCapture(registerSlug: 'shillinq', invoice: self::sale());

		$this->assertSame('issued', $result['lifecycleState']);
		$this->assertSame('inv-cs-1', $result['id']);
	}//end testACapturedDraftSaleIsIssued()

	/**
	 * When `issue` leaves the sale a draft (a guard refused it), the capture is not settled against it.
	 *
	 * @return void
	 */
	public function testASaleThatIsNotIssuedRefusesTheSettlement(): void {
		$this->transitions->expects($this->once())->method('runAsSystem')->with('inv-cs-1', 'issue');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('The counter sale was not issued');

		$this->settlement(invoices: [self::sale()])->issueOnCapture(registerSlug: 'shillinq', invoice: self::sale());
	}//end testASaleThatIsNotIssuedRefusesTheSettlement()

	/**
	 * An ordinary issued invoice, and a counter sale already issued, pass unchanged.
	 *
	 * @return void
	 */
	public function testAnyOtherInvoicePassesUnchanged(): void {
		$this->transitions->expects($this->never())->method('runAsSystem');
		$ordinary = ['id' => 'inv-0601', 'lifecycleState' => 'issued', 'grossAmount' => 145.2];

		$settlement = $this->settlement(invoices: []);

		$this->assertSame($ordinary, $settlement->issueOnCapture(registerSlug: 'shillinq', invoice: $ordinary));
		$this->assertSame(self::sale(state: 'issued'), $settlement->issueOnCapture(registerSlug: 'shillinq', invoice: self::sale(state: 'issued')));
		$this->assertSame(
			['id' => 'inv-2', 'lifecycleState' => 'draft'],
			$settlement->issueOnCapture(registerSlug: 'shillinq', invoice: ['id' => 'inv-2', 'lifecycleState' => 'draft'])
		);
	}//end testAnyOtherInvoicePassesUnchanged()

	/**
	 * A failed payment of a draft sale cancels it.
	 *
	 * @return void
	 */
	public function testAFailedPaymentCancelsTheDraftSale(): void {
		$this->transitions->expects($this->once())->method('runAsSystem')->with('inv-cs-1', 'cancel');

		$cancelled = $this->settlement(invoices: [self::sale()])
			->cancelOnFailure(registerSlug: 'shillinq', request: ['invoiceReference' => 'inv-cs-1', 'state' => 'failed']);

		$this->assertTrue($cancelled);
	}//end testAFailedPaymentCancelsTheDraftSale()

	/**
	 * A failed payment of an issued invoice, of an ordinary draft, or without an invoice cancels nothing.
	 *
	 * @return void
	 */
	public function testAFailedPaymentOfAnythingElseCancelsNothing(): void {
		$this->transitions->expects($this->never())->method('runAsSystem');
		$settlement = $this->settlement(
			invoices: [self::sale(state: 'issued') + ['id' => 'inv-cs-1'], ['id' => 'inv-2', 'lifecycleState' => 'draft']]
		);

		$this->assertFalse($settlement->cancelOnFailure(registerSlug: 'shillinq', request: ['invoiceReference' => 'inv-cs-1']));
		$this->assertFalse($settlement->cancelOnFailure(registerSlug: 'shillinq', request: ['invoiceReference' => 'inv-2']));
		$this->assertFalse($settlement->cancelOnFailure(registerSlug: 'shillinq', request: ['invoiceReference' => 'inv-gone']));
		$this->assertFalse($settlement->cancelOnFailure(registerSlug: 'shillinq', request: ['subjectKind' => 'object']));
	}//end testAFailedPaymentOfAnythingElseCancelsNothing()

	/**
	 * A cancel that throws is logged and the outcome still recorded by the caller.
	 *
	 * @return void
	 */
	public function testACancelThatFailsIsLoggedNotThrown(): void {
		$this->transitions->method('runAsSystem')->willThrowException(new \RuntimeException('engine down'));
		$this->logger->expects($this->once())->method('warning')
			->with('Shillinq: a counter sale whose payment failed could not be cancelled', $this->callback(
				static fn (array $context): bool => $context['invoiceReference'] === 'inv-cs-1' && $context['exception'] === 'engine down'
			));

		$this->assertFalse(
			$this->settlement(invoices: [self::sale()])->cancelOnFailure(registerSlug: 'shillinq', request: ['invoiceReference' => 'inv-cs-1'])
		);
	}//end testACancelThatFailsIsLoggedNotThrown()
}//end class
