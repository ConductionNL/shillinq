<?php

/**
 * Gap tests for ObjectRequestRefundController.
 *
 * Pins the payment.administer refusal on both refund actions and how each
 * outcome of a refund step (forbidden, invalid, failure, success) is answered.
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
use OCA\Shillinq\Controller\ObjectRequestRefundController;
use OCA\Shillinq\Service\ObjectRequestRefundService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Service\RefundStepForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the refund approve / markPaid actions.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ObjectRequestRefundController::class)]
#[UsesClass(PaymentActionAuthorizer::class)]
#[UsesClass(RefundStepForbiddenException::class)]
final class ObjectRequestRefundControllerGapsTest extends TestCase {

	/**
	 * Mock refund service.
	 *
	 * @var ObjectRequestRefundService&MockObject
	 */
	private ObjectRequestRefundService&MockObject $refunds;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the refund service and logger mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->refunds = $this->createMock(ObjectRequestRefundService::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller with a real authorizer whose caller is admin or not.
	 *
	 * @param bool $administer Whether the caller carries payment.administer (via the admin group).
	 *
	 * @return ObjectRequestRefundController
	 */
	private function controller(bool $administer): ObjectRequestRefundController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($administer);
		$groups->method('isInGroup')->willReturn(false);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new ObjectRequestRefundController(
			'shillinq',
			$this->createMock(IRequest::class),
			new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groups),
			$this->refunds,
			$this->logger
		);
	}//end controller()

	/**
	 * Without payment.administer both actions answer 403 and touch no refund.
	 *
	 * @return void
	 */
	public function testBothActionsRefuseACallerWithoutAdminister(): void {
		$this->refunds->expects($this->never())->method('approve');
		$this->refunds->expects($this->never())->method('markPaid');
		$controller = $this->controller(administer: false);

		$approve = $controller->approve('pr-1');
		self::assertSame(403, $approve->getStatus());
		self::assertSame(['error' => 'Approving a refund needs the payment.administer action.'], $approve->getData());

		$paid = $controller->markPaid('pr-1', 'REF', '1100');
		self::assertSame(403, $paid->getStatus());
		self::assertSame(['error' => 'Marking a refund paid needs the payment.administer action.'], $paid->getData());
	}//end testBothActionsRefuseACallerWithoutAdminister()

	/**
	 * Approve passes the id and answers the state and refunds only.
	 *
	 * @return void
	 */
	public function testApproveAnswersStateAndRefunds(): void {
		$this->refunds->expects($this->once())->method('approve')->with('pr-1')
			->willReturn(['state' => 'refund-approved', 'refunds' => [['amount' => 10]], 'secret' => 'x']);

		$response = $this->controller(administer: true)->approve('pr-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['state' => 'refund-approved', 'refunds' => [['amount' => 10]]], $response->getData());
	}//end testApproveAnswersStateAndRefunds()

	/**
	 * markPaid forwards the bank reference and account; missing keys default.
	 *
	 * @return void
	 */
	public function testMarkPaidForwardsBankDetailsAndDefaultsMissingKeys(): void {
		$this->refunds->expects($this->once())->method('markPaid')->with('pr-2', 'BANK-9', '1100')->willReturn([]);

		$response = $this->controller(administer: true)->markPaid('pr-2', 'BANK-9', '1100');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['state' => '', 'refunds' => []], $response->getData());
	}//end testMarkPaidForwardsBankDetailsAndDefaultsMissingKeys()

	/**
	 * A forbidden step is 403 and an invalid one 400, both carrying the reason, unlogged.
	 *
	 * @return void
	 */
	public function testForbiddenAndInvalidStepsCarryTheirReason(): void {
		$this->logger->expects($this->never())->method('error');
		$this->refunds->method('approve')->willThrowException(new RefundStepForbiddenException('You raised this refund.'));
		$this->refunds->method('markPaid')->willThrowException(new InvalidArgumentException('Bank reference missing.'));
		$controller = $this->controller(administer: true);

		$approve = $controller->approve('pr-1');
		self::assertSame(403, $approve->getStatus());
		self::assertSame(['error' => 'You raised this refund.'], $approve->getData());

		$paid = $controller->markPaid('pr-1');
		self::assertSame(400, $paid->getStatus());
		self::assertSame(['error' => 'Bank reference missing.'], $paid->getData());
	}//end testForbiddenAndInvalidStepsCarryTheirReason()

	/**
	 * Any other failure is a logged 500 with a generic message.
	 *
	 * @return void
	 */
	public function testOtherFailureIsALogged500(): void {
		$this->refunds->method('approve')->willThrowException(new RuntimeException('ledger down'));
		$this->logger->expects($this->once())->method('error')
			->with('Shillinq: a refund step could not be carried out', ['exception' => 'ledger down']);

		$response = $this->controller(administer: true)->approve('pr-1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'The refund step could not be carried out.'], $response->getData());
	}//end testOtherFailureIsALogged500()
}//end class
