<?php

/**
 * Unit tests for the direct debit collection outcome actions.
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
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-collection-outcome-settles-or-keeps-the-invoice-req-sdd-013
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\RecordDirectDebitRejectAction;
use OCA\Shillinq\Lifecycle\Action\SettleDirectDebitCollectionAction;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-SDD-013: a succeeded collection pays its invoice through the real
 * settlement service; a rejected one keeps it open with the reason code.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\SettleDirectDebitCollectionAction
 * @covers \OCA\Shillinq\Lifecycle\Action\RecordDirectDebitRejectAction
 * @uses   \OCA\Shillinq\Service\Bank\InvoiceSettlementService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class DirectDebitCollectionOutcomeActionsTest extends TestCase {

	/**
	 * The transitions the settlement asked for, as [invoice id, action].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $ran = [];

	/**
	 * The succeed action over the real settlement service and these invoices.
	 *
	 * @param bool $refuse Whether the invoice's transition is refused.
	 *
	 * @return SettleDirectDebitCollectionAction
	 */
	private function settle(bool $refuse=false): SettleDirectDebitCollectionAction {
		$store = new InMemoryObjectServiceStub(
			data: [
				'ARInvoice' => [
					['id' => 'inv-118', 'invoiceNumber' => 'F-2026-118', 'lifecycleState' => 'issued'],
					['id' => 'inv-120', 'invoiceNumber' => 'F-2026-120', 'lifecycleState' => 'overdue'],
					['id' => 'inv-121', 'invoiceNumber' => 'F-2026-121', 'lifecycleState' => 'paid'],
				],
			]
		);
		$runner = $this->createMock(ObjectTransitionRunner::class);
		$runner->method('run')->willReturnCallback(
			function (string $objectId, string $action) use ($refuse): void {
				if ($refuse === true) {
					throw new RuntimeException('guard refused');
				}

				$this->ran[] = [$objectId, $action];
			}
		);
		$location = $this->createConfiguredMock(originalClassName: RegisterLocation::class, configuration: ['getRegisterSlug' => 'shillinq']);

		return new SettleDirectDebitCollectionAction(
			settlement: new InvoiceSettlementService(
				objectService: $store,
				transitions: $runner,
				registerLocation: $location,
				logger: $this->createMock(LoggerInterface::class)
			)
		);

	}//end settle()

	/**
	 * A succeeded collection of EUR 500 marks its issued invoice paid.
	 *
	 * @return void
	 */
	public function testASucceededCollectionPaysItsInvoice(): void {
		$collection = ['id' => 'dc-1', 'invoiceId' => 'inv-118', 'amount' => 500.0, 'status' => 'succeeded'];

		$this->assertSame($collection, $this->settle()->execute($collection, ['status' => 'accepted_by_bank'] + $collection, [], 'succeed'));
		$this->assertSame([['inv-118', 'mark-paid']], $this->ran);

	}//end testASucceededCollectionPaysItsInvoice()

	/**
	 * An overdue invoice is paid by its own transition; a paid one is left alone.
	 *
	 * @return void
	 */
	public function testAnOverdueInvoiceIsPaidAndAPaidOneIsLeftAlone(): void {
		$action = $this->settle();
		$action->execute(['invoiceId' => 'inv-120'], [], [], 'succeed');
		$action->execute(['invoiceId' => 'inv-121'], [], [], 'succeed');

		$this->assertSame([['inv-120', 'pay-overdue']], $this->ran);

	}//end testAnOverdueInvoiceIsPaidAndAPaidOneIsLeftAlone()

	/**
	 * A refused settlement refuses the succeed transition, naming the invoice.
	 *
	 * @return void
	 */
	public function testARefusedSettlementRefusesTheTransition(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invoice inv-118 could not be settled: transition refused.');
		$this->settle(refuse: true)->execute(['invoiceId' => 'inv-118'], [], [], 'succeed');

	}//end testARefusedSettlementRefusesTheTransition()

	/**
	 * A missing invoice, or none named, refuses the transition.
	 *
	 * @return void
	 */
	public function testACollectionWithoutItsInvoiceIsRefused(): void {
		try {
			$this->settle()->execute(['invoiceId' => 'inv-missing'], [], [], 'succeed');
			$this->fail('A missing invoice settled.');
		} catch (RuntimeException $e) {
			$this->assertSame('Invoice inv-missing could not be settled: not found.', $e->getMessage());
		}

		$this->expectExceptionMessage('The collection names no invoice to settle.');
		$this->settle()->execute(['invoiceId' => ' '], [], [], 'succeed');

	}//end testACollectionWithoutItsInvoiceIsRefused()

	/**
	 * A rejected collection keeps reason AM04, upper-cased.
	 *
	 * @return void
	 */
	public function testARejectedCollectionKeepsItsReasonCode(): void {
		$collection = ['id' => 'dc-2', 'invoiceId' => 'inv-119', 'status' => 'rejected', 'pain002ReasonCode' => ' am04 '];

		$result = (new RecordDirectDebitRejectAction())->execute($collection, ['status' => 'submitted'] + $collection, [], 'reject');

		$this->assertSame(array_replace($collection, ['pain002ReasonCode' => 'AM04']), $result);
		$this->assertSame('inv-119', $result['invoiceId']);

	}//end testARejectedCollectionKeepsItsReasonCode()

	/**
	 * A rejection without a reason code, or with a malformed one, is refused.
	 *
	 * @return void
	 */
	public function testARejectionNeedsAReasonCode(): void {
		foreach ([[], ['pain002ReasonCode' => 'AM0'], ['pain002ReasonCode' => 'AM04X']] as $collection) {
			try {
				(new RecordDirectDebitRejectAction())->execute($collection, [], [], 'reject');
				$this->fail('A rejection without a valid code was accepted.');
			} catch (RuntimeException $e) {
				$this->assertSame('A rejected collection needs its four-character reason code, such as AM04.', $e->getMessage());
			}
		}

	}//end testARejectionNeedsAReasonCode()
}//end class
