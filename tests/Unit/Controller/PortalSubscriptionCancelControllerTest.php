<?php

/**
 * Unit tests for PortalSubscriptionCancelController.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PortalSubscriptionCancelController;
use OCA\Shillinq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Shillinq\Kernel\Contracts\SubscriptionCancellationServiceInterface;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The forward is answered only for a verified customer, and the service decides ownership.
 *
 * @covers \OCA\Shillinq\Controller\PortalSubscriptionCancelController
 */
final class PortalSubscriptionCancelControllerTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var SubscriptionCancellationServiceInterface&MockObject
	 */
	private SubscriptionCancellationServiceInterface $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(SubscriptionCancellationServiceInterface::class);
	}//end setUp()

	/**
	 * The controller with the given verified claims and parameters.
	 *
	 * @param array<string, mixed>|null $claims The verified claims, or null for a bad assertion.
	 * @param array<string, mixed>      $params The request parameters.
	 *
	 * @return PortalSubscriptionCancelController
	 */
	private function controller(?array $claims, array $params = []): PortalSubscriptionCancelController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('assertion');
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));
		$verifier = $this->createMock(PortalAssertionVerifierInterface::class);
		$verifier->method('verify')->willReturn($claims);

		return new PortalSubscriptionCancelController(request: $request, verifier: $verifier, cancellation: $this->service, logger: new NullLogger());
	}//end controller()

	/**
	 * Without a valid assertion: 401, and the service is never asked.
	 *
	 * @return void
	 */
	public function testABadAssertionIsUnauthorized(): void {
		$this->service->expects($this->never())->method('cancel');

		$this->assertSame(401, $this->controller(claims: null)->cancel()->getStatus());
	}//end testABadAssertionIsUnauthorized()

	/**
	 * Another audience: 403, and the service is never asked.
	 *
	 * @return void
	 */
	public function testAnotherAudienceIsForbidden(): void {
		$this->service->expects($this->never())->method('cancel');

		$this->assertSame(403, $this->controller(claims: ['audience' => 'supplier'])->cancel()->getStatus());
	}//end testAnotherAudienceIsForbidden()

	/**
	 * A customer's request is passed on with its row id and reason; the answer maps to the status.
	 *
	 * @return void
	 */
	public function testACustomersRequestIsPassedOnAndAnswered(): void {
		$claims = ['audience' => 'customer', 'sub' => 's-1'];
		$this->service->expects($this->once())->method('cancel')
			->with($claims, 'p-1', 'too-expensive', '')
			->willReturn(SubscriptionCancellationServiceInterface::CANCELLED);

		$response = $this->controller(claims: $claims, params: ['profileId' => 'p-1', 'reasonCode' => 'too-expensive', 'reasonText' => ['not', 'a', 'string']])->cancel();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['status' => 'cancelled'], $response->getData());
	}//end testACustomersRequestIsPassedOnAndAnswered()

	/**
	 * Refused and downstream outcomes map to 403 and 502.
	 *
	 * @return void
	 */
	public function testRefusalsAndOutagesMapToTheirStatus(): void {
		$this->service->method('cancel')->willReturnOnConsecutiveCalls(
			SubscriptionCancellationServiceInterface::FORBIDDEN,
			SubscriptionCancellationServiceInterface::DOWNSTREAM_ERROR
		);
		$claims = ['audience' => 'customer'];

		$this->assertSame(403, $this->controller(claims: $claims)->cancel()->getStatus());
		$this->assertSame(502, $this->controller(claims: $claims)->cancel()->getStatus());
	}//end testRefusalsAndOutagesMapToTheirStatus()
}//end class
