<?php

/**
 * Unit tests for SlotService.
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
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SlotService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers the pure slot computation (REQ-WSW-002): operational-hour bounding,
 * conflict exclusion, past-slot filtering, and step granularity.
 */
class SlotServiceTest extends TestCase
{
    /**
     * The service under test (pure-logic methods need no live OR).
     *
     * @var SlotService
     */
    private SlotService $service;

    /**
     * Build the service with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $container    = $this->createMock(ContainerInterface::class);
        $appConfig    = $this->createMock(IAppConfig::class);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $logger       = $this->createMock(LoggerInterface::class);
        $appConfig->method('getValueString')->willReturn('shillinq');

        $this->service = new SlotService(
            container: $container,
            appConfig: $appConfig,
            cacheFactory: $cacheFactory,
            logger: $logger,
        );

    }//end setUp()

    /**
     * 09:00-18:00 with a 45-min service yields back-to-back slots, none past close.
     *
     * @return void
     */
    public function testComputeSlotsFillsOperationalHours(): void
    {
        $slots = $this->service->computeSlots(540, 1080, 45, [], -1, 45);

        self::assertSame(540, $slots[0]['startMinutes']);
        self::assertSame(585, $slots[0]['endMinutes']);
        // Last slot must end at or before close (1080 = 18:00).
        $last = end($slots);
        self::assertLessThanOrEqual(1080, $last['endMinutes']);

    }//end testComputeSlotsFillsOperationalHours()

    /**
     * A booked 10:00-10:45 interval is excluded; adjacent slots remain.
     *
     * @return void
     */
    public function testComputeSlotsExcludesBookedInterval(): void
    {
        // booked 10:00-10:45 == [600,645). On a 45-min grid from 09:00 the
        // candidate slots are 540, 585, 630, 675, … The 630 (10:30) slot spans
        // [630,675) and overlaps the booking, so it is excluded; 675 (11:15) is free.
        $slots  = $this->service->computeSlots(540, 1080, 45, [[600, 645]], -1, 45);
        $starts = array_column($slots, 'startMinutes');

        self::assertContains(540, $starts, '09:00 should be available');
        self::assertNotContains(630, $starts, '10:30 overlaps the booking and must be excluded');
        self::assertContains(675, $starts, '11:15 should be available');

    }//end testComputeSlotsExcludesBookedInterval()

    /**
     * Past slots (before nowMinutes) are filtered out.
     *
     * @return void
     */
    public function testComputeSlotsFiltersPastTimes(): void
    {
        // now == 12:00 (720). Everything before noon excluded.
        $slots  = $this->service->computeSlots(540, 1080, 60, [], 720, 60);
        $starts = array_column($slots, 'startMinutes');

        foreach ($starts as $start) {
            self::assertGreaterThanOrEqual(720, $start, 'No slot may start before now');
        }
        self::assertContains(720, $starts, 'A slot at exactly now is allowed');

    }//end testComputeSlotsFiltersPastTimes()

    /**
     * A partial slot that would overrun closing time is excluded.
     *
     * @return void
     */
    public function testComputeSlotsRespectsClosingTime(): void
    {
        // open 09:00 (540), close 10:00 (600), 45-min service: only 09:00 fits.
        $slots = $this->service->computeSlots(540, 600, 45, [], -1, 45);

        self::assertCount(1, $slots);
        self::assertSame(540, $slots[0]['startMinutes']);

    }//end testComputeSlotsRespectsClosingTime()

    /**
     * Invalid bounds or non-positive duration yield no slots.
     *
     * @return void
     */
    public function testComputeSlotsHandlesDegenerateInput(): void
    {
        self::assertSame([], $this->service->computeSlots(600, 540, 30, []));
        self::assertSame([], $this->service->computeSlots(540, 1080, 0, []));

    }//end testComputeSlotsHandlesDegenerateInput()

    /**
     * getAvailableSlots returns service_unavailable when OR is absent (fail-safe).
     *
     * @return void
     */
    public function testGetAvailableSlotsWithoutOpenRegister(): void
    {
        $result = $this->service->getAvailableSlots('haircut', '2026-05-22', 'salon-demo');

        self::assertArrayHasKey('error', $result);
        self::assertSame('service_unavailable', $result['error']);

    }//end testGetAvailableSlotsWithoutOpenRegister()
}//end class
