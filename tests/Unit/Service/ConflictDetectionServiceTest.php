<?php

/**
 * Unit tests for ConflictDetectionService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ConflictDetectionService;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the interval-overlap conflict detection core (REQ-004).
 *
 * @covers \OCA\Shillinq\Service\ConflictDetectionService
 *
 * @spec openspec/changes/bookings-resource-calendar/specs/bookings/spec.md#req-004-conflict-detection-for-double-booking-prevention
 */
class ConflictDetectionServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var ConflictDetectionService
     */
    private ConflictDetectionService $service;

    /**
     * Mock container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock settings service.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settings;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->settings  = $this->createMock(SettingsService::class);
        $logger          = $this->createMock(LoggerInterface::class);

        $this->service = new ConflictDetectionService(
            container: $this->container,
            settings: $this->settings,
            logger: $logger
        );

    }//end setUp()

    /**
     * Build a booking row fixture.
     *
     * @param string $id     Booking id.
     * @param string $start  Start (ISO-8601 UTC).
     * @param string $end    End (ISO-8601 UTC).
     * @param string $status Status.
     *
     * @return array<string,mixed>
     */
    private function booking(string $id, string $start, string $end, string $status='confirmed'): array
    {
        return [
            'id'        => $id,
            'startTime' => $start,
            'endTime'   => $end,
            'status'    => $status,
        ];

    }//end booking()

    /**
     * REQ-004: overlapping intervals are detected.
     *
     * @return void
     */
    public function testIntervalsOverlapTrueOnOverlap(): void
    {
        // A 10:00-10:30, B 10:15-11:00 overlap.
        $a = [strtotime('2026-05-21T10:00:00Z'), strtotime('2026-05-21T10:30:00Z')];
        $b = [strtotime('2026-05-21T10:15:00Z'), strtotime('2026-05-21T11:00:00Z')];
        self::assertTrue(
            ConflictDetectionService::intervalsOverlap(aStart: $a[0], aEnd: $a[1], bStart: $b[0], bEnd: $b[1])
        );

    }//end testIntervalsOverlapTrueOnOverlap()

    /**
     * REQ-004 edge case: adjacent touching intervals do NOT overlap.
     *
     * @return void
     */
    public function testIntervalsOverlapFalseOnAdjacent(): void
    {
        // A 10:00-10:30, B 10:30-11:00 are adjacent, not overlapping.
        $a = [strtotime('2026-05-21T10:00:00Z'), strtotime('2026-05-21T10:30:00Z')];
        $b = [strtotime('2026-05-21T10:30:00Z'), strtotime('2026-05-21T11:00:00Z')];
        self::assertFalse(
            ConflictDetectionService::intervalsOverlap(aStart: $a[0], aEnd: $a[1], bStart: $b[0], bEnd: $b[1])
        );

    }//end testIntervalsOverlapFalseOnAdjacent()

    /**
     * REQ-004: fully disjoint intervals do not overlap.
     *
     * @return void
     */
    public function testIntervalsOverlapFalseOnDisjoint(): void
    {
        $a = [strtotime('2026-05-21T09:00:00Z'), strtotime('2026-05-21T09:30:00Z')];
        $b = [strtotime('2026-05-21T10:00:00Z'), strtotime('2026-05-21T10:30:00Z')];
        self::assertFalse(
            ConflictDetectionService::intervalsOverlap(aStart: $a[0], aEnd: $a[1], bStart: $b[0], bEnd: $b[1])
        );

    }//end testIntervalsOverlapFalseOnDisjoint()

    /**
     * REQ-008: toEpoch parses a UTC timestamp; equal UTC instants compare equal.
     *
     * @return void
     */
    public function testToEpochParsesUtcAndEqualInstants(): void
    {
        // 10:00Z and 12:00+02:00 are the same UTC instant.
        self::assertSame(
            ConflictDetectionService::toEpoch('2026-05-21T10:00:00Z'),
            ConflictDetectionService::toEpoch('2026-05-21T12:00:00+02:00')
        );
        self::assertNull(ConflictDetectionService::toEpoch('not-a-date'));
        self::assertNull(ConflictDetectionService::toEpoch(''));

    }//end testToEpochParsesUtcAndEqualInstants()

    /**
     * REQ-004: findOverlapping returns the conflicting booking on overlap.
     *
     * @return void
     */
    public function testFindOverlappingReturnsConflict(): void
    {
        $existing  = [$this->booking('bk-002', '2026-05-21T11:00:00Z', '2026-05-21T11:45:00Z')];
        $conflicts = $this->service->findOverlapping(
            existing: $existing,
            startTime: '2026-05-21T11:15:00Z',
            endTime: '2026-05-21T12:00:00Z'
        );
        self::assertCount(1, $conflicts);
        self::assertSame('bk-002', $conflicts[0]['id']);

    }//end testFindOverlappingReturnsConflict()

    /**
     * REQ-004: no overlap returns an empty conflict list.
     *
     * @return void
     */
    public function testFindOverlappingReturnsEmptyWhenNoOverlap(): void
    {
        $existing  = [$this->booking('bk-001', '2026-05-21T10:00:00Z', '2026-05-21T10:30:00Z')];
        $conflicts = $this->service->findOverlapping(
            existing: $existing,
            startTime: '2026-05-21T10:30:00Z',
            endTime: '2026-05-21T11:00:00Z'
        );
        self::assertSame([], $conflicts);

    }//end testFindOverlappingReturnsEmptyWhenNoOverlap()

    /**
     * REQ-004: cancelled bookings never participate in conflict detection.
     *
     * @return void
     */
    public function testFindOverlappingIgnoresCancelled(): void
    {
        $existing  = [$this->booking('bk-x', '2026-05-21T11:00:00Z', '2026-05-21T12:00:00Z', 'cancelled')];
        $conflicts = $this->service->findOverlapping(
            existing: $existing,
            startTime: '2026-05-21T11:15:00Z',
            endTime: '2026-05-21T11:45:00Z'
        );
        self::assertSame([], $conflicts);

    }//end testFindOverlappingIgnoresCancelled()

    /**
     * REQ-007: an edited booking excludes itself from the conflict check.
     *
     * @return void
     */
    public function testFindOverlappingExcludesSelf(): void
    {
        $existing  = [$this->booking('bk-self', '2026-05-21T11:00:00Z', '2026-05-21T12:00:00Z')];
        $conflicts = $this->service->findOverlapping(
            existing: $existing,
            startTime: '2026-05-21T11:00:00Z',
            endTime: '2026-05-21T12:00:00Z',
            excludeBookingId: 'bk-self'
        );
        self::assertSame([], $conflicts);

    }//end testFindOverlappingExcludesSelf()

    /**
     * REQ-004: a partial overlap with multiple existing bookings returns only
     * the genuinely overlapping ones.
     *
     * @return void
     */
    public function testFindOverlappingMultipleReturnsOnlyConflicting(): void
    {
        $existing  = [
            $this->booking('bk-a', '2026-05-21T09:00:00Z', '2026-05-21T09:30:00Z'),
            $this->booking('bk-b', '2026-05-21T10:15:00Z', '2026-05-21T10:45:00Z'),
            $this->booking('bk-c', '2026-05-21T14:00:00Z', '2026-05-21T15:00:00Z'),
        ];
        $conflicts = $this->service->findOverlapping(
            existing: $existing,
            startTime: '2026-05-21T10:00:00Z',
            endTime: '2026-05-21T10:30:00Z'
        );
        self::assertCount(1, $conflicts);
        self::assertSame('bk-b', $conflicts[0]['id']);

    }//end testFindOverlappingMultipleReturnsOnlyConflicting()

    /**
     * Defensive: bookings with unparseable timestamps are skipped, not fatal.
     *
     * @return void
     */
    public function testFindOverlappingSkipsUnparseableRows(): void
    {
        $existing  = [$this->booking('bk-bad', 'garbage', 'also-garbage')];
        $conflicts = $this->service->findOverlapping(
            existing: $existing,
            startTime: '2026-05-21T11:00:00Z',
            endTime: '2026-05-21T12:00:00Z'
        );
        self::assertSame([], $conflicts);

    }//end testFindOverlappingSkipsUnparseableRows()

    /**
     * REQ-004 + REQ-008: checkConflicts fetches via ObjectService and detects
     * the overlap using UTC comparison (different wall-clock zones, same instant).
     *
     * @return void
     */
    public function testCheckConflictsUsesObjectServiceAndDetectsUtcOverlap(): void
    {
        $rows = [$this->booking('bk-002', '2026-05-21T11:00:00Z', '2026-05-21T11:45:00Z')];

        $objectService = new class($rows) {
            /**
             * @param array<int,array<string,mixed>> $rows Stub rows.
             */
            public function __construct(private array $rows)
            {
            }//end __construct()

            public function setRegister(mixed $r): static
            {
                return $this;
            }//end setRegister()

            public function setSchema(mixed $s): static
            {
                return $this;
            }//end setSchema()

            /**
             * @param array<string,mixed> $config Query config.
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $config): array
            {
                return $this->rows;
            }//end findAll()
        };

        $this->settings->method('isOpenRegisterAvailable')->willReturn(true);
        $this->settings->method('getRegisterSlug')->willReturn('shillinq');
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        // Proposed booking expressed in +02:00 wall time = 11:15Z, overlapping bk-002.
        $conflicts = $this->service->checkConflicts(
            resourceId: 'res-001',
            startTime: '2026-05-21T13:15:00+02:00',
            endTime: '2026-05-21T14:00:00+02:00'
        );
        self::assertCount(1, $conflicts);
        self::assertSame('bk-002', $conflicts[0]['id']);

    }//end testCheckConflictsUsesObjectServiceAndDetectsUtcOverlap()

    /**
     * When OpenRegister is unavailable, checkConflicts returns no conflicts
     * (the write path itself fails closed downstream).
     *
     * @return void
     */
    public function testCheckConflictsEmptyWhenOpenRegisterUnavailable(): void
    {
        $this->settings->method('isOpenRegisterAvailable')->willReturn(false);
        self::assertSame(
            [],
            $this->service->checkConflicts(
                resourceId: 'res-001',
                startTime: '2026-05-21T10:00:00Z',
                endTime: '2026-05-21T10:30:00Z'
            )
        );

    }//end testCheckConflictsEmptyWhenOpenRegisterUnavailable()
}//end class
