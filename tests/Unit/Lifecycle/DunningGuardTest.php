<?php

/**
 * Unit tests for DunningGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-accounts-receivable-core/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\DunningGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DunningGuard cadence evaluation (REQ-AR-005).
 */
class DunningGuardTest extends TestCase
{

    /**
     * The guard under test.
     *
     * @var DunningGuard
     */
    private DunningGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new DunningGuard();

    }//end setUp()

    /**
     * Below the first reminder threshold returns null.
     *
     * @return void
     */
    public function testBelowFirstThresholdReturnsNull(): void
    {
        self::assertNull($this->guard->levelForDaysPastDue(13));

    }//end testBelowFirstThresholdReturnsNull()

    /**
     * Each cadence threshold maps to the correct escalation level.
     *
     * @return void
     */
    public function testCadenceLevels(): void
    {
        self::assertSame('reminder1', $this->guard->levelForDaysPastDue(14));
        self::assertSame('reminder1', $this->guard->levelForDaysPastDue(29));
        self::assertSame('reminder2', $this->guard->levelForDaysPastDue(30));
        self::assertSame('reminder2', $this->guard->levelForDaysPastDue(44));
        self::assertSame('formal-notice', $this->guard->levelForDaysPastDue(45));
        self::assertSame('formal-notice', $this->guard->levelForDaysPastDue(59));
        self::assertSame('collection', $this->guard->levelForDaysPastDue(60));
        self::assertSame('collection', $this->guard->levelForDaysPastDue(120));

    }//end testCadenceLevels()
}//end class
