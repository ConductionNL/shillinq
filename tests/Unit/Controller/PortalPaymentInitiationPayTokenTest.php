<?php

/**
 * Unit tests for the signed pay link path of PortalPaymentInitiationController
 * (receivables-payment-links task 3.1, REQ-RPL-006).
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
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.1
 *
 * @covers \OCA\Shillinq\Controller\PortalPaymentInitiationController
 * @uses   \OCA\Shillinq\Kernel\Model\PortalPaymentSessionResult
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PortalPaymentInitiationController;
use OCA\Shillinq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Shillinq\Kernel\Contracts\PortalPaymentSessionServiceInterface;
use OCA\Shillinq\Kernel\Model\PortalPaymentSessionResult;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the pay token path of the initiation receiver.
 */
final class PortalPaymentInitiationPayTokenTest extends TestCase {

	/**
	 * The mocked assertion verifier.
	 *
	 * @var PortalAssertionVerifierInterface&MockObject
	 */
	private PortalAssertionVerifierInterface&MockObject $verifier;

	/**
	 * The mocked session service.
	 *
	 * @var PortalPaymentSessionServiceInterface&MockObject
	 */
	private PortalPaymentSessionServiceInterface&MockObject $sessions;

	protected function setUp(): void {
		parent::setUp();
		$this->verifier = $this->createMock(PortalAssertionVerifierInterface::class);
		$this->sessions = $this->createMock(PortalPaymentSessionServiceInterface::class);
	}//end setUp()

	/**
	 * Call initiate() with a header and body.
	 *
	 * @param string               $assertion The X-Portal-Subject header.
	 * @param array<string, mixed> $params    The body.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function initiate(string $assertion, array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($assertion);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$controller = new PortalPaymentInitiationController(
			request: $request,
			verifier: $this->verifier,
			sessionService: $this->sessions,
			logger: $this->createMock(LoggerInterface::class),
		);
		$response = $controller->initiate();

		return [$response->getStatus(), $response->getData()];
	}//end initiate()

	/**
	 * A guest forward with a token opens the checkout the session service answers.
	 *
	 * @return void
	 */
	public function testAGuestTokenOpensTheCheckout(): void {
		$this->verifier->expects($this->never())->method('verify');
		$this->sessions->expects($this->once())->method('initiateForPayToken')->with('tok.sig')
			->willReturn(PortalPaymentSessionResult::success(checkoutUrl: 'https://www.mollie.com/checkout/tr_1'));
		$this->sessions->expects($this->never())->method('initiate');

		self::assertSame([200, ['checkoutUrl' => 'https://www.mollie.com/checkout/tr_1']], $this->initiate(assertion: '', params: ['payToken' => 'tok.sig']));
	}//end testAGuestTokenOpensTheCheckout()

	/**
	 * A paid invoice answers paid without a checkout.
	 *
	 * @return void
	 */
	public function testAPaidInvoiceAnswersPaid(): void {
		$this->sessions->method('initiateForPayToken')->willReturn(PortalPaymentSessionResult::paid());

		self::assertSame([200, ['status' => 'paid', 'message' => 'This invoice has been paid.']], $this->initiate(assertion: '', params: ['payToken' => 'tok.sig']));
	}//end testAPaidInvoiceAnswersPaid()

	/**
	 * A refused token is the uniform 403.
	 *
	 * @return void
	 */
	public function testARefusedTokenIsForbidden(): void {
		$this->sessions->method('initiateForPayToken')->willReturn(PortalPaymentSessionResult::forbidden());

		self::assertSame([403, ['error' => 'forbidden']], $this->initiate(assertion: '', params: ['payToken' => 'bad']));
	}//end testARefusedTokenIsForbidden()

	/**
	 * A failure behind the token path is the logged 502 without internals.
	 *
	 * @return void
	 */
	public function testAFailureIsADownstreamError(): void {
		$this->sessions->method('initiateForPayToken')->willThrowException(new RuntimeException('secret detail'));

		self::assertSame([502, ['error' => 'downstream_error']], $this->initiate(assertion: '', params: ['payToken' => 'tok.sig']));
	}//end testAFailureIsADownstreamError()

	/**
	 * With a subject assertion the token is ignored and the assertion decides: a bad one is 401.
	 *
	 * @return void
	 */
	public function testWithAnAssertionTheTokenIsIgnored(): void {
		$this->verifier->expects($this->once())->method('verify')->with('jwt')->willReturn(null);
		$this->sessions->expects($this->never())->method('initiateForPayToken');

		self::assertSame([401, ['error' => 'unauthorized']], $this->initiate(assertion: 'jwt', params: ['payToken' => 'tok.sig']));
	}//end testWithAnAssertionTheTokenIsIgnored()

	/**
	 * Without an assertion and without a token, or with a non-string token, the answer stays 401.
	 *
	 * @return void
	 */
	public function testNoCredentialIsUnauthorized(): void {
		$this->verifier->method('verify')->willReturn(null);
		$this->sessions->expects($this->never())->method('initiateForPayToken');

		self::assertSame([401, ['error' => 'unauthorized']], $this->initiate(assertion: '', params: []));
		self::assertSame([401, ['error' => 'unauthorized']], $this->initiate(assertion: '', params: ['payToken' => ['x']]));
		self::assertSame([401, ['error' => 'unauthorized']], $this->initiate(assertion: '', params: ['payToken' => '']));
	}//end testNoCredentialIsUnauthorized()
}//end class
