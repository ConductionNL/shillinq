<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use OCA\Shillinq\Controller\PaymentPlanController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The payment plan endpoints over the real services (REQ-RPPL-001, REQ-RPPL-003).
 */
final class PaymentPlanControllerTest extends TestCase {
	use PaymentPlanFixture;

	/**
	 * Seed Café De Zwaan.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seed();
	}//end setUp()

	/**
	 * The controller with request parameters and an administration check.
	 *
	 * @param array<string,mixed> $params    Request parameters.
	 * @param bool                $canAccess Whether the user may act in the administration.
	 *
	 * @return PaymentPlanController
	 */
	private function controller(array $params, bool $canAccess = true): PaymentPlanController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn($canAccess);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bookkeeper');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$logger = $this->createMock(LoggerInterface::class);

		return new PaymentPlanController(
			$request,
			$this->service(),
			$this->matcher(),
			new ManualMatchService($this->store, $this->runner(), $this->settings(), $logger),
			$context,
			$session,
			$l10n,
			$logger
		);
	}//end controller()

	/**
	 * Draft and activate through the endpoints.
	 *
	 * @return void
	 */
	public function testAPlanIsDrawnUpAndActivated(): void {
		$params = ['administrationId' => 'adm-hoekstra', 'customerId' => 'cust-zwaan', 'invoiceIds' => ['ar-0231', 'ar-0266'], 'instalmentCount' => '6', 'frequency' => 'monthly', 'firstDueDate' => '2026-11-01', 'graceDays' => '14', 'includesCharges' => 'false'];
		$created = $this->controller($params)->create();

		$this->assertSame(201, $created->getStatus());
		$planId = (string)$created->getData()['plan']['id'];
		$this->assertSame('RGL-2026-0007', $created->getData()['plan']['planNumber']);

		$activated = $this->controller([])->activate($planId);
		$this->assertSame(200, $activated->getStatus());
		$this->assertSame('active', $activated->getData()['lifecycleState']);
	}//end testAPlanIsDrawnUpAndActivated()

	/**
	 * Outside the administration: 403 on create, 404 on a plan.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationIsRefused(): void {
		$plan = $this->activeZwaanPlan();

		$this->assertSame(403, $this->controller(['administrationId' => 'adm-hoekstra'], false)->create()->getStatus());
		$this->assertSame(404, $this->controller(['amount' => 10, 'paidDate' => '2026-11-01'], false)->settle((string)$plan['id'])->getStatus());
		$this->assertSame(404, $this->controller([], false)->activate((string)$plan['id'])->getStatus());
	}//end testAnotherAdministrationIsRefused()

	/**
	 * Settling by hand needs a date, and a refused draft answers 422 with the reason.
	 *
	 * @return void
	 */
	public function testSettleByHandAndRefusals(): void {
		$plan = $this->activeZwaanPlan();

		$this->assertSame(422, $this->controller(['amount' => 403.33])->settle((string)$plan['id'])->getStatus());
		$paid = $this->controller(['amount' => 403.33, 'paidDate' => '2026-11-01'])->settle((string)$plan['id']);
		$this->assertSame(200, $paid->getStatus());
		$this->assertSame([1], $paid->getData()['instalmentsPaid']);

		$refused = $this->controller(['administrationId' => 'adm-hoekstra', 'customerId' => 'cust-zwaan', 'invoiceIds' => ['ar-0300'], 'instalmentCount' => 2, 'firstDueDate' => '2026-11-01'])->create();
		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('Invoice 2026-0300 is not overdue with an amount due.', $refused->getData()['message']);
	}//end testSettleByHandAndRefusals()

	/**
	 * A bank line lists the plan it can pay and pays it once confirmed.
	 *
	 * @return void
	 */
	public function testABankLineIsOfferedAndConfirmed(): void {
		$plan = $this->activeZwaanPlan();
		$this->line('line-t1', 403.33, 'termijn november');

		$candidates = $this->controller([])->lineCandidates('line-t1')->getData()['candidates'];
		$this->assertSame('RGL-2026-0007', $candidates[0]['planNumber']);
		$this->assertSame('medium', $candidates[0]['confidence']);

		$paid = $this->controller(['lineId' => 'line-t1'])->payFromLine((string)$plan['id']);
		$this->assertSame(200, $paid->getStatus());
		$this->assertSame($plan['id'], $paid->getData()['paymentPlanId']);
		$this->assertSame('paid', $this->all('PaymentPlanInstalment')[0]['state']);
	}//end testABankLineIsOfferedAndConfirmed()
	/**
	 * Cancelling ends the plan with the reason and answers 404 outside the administration.
	 *
	 * @return void
	 */
	public function testAPlanIsCancelledWithItsReason(): void {
		$plan = $this->activeZwaanPlan();

		$this->assertSame(404, $this->controller(['reason' => 'x'], false)->cancel((string)$plan['id'])->getStatus());

		$cancelled = $this->controller(['reason' => 'Customer paid in full elsewhere'])->cancel((string)$plan['id']);
		$this->assertSame(200, $cancelled->getStatus());
		$this->assertSame('cancelled', $cancelled->getData()['lifecycleState']);
		$this->assertSame('Customer paid in full elsewhere', $cancelled->getData()['endReason']);
	}//end testAPlanIsCancelledWithItsReason()
}//end class
