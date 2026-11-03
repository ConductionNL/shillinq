<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use OCA\Shillinq\PaymentPlan\PaymentPlanRefusedException;
use OCA\Shillinq\PaymentPlan\PaymentPlanService;
use PHPUnit\Framework\TestCase;

/**
 * RW124 probe: assertNotInOpenPlan over the magic-accessor, OR-faithful store.
 *
 * @coversNothing
 */
/**
 * An invoice already on an open payment plan is refused, over the shared
 * stub's OpenRegister-like scope.
 *
 * @covers \OCA\Shillinq\PaymentPlan\PaymentPlanService
 */
final class Rw124OpenPlanProbeTest extends TestCase {
	use PaymentPlanFixture;

	protected function setUp(): void {
		$this->seed();
	}

	private function check(array $invoice): void {
		$m = new \ReflectionMethod(PaymentPlanService::class, 'assertNotInOpenPlan');
		$m->invoke($this->service(), $invoice, '2026-0231');
	}

	public function testAnInvoiceOnAnActivePlanIsRefused(): void {
		$plan = $this->activeZwaanPlan();
		$invoice = $this->stored('ARInvoice', 'ar-0231');
		self::assertNotSame('', (string)($invoice['paymentPlanId'] ?? ''), 'fixture links the invoice to the plan');
		$this->expectException(PaymentPlanRefusedException::class);
		$this->check($invoice);
	}

	public function testAnInvoiceWithoutAPlanPasses(): void {
		$this->check(['paymentPlanId' => '']);
		self::assertTrue(true);
	}

	public function testAnUnknownPlanIdPasses(): void {
		$this->check(['paymentPlanId' => '99999999-9999-4999-8999-999999999999']);
		self::assertTrue(true);
	}

	public function testASeededActivePlanIsRefused(): void {
		$uuid = '12121212-1212-4121-8121-121212121212';
		$this->store->setRegister('shillinq')->setSchema('PaymentPlan')->saveObject(['id' => $uuid, 'uuid' => $uuid, 'planNumber' => 'RGL-SEED', 'lifecycleState' => 'active']);
		$this->expectException(PaymentPlanRefusedException::class);
		$this->check(['paymentPlanId' => $uuid]);
	}

	public function testASeededClosedPlanPasses(): void {
		$uuid = '13131313-1313-4131-8131-131313131313';
		$this->store->setRegister('shillinq')->setSchema('PaymentPlan')->saveObject(['id' => $uuid, 'uuid' => $uuid, 'planNumber' => 'RGL-DONE', 'lifecycleState' => 'completed']);
		$this->check(['paymentPlanId' => $uuid]);
		self::assertTrue(true);
	}

	public function testAFailingPlanLookupRefuses(): void {
		$this->store = new class() extends \OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub {
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true
			): ?\OCA\OpenRegister\Contract\ObjectEntityInterface {
				throw new \RuntimeException('OpenRegister is down');
			}
		};
		$this->expectException(PaymentPlanRefusedException::class);
		$this->check(['paymentPlanId' => '12121212-1212-4121-8121-121212121212']);
	}
}
