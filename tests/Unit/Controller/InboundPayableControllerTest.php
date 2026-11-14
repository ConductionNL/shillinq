<?php

/**
 * Unit tests for InboundPayableController.
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

use InvalidArgumentException;
use OCA\Shillinq\Controller\InboundPayableController;
use OCA\Shillinq\Service\InboundPayableService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the three payout steps: the administer gate, the service hand-off,
 * and the mapping of a refusal (400) and a failure (500, logged).
 *
 * @covers \OCA\Shillinq\Controller\InboundPayableController
 * @uses \OCA\Shillinq\Service\PaymentActionAuthorizer
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InboundPayableControllerTest extends TestCase {

	/**
	 * The payable service double.
	 *
	 * @var InboundPayableService&MockObject
	 */
	private InboundPayableService&MockObject $payables;

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
		$this->payables = $this->createMock(InboundPayableService::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller, with a caller who may or may not administer payments.
	 *
	 * @param boolean $mayAdminister Whether the caller holds payment.administer.
	 *
	 * @return InboundPayableController The controller.
	 */
	private function controller(bool $mayAdminister): InboundPayableController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('boekhouder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $mayAdminister === true && $group === 'finance'
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([PaymentActionAuthorizer::ACTION_ADMINISTER => ['finance']]));

		return new InboundPayableController(
			'shillinq',
			$this->createMock(IRequest::class),
			new PaymentActionAuthorizer($config, $session, $groups),
			$this->payables,
			$this->logger
		);
	}//end controller()

	/**
	 * Without payment.administer every step is forbidden and the service is never asked.
	 *
	 * @return void
	 */
	public function testEveryStepNeedsTheAdministerAction(): void {
		$this->payables->expects($this->never())->method($this->anything());
		$controller = $this->controller(false);

		$approve = $controller->approve('p-1');
		$paid    = $controller->markPaid('p-1', 'REF');
		$reject  = $controller->reject('p-1', 'dubbel');

		$this->assertSame(Http::STATUS_FORBIDDEN, $approve->getStatus());
		$this->assertSame(['error' => 'Approving a payout needs the payment.administer action.'], $approve->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $paid->getStatus());
		$this->assertSame(['error' => 'Marking a payout paid needs the payment.administer action.'], $paid->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $reject->getStatus());
		$this->assertSame(['error' => 'Rejecting a payout needs the payment.administer action.'], $reject->getData());
	}//end testEveryStepNeedsTheAdministerAction()

	/**
	 * Approving returns the service's payable.
	 *
	 * @return void
	 */
	public function testApproveReturnsThePayable(): void {
		$this->payables->expects($this->once())->method('approve')->with('p-1')->willReturn(['id' => 'p-1', 'status' => 'approved']);

		$response = $this->controller(true)->approve('p-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'p-1', 'status' => 'approved'], $response->getData());
	}//end testApproveReturnsThePayable()

	/**
	 * Marking paid hands every bank detail to the service.
	 *
	 * @return void
	 */
	public function testMarkPaidPassesTheBankDetails(): void {
		$this->payables->expects($this->once())
			->method('markPaid')
			->with('p-1', 'REF-9', 'NL00BANK0123456789', '2026-11-01')
			->willReturn(['id' => 'p-1', 'status' => 'paid']);

		$response = $this->controller(true)->markPaid('p-1', 'REF-9', 'NL00BANK0123456789', '2026-11-01');

		$this->assertSame(['id' => 'p-1', 'status' => 'paid'], $response->getData());
	}//end testMarkPaidPassesTheBankDetails()

	/**
	 * A refusal from the service is a 400 carrying its message.
	 *
	 * @return void
	 */
	public function testARefusedStepIsABadRequest(): void {
		$this->payables->method('reject')->with('p-1', '')->willThrowException(new InvalidArgumentException('Payable is already paid.'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller(true)->reject('p-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Payable is already paid.'], $response->getData());
	}//end testARefusedStepIsABadRequest()

	/**
	 * Any other failure is a logged 500 that does not leak its message.
	 *
	 * @return void
	 */
	public function testAFailedStepIsALoggedServerError(): void {
		$this->payables->method('approve')->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Shillinq: a payout step could not be carried out', ['exception' => 'database gone']);

		$response = $this->controller(true)->approve('p-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The payout step could not be carried out.'], $response->getData());
	}//end testAFailedStepIsALoggedServerError()
}//end class
