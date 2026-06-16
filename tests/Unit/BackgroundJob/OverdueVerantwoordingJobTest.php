<?php

/**
 * Unit tests for OverdueVerantwoordingJob.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\OverdueVerantwoordingJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the pure overdue rule (REQ-SUBV-010): a non-final accountability report
 * is overdue more than 90 days after grant award.
 */
class OverdueVerantwoordingJobTest extends TestCase
{
    /**
     * The job under test.
     *
     * @var OverdueVerantwoordingJob
     */
    private OverdueVerantwoordingJob $job;

    /**
     * Set up the job with mocked dependencies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $time      = $this->createMock(ITimeFactory::class);
        $container = $this->createMock(ContainerInterface::class);
        $appConfig = $this->createMock(IAppConfig::class);
        $notifier  = $this->createMock(INotificationManager::class);
        $logger    = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        $this->job = new OverdueVerantwoordingJob(
            time: $time,
            container: $container,
            appConfig: $appConfig,
            notificationManager: $notifier,
            logger: $logger,
        );

    }//end setUp()

    /**
     * A draft report more than 90 days after award is overdue (REQ-SUBV-010).
     *
     * @return void
     */
    public function testDraftBeyond90DaysIsOverdue(): void
    {
        $now            = new \DateTimeImmutable('2026-06-01');
        $verantwoording = ['status' => 'draft'];

        self::assertTrue(
            $this->job->isOverdue(verantwoording: $verantwoording, now: $now, awardDate: '2026-01-01')
        );

    }//end testDraftBeyond90DaysIsOverdue()

    /**
     * A submitted report within 90 days is NOT overdue (REQ-SUBV-010).
     *
     * @return void
     */
    public function testSubmittedWithin90DaysNotOverdue(): void
    {
        $now            = new \DateTimeImmutable('2026-03-01');
        $verantwoording = ['status' => 'submitted'];

        self::assertFalse(
            $this->job->isOverdue(verantwoording: $verantwoording, now: $now, awardDate: '2026-01-01')
        );

    }//end testSubmittedWithin90DaysNotOverdue()

    /**
     * A final report is never overdue regardless of age (REQ-SUBV-010).
     *
     * @return void
     */
    public function testFinalReportNeverOverdue(): void
    {
        $now            = new \DateTimeImmutable('2027-01-01');
        $verantwoording = ['status' => 'final'];

        self::assertFalse(
            $this->job->isOverdue(verantwoording: $verantwoording, now: $now, awardDate: '2024-01-01')
        );

    }//end testFinalReportNeverOverdue()

    /**
     * The 90-day boundary is exclusive: exactly 90 days is not yet overdue,
     * 91 days is overdue (REQ-SUBV-010).
     *
     * @return void
     */
    public function testNinetyDayBoundary(): void
    {
        $award = '2026-01-01';

        $day90 = new \DateTimeImmutable('2026-04-01');
        self::assertFalse(
            $this->job->isOverdue(verantwoording: ['status' => 'draft'], now: $day90, awardDate: $award)
        );

        $day91 = new \DateTimeImmutable('2026-04-02');
        self::assertTrue(
            $this->job->isOverdue(verantwoording: ['status' => 'draft'], now: $day91, awardDate: $award)
        );

    }//end testNinetyDayBoundary()

    /**
     * The award date falls back to the reportingPeriod start when not supplied (REQ-SUBV-010).
     *
     * @return void
     */
    public function testFallsBackToReportingPeriodStart(): void
    {
        $now            = new \DateTimeImmutable('2026-06-01');
        $verantwoording = ['status' => 'approved', 'reportingPeriod' => '2026-01-01 to 2026-02-01'];

        self::assertTrue($this->job->isOverdue(verantwoording: $verantwoording, now: $now));

    }//end testFallsBackToReportingPeriodStart()

    /**
     * A record with no parseable award reference is never overdue (fail-safe).
     *
     * @return void
     */
    public function testUnparseableDateNeverOverdue(): void
    {
        $now            = new \DateTimeImmutable('2026-06-01');
        $verantwoording = ['status' => 'draft', 'reportingPeriod' => ''];

        self::assertFalse($this->job->isOverdue(verantwoording: $verantwoording, now: $now));

    }//end testUnparseableDateNeverOverdue()
}//end class
