<?php

/**
 * Unit tests for PortalPaymentInitiationController (portal-payment-initiation).
 *
 * Pins the receiver endpoint's fail-closed ordering (verify -> audience gate
 * -> delegate) and the 200/401/403/502/503 response matrix. Uses a REAL
 * PortalAssertionVerifier (plain-secret construction) with assertions minted
 * the portaliq way, and a MOCKED PortalPaymentSessionService — no test
 * touches OpenRegister or a real PSP.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PortalPaymentInitiationController;
use OCA\Shillinq\Portal\PortalAssertionVerifier;
use OCA\Shillinq\Service\Payment\PortalPaymentSessionResult;
use OCA\Shillinq\Service\Payment\PortalPaymentSessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PortalPaymentInitiationController.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
 */
final class PortalPaymentInitiationControllerTest extends TestCase {
	/**
	 * Signing secret shared by mint + verifier (>= 16 chars).
	 */
	private const SECRET = 'shillinq-test-secret-01234567890';

	/**
	 * The asserted subjectRef.
	 */
	private const SUBJECT = '00000000-0000-0000-0000-000000000000';

	/**
	 * The target invoice id.
	 */
	private const INVOICE_ID = '11111111-1111-1111-1111-111111111111';

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mocked session service.
	 *
	 * @var PortalPaymentSessionService&MockObject
	 */
	private PortalPaymentSessionService&MockObject $sessionService;

	/**
	 * The controller under test.
	 *
	 * @var PortalPaymentInitiationController
	 */
	private PortalPaymentInitiationController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->sessionService = $this->createMock(PortalPaymentSessionService::class);

