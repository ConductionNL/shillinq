<?php

/**
 * Unit tests for InventoryPostingGuard (inventory-cogs-posting).
 *
 * Covers the four predicates per inventory-cogs-posting tasks 5/7/8/9:
 *   - canPost (REQ-CG-002 / REQ-CG-003 gate)
 *       * unitCost_missing skip
 *       * config_missing skip
 *       * posting_disabled skip
 *       * happy-path permit
 *   - canPostVariance (REQ-CG-004 gate)
 *       * delegates to canPost (denies on its skip cases)
 *       * zero-delta short-circuit (Zero variance scenario)
 *       * positive + negative deltas permitted
 *       * missing variance / actualQuantity denies (fail-closed)
 *   - direction (REQ-CG-004 D4 ADR-031 exception path)
 *       * positive delta -> 'positive'
 *       * negative delta -> 'negative'
 *       * zero / boundary -> 'negative' (defensive default)
 *   - accountExists (REQ-CG-001 FK invariant)
 *       * all four FKs resolve -> true
 *       * any FK does not resolve -> false
 *       * empty administrationId -> true (schema-level required catches this)
 *       * empty FK field -> skipped (required-fields enforcement handles it)
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-7
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-8
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\InventoryPostingGuard;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests for InventoryPostingGuard.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InventoryPostingGuardTest extends TestCase {

	/**
	 * Build a guard wired to the given in-memory ObjectService.
	 *
	 * @param InMemoryObjectService $os The stub.
	 *
	 * @return InventoryPostingGuard
	 */
	private function makeGuard(InMemoryObjectService $os): InventoryPostingGuard {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createStub(LoggerInterface::class);

		return new InventoryPostingGuard(
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeGuard()

	/**
	 * Seed a healthy config + accounts for the happy-path tests.
	 *
	 * @param InMemoryObjectService $os The stub.
	 * @param string $administrationId Tenant id.
	 * @param bool $isActive Config active flag.
	 *
	 * @return void
	 */
	private function seedHealthyConfig(
		InMemoryObjectService $os,
		string $administrationId = 'adm-1',
		bool $isActive = true,
	): void {
		$os->seed(
			schema: 'InventoryGLConfig',
			rows: [
				[
					'administrationId' => $administrationId,
					'cogsAccountNumber' => '7000',
					'inventoryAssetAccountNumber' => '1400',
					'grIrClearingAccountNumber' => '1800',
					'inventoryAdjustmentAccountNumber' => '7100',
					'isActive' => $isActive,
				],
			]
		);

		$os->seed(
			schema: 'Account',
			rows: [
				['accountNumber' => '7000', 'administrationId' => $administrationId],
				['accountNumber' => '1400', 'administrationId' => $administrationId],
				['accountNumber' => '1800', 'administrationId' => $administrationId],
				['accountNumber' => '7100', 'administrationId' => $administrationId],
			]
		);

	}//end seedHealthyConfig()

	/**
	 * REQ-CG-002 happy path: valuation with unitCost + active config -> canPost = true.
	 *
	 * @return void
	 */
	public function testCanPostPermitsWhenConfigActiveAndUnitCostPresent(): void {
		$os = new InMemoryObjectService();
		$this->seedHealthyConfig(os: $os);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->canPost(
			valuation: [
				'id' => 'iv-1',
				'administrationId' => 'adm-1',
				'unitCost' => 45.00,
			]
		);

		self::assertTrue($result);

	}//end testCanPostPermitsWhenConfigActiveAndUnitCostPresent()

	/**
	 * REQ-CG-002 skip scenario: unitCost null -> canPost = false (unitCost_missing).
	 *
	 * @return void
	 */
	public function testCanPostDeniesWhenUnitCostNull(): void {
		$os = new InMemoryObjectService();
		$this->seedHealthyConfig(os: $os);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->canPost(
			valuation: [
				'id' => 'iv-1',
				'administrationId' => 'adm-1',
				'unitCost' => null,
			]
		);

		self::assertFalse($result);

	}//end testCanPostDeniesWhenUnitCostNull()

	/**
	 * REQ-CG-002 skip scenario: no InventoryGLConfig -> canPost = false (config_missing).
	 *
	 * @return void
	 */
	public function testCanPostDeniesWhenNoConfig(): void {
		$os = new InMemoryObjectService();
		$guard = $this->makeGuard(os: $os);

		$result = $guard->canPost(
			valuation: [
				'id' => 'iv-1',
				'administrationId' => 'adm-1',
				'unitCost' => 45.00,
			]
		);

		self::assertFalse($result);

	}//end testCanPostDeniesWhenNoConfig()

	/**
	 * REQ-CG-002 skip scenario: isActive=false -> canPost = false (posting_disabled).
	 *
	 * @return void
	 */
	public function testCanPostDeniesWhenConfigInactive(): void {
		$os = new InMemoryObjectService();
		$this->seedHealthyConfig(os: $os, isActive: false);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->canPost(
			valuation: [
				'id' => 'iv-1',
				'administrationId' => 'adm-1',
				'unitCost' => 45.00,
			]
		);

		self::assertFalse($result);

	}//end testCanPostDeniesWhenConfigInactive()

	/**
	 * REQ-CG-004 zero-variance scenario: delta == 0 -> canPostVariance = false.
	 *
	 * @return void
	 */
	public function testCanPostVarianceShortCircuitsOnZeroDelta(): void {
		$os = new InMemoryObjectService();
		$this->seedHealthyConfig(os: $os);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->canPostVariance(
			valuation: [
				'id' => 'iv-1',
				'administrationId' => 'adm-1',
				'unitCost' => 8.75,
				'variance' => 0,
			]
		);

		self::assertFalse($result);

	}//end testCanPostVarianceShortCircuitsOnZeroDelta()

	/**
	 * REQ-CG-004 positive variance permitted -> canPostVariance = true.
	 *
	 * @return void
	 */
	public function testCanPostVariancePermitsPositiveDelta(): void {
		$os = new InMemoryObjectService();
		$this->seedHealthyConfig(os: $os);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->canPostVariance(
			valuation: [
				'id' => 'iv-1',
				'administrationId' => 'adm-1',
				'unitCost' => 8.75,
				'variance' => 5,
			]
		);

		self::assertTrue($result);

	}//end testCanPostVariancePermitsPositiveDelta()

	/**
	 * REQ-CG-004 negative variance permitted -> canPostVariance = true.
	 *
	 * @return void
	 */
	public function testCanPostVariancePermitsNegativeDelta(): void {
		$os = new InMemoryObjectService();
		$this->seedHealthyConfig(os: $os);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->canPostVariance(
			valuation: [
				'id' => 'iv-1',
				'administrationId' => 'adm-1',
				'unitCost' => 8.75,
				'variance' => -10,
			]
		);

		self::assertTrue($result);

	}//end testCanPostVariancePermitsNegativeDelta()

	/**
	 * REQ-CG-004: actualQuantity + bookQuantity pair is accepted as the delta source.
	 *
	 * @return void
	 */
	public function testCanPostVarianceAcceptsActualBookPair(): void {
		$os = new InMemoryObjectService();
		$this->seedHealthyConfig(os: $os);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->canPostVariance(
			valuation: [
				'id' => 'iv-1',
				'administrationId' => 'adm-1',
				'unitCost' => 8.75,
				'actualQuantity' => 115,
				'bookQuantity' => 110,
			]
		);

		self::assertTrue($result);

	}//end testCanPostVarianceAcceptsActualBookPair()

	/**
	 * Variance missing entirely -> canPostVariance fails closed.
	 *
	 * @return void
	 */
	public function testCanPostVarianceDeniesWhenDeltaUnresolvable(): void {
		$os = new InMemoryObjectService();
		$this->seedHealthyConfig(os: $os);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->canPostVariance(
			valuation: [
				'id' => 'iv-1',
				'administrationId' => 'adm-1',
				'unitCost' => 8.75,
			]
		);

		self::assertFalse($result);

	}//end testCanPostVarianceDeniesWhenDeltaUnresolvable()

	/**
	 * REQ-CG-004 D4: direction(int) returns 'positive' on positive delta.
	 *
	 * @return void
	 */
	public function testDirectionPositiveReturnsPositive(): void {
		$os = new InMemoryObjectService();
		$guard = $this->makeGuard(os: $os);

		self::assertSame('positive', $guard->direction(delta: 5));
		self::assertSame('positive', $guard->direction(delta: 1));
		self::assertSame('positive', $guard->direction(delta: 999));

	}//end testDirectionPositiveReturnsPositive()

	/**
	 * REQ-CG-004 D4: direction(int) returns 'negative' on negative delta.
	 *
	 * @return void
	 */
	public function testDirectionNegativeReturnsNegative(): void {
		$os = new InMemoryObjectService();
		$guard = $this->makeGuard(os: $os);

		self::assertSame('negative', $guard->direction(delta: -5));
		self::assertSame('negative', $guard->direction(delta: -1));
		self::assertSame('negative', $guard->direction(delta: -999));

	}//end testDirectionNegativeReturnsNegative()

	/**
	 * REQ-CG-004 D4 defensive default: direction(0) returns 'negative' so the
	 * canPostVariance zero-delta short-circuit is the only path that
	 * produces a no-op posting; if a zero leaks through the engine the
	 * action picks the negativeVariance routing (a no-op write of a
	 * 0 EUR adjustment posting) — still balance-safe.
	 *
	 * @return void
	 */
	public function testDirectionZeroDefaultsNegative(): void {
		$os = new InMemoryObjectService();
		$guard = $this->makeGuard(os: $os);

		self::assertSame('negative', $guard->direction(delta: 0));

	}//end testDirectionZeroDefaultsNegative()

	/**
	 * REQ-CG-001: all four account FKs resolve -> accountExists = true.
	 *
	 * @return void
	 */
	public function testAccountExistsPermitsWhenAllAccountsResolve(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			schema: 'Account',
			rows: [
				['accountNumber' => '7000', 'administrationId' => 'adm-1'],
				['accountNumber' => '1400', 'administrationId' => 'adm-1'],
				['accountNumber' => '1800', 'administrationId' => 'adm-1'],
				['accountNumber' => '7100', 'administrationId' => 'adm-1'],
			]
		);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->accountExists(
			proposed: [
				'administrationId' => 'adm-1',
				'cogsAccountNumber' => '7000',
				'inventoryAssetAccountNumber' => '1400',
				'grIrClearingAccountNumber' => '1800',
				'inventoryAdjustmentAccountNumber' => '7100',
			]
		);

		self::assertTrue($result);

	}//end testAccountExistsPermitsWhenAllAccountsResolve()

	/**
	 * REQ-CG-001: any unresolved FK -> accountExists = false.
	 *
	 * @return void
	 */
	public function testAccountExistsDeniesWhenOneAccountMissing(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			schema: 'Account',
			rows: [
				['accountNumber' => '7000', 'administrationId' => 'adm-1'],
				['accountNumber' => '1400', 'administrationId' => 'adm-1'],
				// 1800 deliberately omitted.
				['accountNumber' => '7100', 'administrationId' => 'adm-1'],
			]
		);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->accountExists(
			proposed: [
				'administrationId' => 'adm-1',
				'cogsAccountNumber' => '7000',
				'inventoryAssetAccountNumber' => '1400',
				'grIrClearingAccountNumber' => '1800',
				'inventoryAdjustmentAccountNumber' => '7100',
			]
		);

		self::assertFalse($result);

	}//end testAccountExistsDeniesWhenOneAccountMissing()

	/**
	 * REQ-CG-001: account exists in a DIFFERENT administration -> accountExists = false.
	 *
	 * @return void
	 */
	public function testAccountExistsDeniesOnCrossAdministrationFk(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			schema: 'Account',
			rows: [
				['accountNumber' => '7000', 'administrationId' => 'adm-2'],
				['accountNumber' => '1400', 'administrationId' => 'adm-1'],
				['accountNumber' => '1800', 'administrationId' => 'adm-1'],
				['accountNumber' => '7100', 'administrationId' => 'adm-1'],
			]
		);

		$guard = $this->makeGuard(os: $os);

		$result = $guard->accountExists(
			proposed: [
				'administrationId' => 'adm-1',
				'cogsAccountNumber' => '7000',
				'inventoryAssetAccountNumber' => '1400',
				'grIrClearingAccountNumber' => '1800',
				'inventoryAdjustmentAccountNumber' => '7100',
			]
		);

		self::assertFalse($result);

	}//end testAccountExistsDeniesOnCrossAdministrationFk()

	/**
	 * Empty administrationId yields a permissive return so the
	 * required-fields validator can surface the missing tenant id
	 * with a clearer message than this FK guard.
	 *
	 * @return void
	 */
	public function testAccountExistsPermitsWhenAdministrationIdEmpty(): void {
		$os = new InMemoryObjectService();
		$guard = $this->makeGuard(os: $os);

		$result = $guard->accountExists(
			proposed: [
				'cogsAccountNumber' => '7000',
				'inventoryAssetAccountNumber' => '1400',
				'grIrClearingAccountNumber' => '1800',
				'inventoryAdjustmentAccountNumber' => '7100',
			]
		);

		self::assertTrue($result);

	}//end testAccountExistsPermitsWhenAdministrationIdEmpty()

	/**
	 * Empty FK field is skipped — required-fields validator handles it.
	 *
	 * @return void
	 */
	public function testAccountExistsSkipsEmptyFields(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			schema: 'Account',
			rows: [
				['accountNumber' => '7000', 'administrationId' => 'adm-1'],
				['accountNumber' => '1400', 'administrationId' => 'adm-1'],
				['accountNumber' => '1800', 'administrationId' => 'adm-1'],
			]
		);

		$guard = $this->makeGuard(os: $os);

		// Only the populated FKs are checked; the empty one is skipped.
		$result = $guard->accountExists(
			proposed: [
				'administrationId' => 'adm-1',
				'cogsAccountNumber' => '7000',
				'inventoryAssetAccountNumber' => '1400',
				'grIrClearingAccountNumber' => '1800',
				'inventoryAdjustmentAccountNumber' => '',
			]
		);

		self::assertTrue($result);

	}//end testAccountExistsSkipsEmptyFields()

}//end class
