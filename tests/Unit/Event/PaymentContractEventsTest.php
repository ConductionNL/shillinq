<?php

/**
 * Unit tests for the payment contract events other apps dispatch to shillinq.
 *
 * Each event carries a request in and an answer out: accepted with the
 * contract version and what shillinq made of it, or refused with an error,
 * never both. The request side normalises what callers send (trimmed fields,
 * an IBAN without spaces in capitals, the amount to cents, correlation ids
 * without blanks).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Event;

use OCA\Shillinq\Event\PayableCancelRequestedEvent;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCA\Shillinq\Event\PayablesRequestedEvent;
use OCA\Shillinq\Event\PaymentCreditRequestedEvent;
use OCA\Shillinq\Event\PaymentRefundRequestedEvent;
use OCA\Shillinq\Event\PaymentSettlementCommandEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Request normalisation and the accept/refuse answer of the payment events.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(PayableRaisedEvent::class)]
#[CoversClass(PayableCancelRequestedEvent::class)]
#[CoversClass(PayablesRequestedEvent::class)]
#[CoversClass(PaymentSettlementCommandEvent::class)]
#[CoversClass(PaymentCreditRequestedEvent::class)]
#[CoversClass(PaymentRefundRequestedEvent::class)]
final class PaymentContractEventsTest extends TestCase {

	/**
	 * A raised payable is normalised for the ledger.
	 *
	 * @return void
	 */
	public function testARaisedPayableIsNormalised(): void {
		$event = new PayableRaisedEvent(
			sourceApp: ' pipelinq ',
			amount: 12.345,
			payeeName: ' Bakkerij Korenbloem ',
			payeeIban: 'nl91 abna 0417 1643 00',
			reference: ' REF-1 ',
			description: ' Subsidie ',
			currency: ' eur ',
			linkedObjects: ['a' => 'obj-1', 'b' => 'obj-2'],
		);

		self::assertSame(
			[
				'sourceApp' => 'pipelinq',
				'category' => '',
				'amount' => 12.35,
				'currency' => 'EUR',
				'payee' => ['name' => 'Bakkerij Korenbloem', 'iban' => 'NL91ABNA0417164300'],
				'reference' => 'REF-1',
				'description' => 'Subsidie',
				'correlationId' => '',
				'administrationId' => '',
				'dueOn' => '',
				'callbackUrl' => '',
				'linkedObjects' => ['obj-1', 'obj-2'],
				'historicalState' => '',
				'paidOn' => '',
				'bankReference' => '',
			],
			$event->toArray()
		);
	}//end testARaisedPayableIsNormalised()

	/**
	 * Accepting answers the contract version; a later refusal clears the answer.
	 *
	 * @return void
	 */
	public function testARaisedPayableIsAcceptedOrRefusedNeverBoth(): void {
		$event = new PayableRaisedEvent('app', 1.0, 'n', 'NL00', 'r');
		self::assertFalse($event->isHandled());
		self::assertNull($event->getResult());

		$event->refuse('missing IBAN');
		$event->accept('payable-1', 'open');
		self::assertTrue($event->isHandled());
		self::assertNull($event->getError());
		self::assertSame(['contractVersion' => 1, 'payableId' => 'payable-1', 'state' => 'open'], $event->getResult());

		$event->refuse('duplicate reference');
		self::assertFalse($event->isHandled());
		self::assertNull($event->getResult());
		self::assertSame('duplicate reference', $event->getError());
	}//end testARaisedPayableIsAcceptedOrRefusedNeverBoth()

	/**
	 * A cancellation request reads trimmed, and answers the same way.
	 *
	 * @return void
	 */
	public function testACancellationIsTrimmedAndAnswered(): void {
		$event = new PayableCancelRequestedEvent(' pipelinq ', ' REF-1 ', ' withdrawn ');
		self::assertSame(['pipelinq', 'REF-1', 'withdrawn'], [$event->getSourceApp(), $event->getReference(), $event->getReason()]);

		$event->accept('payable-1', 'cancelled');
		self::assertSame(['contractVersion' => 1, 'payableId' => 'payable-1', 'state' => 'cancelled'], $event->getResult());

		$event->refuse('already paid');
		self::assertSame([false, null, 'already paid'], [$event->isHandled(), $event->getResult(), $event->getError()]);
	}//end testACancellationIsTrimmedAndAnswered()

	/**
	 * A payables query drops blank correlation ids and answers a list.
	 *
	 * @return void
	 */
	public function testAPayablesQueryDropsBlankIdsAndAnswersAList(): void {
		$event = new PayablesRequestedEvent(' pipelinq ', ['c-1', '', 7, 'c-2'], ' grant ', 2026);
		self::assertSame(['c-1', '7', 'c-2'], $event->getCorrelationIds());
		self::assertSame(['pipelinq', 'grant', 2026], [$event->getSourceApp(), $event->getCategory(), $event->getPaidInYear()]);
		self::assertSame([], $event->getPayables());
		self::assertFalse($event->isHandled());

		$event->answer(['x' => ['id' => 'p-1'], 'y' => ['id' => 'p-2']]);
		self::assertTrue($event->isHandled());
		self::assertSame([['id' => 'p-1'], ['id' => 'p-2']], $event->getPayables());

		$event->refuse('unknown app');
		self::assertSame([false, [], 'unknown app'], [$event->isHandled(), $event->getPayables(), $event->getError()]);

		$event->answer([]);
		self::assertTrue($event->isHandled(), 'an empty answer is still an answer');
		self::assertNull($event->getError());
	}//end testAPayablesQueryDropsBlankIdsAndAnswersAList()

	/**
	 * Credit and refund commands carry their request and answer with the payment request id.
	 *
	 * @return void
	 */
	public function testSettlementCommandsAnswerWithTheirPaymentRequest(): void {
		foreach ([PaymentCreditRequestedEvent::class, PaymentRefundRequestedEvent::class] as $class) {
			$event = new $class('portaliq', 'pr-9', 'overpaid', 'corr-1');
			self::assertSame(['portaliq', 'pr-9', 'overpaid', 'corr-1'], [$event->getSourceApp(), $event->getPaymentRequestId(), $event->getReason(), $event->getCorrelationId()]);

			$event->accept('refunded');
			self::assertSame(['contractVersion' => 1, 'paymentRequestId' => 'pr-9', 'state' => 'refunded'], $event->getResult(), $class);
			self::assertTrue($event->isHandled());

			$event->refuse('not paid');
			self::assertSame([false, null, 'not paid'], [$event->isHandled(), $event->getResult(), $event->getError()], $class);
		}
	}//end testSettlementCommandsAnswerWithTheirPaymentRequest()
}//end class