		$this->controller = new PortalPaymentInitiationController(
			request: $this->request,
			verifier: new PortalAssertionVerifier(config: null, secretOverride: self::SECRET),
			sessionService: $this->sessionService,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Mint an assertion exactly the way portaliq does.
	 *
	 * @param string $secret The HMAC signing secret.
	 * @param array $overrides Claim overrides (null removes a claim).
	 *
	 * @return string Compact JWT.
	 */
	private function mintAssertion(string $secret, array $overrides = []): string {
		$iat = time();
		$claims = [
			'sub' => self::SUBJECT,
			'audience' => 'customer',
			'organisation' => '22222222-2222-2222-2222-222222222222',
			'trust' => 'low',
			'jti' => 'sessionjti0000000000000000000000',
			'use' => 'assertion',
			'iat' => $iat,
			'exp' => ($iat + 60),
			'iss' => 'portaliq',
		];

		foreach ($overrides as $claim => $value) {
			if ($value === null) {
				unset($claims[$claim]);
				continue;
			}

			$claims[$claim] = $value;
		}

		$b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
		$hPart = $b64((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
		$cPart = $b64((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

		return $hPart . '.' . $cPart . '.' . $b64(hash_hmac('sha256', $hPart . '.' . $cPart, $secret, true));
	}//end mintAssertion()

	/**
	 * Wire the request mock with an assertion header + invoiceId param.
	 *
	 * @param string $header The X-Portal-Subject header value.
	 * @param mixed $invoiceId The invoiceId body param (or null for absent).
	 *
	 * @return void
	 */
	private function wireRequest(string $header, mixed $invoiceId): void {
		$this->request->method('getHeader')
			->with(PortalAssertionVerifier::HEADER)
			->willReturn($header);
		$this->request->method('getParam')
			->willReturnCallback(
				static function (string $key, mixed $default = null) use ($invoiceId): mixed {
					if ($key === 'invoiceId') {
						return $invoiceId;
					}

					return $default;
				}
			);
	}//end wireRequest()

	/**
	 * Happy path: 200 with the checkout URL.
	 *
	 * @return void
	 */
	public function testHappyPathReturns200WithCheckoutUrl(): void {
		$this->wireRequest(header: $this->mintAssertion(secret: self::SECRET), invoiceId: self::INVOICE_ID);
		$this->sessionService->method('initiate')->willReturn(
			PortalPaymentSessionResult::success(checkoutUrl: 'https://mollie.example/checkout/tr_1')
		);

		$response = $this->controller->initiate();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['checkoutUrl' => 'https://mollie.example/checkout/tr_1'], $response->getData());
	}//end testHappyPathReturns200WithCheckoutUrl()

	/**
	 * Missing/invalid assertion: 401, session service never called.
	 *
	 * @return void
	 */
	public function testMissingOrInvalidAssertionIs401(): void {
		$this->sessionService->expects($this->never())->method('initiate');

		$this->wireRequest(header: '', invoiceId: self::INVOICE_ID);
		$response = $this->controller->initiate();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'unauthorized'], $response->getData());
	}//end testMissingOrInvalidAssertionIs401()

	/**
	 * An expired assertion is the same 401, no session-service call.
	 *
	 * @return void
	 */
	public function testExpiredAssertionIs401(): void {
		$this->sessionService->expects($this->never())->method('initiate');

		$now = time();
		$this->wireRequest(
			header: $this->mintAssertion(secret: self::SECRET, overrides: ['iat' => ($now - 120), 'exp' => ($now - 60)]),
			invoiceId: self::INVOICE_ID
		);

		$response = $this->controller->initiate();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testExpiredAssertionIs401()

	/**
	 * A wrong-audience assertion is refused with 403 before the session
	 * service is ever called (REQ-SPPI-002).
	 *
	 * @return void
	 */
	public function testWrongAudienceIs403(): void {
		$this->sessionService->expects($this->never())->method('initiate');

		$this->wireRequest(
			header: $this->mintAssertion(secret: self::SECRET, overrides: ['audience' => 'supplier']),
			invoiceId: self::INVOICE_ID
		);

		$response = $this->controller->initiate();
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'forbidden'], $response->getData());
	}//end testWrongAudienceIs403()

	/**
	 * The session service's uniform forbidden outcome maps to 403.
	 *
	 * @return void
	 */
	public function testSessionServiceForbiddenMapsTo403(): void {
		$this->wireRequest(header: $this->mintAssertion(secret: self::SECRET), invoiceId: 'someone-elses-invoice');
		$this->sessionService->method('initiate')->willReturn(PortalPaymentSessionResult::forbidden());

		$response = $this->controller->initiate();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'forbidden'], $response->getData());
	}//end testSessionServiceForbiddenMapsTo403()

	/**
	 * A dormant provider maps to 503 with a machine-readable `deferred` status
	 * — never a fabricated checkout URL.
	 *
	 * @return void
	 */
	public function testDeferredMapsTo503(): void {
		$this->wireRequest(header: $this->mintAssertion(secret: self::SECRET), invoiceId: self::INVOICE_ID);
		$this->sessionService->method('initiate')->willReturn(PortalPaymentSessionResult::deferred());

		$response = $this->controller->initiate();

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame(['status' => 'deferred'], $response->getData());
	}//end testDeferredMapsTo503()

	/**
	 * A downstream/OpenRegister/PSP failure maps to 502, never leaking
	 * internals.
	 *
	 * @return void
	 */
	public function testDownstreamErrorMapsTo502(): void {
		$this->wireRequest(header: $this->mintAssertion(secret: self::SECRET), invoiceId: self::INVOICE_ID);
		$this->sessionService->method('initiate')->willReturn(PortalPaymentSessionResult::downstreamError());

		$response = $this->controller->initiate();

		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		self::assertSame(['error' => 'downstream_error'], $response->getData());
	}//end testDownstreamErrorMapsTo502()

	/**
	 * An unexpected exception from the session service is caught and mapped
	 * to 502 — never a raw stack trace to a #[PublicPage] caller.
	 *
	 * @return void
	 */
	public function testUnexpectedExceptionMapsTo502(): void {
		$this->wireRequest(header: $this->mintAssertion(secret: self::SECRET), invoiceId: self::INVOICE_ID);
		$this->sessionService->method('initiate')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller->initiate();

		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		self::assertSame(['error' => 'downstream_error'], $response->getData());
	}//end testUnexpectedExceptionMapsTo502()

	/**
	 * A missing/non-string invoiceId is passed through as an empty string —
	 * the session service (not the controller) applies the uniform
	 * fail-closed treatment.
	 *
	 * @return void
	 */
	public function testMissingInvoiceIdIsPassedAsEmptyString(): void {
		$this->wireRequest(header: $this->mintAssertion(secret: self::SECRET), invoiceId: null);
		$this->sessionService->expects($this->once())
			->method('initiate')
			->with($this->anything(), '')
			->willReturn(PortalPaymentSessionResult::forbidden());

		$response = $this->controller->initiate();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testMissingInvoiceIdIsPassedAsEmptyString()
}//end class
