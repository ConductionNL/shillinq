<?php

/**
 * Characterisation tests shared by the deposit and payment-request webhooks.
 *
 * Both endpoints verify the gateway signature, decode the payload and
 * normalise a Mollie or Stripe event the same way. These tests pin that
 * behaviour for both controllers: signature header selection and the
 * Stripe v1 segment, fail-closed paths, the logged refusal, the event each
 * gateway payload becomes (including error details), the exception path and
 * the result-to-status mapping.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DepositWebhookController;
use OCA\Shillinq\Controller\PaymentRequestWebhookController;
use OCA\Shillinq\Service\DepositReconciliationService;
use OCA\Shillinq\Service\PaymentReconciliationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the webhook intake of both payment webhook controllers.
 */
final class PaymentWebhookIntakeCharacterisationTest extends TestCase {

	/**
	 * The shared secret used to sign test payloads.
	 *
	 * @var string
	 */
	private const SECRET = 'whsec_characterisation';

	/**
	 * Logger mock of the controller under test.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every header name the controller asked for.
	 *
	 * @var array<int,string>
	 */
	private array $headersAsked = [];

	/**
	 * Every config key the controller read.
	 *
	 * @var array<int,string>
	 */
	private array $configRead = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->headersAsked = [];
		$this->configRead = [];

	}//end setUp()

	/**
	 * Build a request answering the given header value for any header.
	 *
	 * @param string $headerValue The signature header value.
	 *
	 * @return IRequest
	 */
	private function request(string $headerValue): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			function (string $name) use ($headerValue): string {
				$this->headersAsked[] = $name;
				return $headerValue;
			}
		);
		return $request;

	}//end request()

	/**
	 * Build an app config answering the given secret.
	 *
	 * @param string $secret The configured secret.
	 *
	 * @return IAppConfig
	 */
	private function config(string $secret): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($secret): string {
				$this->configRead[] = $app . '/' . $key . '/' . $default;
				return $secret;
			}
		);
		return $config;

	}//end config()

	/**
	 * Build a deposit webhook controller.
	 *
	 * @param array{body:string,header:string,secret:string} $call The call.
	 * @param DepositReconciliationService $recon The reconciliation mock.
	 *
	 * @return DepositWebhookController
	 */
	private function deposit(array $call, DepositReconciliationService $recon): DepositWebhookController {
		return new class($this->request($call['header']), $this->config($call['secret']), $recon, $this->logger, $call['body']) extends DepositWebhookController {
			/**
			 * Raw body served by the seam.
			 *
			 * @var string
			 */
			private string $body;

			/**
			 * Construct the test double.
			 *
			 * @param IRequest $request Request.
			 * @param IAppConfig $appConfig Config.
			 * @param DepositReconciliationService $recon Reconciliation.
			 * @param LoggerInterface $logger Logger.
			 * @param string $body Raw body.
			 */
			public function __construct(IRequest $request, IAppConfig $appConfig, DepositReconciliationService $recon, LoggerInterface $logger, string $body) {
				parent::__construct($request, $appConfig, $recon, $logger);
				$this->body = $body;
			}

			/**
			 * Serve the injected body.
			 *
			 * @return string
			 */
			protected function getRawBody(): string {
				return $this->body;
			}
		};

	}//end deposit()

	/**
	 * Build a payment-request webhook controller.
	 *
	 * @param array{body:string,header:string,secret:string} $call The call.
	 * @param PaymentReconciliationService $recon The reconciliation mock.
	 *
	 * @return PaymentRequestWebhookController
	 */
	private function paymentRequest(array $call, PaymentReconciliationService $recon): PaymentRequestWebhookController {
		return new class($this->request($call['header']), $this->config($call['secret']), $recon, $this->logger, $call['body']) extends PaymentRequestWebhookController {
			/**
			 * Raw body served by the seam.
			 *
			 * @var string
			 */
			private string $body;

			/**
			 * Construct the test double.
			 *
			 * @param IRequest $request Request.
			 * @param IAppConfig $appConfig Config.
			 * @param PaymentReconciliationService $recon Reconciliation.
			 * @param LoggerInterface $logger Logger.
			 * @param string $body Raw body.
			 */
			public function __construct(IRequest $request, IAppConfig $appConfig, PaymentReconciliationService $recon, LoggerInterface $logger, string $body) {
				parent::__construct($request, $appConfig, $recon, $logger);
				$this->body = $body;
			}

			/**
			 * Serve the injected body.
			 *
			 * @return string
			 */
			protected function getRawBody(): string {
				return $this->body;
			}
		};

	}//end paymentRequest()

	/**
	 * A signed call.
	 *
	 * @param array<string,mixed>|string $payload The payload (array is JSON-encoded).
	 *
	 * @param string $gateway The gateway whose signing scheme to use.
	 * @param int|null $timestamp The Stripe signing time, null for now.
	 *
	 * @return array{body:string,header:string,secret:string}
	 */
	private static function signed(array|string $payload, string $gateway = 'mollie', ?int $timestamp = null): array {
		$body = is_string($payload) === true ? $payload : (string)json_encode($payload);
		if ($gateway === 'stripe') {
			$timestamp ??= time();
			$header = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);
			return ['body' => $body, 'header' => $header, 'secret' => self::SECRET];
		}

		return ['body' => $body, 'header' => hash_hmac('sha256', $body, self::SECRET), 'secret' => self::SECRET];

	}//end signed()

	/**
	 * Call the deposit webhook with a reconciliation that must not run.
	 *
	 * @param string $gateway The route gateway.
	 * @param array{body:string,header:string,secret:string} $call The call.
	 *
	 * @return JSONResponse
	 */
	private function depositWithoutDispatch(string $gateway, array $call): JSONResponse {
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->expects(self::never())->method('reconcile');
		return $this->deposit($call, $recon)->handle($gateway);

	}//end depositWithoutDispatch()

	/**
	 * Call the payment-request webhook with a reconciliation that must not run.
	 *
	 * @param string $gateway The route gateway.
	 * @param array{body:string,header:string,secret:string} $call The call.
	 *
	 * @return JSONResponse
	 */
	private function paymentRequestWithoutDispatch(string $gateway, array $call): JSONResponse {
		$recon = $this->createMock(PaymentReconciliationService::class);
		$recon->expects(self::never())->method('reconcile');
		return $this->paymentRequest($call, $recon)->handle($gateway);

	}//end paymentRequestWithoutDispatch()

	/**
	 * Calls that are refused before reconciliation, with status and HTTP code.
	 *
	 * @return array<string,array{0:string,1:array{body:string,header:string,secret:string},2:string,3:int}>
	 */
	public static function refusedCalls(): array {
		$paid = ['id' => 'tr_1', 'status' => 'paid'];
		$body = (string)json_encode($paid);
		$sig = hash_hmac('sha256', $body, self::SECRET);
		return [
			'unknown gateway' => ['paypal', self::signed($paid), 'unknown-gateway', 404],
			'empty body' => ['mollie', ['body' => '', 'header' => 'x', 'secret' => self::SECRET], 'empty-body', 400],
			'no secret' => ['mollie', ['body' => $body, 'header' => $sig, 'secret' => ''], 'invalid-signature', 400],
			'no header' => ['mollie', ['body' => $body, 'header' => '', 'secret' => self::SECRET], 'invalid-signature', 400],
			'wrong signature' => ['mollie', ['body' => $body, 'header' => str_repeat('0', 64), 'secret' => self::SECRET], 'invalid-signature', 400],
			'stripe header without v1' => ['stripe', ['body' => $body, 'header' => 't=1,v0=' . $sig, 'secret' => self::SECRET], 'invalid-signature', 400],
			'not json' => ['mollie', self::signed('{nope'), 'malformed-payload', 400],
			'json scalar' => ['mollie', self::signed('"text"'), 'malformed-payload', 400],
			'mollie open status' => ['mollie', self::signed(['id' => 'tr_1', 'status' => 'open']), 'unparseable-event', 400],
			'mollie no id' => ['mollie', self::signed(['status' => 'paid']), 'unparseable-event', 400],
			'stripe unknown type' => ['stripe', self::signed(['type' => 'customer.created', 'data' => ['object' => ['id' => 'pi_1']]], 'stripe'), 'unparseable-event', 400],
			'stripe no id' => ['stripe', self::signed(['type' => 'payment_intent.succeeded'], 'stripe'), 'unparseable-event', 400],
			'stripe signature over the body alone' => ['stripe', ['body' => $body, 'header' => 't=' . time() . ',v1=' . $sig, 'secret' => self::SECRET], 'invalid-signature', 400],
			'stripe signature without a timestamp' => ['stripe', ['body' => $body, 'header' => 'v1=' . $sig, 'secret' => self::SECRET], 'invalid-signature', 400],
			'stripe timestamp older than five minutes' => ['stripe', self::signed($paid, 'stripe', time() - 301), 'invalid-signature', 400],
			'stripe timestamp more than five minutes ahead' => ['stripe', self::signed($paid, 'stripe', time() + 301), 'invalid-signature', 400],
		];

	}//end refusedCalls()

	/**
	 * Both webhooks refuse the same calls with the same status, never reconciling.
	 *
	 * @param string $gateway The route gateway.
	 * @param array{body:string,header:string,secret:string} $call The call.
	 * @param string $status The expected status.
	 * @param int $code The expected HTTP code.
	 *
	 * @return void
	 *
	 * @dataProvider refusedCalls
	 */
	public function testRefusedCallsAreRefusedByBothWebhooks(string $gateway, array $call, string $status, int $code): void {
		$deposit = $this->depositWithoutDispatch($gateway, $call);
		self::assertSame([$code, ['status' => $status]], [$deposit->getStatus(), $deposit->getData()]);

		$payment = $this->paymentRequestWithoutDispatch($gateway, $call);
		self::assertSame([$code, ['status' => $status]], [$payment->getStatus(), $payment->getData()]);

	}//end testRefusedCallsAreRefusedByBothWebhooks()

	/**
	 * The gateway slug is case-insensitive and selects header and secret key.
	 *
	 * @return void
	 */
	public function testGatewaySelectsHeaderAndSecretKey(): void {
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->method('reconcile')->willReturn(DepositReconciliationService::RESULT_APPLIED);
		$this->deposit(self::signed(['id' => 'tr_1', 'status' => 'paid']), $recon)->handle('MOLLIE');
		$stripe = self::signed(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1']]], 'stripe');
		$this->deposit($stripe, $recon)->handle('Stripe');

		self::assertSame(['X-Mollie-Signature', 'Stripe-Signature'], $this->headersAsked);
		self::assertSame(
			['shillinq/deposit_webhook_secret_mollie/', 'shillinq/deposit_webhook_secret_stripe/'],
			$this->configRead
		);

	}//end testGatewaySelectsHeaderAndSecretKey()

	/**
	 * The signature is compared trimmed and lower-cased; every Stripe v1 value is tried.
	 *
	 * @return array<string,array{0:string,1:callable}>
	 */
	public static function acceptedSignatures(): array {
		return [
			'mollie upper case padded' => ['mollie', static fn (string $sig): string => '  ' . strtoupper($sig) . ' '],
			'stripe timestamped' => ['stripe', static fn (string $header): string => $header],
			'stripe v1 among parts' => ['stripe', static fn (string $header): string => str_replace(',v1=', ', v0=abc , v1=ffff, v1=', $header)],
		];

	}//end acceptedSignatures()

	/**
	 * Accepted signature spellings reach reconciliation on both webhooks.
	 *
	 * @param string $gateway The gateway.
	 * @param callable $spell Builds the header from the hex signature.
	 *
	 * @return void
	 *
	 * @dataProvider acceptedSignatures
	 */
	public function testAcceptedSignatureSpellings(string $gateway, callable $spell): void {
		$payload = ['id' => 'tr_1', 'status' => 'paid'];
		if ($gateway === 'stripe') {
			$payload = ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1']]];
		}

		$call = self::signed($payload, $gateway);
		$call['header'] = $spell($call['header']);

		$deposit = $this->createMock(DepositReconciliationService::class);
		$deposit->expects(self::once())->method('reconcile')->willReturn(DepositReconciliationService::RESULT_APPLIED);
		self::assertSame(200, $this->deposit($call, $deposit)->handle($gateway)->getStatus());

		$payment = $this->createMock(PaymentReconciliationService::class);
		$payment->expects(self::once())->method('reconcile')->willReturn(['result' => 'applied']);
		self::assertSame(200, $this->paymentRequest($call, $payment)->handle($gateway)->getStatus());

	}//end testAcceptedSignatureSpellings()

	/**
	 * A rejected signature is logged per webhook without the body.
	 *
	 * @return void
	 */
	public function testRejectedSignatureIsLogged(): void {
		$call = ['body' => '{"id":"tr_1"}', 'header' => 'bad', 'secret' => self::SECRET];
		$this->logger->expects(self::exactly(2))->method('warning')
			->willReturnCallback(
				function (string $message, array $context): void {
					$this->headersAsked[] = $message . '|' . json_encode($context);
				}
			);

		$this->depositWithoutDispatch('mollie', $call);
		$this->paymentRequestWithoutDispatch('stripe', $call);

		self::assertSame(
			[
				'X-Mollie-Signature',
				'Shillinq: rejected deposit webhook with invalid signature|{"gateway":"mollie"}',
				'Stripe-Signature',
				'Shillinq: rejected payment webhook with invalid signature|{"gateway":"stripe"}',
			],
			$this->headersAsked
		);

	}//end testRejectedSignatureIsLogged()

	/**
	 * Gateway payloads and the deposit reconcile arguments they become.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<int,mixed>}>
	 */
	public static function depositEvents(): array {
		$failure = ['code' => 'card_declined', 'message' => 'Declined'];
		return [
			'mollie paid' => ['mollie', ['id' => 'tr_1', 'status' => 'paid'], ['tr_1', 'authorized', 'mollie', null, null]],
			'mollie failed' => ['mollie', ['id' => 'tr_1', 'status' => 'failed'], ['tr_1', 'failed', 'mollie', 'failed', 'Payment failed at gateway.']],
			'mollie expired' => ['mollie', ['id' => 'tr_1', 'status' => 'expired'], ['tr_1', 'failed', 'mollie', 'expired', null]],
			'mollie canceled' => ['mollie', ['id' => 'tr_1', 'status' => 'canceled'], ['tr_1', 'voided', 'mollie', null, null]],
			'stripe succeeded' => ['stripe', ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1']]], ['pi_1', 'authorized', 'stripe', null, null]],
			'stripe failed with error' => ['stripe', ['type' => 'payment_intent.payment_failed', 'data' => ['object' => ['id' => 'pi_1', 'last_payment_error' => $failure]]], ['pi_1', 'failed', 'stripe', 'card_declined', 'Declined']],
			'stripe failed with code only' => ['stripe', ['type' => 'payment_intent.payment_failed', 'data' => ['object' => ['id' => 'pi_1', 'last_payment_error' => ['code' => 42]]]], ['pi_1', 'failed', 'stripe', '42', null]],
			'stripe refunded' => ['stripe', ['type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1']]], ['ch_1', 'voided', 'stripe', null, null]],
			'stripe canceled' => ['stripe', ['type' => 'payment_intent.canceled', 'data' => ['object' => ['id' => 'pi_1']]], ['pi_1', 'voided', 'stripe', null, null]],
		];

	}//end depositEvents()

	/**
	 * The deposit webhook reconciles the normalised event.
	 *
	 * @param string $gateway The gateway.
	 * @param array<string,mixed> $payload The gateway payload.
	 * @param array<int,mixed> $arguments The expected reconcile arguments.
	 *
	 * @return void
	 *
	 * @dataProvider depositEvents
	 */
	public function testDepositEventNormalisation(string $gateway, array $payload, array $arguments): void {
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->expects(self::once())->method('reconcile')->with(...$arguments)
			->willReturn(DepositReconciliationService::RESULT_APPLIED);

		$response = $this->deposit(self::signed($payload, $gateway), $recon)->handle($gateway);

		self::assertSame([200, ['status' => 'applied']], [$response->getStatus(), $response->getData()]);

	}//end testDepositEventNormalisation()

	/**
	 * Gateway payloads and the payment-request event they become.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>}>
	 */
	public static function paymentRequestEvents(): array {
		$event = static fn (string $id, string $outcome, ?string $code, ?string $message): array => [
			'paymentIntentId' => $id,
			'outcome' => $outcome,
			'errorCode' => $code,
			'errorMessage' => $message,
		];
		$failure = ['code' => 'card_declined', 'message' => 'Declined'];
		return [
			'mollie paid' => ['mollie', ['id' => 'tr_1', 'status' => 'paid'], $event('tr_1', 'captured', null, null)],
			'mollie failed' => ['mollie', ['id' => 'tr_1', 'status' => 'failed'], $event('tr_1', 'failed', 'failed', 'Payment failed at gateway.')],
			'mollie expired' => ['mollie', ['id' => 'tr_1', 'status' => 'expired'], $event('tr_1', 'failed', 'expired', null)],
			'mollie canceled' => ['mollie', ['id' => 'tr_1', 'status' => 'canceled'], $event('tr_1', 'voided', null, null)],
			'stripe capturable' => ['stripe', ['type' => 'payment_intent.amount_capturable_updated', 'data' => ['object' => ['id' => 'pi_1']]], $event('pi_1', 'authorized', null, null)],
			'stripe succeeded' => ['stripe', ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1']]], $event('pi_1', 'captured', null, null)],
			'stripe captured' => ['stripe', ['type' => 'charge.captured', 'data' => ['object' => ['id' => 'ch_1']]], $event('ch_1', 'captured', null, null)],
			'stripe failed with error' => ['stripe', ['type' => 'payment_intent.payment_failed', 'data' => ['object' => ['id' => 'pi_1', 'last_payment_error' => $failure]]], $event('pi_1', 'failed', 'card_declined', 'Declined')],
			'stripe failed with message only' => ['stripe', ['type' => 'payment_intent.payment_failed', 'data' => ['object' => ['id' => 'pi_1', 'last_payment_error' => ['message' => 'No']]]], $event('pi_1', 'failed', null, 'No')],
			'stripe refunded' => ['stripe', ['type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1']]], $event('ch_1', 'voided', null, null)],
			'stripe canceled' => ['stripe', ['type' => 'payment_intent.canceled', 'data' => ['object' => ['id' => 'pi_1']]], $event('pi_1', 'voided', null, null)],
		];

	}//end paymentRequestEvents()

	/**
	 * The payment-request webhook reconciles the normalised event.
	 *
	 * @param string $gateway The gateway.
	 * @param array<string,mixed> $payload The gateway payload.
	 * @param array<string,mixed> $event The expected event.
	 *
	 * @return void
	 *
	 * @dataProvider paymentRequestEvents
	 */
	public function testPaymentRequestEventNormalisation(string $gateway, array $payload, array $event): void {
		$recon = $this->createMock(PaymentReconciliationService::class);
		$recon->expects(self::once())->method('reconcile')->with($gateway, self::identicalTo($event))
			->willReturn(['result' => 'applied']);

		$response = $this->paymentRequest(self::signed($payload, $gateway), $recon)->handle($gateway);

		self::assertSame([200, ['status' => 'applied']], [$response->getStatus(), $response->getData()]);

	}//end testPaymentRequestEventNormalisation()

	/**
	 * Reconcile results and the response they map to.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function results(): array {
		return [
			'applied' => ['applied', 200],
			'noop' => ['noop', 202],
			'not found' => ['not-found', 404],
			'unapplied' => ['captured-unapplied', 200],
		];

	}//end results()

	/**
	 * Both webhooks map the reconcile result the same way.
	 *
	 * @param string $result The reconcile result.
	 * @param int $code The expected HTTP code.
	 *
	 * @return void
	 *
	 * @dataProvider results
	 */
	public function testResultMapping(string $result, int $code): void {
		$call = self::signed(['id' => 'tr_1', 'status' => 'paid']);

		$deposit = $this->createMock(DepositReconciliationService::class);
		$deposit->method('reconcile')->willReturn($result);
		$response = $this->deposit($call, $deposit)->handle('mollie');
		self::assertSame([$code, ['status' => $result]], [$response->getStatus(), $response->getData()]);

		$payment = $this->createMock(PaymentReconciliationService::class);
		$payment->method('reconcile')->willReturn(['result' => $result]);
		$response = $this->paymentRequest($call, $payment)->handle('mollie');
		self::assertSame([$code, ['status' => $result]], [$response->getStatus(), $response->getData()]);

	}//end testResultMapping()

	/**
	 * A failing reconciliation is logged and answered with a bare 500.
	 *
	 * @return void
	 */
	public function testReconcileFailureIsLoggedAndA500(): void {
		$call = self::signed(['id' => 'tr_1', 'status' => 'paid']);
		$this->logger->expects(self::exactly(2))->method('error')
			->willReturnCallback(
				function (string $message, array $context): void {
					$this->headersAsked[] = $message . '|' . json_encode($context);
				}
			);

		$deposit = $this->createMock(DepositReconciliationService::class);
		$deposit->method('reconcile')->willThrowException(new \RuntimeException('db down'));
		$first = $this->deposit($call, $deposit)->handle('mollie');

		$payment = $this->createMock(PaymentReconciliationService::class);
		$payment->method('reconcile')->willThrowException(new \RuntimeException('db gone'));
		$second = $this->paymentRequest($call, $payment)->handle('mollie');

		self::assertSame([500, ['status' => 'error']], [$first->getStatus(), $first->getData()]);
		self::assertSame([500, ['status' => 'error']], [$second->getStatus(), $second->getData()]);
		self::assertSame(
			[
				'X-Mollie-Signature',
				'Shillinq: deposit webhook reconciliation failed|{"gateway":"mollie","exception":"db down"}',
				'X-Mollie-Signature',
				'Shillinq: payment webhook reconciliation failed|{"gateway":"mollie","exception":"db gone"}',
			],
			$this->headersAsked
		);

	}//end testReconcileFailureIsLoggedAndA500()
}//end class
