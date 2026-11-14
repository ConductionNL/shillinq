<?php

/**
 * Gap tests for PaymentPlanController's write actions.
 *
 * Pins create, activate, settle, cancel and payFromLine: who is refused and
 * how, what reaches the plan service or bank matcher, and how a refusal or a
 * failure of that call is answered.
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

use OCA\Shillinq\Controller\PaymentPlanController;
use OCA\Shillinq\PaymentPlan\PaymentPlanBankMatcher;
use OCA\Shillinq\Kernel\Exception\PaymentPlanRefusedException;
use OCA\Shillinq\PaymentPlan\PaymentPlanService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Kernel\Exception\ManualMatchRefusedException;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the payment plan write actions.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(PaymentPlanController::class)]
#[UsesClass(PaymentPlanRefusedException::class)]
#[UsesClass(ManualMatchRefusedException::class)]
final class PaymentPlanControllerGapsTest extends TestCase {

	/**
	 * Mock plan service.
	 *
	 * @var PaymentPlanService&MockObject
	 */
	private PaymentPlanService&MockObject $plans;

	/**
	 * Mock bank matcher.
	 *
	 * @var PaymentPlanBankMatcher&MockObject
	 */
	private PaymentPlanBankMatcher&MockObject $matcher;

	/**
	 * Mock bank line reader.
	 *
	 * @var ManualMatchService&MockObject
	 */
	private ManualMatchService&MockObject $lines;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Administrations the caller may act in.
	 *
	 * @var array<int,string>
	 */
	private array $memberOf = ['a1'];

	/**
	 * Set up the boundary mocks; plan p1 lives in a1, p2 in a2, others are unknown.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->plans   = $this->createMock(PaymentPlanService::class);
		$this->matcher = $this->createMock(PaymentPlanBankMatcher::class);
		$this->lines   = $this->createMock(ManualMatchService::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->plans->method('plan')->willReturnCallback(
			static function (string $planId): array {
				$plans = ['p1' => ['id' => 'p1', 'administrationId' => 'a1'], 'p2' => ['id' => 'p2', 'administrationId' => 'a2']];
				if (isset($plans[$planId]) === false) {
					throw new PaymentPlanRefusedException('This payment plan does not exist.');
				}

				return $plans[$planId];
			}
		);
		$this->lines->method('findLine')->willReturnCallback(
			static function (string $lineId): array {
				$lines = ['l1' => ['id' => 'l1', 'administrationId' => 'a1'], 'l2' => ['id' => 'l2', 'administrationId' => 'a2']];
				if (isset($lines[$lineId]) === false) {
					throw new OutOfBoundsException('no line');
				}

				return $lines[$lineId];
			}
		);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $params   Request parameters.
	 * @param bool                $loggedIn Whether a user is signed in.
	 *
	 * @return PaymentPlanController
	 */
	private function controller(array $params=[], bool $loggedIn=true): PaymentPlanController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $user : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(fn (string $id): bool => in_array($id, $this->memberOf, true));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => 'T:' . vsprintf($text, $parameters)
		);

		return new PaymentPlanController($request, $this->plans, $this->matcher, $this->lines, $context, $session, $l10n, $this->logger);
	}//end controller()

	/**
	 * Every write action answers an anonymous caller 401 without touching a plan.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefusedEverywhere(): void {
		$this->plans->expects($this->never())->method('plan');
		$this->lines->expects($this->never())->method('findLine');
		$controller = $this->controller(['administrationId' => 'a1'], loggedIn: false);

		$responses = [
			$controller->create(),
			$controller->activate('p1'),
			$controller->settle('p1'),
			$controller->cancel('p1'),
			$controller->payFromLine('p1'),
		];
		foreach ($responses as $response) {
			self::assertSame(401, $response->getStatus());
			self::assertSame(['message' => 'T:Not logged in'], $response->getData());
		}
	}//end testAnonymousCallerIsRefusedEverywhere()

	/**
	 * create() refuses a foreign administration with 403 before drafting.
	 *
	 * @return void
	 */
	public function testCreateRefusesAForeignAdministration(): void {
		$this->plans->expects($this->never())->method('draft');

		$response = $this->controller(['administrationId' => 'a2'])->create();

		self::assertSame(403, $response->getStatus());
		self::assertSame(['message' => 'T:Not allowed for this administration'], $response->getData());
	}//end testCreateRefusesAForeignAdministration()

	/**
	 * create() drafts with the request input, a boolean includesCharges and the actor, answering 201.
	 *
	 * @return void
	 */
	public function testCreateDraftsWithNormalisedInput(): void {
		$this->plans->expects($this->once())->method('draft')
			->with(['administrationId' => 'a1', 'includesCharges' => true, 'total' => 300], 'bob')
			->willReturn(['id' => 'p9', 'instalments' => [1, 2, 3]]);

		$response = $this->controller(['administrationId' => 'a1', 'includesCharges' => 'yes', 'total' => 300])->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame(['id' => 'p9', 'instalments' => [1, 2, 3]], $response->getData());
	}//end testCreateDraftsWithNormalisedInput()

	/**
	 * An unknown plan and one in a foreign administration are both 404 on every plan action.
	 *
	 * @return void
	 */
	public function testUnknownOrForeignPlanIsMaskedAs404(): void {
		$this->plans->expects($this->never())->method('activate');
		$this->plans->expects($this->never())->method('receive');
		$this->plans->expects($this->never())->method('cancel');
		$this->matcher->expects($this->never())->method('payFromLine');
		$controller = $this->controller(['paidDate' => '2026-01-02', 'lineId' => 'l1']);

		foreach (['p404', 'p2'] as $planId) {
			foreach (['activate', 'settle', 'cancel', 'payFromLine'] as $action) {
				$response = $controller->$action($planId);
				self::assertSame(404, $response->getStatus(), $action . ' ' . $planId);
				self::assertSame(['message' => 'T:This payment plan does not exist.'], $response->getData());
			}
		}
	}//end testUnknownOrForeignPlanIsMaskedAs404()

	/**
	 * activate() passes the plan and actor; a refusal comes back as a translated 422.
	 *
	 * @return void
	 */
	public function testActivateAndItsRefusal(): void {
		$this->plans->expects($this->exactly(2))->method('activate')->with('p1', 'bob')->willReturnOnConsecutiveCalls(
			['id' => 'p1', 'status' => 'active'],
			$this->throwException(new PaymentPlanRefusedException('Plan %1$s has no instalments.', ['p1']))
		);
		$controller = $this->controller();

		$ok = $controller->activate('p1');
		self::assertSame(200, $ok->getStatus());
		self::assertSame(['id' => 'p1', 'status' => 'active'], $ok->getData());

		$refused = $controller->activate('p1');
		self::assertSame(422, $refused->getStatus());
		self::assertSame(['message' => 'T:Plan p1 has no instalments.'], $refused->getData());
	}//end testActivateAndItsRefusal()

	/**
	 * settle() requires a Y-m-d paid date, then receives the cast amount by hand.
	 *
	 * @return void
	 */
	public function testSettleValidatesTheDateAndReceives(): void {
		$this->plans->expects($this->once())->method('receive')->with('p1', 125.5, '2026-03-01', 'by-hand')
			->willReturn(['id' => 'p1', 'paid' => 125.5]);

		foreach (['', '01-03-2026', '2026-3-1'] as $date) {
			$bad = $this->controller(['paidDate' => $date, 'amount' => '125.50'])->settle('p1');
			self::assertSame(422, $bad->getStatus());
			self::assertSame(['message' => 'T:Enter the date the payment arrived.'], $bad->getData());
		}

		$ok = $this->controller(['paidDate' => '2026-03-01', 'amount' => '125.50'])->settle('p1');
		self::assertSame(200, $ok->getStatus());
		self::assertSame(['id' => 'p1', 'paid' => 125.5], $ok->getData());
	}//end testSettleValidatesTheDateAndReceives()

	/**
	 * cancel() passes the trimmed reason; an unexpected failure is a logged 500.
	 *
	 * @return void
	 */
	public function testCancelTrimsReasonAndLogsFailure(): void {
		$this->plans->expects($this->exactly(2))->method('cancel')->with('p1', 'customer paid in full')
			->willReturnOnConsecutiveCalls(['id' => 'p1', 'status' => 'cancelled'], $this->throwException(new RuntimeException('db gone')));
		$this->logger->expects($this->once())->method('error')
			->with('PaymentPlanController: request failed', ['exception' => 'db gone']);
		$controller = $this->controller(['reason' => '  customer paid in full ']);

		$ok = $controller->cancel('p1');
		self::assertSame(200, $ok->getStatus());
		self::assertSame(['id' => 'p1', 'status' => 'cancelled'], $ok->getData());

		$failed = $controller->cancel('p1');
		self::assertSame(500, $failed->getStatus());
		self::assertSame(['message' => 'T:The payment plan could not be updated.'], $failed->getData());
	}//end testCancelTrimsReasonAndLogsFailure()

	/**
	 * payFromLine() refuses an unknown or foreign line as 404 and never books it.
	 *
	 * @return void
	 */
	public function testPayFromLineRefusesUnknownOrForeignLine(): void {
		$this->matcher->expects($this->never())->method('payFromLine');

		foreach (['', 'l404', 'l2'] as $lineId) {
			$response = $this->controller(['lineId' => $lineId])->payFromLine('p1');
			self::assertSame(404, $response->getStatus(), $lineId);
			self::assertSame(['message' => 'T:This payment plan does not exist.'], $response->getData());
		}
	}//end testPayFromLineRefusesUnknownOrForeignLine()

	/**
	 * payFromLine() books the line with the actor and reason; a match refusal is a translated 422.
	 *
	 * @return void
	 */
	public function testPayFromLineBooksAndTranslatesAMatchRefusal(): void {
		$line = ['id' => 'l1', 'administrationId' => 'a1'];
		$this->matcher->expects($this->exactly(2))->method('payFromLine')
			->with('p1', $line, 'bob', 'Payment plan instalment confirmed by bob')
			->willReturnOnConsecutiveCalls(
				['id' => 'p1', 'paid' => true],
				$this->throwException(new ManualMatchRefusedException('Line %1$s is already matched.', ['l1']))
			);
		$controller = $this->controller(['lineId' => 'l1']);

		$ok = $controller->payFromLine('p1');
		self::assertSame(200, $ok->getStatus());
		self::assertSame(['id' => 'p1', 'paid' => true], $ok->getData());

		$refused = $controller->payFromLine('p1');
		self::assertSame(422, $refused->getStatus());
		self::assertSame(['message' => 'T:Line l1 is already matched.'], $refused->getData());
	}//end testPayFromLineBooksAndTranslatesAMatchRefusal()
}//end class
