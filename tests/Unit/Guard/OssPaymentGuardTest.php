<?php

/**
 * Unit tests for OssPaymentGuard.
 *
 * Correctness proof for tasks.md Task 19 (design D2). The register.d
 * `requires` tag `OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid`
 * was never registered, so `OssReturn.pay` and `OssPayment.reconcile` both
 * hard-failed with HTTP 500 and the guard never ran. This guard is the
 * single-array adapter that tag always implied; these tests prove it resolves
 * BOTH object shapes and is fail-closed without a counterpart.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/revive-gl-tax-capabilities/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\OssPaymentGuard;
use OCA\Shillinq\Service\OssPaymentReconciliation;
use OCA\Shillinq\Service\OssRecordResolver;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests the `OssReturn.pay` / `OssPayment.reconcile` precondition.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssPaymentGuardTest extends TestCase {

	/**
	 * The in-memory ObjectService backing the chain.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $objects;

	/**
	 * The guard under test.
	 *
	 * @var OssPaymentGuard
	 */
	private OssPaymentGuard $guard;

	/**
	 * Set up the guard over the real kernel.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = new InMemoryObjectService();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new OssPaymentGuard(
			resolver: new OssRecordResolver(
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->objects),
		),
			reconciliation: new OssPaymentReconciliation(),
		);

	}//end setUp()

	/**
	 * The declared OssReturn.
	 *
	 * @return array<string,mixed>
	 */
	private function ossReturn(): array {
		return [
			'id' => 'ossret-1',
			'totalVatAmount' => 3242.0,
		];

	}//end ossReturn()

	/**
	 * Seed the pair.
	 *
	 * @param float $amount The payment amount.
	 * @param string $bankTransactionId The linked bank transaction ('' = none).
	 *
	 * @return array<string,mixed> The payment payload.
	 */
	private function seedPair(float $amount, string $bankTransactionId): array {
		$payment = [
			'id' => 'osspay-1',
			'ossReturnId' => 'ossret-1',
			'amount' => $amount,
			'bankTransactionId' => $bankTransactionId,
			'reconciliationStatus' => 'pending',
		];

		$this->objects->seed('OssReturn', [$this->ossReturn()]);
		$this->objects->seed('OssPayment', [$payment]);

		return $payment;
	}//end seedPair()

	/**
	 * A payment settling the return in full is permitted (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testPaymentSideAllowsAFullSettlement(): void {
		$payment = $this->seedPair(amount: 3242.0, bankTransactionId: 'bank-tx-1');

		self::assertTrue($this->guard->canMarkPaid(object: $payment));

	}//end testPaymentSideAllowsAFullSettlement()

	/**
	 * A short payment is denied (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testPaymentSideDeniesAShortSettlement(): void {
		$payment = $this->seedPair(amount: 3000.0, bankTransactionId: 'bank-tx-1');

		self::assertFalse($this->guard->canMarkPaid(object: $payment));

	}//end testPaymentSideDeniesAShortSettlement()

	/**
	 * A payment with no linked bank transaction is denied (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testPaymentSideDeniesAnUnlinkedPayment(): void {
		$payment = $this->seedPair(amount: 3242.0, bankTransactionId: '');

		self::assertFalse($this->guard->canMarkPaid(object: $payment));

	}//end testPaymentSideDeniesAnUnlinkedPayment()

	/**
	 * The SAME tag is used by `OssReturn.pay`: given the return, the guard
	 * resolves the payment behind it (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testReturnSideResolvesItsPayment(): void {
		$this->seedPair(amount: 3242.0, bankTransactionId: 'bank-tx-1');

		self::assertTrue($this->guard->canMarkPaid(object: $this->ossReturn()));

	}//end testReturnSideResolvesItsPayment()

	/**
	 * A return with no payment at all is denied — fail-closed (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testReturnSideWithoutAPaymentIsDenied(): void {
		$this->objects->seed('OssReturn', [$this->ossReturn()]);

		self::assertFalse($this->guard->canMarkPaid(object: $this->ossReturn()));

	}//end testReturnSideWithoutAPaymentIsDenied()

	/**
	 * A payment whose OssReturn cannot be read is denied — fail-closed
	 * (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testPaymentWithAnUnresolvableReturnIsDenied(): void {
		$payment = [
			'id' => 'osspay-1',
			'ossReturnId' => 'ossret-missing',
			'amount' => 3242.0,
			'bankTransactionId' => 'bank-tx-1',
		];

		self::assertFalse($this->guard->canMarkPaid(object: $payment));

	}//end testPaymentWithAnUnresolvableReturnIsDenied()
}//end class
