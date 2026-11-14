<?php

/**
 * Unit tests for IntegriqPaymentAdapter.
 *
 * A dispatcher stand-in plays integriq's PaymentRequestedListener on the real
 * event class (the verbatim stub under tests/stubs/Integriq): it answers with
 * a live checkout, a log-provider result or a refusal, or leaves the command
 * unanswered.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Integriq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\External\Integriq;

use OCA\Integriq\Event\PaymentRequestedEvent;
use OCA\Shillinq\Service\External\Integriq\IntegriqPaymentAdapter;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Verifies how the adapter sends integriq's payment command and reads its answer.
 *
 * @covers \OCA\Shillinq\Service\External\Integriq\IntegriqPaymentAdapter
 * @uses   \OCA\Shillinq\Service\External\Mollie\MolliePaymentResult
 */
final class IntegriqPaymentAdapterTest extends TestCase {

	/**
	 * The dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * A payment payload as the payment provider builds it for a request.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return [
			'amount' => ['value' => '97.50', 'currency' => 'EUR'],
			'description' => 'Leges Z-2026-00188',
			'redirectUrl' => 'https://portal.example.test/return',
			'webhookUrl' => 'https://shillinq.example.test/webhook',
			'method' => 'ideal',
			'metadata' => ['paymentRequestId' => 'pr-1', 'administrationId' => 'adm-1', 'correlationId' => 'pr-1'],
		];
	}//end payload()

	/**
	 * Have the dispatcher answer the command as integriq's listener would.
	 *
	 * @param callable(PaymentRequestedEvent): void $answer The answer.
	 * @param PaymentRequestedEvent|null            $seen   Receives the dispatched event.
	 *
	 * @return void
	 */
	private function integriqAnswers(callable $answer, ?PaymentRequestedEvent &$seen = null): void {
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function ($event) use ($answer, &$seen): void {
				$seen = $event;
				$answer($event);
			}
		);
	}//end integriqAnswers()

	/**
	 * A handled command returns integriq's checkout, provider payment id and intent id.
	 *
	 * @return void
	 */
	public function testAHandledCommandReturnsTheLiveCheckout(): void {
		$seen = null;
		$this->integriqAnswers(
			answer: static function (PaymentRequestedEvent $event): void {
				$event->setHandled(handled: true);
				$event->setResult(
					paymentIntentId: 'pi-9',
					providerPaymentId: 'tr_abc',
					checkoutUrl: 'https://checkout.example.test/tr_abc',
					paymentStatus: 'open',
					dormant: false
				);
			},
			seen: $seen
		);

		$result = (new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger))->createPayment(payload: $this->payload());

		self::assertFalse(condition: $result->dormant);
		self::assertSame(expected: 'open', actual: $result->paymentStatus);
		self::assertSame(expected: 'tr_abc', actual: $result->molliePaymentId);
		self::assertSame(expected: 'https://checkout.example.test/tr_abc', actual: $result->checkoutUrl);
		self::assertSame(expected: ['paymentIntentId' => 'pi-9'], actual: $result->extras);
	}//end testAHandledCommandReturnsTheLiveCheckout()

	/**
	 * The command carries shillinq as the source app, the request as subject and the payload's fields.
	 *
	 * @return void
	 */
	public function testTheCommandCarriesProvenanceAndThePayment(): void {
		$seen = null;
		$this->integriqAnswers(answer: static fn (PaymentRequestedEvent $event) => $event->setHandled(handled: true), seen: $seen);

		(new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger))->createPayment(payload: $this->payload());

		self::assertInstanceOf(expected: PaymentRequestedEvent::class, actual: $seen);
		self::assertSame(expected: 'shillinq', actual: $seen->getSourceApp());
		self::assertSame(expected: 'PaymentRequest/pr-1', actual: $seen->getSubjectRef());
		self::assertSame(expected: '97.50', actual: $seen->getAmountValue());
		self::assertSame(expected: 'EUR', actual: $seen->getAmountCurrency());
		self::assertSame(expected: 'Leges Z-2026-00188', actual: $seen->getDescription());
		self::assertSame(expected: 'https://portal.example.test/return', actual: $seen->getRedirectUrl());
		self::assertSame(expected: 'ideal', actual: $seen->getMethod());
		self::assertSame(expected: 'pr-1', actual: $seen->getCorrelationId());
		self::assertSame(
			expected: ['paymentRequestId' => 'pr-1', 'administrationId' => 'adm-1', 'correlationId' => 'pr-1'],
			actual: $seen->getMetadata()
		);
	}//end testTheCommandCarriesProvenanceAndThePayment()

	/**
	 * An invoice payment names the invoice as subject, and no method leaves it to integriq.
	 *
	 * @return void
	 */
	public function testAnInvoicePaymentNamesTheInvoice(): void {
		$seen = null;
		$this->integriqAnswers(answer: static fn (PaymentRequestedEvent $event) => $event->setHandled(handled: true), seen: $seen);
		$payload = $this->payload();
		$payload['metadata'] = ['invoiceId' => 'inv-7'];
		unset($payload['method']);

		(new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger))->createPayment(payload: $payload);

		self::assertSame(expected: 'ARInvoice/inv-7', actual: $seen->getSubjectRef());
		self::assertNull(actual: $seen->getMethod());
		self::assertSame(expected: '', actual: $seen->getCorrelationId());
	}//end testAnInvoicePaymentNamesTheInvoice()

	/**
	 * An unanswered command is dormant: no link, no payment id, the reason named.
	 *
	 * @return void
	 */
	public function testAnUnhandledCommandIsDormant(): void {
		$this->integriqAnswers(answer: static function (): void {
		});

		$result = (new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger))->createPayment(payload: $this->payload());

		self::assertTrue(condition: $result->dormant);
		self::assertSame(expected: 'PAYMENT_DEFERRED', actual: $result->paymentStatus);
		self::assertSame(expected: '', actual: $result->checkoutUrl);
		self::assertSame(expected: '', actual: $result->molliePaymentId);
		self::assertSame(expected: 'command-not-handled', actual: $result->extras['reason']);
	}//end testAnUnhandledCommandIsDormant()

	/**
	 * A log-provider source is dormant even when it hands back a URL.
	 *
	 * @return void
	 */
	public function testALogProviderSourceIsDormantWithoutALink(): void {
		$this->integriqAnswers(
			answer: static function (PaymentRequestedEvent $event): void {
				$event->setHandled(handled: true);
				$event->setResult(
					paymentIntentId: 'pi-1',
					providerPaymentId: 'log_1',
					checkoutUrl: 'https://log.example.test',
					paymentStatus: 'open',
					dormant: true
				);
			}
		);

		$result = (new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger))->createPayment(payload: $this->payload());

		self::assertTrue(condition: $result->dormant);
		self::assertSame(expected: '', actual: $result->checkoutUrl);
		self::assertSame(expected: '', actual: $result->molliePaymentId);
		self::assertSame(expected: 'log-provider', actual: $result->extras['reason']);
	}//end testALogProviderSourceIsDormantWithoutALink()

	/**
	 * A source that is not set up is dormant with integriq's code and reason, and no warning.
	 *
	 * @return void
	 */
	public function testANoSourceRefusalIsDormant(): void {
		$this->integriqAnswers(
			answer: static function (PaymentRequestedEvent $event): void {
				$event->setHandled(handled: true);
				$event->setRefusal(reason: 'No active payment source.', code: 'no-source');
			}
		);
		$this->logger->expects($this->never())->method('warning');

		$result = (new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger))->createPayment(payload: $this->payload());

		self::assertTrue(condition: $result->dormant);
		self::assertSame(expected: '', actual: $result->checkoutUrl);
		self::assertSame(expected: 'no-source', actual: $result->extras['reason']);
		self::assertSame(expected: 'No active payment source.', actual: $result->extras['note']);
	}//end testANoSourceRefusalIsDormant()

	/**
	 * A provider error gives no link and is logged as a warning.
	 *
	 * @return void
	 */
	public function testAProviderErrorIsDormantAndWarned(): void {
		$this->integriqAnswers(
			answer: static function (PaymentRequestedEvent $event): void {
				$event->setHandled(handled: true);
				$event->setRefusal(reason: 'Mollie answered 500.', code: 'provider-error');
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'Shillinq: integriq refused the payment',
			['code' => 'provider-error', 'reason' => 'Mollie answered 500.']
		);

		$result = (new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger))->createPayment(payload: $this->payload());

		self::assertTrue(condition: $result->dormant);
		self::assertSame(expected: '', actual: $result->checkoutUrl);
		self::assertSame(expected: 'provider-error', actual: $result->extras['reason']);
	}//end testAProviderErrorIsDormantAndWarned()

	/**
	 * A dispatch that throws fails closed to dormant.
	 *
	 * @return void
	 */
	public function testADispatchFailureIsDormant(): void {
		$this->dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('listener exploded'));

		$result = (new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger))->createPayment(payload: $this->payload());

		self::assertTrue(condition: $result->dormant);
		self::assertSame(expected: 'command-failed', actual: $result->extras['reason']);
		self::assertSame(expected: 'listener exploded', actual: $result->extras['note']);
	}//end testADispatchFailureIsDormant()

	/**
	 * Without integriq the adapter is dormant and dispatches nothing.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqTheAdapterIsDormantAndSendsNothing(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$adapter = new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger, commandEvent: 'OCA\Integriq\Event\NoSuchEvent');

		$result = $adapter->createPayment(payload: $this->payload());

		self::assertTrue(condition: $adapter->isDormant());
		self::assertTrue(condition: $result->dormant);
		self::assertSame(expected: '', actual: $result->checkoutUrl);
		self::assertSame(expected: 'integriq-absent', actual: $result->extras['reason']);
	}//end testWithoutIntegriqTheAdapterIsDormantAndSendsNothing()

	/**
	 * With integriq's command available the adapter reports itself live.
	 *
	 * @return void
	 */
	public function testWithIntegriqTheAdapterIsNotDormant(): void {
		self::assertFalse(condition: (new IntegriqPaymentAdapter(eventDispatcher: $this->dispatcher, logger: $this->logger))->isDormant());
	}//end testWithIntegriqTheAdapterIsNotDormant()
}//end class
