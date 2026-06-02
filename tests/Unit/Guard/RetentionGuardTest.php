<?php

/**
 * Unit tests for RetentionGuard.
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
 * @spec openspec/changes/bookkeeping-archiefwet-retention/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\RetentionGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RetentionGuard.
 *
 * Covers:
 * - requiresReview: due/not-due, legal-hold suspension, missing date fail-closed
 * - allowsDisposal: past/future end date, legal-hold suspension, missing date
 * - fail-closed on unparseable dates
 */
class RetentionGuardTest extends TestCase
{

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The guard under test.
     *
     * @var RetentionGuard
     */
    private RetentionGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->guard  = new RetentionGuard(logger: $this->logger);

    }//end setUp()

    /**
     * requiresReview permits review when the review-due date is in the past and no hold.
     *
     * @return void
     */
    public function testRequiresReviewPermitsWhenDue(): void
    {
        $doc = [
            'documentId'    => 'inv-1',
            'reviewDueDate' => $this->daysFromToday(-5),
            'legalHold'     => false,
        ];

        self::assertTrue($this->guard->requiresReview($doc));

    }//end testRequiresReviewPermitsWhenDue()

    /**
     * requiresReview denies review when the review-due date is still in the future.
     *
     * @return void
     */
    public function testRequiresReviewDeniesWhenNotYetDue(): void
    {
        $doc = [
            'documentId'    => 'inv-1',
            'reviewDueDate' => $this->daysFromToday(30),
            'legalHold'     => false,
        ];

        self::assertFalse($this->guard->requiresReview($doc));

    }//end testRequiresReviewDeniesWhenNotYetDue()

    /**
     * requiresReview denies review while a legal hold is active, even if due (REQ-RET-005).
     *
     * @return void
     */
    public function testRequiresReviewDeniedByLegalHold(): void
    {
        $doc = [
            'documentId'    => 'inv-1',
            'reviewDueDate' => $this->daysFromToday(-5),
            'legalHold'     => true,
        ];

        self::assertFalse($this->guard->requiresReview($doc));

    }//end testRequiresReviewDeniedByLegalHold()

    /**
     * requiresReview is fail-closed when no review date is set.
     *
     * @return void
     */
    public function testRequiresReviewFailsClosedWithoutDate(): void
    {
        self::assertFalse($this->guard->requiresReview(['documentId' => 'inv-1']));

    }//end testRequiresReviewFailsClosedWithoutDate()

    /**
     * allowsDisposal permits disposal when retention end date has passed and no hold.
     *
     * @return void
     */
    public function testAllowsDisposalPermitsAfterEndDate(): void
    {
        $doc = [
            'documentId'       => 'inv-1',
            'retentionEndDate' => $this->daysFromToday(-1),
            'legalHold'        => false,
        ];

        self::assertTrue($this->guard->allowsDisposal($doc));

    }//end testAllowsDisposalPermitsAfterEndDate()

    /**
     * allowsDisposal denies disposal before the retention end date (REQ-RET-008).
     *
     * @return void
     */
    public function testAllowsDisposalDeniesBeforeEndDate(): void
    {
        $doc = [
            'documentId'       => 'inv-1',
            'retentionEndDate' => $this->daysFromToday(10),
            'legalHold'        => false,
        ];

        self::assertFalse($this->guard->allowsDisposal($doc));

    }//end testAllowsDisposalDeniesBeforeEndDate()

    /**
     * allowsDisposal is suspended by a legal hold regardless of end date (REQ-RET-005).
     *
     * @return void
     */
    public function testAllowsDisposalSuspendedByLegalHold(): void
    {
        $doc = [
            'documentId'       => 'inv-1',
            'retentionEndDate' => $this->daysFromToday(-100),
            'legalHold'        => true,
        ];

        self::assertFalse($this->guard->allowsDisposal($doc));

    }//end testAllowsDisposalSuspendedByLegalHold()

    /**
     * allowsDisposal is fail-closed when no end date is set.
     *
     * @return void
     */
    public function testAllowsDisposalFailsClosedWithoutDate(): void
    {
        self::assertFalse($this->guard->allowsDisposal(['documentId' => 'inv-1']));

    }//end testAllowsDisposalFailsClosedWithoutDate()

    /**
     * Both guards fail closed on an unparseable date string.
     *
     * @return void
     */
    public function testGuardsFailClosedOnUnparseableDate(): void
    {
        self::assertFalse(
            $this->guard->requiresReview(['reviewDueDate' => 'not-a-date', 'legalHold' => false])
        );
        self::assertFalse(
            $this->guard->allowsDisposal(['retentionEndDate' => 'not-a-date', 'legalHold' => false])
        );

    }//end testGuardsFailClosedOnUnparseableDate()

    /**
     * Produce an ISO date string offset from today by the given number of days.
     *
     * @param int $days Offset in days (negative = past).
     *
     * @return string ISO-8601 date (YYYY-MM-DD).
     */
    private function daysFromToday(int $days): string
    {
        $utc  = new \DateTimeZone('UTC');
        $date = (new \DateTimeImmutable('today', $utc))->modify($days.' days');
        return $date->format('Y-m-d');

    }//end daysFromToday()
}//end class
