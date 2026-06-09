<?php

/**
 * Unit tests for SoftCloseExecutor — accrual calculation methods.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-30
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SoftCloseExecutor;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Validates the five accrual calculation methods on SoftCloseExecutor (REQ-CLS-003).
 *
 * Calculations are pure functions — no DI required beyond a stub container +
 * stub config — they only consume the rule + run context.
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-30
 */
final class SoftCloseExecutorTest extends TestCase
{
    /**
     * Build an executor with stub DI.
     *
     * @return SoftCloseExecutor
     */
    private function executor(): SoftCloseExecutor
    {
        $container = $this->createStub(ContainerInterface::class);
        $config    = $this->createStub(IAppConfig::class);
        $config->method('getValueString')->willReturn('shillinq');
        return new SoftCloseExecutor($container, $config, new NullLogger());

    }//end executor()

    /**
     * Fixed amount accrual: 12K EUR rent = 1,200,000 cents.
     *
     * @return void
     */
    public function testFixedAmountAccrualReturnsConfiguredCents(): void
    {
        $e    = $this->executor();
        $rule = ['calculationMethod' => 'fixed-amount', 'calculationParameters' => ['amountCents' => 1200000]];
        self::assertSame(1200000, $e->computeAccrualCents(rule: $rule, context: []));

    }//end testFixedAmountAccrualReturnsConfiguredCents()

    /**
     * Percentage-of-revenue: 3% of EUR 450,000 MTD revenue = EUR 13,500.
     *
     * @return void
     */
    public function testPercentageOfRevenueAccrual(): void
    {
        $e       = $this->executor();
        $rule    = ['calculationMethod' => 'percentage-of-revenue', 'calculationParameters' => ['rate' => 0.03, 'sourceField' => 'revenue_mtd']];
        $context = ['revenueMtdCents' => 45000000];
        self::assertSame(1350000, $e->computeAccrualCents(rule: $rule, context: $context));

    }//end testPercentageOfRevenueAccrual()

    /**
     * Straight-line-from-contract: EUR 100K loan × 5% annual / 365 days × 17 days ≈ EUR 232.88.
     *
     * @return void
     */
    public function testStraightLineFromContractAccrual(): void
    {
        $e       = $this->executor();
        $rule    = ['calculationMethod' => 'straight-line-from-contract', 'calculationParameters' => ['principalCents' => 10000000, 'annualRate' => 0.05, 'dayCount' => 365]];
        $context = ['daysElapsed' => 17];
        // 10000000 × 0.05 × 17 / 365 = 23287.67 -> round = 23288 cents.
        self::assertSame(23288, $e->computeAccrualCents(rule: $rule, context: $context));

    }//end testStraightLineFromContractAccrual()

    /**
     * Days-elapsed: monthly 12K, 17 days of 31 = 6,580 EUR.
     *
     * @return void
     */
    public function testDaysElapsedOfPeriodAccrual(): void
    {
        $e       = $this->executor();
        $rule    = ['calculationMethod' => 'days-elapsed-of-period', 'calculationParameters' => ['monthlyAmountCents' => 1200000]];
        $context = ['daysElapsed' => 17, 'daysInPeriod' => 31];
        // 1200000 × 17 / 31 = 658064.5 → 658065 cents.
        self::assertSame(658065, $e->computeAccrualCents(rule: $rule, context: $context));

    }//end testDaysElapsedOfPeriodAccrual()

    /**
     * External-lookup: amount injected via run context.
     *
     * @return void
     */
    public function testExternalLookupAccrualReadsContext(): void
    {
        $e       = $this->executor();
        $rule    = ['calculationMethod' => 'external-lookup', 'calculationParameters' => ['source' => 'payroll-calendar']];
        $context = ['lookupAmountCents' => 820000];
        self::assertSame(820000, $e->computeAccrualCents(rule: $rule, context: $context));

    }//end testExternalLookupAccrualReadsContext()

    /**
     * Unknown calculation method returns 0 cents (fail-closed).
     *
     * @return void
     */
    public function testUnknownMethodReturnsZero(): void
    {
        $e    = $this->executor();
        self::assertSame(0, $e->computeAccrualCents(rule: ['calculationMethod' => 'bogus'], context: []));

    }//end testUnknownMethodReturnsZero()

    /**
     * Negative parameters are clamped to 0 (REQ-CLS-003 — no negative accruals).
     *
     * @return void
     */
    public function testNegativeFixedAmountClampsToZero(): void
    {
        $e    = $this->executor();
        $rule = ['calculationMethod' => 'fixed-amount', 'calculationParameters' => ['amountCents' => -500]];
        self::assertSame(0, $e->computeAccrualCents(rule: $rule, context: []));

    }//end testNegativeFixedAmountClampsToZero()
}
