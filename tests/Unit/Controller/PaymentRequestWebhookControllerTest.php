<?php

/**
 * Unit tests for PaymentRequestWebhookController.
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
 *
 * @spec openspec/changes/shillinq-payment-webhook-controller-test-coverage/specs/ar-invoice-payment-links/spec.md (REQ-APL-009)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PaymentRequestWebhookController;
use OCA\Shillinq\Service\PaymentReconciliationService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests signature verification, payload normalisation and idempotent responses
 * for the shared PaymentRequest/DepositPayment webhook endpoint (REQ-APL-009).
 *
 * Mirrors DepositWebhookControllerTest's constructor-mocking pattern for its
 * structurally identical sibling endpoint.
 */
final class PaymentRequestWebhookControllerTest extends TestCase {

	/**
	 * The shared secret used to sign test payloads.
	 *
	 * @var string
	 */
	private const SECRET = 'whsec_test_shared_secret';

	/**
	 * Build a controller whose getRawBody() returns $rawBody, with a request that
	 * answers the given signature header. When $secretQueried is provided, every
	 * IAppConfig::getValueString() call is recorded into it so tests can assert
	 * the fail-fast paths (unknown gateway) never touch config at all.
	 *
	 * @param string $rawBody The raw request body.
	 * @param string $signatureValue The signature header value.
	 * @param PaymentReconciliationService $recon The reconciliation service (mock).
	 * @param string $configuredSecret The configured webhook secret ('' to disable).
	 * @param array<int,string>|null $secretQueried Out param: records each config-lookup call.
	 *
	 * @return PaymentRequestWebhookController
	 */
	private function makeController(
		string $rawBody,
		string $signatureValue,
		PaymentReconciliationService $recon,
		string $configuredSecret = self::SECRET,
		?array &$secretQueried = null,
	): PaymentRequestWebhookController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($signatureValue): string {
				if ($name === 'Stripe-Signature' || $name === 'X-Mollie-Signature') {
					return $signatureValue;
				}

				return '';
			}
		);

		$secretQueried = [];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $key) use ($configuredSecret, &$secretQueried): string {
				$secretQueried[] = $key;
				return $configuredSecret;
			}
		);

		return new class($request, $appConfig, $recon, $this->createMock(originalClassName: LoggerInterface::class), $rawBody) extends PaymentRequestWebhookController {
			/**
			 * Construct the test double with an injected raw body.
			 *
			 * @param IRequest $request Request.
			 * @param IAppConfig $appConfig Config.
			 * @param PaymentReconciliationService $recon Reconciliation.
			 * @param LoggerInterface $logger Logger.
			 * @param string $rawBody Injected body.
			 */
			public function __construct(
				IRequest $request,
				IAppConfig $appConfig,
				PaymentReconciliationService $recon,
				LoggerInterface $logger,
				private string $rawBody,
			) {
				parent::__construct(request: $request, appConfig: $appConfig, reconciliation: $recon, logger: $logger);
			}//end __construct()

			/**
			 * Return the injected raw body instead of reading php://input.
			 *
			 * @return string
			 */
			protected function getRawBody(): string {
				return $this->rawBody;
			}//end getRawBody()
		};

	}//end makeController()

	/**
	 * Compute the valid HMAC-SHA256 signature for a body.
	 *
	 * @param string $body The raw body.
	 *
	 * @return string Hex signature.
	 */
	private function sign(string $body): string {
		return hash_hmac(algo: 'sha256', data: $body, key: self::SECRET);
	}//end sign()

	/**
	 * A valid signature and a known event dispatches reconciliation and returns 200.
	 *
	 * @return void
	 */
	public function testValidSignatureKnownEventDispatchesReconciliation(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'paid']);
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->expects($this->once())
			->method('reconcile')
			->with(
				$this->equalTo(value: 'mollie'),
				$this->equalTo(
					value: [
						'paymentIntentId' => 'tr_1',
						'outcome' => PaymentReconciliationService::OUTCOME_CAPTURED,
						'errorCode' => null,
						'errorMessage' => null,
					]
				)
			)
			->willReturn(['result' => PaymentReconciliationService::RESULT_APPLIED, 'schema' => 'PaymentRequest']);

		$controller = $this->makeController(rawBody: $body, signatureValue: $this->sign(body: $body), recon: $recon);
		$response = $controller->handle(gateway: 'mollie');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());

	}//end testValidSignatureKnownEventDispatchesReconciliation()

	/**
	 * An idempotent no-op reconciliation returns 202 Accepted.
	 *
	 * @return void
	 */
	public function testIdempotentNoopReturns202(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'paid']);
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->method('reconcile')->willReturn(['result' => PaymentReconciliationService::RESULT_NOOP, 'schema' => null]);

		$controller = $this->makeController(rawBody: $body, signatureValue: $this->sign(body: $body), recon: $recon);
		$response = $controller->handle(gateway: 'mollie');

		$this->assertSame(expected: Http::STATUS_ACCEPTED, actual: $response->getStatus());

	}//end testIdempotentNoopReturns202()

	/**
	 * An invalid/tampered signature is rejected with 400 AND reconcile() is never
	 * called — proving the fail-closed gate actually gates (REQ-APL-009).
	 *
	 * @return void
	 */
	public function testInvalidSignatureReturns400AndNoDispatch(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'paid']);
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->expects($this->never())->method('reconcile');

		$controller = $this->makeController(rawBody: $body, signatureValue: 'deadbeef', recon: $recon);
		$response = $controller->handle(gateway: 'mollie');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'invalid-signature', actual: $response->getData()['status']);

	}//end testInvalidSignatureReturns400AndNoDispatch()

	/**
	 * When no secret is configured the endpoint fails closed (400) and never
	 * dispatches reconciliation, regardless of an otherwise-valid-looking signature.
	 *
	 * @return void
	 */
	public function testMissingSecretFailsClosed(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'paid']);
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->expects($this->never())->method('reconcile');

		$controller = $this->makeController(
			rawBody: $body,
			signatureValue: $this->sign(body: $body),
			recon: $recon,
			configuredSecret: '',
		);
		$response = $controller->handle(gateway: 'mollie');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'invalid-signature', actual: $response->getData()['status']);

	}//end testMissingSecretFailsClosed()

	/**
	 * An unknown `{gateway}` route param returns 404 BEFORE any signature/body
	 * processing — the config lookup for the shared secret must never happen.
	 *
	 * @return void
	 */
	public function testUnknownGatewayReturns404(): void {
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->expects($this->never())->method('reconcile');

		$secretQueried = null;
		$controller = $this->makeController(
			rawBody: '{}',
			signatureValue: 'x',
			recon: $recon,
			secretQueried: $secretQueried,
		);
		$response = $controller->handle(gateway: 'paypal');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: 'unknown-gateway', actual: $response->getData()['status']);
		$this->assertSame(expected: [], actual: $secretQueried);

	}//end testUnknownGatewayReturns404()

	/**
	 * An empty raw body returns 400 (before signature verification is attempted).
	 *
	 * @return void
	 */
	public function testEmptyBodyReturns400(): void {
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->expects($this->never())->method('reconcile');

		$controller = $this->makeController(rawBody: '', signatureValue: 'x', recon: $recon);
		$response = $controller->handle(gateway: 'mollie');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'empty-body', actual: $response->getData()['status']);

	}//end testEmptyBodyReturns400()

	/**
	 * A malformed (invalid-JSON) body with a VALID signature returns 400.
	 *
	 * @return void
	 */
	public function testMalformedPayloadReturns400(): void {
		$body = 'not-json';
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->expects($this->never())->method('reconcile');

		$controller = $this->makeController(rawBody: $body, signatureValue: $this->sign(body: $body), recon: $recon);
		$response = $controller->handle(gateway: 'mollie');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'malformed-payload', actual: $response->getData()['status']);

	}//end testMalformedPayloadReturns400()

	/**
	 * A valid signature and valid JSON, but an event extractEvent() cannot derive
	 * a paymentIntentId from, returns 400 (unparseable-event) without touching
	 * reconcile().
	 *
	 * @return void
	 */
	public function testUnparseableEventReturns400(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'open']);
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->expects($this->never())->method('reconcile');

		$controller = $this->makeController(rawBody: $body, signatureValue: $this->sign(body: $body), recon: $recon);
		$response = $controller->handle(gateway: 'mollie');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'unparseable-event', actual: $response->getData()['status']);

	}//end testUnparseableEventReturns400()

	/**
	 * A Stripe payment_intent.succeeded with a "t=..,v1=<sig>" header is accepted
	 * and mapped to the authorized outcome (mirrors the Mollie happy path for the
	 * other supported gateway).
	 *
	 * @return void
	 */
	public function testStripeSucceededWithV1HeaderDispatchesReconciliation(): void {
		$body = json_encode(
			[
				'type' => 'payment_intent.succeeded',
				'data' => ['object' => ['id' => 'pi_99']],
			]
		);
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->expects($this->once())
			->method('reconcile')
			->with(
				$this->equalTo(value: 'stripe'),
				$this->equalTo(
					value: [
						'paymentIntentId' => 'pi_99',
						'outcome' => PaymentReconciliationService::OUTCOME_CAPTURED,
						'errorCode' => null,
						'errorMessage' => null,
					]
				)
			)
			->willReturn(['result' => PaymentReconciliationService::RESULT_APPLIED, 'schema' => 'PaymentRequest']);

		$header = 't=1700000000,v1=' . $this->sign(body: $body);
		$controller = $this->makeController(rawBody: $body, signatureValue: $header, recon: $recon);
		$response = $controller->handle(gateway: 'stripe');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());

	}//end testStripeSucceededWithV1HeaderDispatchesReconciliation()

	/**
	 * A not-found reconciliation returns 404.
	 *
	 * @return void
	 */
	public function testReconcileNotFoundReturns404(): void {
		$body = json_encode(['id' => 'tr_x', 'status' => 'paid']);
		$recon = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$recon->method('reconcile')->willReturn(['result' => PaymentReconciliationService::RESULT_NOT_FOUND, 'schema' => null]);

		$controller = $this->makeController(rawBody: $body, signatureValue: $this->sign(body: $body), recon: $recon);
		$response = $controller->handle(gateway: 'mollie');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());

	}//end testReconcileNotFoundReturns404()
}//end class
