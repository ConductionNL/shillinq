<?php

/**
 * Unit tests for PaymentLinkCreator (receivables-payment-links task 1.3).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.3
 *
 * @covers \OCA\Shillinq\Service\Payment\PaymentLinkCreator
 * @uses   \OCA\Shillinq\Service\Payment\PaymentSessionRequest
 * @uses   \OCA\Shillinq\Service\Payment\PaymentSessionResult
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Payment;

use OCA\Shillinq\Service\Payment\PaymentLinkCreator;
use OCA\Shillinq\Service\Payment\PaymentProviderInterface;
use OCA\Shillinq\Service\Payment\PaymentSessionRequest;
use OCA\Shillinq\Service\Payment\PaymentSessionResult;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The checkout link a raised request is given, and when it is given none.
 */
final class PaymentLinkCreatorTest extends TestCase {

	/**
	 * The mocked provider.
	 *
	 * @var PaymentProviderInterface&MockObject
	 */
	private PaymentProviderInterface&MockObject $provider;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->provider = $this->createMock(PaymentProviderInterface::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The creator under test.
	 *
	 * @param string $redirect The configured return URL.
	 *
	 * @return PaymentLinkCreator
	 */
	private function creator(string $redirect = ''): PaymentLinkCreator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $args): string => 'https://instance.example/' . $route . '/' . $args['gateway']);
		$urls->method('getAbsoluteURL')->willReturn('https://instance.example/');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $key === 'portal_payment_redirect_url' ? $redirect : $default);

		return new PaymentLinkCreator(provider: $this->provider, urlGenerator: $urls, appConfig: $appConfig, logger: $this->logger);
	}//end creator()

	/**
	 * A leges request.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pr-0188',
				'state' => 'pending',
				'amount' => 97.5,
				'currency' => 'EUR',
				'description' => 'Omgevingsvergunning kappen, 1 boom',
				'administrationId' => 'adm-voorbeeld',
			],
			$overrides
		);
	}//end request()

	/**
	 * A live provider gives the checkout link, the provider payment id and an expiry fifteen minutes on.
	 *
	 * @return void
	 */
	public function testALiveProviderGivesTheLinkFields(): void {
		$captured = null;
		$this->provider->expects($this->once())->method('createSession')->willReturnCallback(
			function (PaymentSessionRequest $session) use (&$captured): PaymentSessionResult {
				$captured = $session;
				return new PaymentSessionResult(dormant: false, checkoutUrl: 'https://www.mollie.com/checkout/tr_example0002', paymentIntentId: 'tr_example0002');
			}
		);

		$before = time();
		$fields = $this->creator()->linkFieldsFor(request: self::request());

		self::assertSame(['paymentIntentId', 'providerPaymentId', 'paymentLink', 'checkoutExpiresAt'], array_keys($fields));
		self::assertSame('tr_example0002', $fields['paymentIntentId']);
		self::assertSame('tr_example0002', $fields['providerPaymentId']);
		self::assertSame('https://www.mollie.com/checkout/tr_example0002', $fields['paymentLink']);
		$expires = strtotime($fields['checkoutExpiresAt']);
		self::assertGreaterThanOrEqual($before + 900, $expires);
		self::assertLessThanOrEqual(time() + 900, $expires);

		self::assertSame(97.5, $captured->amount);
		self::assertSame('EUR', $captured->currency);
		self::assertSame('Omgevingsvergunning kappen, 1 boom', $captured->description);
		self::assertSame('ideal', $captured->method);
		self::assertSame('https://instance.example/', $captured->redirectUrl);
		self::assertSame('https://instance.example/shillinq.paymentRequestWebhook.handle/mollie', $captured->webhookUrl);
		self::assertSame(['paymentRequestId' => 'pr-0188', 'administrationId' => 'adm-voorbeeld', 'correlationId' => 'pr-0188'], $captured->metadata);
	}//end testALiveProviderGivesTheLinkFields()

	/**
	 * An invoice-backed request names its invoice, a configured return URL is used, and a request without a description gets one.
	 *
	 * @return void
	 */
	public function testTheSessionNamesTheInvoiceAndTheConfiguredReturnUrl(): void {
		$captured = null;
		$this->provider->method('createSession')->willReturnCallback(
			function (PaymentSessionRequest $session) use (&$captured): PaymentSessionResult {
				$captured = $session;
				return new PaymentSessionResult(dormant: false, checkoutUrl: 'https://www.mollie.com/checkout/tr_9', paymentIntentId: 'tr_9');
			}
		);

		$this->creator(redirect: 'https://portal.example/betaald')->linkFieldsFor(request: self::request(['invoiceReference' => 'inv-1', 'description' => '  ']));

		self::assertSame('https://portal.example/betaald', $captured->redirectUrl);
		self::assertSame('inv-1', $captured->metadata['invoiceId']);
		self::assertSame('Payment request', $captured->description);
	}//end testTheSessionNamesTheInvoiceAndTheConfiguredReturnUrl()

	/**
	 * A dormant provider gives no link.
	 *
	 * @return void
	 */
	public function testADormantProviderGivesNothing(): void {
		$this->provider->method('createSession')->willReturn(new PaymentSessionResult(dormant: true, checkoutUrl: '', paymentIntentId: ''));

		self::assertSame([], $this->creator()->linkFieldsFor(request: self::request()));
	}//end testADormantProviderGivesNothing()

	/**
	 * A live answer without a checkout URL or payment id is no link either.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutACheckoutGivesNothing(): void {
		self::assertSame([], PaymentLinkCreator::fieldsOf(session: new PaymentSessionResult(dormant: false, checkoutUrl: '', paymentIntentId: 'tr_1')));
		self::assertSame([], PaymentLinkCreator::fieldsOf(session: new PaymentSessionResult(dormant: false, checkoutUrl: 'https://www.mollie.com/checkout/x', paymentIntentId: '')));
	}//end testAnAnswerWithoutACheckoutGivesNothing()

	/**
	 * A provider failure is logged and gives no link; it never fails the raise.
	 *
	 * @return void
	 */
	public function testAProviderFailureIsLoggedAndGivesNothing(): void {
		$this->provider->method('createSession')->willThrowException(new RuntimeException('provider down'));
		$this->logger->expects($this->once())->method('warning')->with('Shillinq: no payment link could be created for a raised request', ['error' => 'provider down']);

		self::assertSame([], $this->creator()->linkFieldsFor(request: self::request()));
	}//end testAProviderFailureIsLoggedAndGivesNothing()

	/**
	 * A settled request, one without an id and one without a chargeable amount get no checkout at all.
	 *
	 * @return void
	 */
	public function testARequestThatCannotBeChargedIsNotSentToTheProvider(): void {
		$this->provider->expects($this->never())->method('createSession');
		$creator = $this->creator();

		self::assertSame([], $creator->linkFieldsFor(request: self::request(['state' => 'captured'])));
		self::assertSame([], $creator->linkFieldsFor(request: self::request(['id' => ''])));
		self::assertSame([], $creator->linkFieldsFor(request: self::request(['amount' => 0])));
		self::assertSame([], $creator->linkFieldsFor(request: self::request(['amount' => 'n/a'])));
		self::assertSame([], $creator->linkFieldsFor(request: self::request(['amount' => true])));
	}//end testARequestThatCannotBeChargedIsNotSentToTheProvider()
}//end class
