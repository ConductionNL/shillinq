<?php

/**
 * Unit tests for PortalWithdrawalController.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PortalWithdrawalController;
use OCA\Shillinq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Shillinq\Service\PortalWithdrawalService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The withdrawal forward: a customer's assertion or a guest's token, each
 * passed on to its own path, and every outcome mapped to its status.
 */
#[CoversClass(PortalWithdrawalController::class)]
final class PortalWithdrawalControllerTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var PortalWithdrawalService&MockObject
	 */
	private PortalWithdrawalService&MockObject $service;

	/**
	 * Fresh service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(PortalWithdrawalService::class);
	}//end setUp()

	/**
	 * A controller for a request with this assertion header and parameters.
	 *
	 * @param string                    $header The X-Portal-Subject header.
	 * @param array<string, mixed>|null $claims What the verifier makes of it.
	 * @param array<string, mixed>      $params The request parameters.
	 *
	 * @return PortalWithdrawalController
	 */
	private function controller(string $header, ?array $claims, array $params = []): PortalWithdrawalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($header);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));
		$verifier = $this->createMock(PortalAssertionVerifierInterface::class);
		$verifier->method('verify')->willReturn($claims);

		return new PortalWithdrawalController(request: $request, verifier: $verifier, withdrawals: $this->service);
	}//end controller()

	/**
	 * A bad assertion, or neither an assertion nor a token, is unauthorized;
	 * another audience is forbidden; nothing is withdrawn.
	 *
	 * @return void
	 */
	public function testWithoutAValidCredentialNothingHappens(): void {
		$this->service->expects(self::never())->method('withdrawForSubject');
		$this->service->expects(self::never())->method('withdrawWithLink');

		self::assertSame(401, $this->controller(header: 'assertion', claims: null)->withdraw()->getStatus());
		self::assertSame(401, $this->controller(header: '', claims: null, params: ['token' => ['x']])->withdraw()->getStatus());
		self::assertSame(403, $this->controller(header: 'assertion', claims: ['audience' => 'supplier'])->withdraw()->getStatus());
	}//end testWithoutAValidCredentialNothingHappens()

	/**
	 * A customer's request goes to the subject path with the appointment id.
	 *
	 * @return void
	 */
	public function testACustomersRequestIsPassedOnAndAnswered(): void {
		$claims = ['audience' => 'customer', 'sub' => 's-1'];
		$this->service->expects(self::never())->method('withdrawWithLink');
		$this->service->expects(self::once())->method('withdrawForSubject')
			->with($claims, 'appt-1', self::isInstanceOf(\DateTimeImmutable::class))
			->willReturn(['status' => PortalWithdrawalService::WITHDRAWN, 'code' => null, 'reason' => null]);

		$response = $this->controller(header: 'assertion', claims: $claims, params: ['appointmentId' => 'appt-1', 'token' => 'ignored'])->withdraw();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['status' => 'withdrawn'], $response->getData());
	}//end testACustomersRequestIsPassedOnAndAnswered()

	/**
	 * A guest's token goes to the link path; a refusal carries its code and
	 * reason, a forbidden link and an outage their status.
	 *
	 * @return void
	 */
	public function testAGuestsLinkIsPassedOnAndEveryOutcomeMapped(): void {
		$this->service->expects(self::exactly(3))->method('withdrawWithLink')
			->with('tok-1', self::isInstanceOf(\DateTimeImmutable::class))
			->willReturnOnConsecutiveCalls(
				['status' => PortalWithdrawalService::REFUSED, 'code' => 'period-ended', 'reason' => 'The withdrawal period ended on 4 October 2026.'],
				['status' => PortalWithdrawalService::FORBIDDEN, 'code' => null, 'reason' => null],
				['status' => PortalWithdrawalService::DOWNSTREAM_ERROR, 'code' => null, 'reason' => null]
			);

		$refused = $this->controller(header: '', claims: null, params: ['token' => 'tok-1'])->withdraw();
		self::assertSame(409, $refused->getStatus());
		self::assertSame(['error' => 'not_withdrawable', 'code' => 'period-ended', 'reason' => 'The withdrawal period ended on 4 October 2026.'], $refused->getData());
		self::assertSame(403, $this->controller(header: '', claims: null, params: ['token' => 'tok-1'])->withdraw()->getStatus());
		self::assertSame(502, $this->controller(header: '', claims: null, params: ['token' => 'tok-1'])->withdraw()->getStatus());
	}//end testAGuestsLinkIsPassedOnAndEveryOutcomeMapped()
}//end class
