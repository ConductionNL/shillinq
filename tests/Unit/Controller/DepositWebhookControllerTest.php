<?php

/**
 * Unit tests for DepositWebhookController.
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
 * @spec openspec/changes/bookings-deposits/specs/bookings-deposits/spec.md (REQ-DP-006, REQ-DP-001)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DepositWebhookController;
use OCA\Shillinq\Service\DepositReconciliationService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests signature verification, payload normalisation and idempotent responses.
 */
final class DepositWebhookControllerTest extends TestCase {
	/**
	 * The shared secret used to sign test payloads.
	 *
	 * @var string
	 */
	private const SECRET = 'whsec_test_shared_secret';

	/**
	 * Build a controller whose getRawBody() returns $rawBody, with a request that
	 * answers the given signature header.
	 *
	 * @param string $rawBody The raw request body.
	 * @param string $signatureValue The signature header value.
	 * @param DepositReconciliationService $recon The reconciliation service (mock).
	 * @param string $configuredSecret The configured webhook secret ('' to disable).
	 *
	 * @return DepositWebhookController
	 */
	private function makeController(
		string $rawBody,
		string $signatureValue,
		DepositReconciliationService $recon,
		string $configuredSecret = self::SECRET,
	): DepositWebhookController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($signatureValue): string {
				if ($name === 'Stripe-Signature' || $name === 'X-Mollie-Signature') {
					return $signatureValue;
				}

				return '';
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($configuredSecret);

		return new class($request, $appConfig, $recon, $this->createMock(LoggerInterface::class), $rawBody) extends DepositWebhookController {
			/**
			 * @param IRequest $request Request.
			 * @param IAppConfig $appConfig Config.
			 * @param DepositReconciliationService $recon Reconciliation.
			 * @param LoggerInterface $logger Logger.
			 * @param string $rawBody Injected body.
			 */
			public function __construct(
				IRequest $request,
				IAppConfig $appConfig,
				DepositReconciliationService $recon,
				LoggerInterface $logger,
				private string $rawBody,
			) {
				parent::__construct($request, $appConfig, $recon, $logger);
			}

			protected function getRawBody(): string {
				return $this->rawBody;
			}
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
		return hash_hmac('sha256', $body, self::SECRET);
	}//end sign()

	/**
	 * A Mollie paid event with a valid signature reconciles and returns 200.
	 *
	 * @return void
	 */
	public function testValidMolliePaidReturns200(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'paid']);
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->expects(self::once())
			->method('reconcile')
			->with(
				self::equalTo('tr_1'),
				self::equalTo(DepositReconciliationService::OUTCOME_AUTHORIZED),
				self::equalTo('mollie')
			)
			->willReturn(DepositReconciliationService::RESULT_APPLIED);

		$controller = $this->makeController($body, $this->sign($body), $recon);
		$response = $controller->handle('mollie');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testValidMolliePaidReturns200()

	/**
	 * An idempotent no-op reconciliation returns 202 Accepted.
	 *
	 * @return void
	 */
	public function testIdempotentNoopReturns202(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'paid']);
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->method('reconcile')->willReturn(DepositReconciliationService::RESULT_NOOP);

		$controller = $this->makeController($body, $this->sign($body), $recon);
		$response = $controller->handle('mollie');

		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
	}//end testIdempotentNoopReturns202()

	/**
	 * An invalid signature is rejected with 401 and reconcile() is never called
	 * (ADR-005: a public webhook MUST verify the provider signature).
	 *
	 * @return void
	 */
	public function testInvalidSignatureReturns400(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'paid']);
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->expects(self::never())->method('reconcile');

		$controller = $this->makeController($body, 'deadbeef', $recon);
		$response = $controller->handle('mollie');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testInvalidSignatureReturns400()

	/**
	 * When no secret is configured the endpoint fails closed (400), never open.
	 *
	 * @return void
	 */
	public function testMissingSecretFailsClosed(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'paid']);
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->expects(self::never())->method('reconcile');

		$controller = $this->makeController($body, $this->sign($body), $recon, configuredSecret: '');
		$response = $controller->handle('mollie');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testMissingSecretFailsClosed()

	/**
	 * A Stripe payment_intent.succeeded with a "t=..,v1=<sig>" header is accepted
	 * and mapped to the authorized outcome.
	 *
	 * @return void
	 */
	public function testStripeSucceededWithV1Header(): void {
		$body = json_encode(
			[
				'type' => 'payment_intent.succeeded',
				'data' => ['object' => ['id' => 'pi_99']],
			]
		);
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->expects(self::once())
			->method('reconcile')
			->with(
				self::equalTo('pi_99'),
				self::equalTo(DepositReconciliationService::OUTCOME_AUTHORIZED),
				self::equalTo('stripe')
			)
			->willReturn(DepositReconciliationService::RESULT_APPLIED);

		$header = 't=1700000000,v1=' . $this->sign($body);
		$controller = $this->makeController($body, $header, $recon);
		$response = $controller->handle('stripe');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testStripeSucceededWithV1Header()

	/**
	 * An unknown gateway slug returns 404.
	 *
	 * @return void
	 */
	public function testUnknownGatewayReturns404(): void {
		$recon = $this->createMock(DepositReconciliationService::class);
		$controller = $this->makeController('{}', 'x', $recon);
		$response = $controller->handle('paypal');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUnknownGatewayReturns404()

	/**
	 * Malformed JSON (valid signature) returns 400.
	 *
	 * @return void
	 */
	public function testMalformedPayloadReturns400(): void {
		$body = 'not-json';
		$recon = $this->createMock(DepositReconciliationService::class);
		$controller = $this->makeController($body, $this->sign($body), $recon);
		$response = $controller->handle('mollie');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testMalformedPayloadReturns400()

	/**
	 * A valid signature over an event type we do not handle returns 400
	 * (unparseable event) without touching reconcile().
	 *
	 * @return void
	 */
	public function testUnhandledEventReturns400(): void {
		$body = json_encode(['id' => 'tr_1', 'status' => 'open']);
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->expects(self::never())->method('reconcile');

		$controller = $this->makeController($body, $this->sign($body), $recon);
		$response = $controller->handle('mollie');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testUnhandledEventReturns400()

	/**
	 * A not-found reconciliation returns 404.
	 *
	 * @return void
	 */
	public function testReconcileNotFoundReturns404(): void {
		$body = json_encode(['id' => 'tr_x', 'status' => 'paid']);
		$recon = $this->createMock(DepositReconciliationService::class);
		$recon->method('reconcile')->willReturn(DepositReconciliationService::RESULT_NOT_FOUND);

		$controller = $this->makeController($body, $this->sign($body), $recon);
		$response = $controller->handle('mollie');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testReconcileNotFoundReturns404()
}//end class
