<?php

/**
 * Unit tests for SoftCloseSwingCheck.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-continuous-close/spec.md#requirement-the-nightly-soft-close-flags-the-swings-per-account-req-cls-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TrialBalanceServiceInterface;
use OCA\Shillinq\Service\FluxService;
use OCA\Shillinq\Service\SoftCloseSwingCheck;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The nightly swing check compares each account's movement with the period
 * before and stores a flux run through the real FluxService (REQ-CLS-011).
 */
#[CoversClass(SoftCloseSwingCheck::class)]
#[UsesClass(FluxService::class)]
final class SoftCloseSwingCheckTest extends TestCase {

	/**
	 * Captured saveObject calls as [schema, object].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * The periods the trial balance was asked for.
	 *
	 * @var string[]
	 */
	private array $periodsRead = [];

	/**
	 * Build the check over a trial balance per period and a materiality policy store.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rowsPerPeriod Trial balance rows per period.
	 * @param array<int,mixed>|RuntimeException            $policies      What findAll answers for MaterialityPolicy.
	 *
	 * @return SoftCloseSwingCheck
	 */
	private function check(array $rowsPerPeriod, array|RuntimeException $policies = []): SoftCloseSwingCheck {
		$trialBalance = $this->createMock(TrialBalanceServiceInterface::class);
		$trialBalance->method('compute')->willReturnCallback(
			function (string $administrationId, string $periodId) use ($rowsPerPeriod): array {
				$this->periodsRead[] = $administrationId . '@' . $periodId;
				return ['data' => ($rowsPerPeriod[$periodId] ?? [])];
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('books');

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		if ($policies instanceof RuntimeException) {
			$objectService->method('findAll')->willThrowException($policies);
		} else {
			$objectService->method('findAll')->willReturn($policies);
		}

		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null): ObjectEntityInterface {
				$this->saves[] = [(string)$schema, $object];
				return $this->createStub(ObjectEntityInterface::class);
			}
		);

		$logger = $this->createStub(LoggerInterface::class);
		$flux = new FluxService(appConfig: $config, logger: $logger, objectService: $objectService);

		return new SoftCloseSwingCheck(
			trialBalance: $trialBalance,
			flux: $flux,
			objectService: $objectService,
			appConfig: $config,
			logger: $logger,
		);
	}//end check()

	/**
	 * One trial balance row.
	 *
	 * @param string $account The account number.
	 * @param float  $debit   The debit movement.
	 * @param float  $credit  The credit movement.
	 *
	 * @return array<string,mixed>
	 */
	private static function row(string $account, float $debit, float $credit = 0.0): array {
		return ['accountNumber' => $account, 'debitMovement' => $debit, 'creditMovement' => $credit];
	}//end row()

	/**
	 * The saved FluxItem rows by account number.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function itemsByAccount(): array {
		$items = [];
		foreach ($this->saves as [$schema, $object]) {
			if ($schema === 'FluxItem') {
				$items[(string)$object['glAccountNumber']] = $object;
			}
		}

		return $items;
	}//end itemsByAccount()

	/**
	 * The spec scenario: 4000 moved EUR 30,000 against EUR 8,000 a month earlier, threshold 25 percent.
	 *
	 * @return void
	 */
	public function testALargeSwingOnOneAccountIsFlagged(): void {
		$check = $this->check(
			rowsPerPeriod: [
				'2026-10' => [self::row(account: '4000', debit: 30000.0), self::row(account: '4100', debit: 1050.0)],
				'2026-09' => [self::row(account: '4000', debit: 8000.0), self::row(account: '4100', debit: 1000.0)],
			],
			policies: [
				['administrationId' => 'adm-1', 'accountGroupCode' => 'operational', 'absoluteThresholdCents' => 0, 'percentageThreshold' => 0.25],
			]
		);

		$summary = $check->run(administrationId: 'adm-1', periodId: '2026-10', asOf: new DateTimeImmutable('2026-11-01T02:00:00+00:00'));

		self::assertSame(['adm-1@2026-10', 'adm-1@2026-09'], $this->periodsRead);
		self::assertSame(2, $summary['itemCount']);
		self::assertSame(1, $summary['materialCount']);

		$items = $this->itemsByAccount();
		self::assertSame(3000000, $items['4000']['actualCents']);
		self::assertSame(800000, $items['4000']['budgetCents']);
		self::assertSame(2200000, $items['4000']['varianceCents']);
		// EUR 22,000 over a threshold of 25 percent of EUR 8,000 is more than ten times it.
		self::assertSame('highly-material', $items['4000']['materialityClassification']);
		self::assertSame('escalated', $items['4000']['status']);
		self::assertSame('immaterial', $items['4100']['materialityClassification']);

		self::assertSame('FluxRun', $this->saves[0][0]);
		$run = $this->saves[0][1];
		self::assertSame('flux-adm-1-2026-10-020000', $run['fluxRunId']);
		self::assertSame($run['fluxRunId'], $items['4000']['fluxRunId']);
		self::assertSame(['adm-1', '2026-10', 'administration', 'prior-period', 0.25], [
			$run['administrationId'],
			$run['periodId'],
			$run['scope'],
			$run['comparisonBasis'],
			$run['materialityPercentage'],
		]);
	}//end testALargeSwingOnOneAccountIsFlagged()

