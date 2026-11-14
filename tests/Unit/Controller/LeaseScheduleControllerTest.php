<?php

/**
 * Unit tests for LeaseController::schedule().
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

use OCA\Shillinq\Controller\LeaseController;
use OCA\Shillinq\Service\LeaseDisclosureService;
use OCA\Shillinq\Service\LeasePaymentScheduleService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the payment-schedule endpoint: the login gate, both identifiers
 * required and well-formed, the trimmed hand-off to the service, and a
 * service failure turned into a logged 500 without its message.
 *
 * @covers \OCA\Shillinq\Controller\LeaseController
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseScheduleControllerTest extends TestCase {

	/**
	 * The schedule service double.
	 *
	 * @var LeasePaymentScheduleService&MockObject
	 */
	private LeasePaymentScheduleService&MockObject $schedule;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schedule = $this->createMock(LeasePaymentScheduleService::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller over the given query parameters.
	 *
	 * @param array<string, mixed> $params   The query parameters.
	 * @param boolean              $loggedIn Whether a user is logged in.
	 *
	 * @return LeaseController The controller.
	 */
	private function controller(array $params, bool $loggedIn = true): LeaseController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $this->createMock(IUser::class) : null);

		return new LeaseController(
			request: $request,
			scheduleService: $this->schedule,
			disclosureService: $this->createMock(LeaseDisclosureService::class),
			userSession: $session,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * An anonymous caller gets 401 and the service is never asked.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefused(): void {
		$this->schedule->expects($this->never())->method('buildSchedule');

		$response = $this->controller(['lease_id' => 'l-1', 'administration_id' => 'adm-1'], false)->schedule();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testAnonymousCallerIsRefused()

	/**
	 * Each identifier is required and must be well-formed.
	 *
	 * @return void
	 */
	public function testIdentifiersAreValidated(): void {
		$this->schedule->expects($this->never())->method('buildSchedule');

		$cases = [
			[['administration_id' => 'adm-1'], 'lease_id is required'],
			[['lease_id' => 'l-1', 'administration_id' => '  '], 'administration_id is required'],
			[['lease_id' => 'l 1', 'administration_id' => 'adm-1'], 'lease_id must be a valid identifier'],
			[['lease_id' => 'l-1', 'administration_id' => str_repeat('a', 65)], 'administration_id must be a valid identifier'],
		];
		foreach ($cases as [$params, $error]) {
			$response = $this->controller($params)->schedule();

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $error);
			$this->assertSame(['error' => $error], $response->getData());
		}
	}//end testIdentifiersAreValidated()

	/**
	 * The trimmed identifiers reach the service and its rows come back with a total.
	 *
	 * @return void
	 */
	public function testTheScheduleIsReturnedWithItsTotal(): void {
		$rows = [['period' => '2026-01', 'payment' => 1000], ['period' => '2026-02', 'payment' => 1000]];
		$this->schedule->expects($this->once())
			->method('buildSchedule')
			->with('l-1', 'adm-1')
			->willReturn($rows);

		$response = $this->controller(['lease_id' => ' l-1 ', 'administration_id' => 'adm-1 '])->schedule();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['data' => $rows, 'total' => 2], $response->getData());
	}//end testTheScheduleIsReturnedWithItsTotal()

	/**
	 * A service failure is a logged 500 that does not leak the cause.
	 *
	 * @return void
	 */
	public function testAFailureIsALoggedServerError(): void {
		$this->schedule->method('buildSchedule')->willThrowException(new RuntimeException('no such lease'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'LeaseController: failed to build lease schedule',
				['leaseId' => 'l-1', 'administrationId' => 'adm-1', 'exception' => 'no such lease']
			);

		$response = $this->controller(['lease_id' => 'l-1', 'administration_id' => 'adm-1'])->schedule();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to build lease schedule'], $response->getData());
	}//end testAFailureIsALoggedServerError()
}//end class
