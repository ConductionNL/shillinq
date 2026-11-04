<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\Shillinq\Guard\BankReconciliationGuard;
use OCA\Shillinq\Guard\CashflowRecurringGuard;
use OCA\Shillinq\Guard\ProgrammaLinkGuard;
use OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard;
use OCA\Shillinq\Lifecycle\StockReservationGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Each guard reads a stored object in the schema it asks for. The store
 * reports its register and schema and answers a lookup the way OpenRegister
 * does: without a schema it searches every schema, and a uuid missed in the
 * schema is retried without it. So an object of another schema that answers
 * the id must be read as absent.
 *
 * @covers \OCA\Shillinq\Guard\BankReconciliationGuard
 * @covers \OCA\Shillinq\Guard\CashflowRecurringGuard
 * @covers \OCA\Shillinq\Guard\ProgrammaLinkGuard
 * @covers \OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard
 * @covers \OCA\Shillinq\Lifecycle\StockReservationGuard
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class GuardsReadTheirOwnSchemaTest extends TestCase {

	private const OTHER = 'c0ffee00-0000-4000-8000-0000000000aa';

	private function appConfig(): IAppConfig {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		return $appConfig;
	}

	/**
	 * A store whose only object with the id lives in the given other schema.
	 *
	 * @param array<string, mixed> $other The other schema's object.
	 * @param array<string, array<int, array<string, mixed>>> $more More rows by schema.
	 */
	private static function store(string $schema, array $other, array $more = []): InMemoryObjectServiceStub {
		return new InMemoryObjectServiceStub([$schema => [['id' => self::OTHER] + $other]] + $more);
	}

	public function testABankMatchIgnoresALockedObjectOfAnotherSchema(): void {
		$guard = new BankReconciliationGuard($this->appConfig(), new NullLogger(), self::store('BankStatement', ['status' => 'reconciled']));

		self::assertTrue($guard->requireParentUnlocked(['reconciliationId' => self::OTHER]));
	}

	public function testARecurringCostIgnoresTheWindowOfAnotherSchema(): void {
		$guard = new CashflowRecurringGuard($this->appConfig(), new NullLogger(), self::store('PurchaseOrder', ['startDate' => '2027-01-01']));

		self::assertTrue($guard->validateOnSave([
			'recurId' => 'rec-scope',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
			'standardAmount' => 500.0,
			'validFrom' => '2026-06-01',
			'contractReference' => self::OTHER,
		]));
	}

	public function testAProgrammeLinkIgnoresTheProgrammeOfAnotherSchema(): void {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn(self::store('GLTransaction', ['programmeStructure' => 'mobiliteit']));
		$guard = new ProgrammaLinkGuard($container, $this->appConfig(), new NullLogger());

		self::assertTrue($guard->validateOnSave(['id' => self::OTHER, 'programmeStructure' => 'water']));
	}

	public function testAClaimIgnoresTheSettlementModeOfAnotherSchema(): void {
		$guard = new ExpenseReimbursementGuard($this->appConfig(), new NullLogger(), self::store('Invoice', ['settlementMode' => 'pass-through']));

		self::assertTrue($guard->requireSettlementModeConsistency(['id' => 'claim-1', 'settlementMode' => 'reimbursable', 'receiptIds' => [self::OTHER]]));
	}

	public function testAMarkupIgnoresTheRateOfAnotherSchema(): void {
		$store = self::store(
			'Invoice',
			['settlementMode' => 'pass-through', 'amountInBaseCurrency' => 100.0, 'markupRateApplied' => 0.01],
			['ReimbursementPolicy' => [['policyId' => 'policy-1', 'requiresMarkupApprovalThreshold' => 10.0]]]
		);
		$guard = new ExpenseReimbursementGuard($this->appConfig(), new NullLogger(), $store);

		// Without a receipt of its own the whole pass-through amount counts as markup.
		self::assertFalse($guard->requireMarkupApprovalIfThreshold([
			'id' => 'claim-1',
			'settlementMode' => 'pass-through',
			'reimbursementPolicyId' => 'policy-1',
			'totalPassThroughAmount' => 100.0,
			'receiptIds' => [self::OTHER],
		]));
	}

	public function testAModeChangeIgnoresAReversalOfAnotherSchema(): void {
		$guard = new ExpenseReimbursementGuard($this->appConfig(), new NullLogger(), self::store('Invoice', ['status' => 'reversed']));

		self::assertFalse($guard->requireGlReversalForModeChange(['settlementMode' => 'reimbursable', 'glReimbursableTransactionId' => self::OTHER]));
	}

	public function testAReservationComparesTheVersionOfItsOwnStockRow(): void {
		// Numeric ids repeat across schemas; the movement row comes first in an unscoped search.
		$store = new InMemoryObjectServiceStub([
			'StockMovement' => [['id' => '7', 'version' => 5]],
			'InventoryStock' => [['id' => '7', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'sku' => 'sku-1', 'quantity' => 10, 'reservedQuantity' => 0, 'version' => 1]],
		]);
		$guard = new StockReservationGuard($this->appConfig(), new NullLogger(), $store);

		self::assertTrue($guard->reserveReservation(['administrationId' => 'adm-1', 'sourceLocationId' => 'loc-1', 'itemId' => 'sku-1', 'quantity' => 1]));
	}
}