	/**
	 * Credits count against debits, an account that moved in one period only is compared with zero, and a still account is left out.
	 *
	 * @return void
	 */
	public function testNetMovementsArePairedAcrossBothPeriods(): void {
		$check = $this->check(
			rowsPerPeriod: [
				'2026-01' => [self::row(account: '8000', debit: 10.0, credit: 250.5), self::row(account: '1000', debit: 0.0), self::row(account: '', debit: 5.0)],
				'2025-12' => [self::row(account: '9000', debit: 40.0)],
			]
		);

		$check->run(administrationId: 'adm-2', periodId: '2026-01', asOf: new DateTimeImmutable('2026-02-01T02:00:00+00:00'));

		self::assertSame(['adm-2@2026-01', 'adm-2@2025-12'], $this->periodsRead);
		$items = $this->itemsByAccount();
		self::assertSame(['8000', '9000'], [$items[8000]['glAccountNumber'], $items[9000]['glAccountNumber']]);
		self::assertCount(2, $items);
		self::assertSame([-24050, 0], [$items['8000']['actualCents'], $items['8000']['budgetCents']]);
		self::assertSame([0, 4000], [$items['9000']['actualCents'], $items['9000']['budgetCents']]);
	}//end testNetMovementsArePairedAcrossBothPeriods()

	/**
	 * Another administration's policy is ignored, and a policy for the default group wins over another group's.
	 *
	 * @return void
	 */
	public function testThePolicyIsTheAdministrationsOwn(): void {
		$check = $this->check(
			rowsPerPeriod: ['2026-05' => [self::row(account: '4000', debit: 100.0)]],
			policies: [
				['administrationId' => 'other', 'accountGroupCode' => 'operational', 'absoluteThresholdCents' => 1, 'percentageThreshold' => 0.9],
				['administrationId' => 'adm-1', 'accountGroupCode' => 'cash', 'absoluteThresholdCents' => 2, 'percentageThreshold' => 0.5],
				['administrationId' => 'adm-1', 'accountGroupCode' => 'operational', 'absoluteThresholdCents' => 3, 'percentageThreshold' => 0.1],
			]
		);

		$check->run(administrationId: 'adm-1', periodId: '2026-05', asOf: new DateTimeImmutable('2026-06-01T02:00:00+00:00'));

		self::assertSame([3, 0.1], [$this->saves[0][1]['materialityAbsoluteCents'], $this->saves[0][1]['materialityPercentage']]);
	}//end testThePolicyIsTheAdministrationsOwn()

	/**
	 * A failing policy lookup runs the check without a threshold, which surfaces every account that moved.
	 *
	 * @return void
	 */
	public function testAFailingPolicyLookupSurfacesEverySwing(): void {
		$check = $this->check(
			rowsPerPeriod: ['2026-05' => [self::row(account: '4000', debit: 1.0)], '2026-04' => [self::row(account: '4000', debit: 1.0)]],
			policies: new RuntimeException('store down')
		);

		$summary = $check->run(administrationId: 'adm-1', periodId: '2026-05', asOf: new DateTimeImmutable('2026-06-01T02:00:00+00:00'));

		self::assertSame([0, 0.0], [$this->saves[0][1]['materialityAbsoluteCents'], $this->saves[0][1]['materialityPercentage']]);
		self::assertSame(0, $summary['materialCount']);
		self::assertSame('immaterial', $this->itemsByAccount()['4000']['materialityClassification']);
	}//end testAFailingPolicyLookupSurfacesEverySwing()

	/**
	 * A period that is not yyyy-mm runs nothing.
	 *
	 * @return void
	 */
	public function testAPeriodThatIsNotAMonthRunsNothing(): void {
		$check = $this->check(rowsPerPeriod: []);

		self::assertNull($check->run(administrationId: 'adm-1', periodId: '2026-Q4', asOf: new DateTimeImmutable()));
		self::assertNull($check->run(administrationId: 'adm-1', periodId: '2026-13', asOf: new DateTimeImmutable()));
		self::assertSame([], $this->periodsRead);
		self::assertSame([], $this->saves);
	}//end testAPeriodThatIsNotAMonthRunsNothing()

	/**
	 * The period before January is December of the year before.
	 *
	 * @return void
	 */
	public function testThePriorPeriodCrossesTheYear(): void {
		self::assertSame('2025-12', SoftCloseSwingCheck::priorPeriod(periodId: '2026-01'));
		self::assertSame('2026-09', SoftCloseSwingCheck::priorPeriod(periodId: '2026-10'));
		self::assertNull(SoftCloseSwingCheck::priorPeriod(periodId: '2026-00'));
	}//end testThePriorPeriodCrossesTheYear()
}//end class
