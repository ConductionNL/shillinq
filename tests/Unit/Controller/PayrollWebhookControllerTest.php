<?php

/**
 * Unit tests for PayrollWebhookController.
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
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\PayrollWebhookController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers the signature-gated webhook receiver (REQ-PAY-009): GET returns 501,
 * and every unsigned / mis-signed POST is rejected with 401 before any object
 * is touched (ADR-005 fail-closed). The happy path requires a request body on
 * php://input and is exercised by the integration (Newman) collection.
 */
class PayrollWebhookControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the controller with the configured webhook secret.
	 *
	 * @param string $secret The configured shared secret ('' = unconfigured).
	 *
	 * @return PayrollWebhookController
	 */
	private function controller(string $secret): PayrollWebhookController {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->request = $this->createMock(IRequest::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($secret) {
				if ($key === 'payroll_webhook_secret') {
					return $secret;
				}

				if ($key === 'register') {
					return 'shillinq';
				}

				return $default;
			}
		);

		return new PayrollWebhookController(
			request: $this->request,
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end controller()

	/**
	 * GET on the webhook endpoint returns 501 Not Implemented (REQ-PAY-009).
	 *
	 * @return void
	 */
	public function testGetReturns501(): void {
		$controller = $this->controller('');
		$response = $controller->info();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
	}//end testGetReturns501()

	/**
	 * An unconfigured secret denies the webhook (fail-closed, ADR-005).
	 *
	 * @return void
	 */
	public function testRejectsWhenSecretUnconfigured(): void {
		$controller = $this->controller('');
		$this->request->method('getHeader')->willReturn('any-signature');

		$response = $controller->receive();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testRejectsWhenSecretUnconfigured()

	/**
	 * A configured secret but missing signature header is rejected (ADR-005).
	 *
	 * @return void
	 */
	public function testRejectsWhenSignatureHeaderMissing(): void {
		$controller = $this->controller('shared-secret');
		$this->request->method('getHeader')->willReturn('');

		$response = $controller->receive();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testRejectsWhenSignatureHeaderMissing()

	/**
	 * A signature that does not match the HMAC of the body is rejected, and the
	 * ObjectService is never resolved from the container (no side effects).
	 *
	 * @return void
	 */
	public function testRejectsMismatchingSignatureWithoutTouchingObjects(): void {
		$controller = $this->controller('shared-secret');
		$this->request->method('getHeader')->willReturn('deadbeef-not-a-valid-hmac');
		// The container must never be asked for the ObjectService on a bad signature.
		$this->container->expects(self::never())->method('get');

		$response = $controller->receive();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testRejectsMismatchingSignatureWithoutTouchingObjects()
}//end class
