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

use OCA\Shillinq\Lifecycle\PaymentPlanGuard;
use OCA\Shillinq\PaymentPlan\PaymentPlanSchedule;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PaymentPlan.activate refuses a schedule that does not add up (REQ-RPPL-001).
 */
final class PaymentPlanGuardTest extends TestCase {
	/**
	 * The guard over a plan with the given instalment amounts.
	 *
	 * @param array<int,float> $amounts The instalment amounts.
	 *
	 * @return PaymentPlanGuard
	 */
	private function guard(array $amounts): PaymentPlanGuard {
		$rows = [];
		foreach ($amounts as $index => $amount) {
			$rows[] = ['id' => 'inst-' . $index, 'planId' => 'plan-7', 'instalmentNumber' => ($index + 1), 'dueDate' => '2026-11-01', 'amount' => $amount, 'administrationId' => 'adm-hoekstra'];
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		return new PaymentPlanGuard(new InMemoryObjectServiceStub(['PaymentPlanInstalment' => $rows]), $settings, new PaymentPlanSchedule(), $this->createMock(LoggerInterface::class));
	}//end guard()

	/**
	 * The guard is the one the schema declares on activate.
	 *
	 * @return void
	 */
	public function testTheSchemaDeclaresTheGuardOnActivate(): void {
		$lifecycle = RegisterSchema::schema('PaymentPlan')['x-openregister-lifecycle'];

		$this->assertSame(PaymentPlanGuard::class, $lifecycle['transitions']['activate']['requires']);
	}//end testTheSchemaDeclaresTheGuardOnActivate()

	/**
	 * A balanced schedule activates.
	 *
	 * @return void
	 */
	public function testABalancedScheduleIsAllowed(): void {
		$result = $this->guard([403.33, 403.33, 403.33, 403.33, 403.33, 403.35])->check(['id' => 'plan-7', 'totalAmount' => 2420.00], 'activate', 'bookkeeper');

		$this->assertTrue($result->isAllowed());
	}//end testABalancedScheduleIsAllowed()

	/**
	 * A schedule a cent short is refused, naming both sums.
	 *
	 * @return void
	 */
	public function testAnUnbalancedScheduleIsRefused(): void {
		$result = $this->guard([403.33, 403.33, 403.33, 403.33, 403.33, 403.34])->check(['id' => 'plan-7', 'totalAmount' => 2420.00], 'activate', 'bookkeeper');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('2,419.99', (string)$result->getMessage());
		$this->assertStringContainsString('2,420.00', (string)$result->getMessage());
	}//end testAnUnbalancedScheduleIsRefused()

	/**
	 * A plan without an id cannot be checked, so it is refused.
	 *
	 * @return void
	 */
	public function testAPlanWithoutAnIdIsRefused(): void {
		$this->assertFalse($this->guard([2420.00])->check(['totalAmount' => 2420.00], 'activate', 'bookkeeper')->isAllowed());
		$this->assertTrue($this->guard([])->check(['id' => 'plan-7'], 'cancel', 'bookkeeper')->isAllowed());
	}//end testAPlanWithoutAnIdIsRefused()
}//end class
