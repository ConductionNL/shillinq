<?php

/**
 * Unit tests for PortalWithdrawalController.
 *
 * REQ-SCX-004: POST /api/portal/withdrawals accepts a valid portal subject or
 * a valid signed link; an expired or tampered link and a missing credential
 * withdraw nothing.
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
 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Shillinq\Controller\PortalWithdrawalController;
use OCA\Shillinq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Shillinq\Service\Booking\PortalWithdrawalService;
use OCA\Shillinq\Service\Booking\WithdrawalLinkService;
use OCA\Shillinq\Service\WithdrawalService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The controller over a real link service and portal service, with the withdrawal itself doubled.
 */
final class PortalWithdrawalControllerTest extends TestCase {

	/**
	 * The withdrawal double.
	 *
	 * @var WithdrawalService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WithdrawalService $withdrawals;

	/**
	 * The portal service double, for the subject path.
	 *
	 * @var PortalWithdrawalService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ?PortalWithdrawalService $portal = null;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->withdrawals = $this->createMock(WithdrawalService::class);
	}//end setUp()

	/**
	 * A link service over a fixed secret.
	 *
	 * @return WithdrawalLinkService
	 */
	private function links(): WithdrawalLinkService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');

		return new WithdrawalLinkService($config, $this->createMock(IAppConfig::class), new WithdrawalService($this->createMock(\OCP\IL10N::class)));
	}//end links()

	/**
	 * The controller for a request with an assertion header and parameters.
	 *
	 * @param string                    $assertion The assertion header, '' for none.
	 * @param array<string, mixed>|null $claims    What the verifier answers.
	 * @param array<string, mixed>      $params    The request parameters.
	 *
	 * @return PortalWithdrawalController
	 */
	private function controller(string $assertion, ?array $claims, array $params): PortalWithdrawalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($assertion);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));
		$verifier = $this->createMock(PortalAssertionVerifierInterface::class);
		$verifier->method('verify')->willReturn($claims);
		$portal = $this->portal ?? new PortalWithdrawalService(
			$this->withdrawals,
			$this->links(),
			$this->createMock(\OCA\OpenRegister\Contract\ObjectServiceInterface::class),
			new NullLogger()
		);

		return new PortalWithdrawalController(request: $request, verifier: $verifier, withdrawals: $portal);
	}//end controller()

	/**
	 * A valid signed link withdraws its appointment.
	 *
	 * @return void
	 */
	public function testAValidLinkWithdraws(): void {
		$this->withdrawals->expects($this->once())->method('withdraw')
			->with('apt-1', 'guest-link', $this->isInstanceOf(DateTimeImmutable::class))
			->willReturn(
				['withdrawn' => true, 'code' => 'withdrawable', 'reason' => null, 'withdrawal' => ['acknowledgedAt' => 'now'], 'appointment' => ['id' => 'apt-1']]
			);
		$token = (string)$this->links()->tokenFor('apt-1', time() + 3600);

		$response = $this->controller('', null, ['token' => $token])->withdraw();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'withdrawn', 'acknowledgedAt' => 'now'], $response->getData());
	}//end testAValidLinkWithdraws()

	/**
	 * An expired or a tampered link is forbidden and withdraws nothing.
	 *
	 * @return void
	 */
	public function testAnExpiredOrTamperedLinkWithdrawsNothing(): void {
		$this->withdrawals->expects($this->never())->method('withdraw');
		$expired = (string)$this->links()->tokenFor('apt-1', time() - 1);
		$valid = (string)$this->links()->tokenFor('apt-1', time() + 3600);
		$swapped = rtrim(strtr(base64_encode((string)json_encode(['i' => 'apt-2', 'e' => time() + 3600])), '+/', '-_'), '=');
		$tampered = $swapped . '.' . explode('.', $valid)[1];

		foreach ([$expired, $tampered] as $token) {
			$response = $this->controller('', null, ['token' => $token])->withdraw();
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame(['error' => 'forbidden'], $response->getData());
		}
	}//end testAnExpiredOrTamperedLinkWithdrawsNothing()

	/**
	 * A valid subject is handed to the portal service with the appointment id.
	 *
	 * @return void
	 */
	public function testAValidSubjectWithdraws(): void {
		$claims = ['audience' => 'customer', 'sub' => 'sub-visser'];
		$this->portal = $this->createMock(PortalWithdrawalService::class);
		$this->portal->expects($this->once())->method('withdrawForSubject')
			->with($claims, 'apt-1', $this->isInstanceOf(DateTimeImmutable::class))
			->willReturn(['status' => PortalWithdrawalService::WITHDRAWN, 'acknowledgedAt' => null]);

		$response = $this->controller('assertion', $claims, ['appointmentId' => 'apt-1'])->withdraw();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAValidSubjectWithdraws()

	/**
	 * A bad assertion, or no credential at all, is unauthorized.
	 *
	 * @return void
	 */
	public function testNoValidCredentialIsUnauthorized(): void {
		$this->withdrawals->expects($this->never())->method('withdraw');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller('assertion', null, ['appointmentId' => 'apt-1'])->withdraw()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller('', null, [])->withdraw()->getStatus());
	}//end testNoValidCredentialIsUnauthorized()

	/**
	 * A refusal answers 409 with the reason; a failure 502.
	 *
	 * @return void
	 */
	public function testRefusalAndFailureStatuses(): void {
		$this->portal = $this->createMock(PortalWithdrawalService::class);
		$this->portal->method('withdrawByLink')->willReturnOnConsecutiveCalls(
			['status' => PortalWithdrawalService::REFUSED, 'reason' => 'The withdrawal period ended on 2026-10-03.'],
			['status' => PortalWithdrawalService::ERROR]
		);

		$refused = $this->controller('', null, ['token' => 'a.b'])->withdraw();
		$this->assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
		$this->assertSame(['error' => 'not_withdrawable', 'reason' => 'The withdrawal period ended on 2026-10-03.'], $refused->getData());
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $this->controller('', null, ['token' => 'a.b'])->withdraw()->getStatus());
	}//end testRefusalAndFailureStatuses()
}//end class
