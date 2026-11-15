<?php

/**
 * Unit tests for DualGaapGuard edge paths.
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
 * @spec openspec/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\DualGaapGuard;
use OCA\Shillinq\Lifecycle\GuardObjectLookup;
use OCA\Shillinq\Lifecycle\GuardSubject;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Missing records and failing lookups deny; the RJk variant is held to the size criteria.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(DualGaapGuard::class)]
#[UsesClass(GuardObjectLookup::class)]
#[UsesClass(GuardSubject::class)]
final class DualGaapGuardEdgesTest extends TestCase {

	/**
	 * A guard whose lookup returns the given rows, or throws.
	 *
	 * @param array<int,mixed>|\Throwable $rows   The rows findAll returns, or what it throws.
	 * @param LoggerInterface|null        $logger The logger, or a silent mock.
	 *
	 * @return DualGaapGuard
	 */
	private function guard(array|\Throwable $rows, ?LoggerInterface $logger = null): DualGaapGuard {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		if ($rows instanceof \Throwable) {
			$objectService->method('findAll')->willThrowException($rows);
		} else {
			$objectService->method('findAll')->willReturn($rows);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new DualGaapGuard(
			appConfig: $appConfig,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			objectService: $objectService,
		);
	}//end guard()

	/**
	 * A transaction or election that is not found cannot move.
	 *
	 * @return void
	 */
	public function testMissingRecordsAreDenied(): void {
		$guard = $this->guard([]);

		self::assertFalse($guard->canReconcileTransaction('tx-1'));
		self::assertFalse($guard->canActivateElection('el-1'));
		self::assertFalse($guard->canActivateElection(''));
	}//end testMissingRecordsAreDenied()

	/**
	 * A failing reconcile lookup denies and logs the transaction.
	 *
	 * @return void
	 */
	public function testAFailingReconcileLookupDenies(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'DualGaapGuard: reconcile check failed — denying transition (fail-closed)',
			['transactionId' => 'tx-1', 'exception' => 'register down']
		);

		self::assertFalse($this->guard(new \RuntimeException('register down'), $logger)->canReconcileTransaction('tx-1'));
	}//end testAFailingReconcileLookupDenies()

	/**
	 * A failing election lookup denies and logs the election.
	 *
	 * @return void
	 */
	public function testAFailingElectionLookupDenies(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'DualGaapGuard: activate-election check failed — denying transition (fail-closed)',
			['electionId' => 'el-1', 'exception' => 'register down']
		);

		self::assertFalse($this->guard(new \RuntimeException('register down'), $logger)->canActivateElection('el-1'));
	}//end testAFailingElectionLookupDenies()

	/**
	 * The RJk variant is allowed only within the small-entity size criteria; a looked-up election is used.
	 *
	 * @return void
	 */
	public function testTheSmallEntityVariantIsHeldToTheSizeCriteria(): void {
		$base = ['complyOrExplainMotivation' => 'Small entity', 'avaDecisionReference' => 'AVA-2026-1', 'rjVariant' => 'RJk'];

		$atTheLimits = $base + ['sizeCriteriaBalanceSheetTotal' => '6000000', 'sizeCriteriaNetRevenue' => 12000000];
		self::assertTrue($this->guard([$atTheLimits])->canActivateElection('el-1'));
		self::assertFalse($this->guard([])->canActivateElection('el-1', $base + ['sizeCriteriaBalanceSheetTotal' => 6000001]));
		self::assertFalse($this->guard([])->canActivateElection('el-1', $base + ['sizeCriteriaNetRevenue' => '12000000.01']));
		self::assertTrue($this->guard([])->canActivateElection('el-1', $base + ['sizeCriteriaBalanceSheetTotal' => 'n/a']));
	}//end testTheSmallEntityVariantIsHeldToTheSizeCriteria()
}//end class
