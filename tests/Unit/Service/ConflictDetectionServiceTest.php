<?php

/**
 * Unit tests for ConflictDetectionService.
 *
 * Covers REQ-004 of bookings-resource-calendar:
 *   - overlap on the same resource is reported,
 *   - bookings on different resources do not conflict,
 *   - adjacent windows (A.end == B.start) do NOT conflict,
 *   - cancelled bookings are excluded,
 *   - excludeBookingId removes the self-edit case,
 *   - malformed inputs raise InvalidArgumentException,
 *   - lockResource enforces an open transaction.
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
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Booking\TransactionalGuard;
use OCA\Shillinq\Service\ConflictDetectionService;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ConflictDetectionService.
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-9
 */
class ConflictDetectionServiceTest extends TestCase {

	/**
	 * SettingsService stub.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Transactional guard stub.
	 *
	 * @var TransactionalGuard&MockObject
	 */
	private TransactionalGuard&MockObject $guard;

	/**
	 * Fake ObjectService — captures register/schema selection and serves rows.
	 *
	 * @var object
	 */
	private object $fakeObjectService;

	/**
	 * PSR container resolving the fake ObjectService.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Build the stack.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settings = $this->createMock(SettingsService::class);
		$this->guard = $this->createMock(TransactionalGuard::class);

		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		// Default the guard to "in a transaction" so lockResource passes the invariant.
		$this->guard->method('inTransaction')->willReturn(true);
		$this->guard->method('lockResourceRow')->willReturn(true);

		$this->fakeObjectService = $this->buildFakeObjectService();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($this->fakeObjectService);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return ConflictDetectionService
	 */
	private function buildService(): ConflictDetectionService {
		return new ConflictDetectionService(
			$this->container,
			$this->settings,
			$this->guard,
			$this->createMock(LoggerInterface::class),
		);
	}//end buildService()

	/**
	 * Build the fake ObjectService used by the tests.
	 *
	 * @return object
	 */
	private function buildFakeObjectService(): object {
		return new class() {
			/**
			 * Records keyed by resource id, each an array of bookings.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $bookings = [
				[
					'bookingId' => 'bk-001',
					'resource' => 'res-001',
					'calendar' => 'cal-001',
					'title' => 'A: 10:00-10:30',
					'startTime' => '2026-05-21T10:00:00Z',
					'endTime' => '2026-05-21T10:30:00Z',
					'status' => 'confirmed',
				],
				[
					'bookingId' => 'bk-002',
					'resource' => 'res-001',
					'calendar' => 'cal-001',
					'title' => 'B: 11:00-11:45',
					'startTime' => '2026-05-21T11:00:00Z',
					'endTime' => '2026-05-21T11:45:00Z',
					'status' => 'confirmed',
				],
				[
					'bookingId' => 'bk-003',
					'resource' => 'res-001',
					'calendar' => 'cal-001',
					'title' => 'C: cancelled 09:30-10:30',
					'startTime' => '2026-05-21T09:30:00Z',
					'endTime' => '2026-05-21T10:30:00Z',
					'status' => 'cancelled',
				],
				[
					'bookingId' => 'bk-004',
					'resource' => 'res-002',
					'calendar' => 'cal-marie',
					'title' => 'D: other resource 10:00-10:30',
					'startTime' => '2026-05-21T10:00:00Z',
					'endTime' => '2026-05-21T10:30:00Z',
					'status' => 'confirmed',
				],
			];

			public function setRegister(string $_): self {
				return $this;
			}

			public function setSchema(string $_): self {
				return $this;
			}

			public function findAll(array $opts = []): array {
				$filters = ($opts['filters'] ?? []);
				$resource = ($filters['resource'] ?? null);
				$result = [];
				foreach ($this->bookings as $bk) {
					if ($resource !== null && $bk['resource'] !== $resource) {
						continue;
					}

					$result[] = $bk;
				}

				return $result;
			}
		};
	}//end buildFakeObjectService()

	/**
	 * Overlapping windows on the same resource are reported.
	 *
	 * @return void
	 */
	public function testDetectsOverlapOnSameResource(): void {
		$service = $this->buildService();
		$conflicts = $service->checkConflicts('res-001', '2026-05-21T11:15:00Z', '2026-05-21T12:00:00Z');
		$this->assertCount(1, $conflicts);
		$this->assertSame('bk-002', $conflicts[0]['bookingId']);
	}//end testDetectsOverlapOnSameResource()

	/**
	 * Bookings on different resources never conflict.
	 *
	 * @return void
	 */
	public function testNoConflictOnDifferentResources(): void {
		$service = $this->buildService();
		$conflicts = $service->checkConflicts('res-003', '2026-05-21T10:00:00Z', '2026-05-21T10:30:00Z');
		$this->assertSame([], $conflicts);
	}//end testNoConflictOnDifferentResources()

	/**
	 * Adjacent bookings (A.end == B.start) do not conflict.
	 *
	 * @return void
	 */
	public function testAdjacentWindowsAreNotConflicts(): void {
		$service = $this->buildService();
		$conflicts = $service->checkConflicts('res-001', '2026-05-21T10:30:00Z', '2026-05-21T11:00:00Z');
		$this->assertSame([], $conflicts);
	}//end testAdjacentWindowsAreNotConflicts()

	/**
	 * Cancelled bookings are excluded from the conflict check.
	 *
	 * @return void
	 */
	public function testCancelledBookingsAreIgnored(): void {
		$service = $this->buildService();
		$conflicts = $service->checkConflicts('res-001', '2026-05-21T09:45:00Z', '2026-05-21T10:00:00Z');
		$this->assertSame([], $conflicts);
	}//end testCancelledBookingsAreIgnored()

	/**
	 * excludeBookingId removes the self-edit case.
	 *
	 * @return void
	 */
	public function testExcludeBookingIdRemovesSelf(): void {
		$service = $this->buildService();
		$conflicts = $service->checkConflicts('res-001', '2026-05-21T11:00:00Z', '2026-05-21T11:45:00Z', 'bk-002');
		$this->assertSame([], $conflicts);
	}//end testExcludeBookingIdRemovesSelf()

	/**
	 * Malformed proposed start raises InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testMalformedStartThrows(): void {
		$service = $this->buildService();
		$this->expectException(\InvalidArgumentException::class);
		$service->checkConflicts('res-001', 'not-a-date', '2026-05-21T10:30:00Z');
	}//end testMalformedStartThrows()

	/**
	 * End ≤ start raises InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testEndBeforeStartThrows(): void {
		$service = $this->buildService();
		$this->expectException(\InvalidArgumentException::class);
		$service->checkConflicts('res-001', '2026-05-21T11:00:00Z', '2026-05-21T10:00:00Z');
	}//end testEndBeforeStartThrows()

	/**
	 * Empty resource id raises InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testEmptyResourceIdThrows(): void {
		$service = $this->buildService();
		$this->expectException(\InvalidArgumentException::class);
		$service->checkConflicts('', '2026-05-21T10:00:00Z', '2026-05-21T10:30:00Z');
	}//end testEmptyResourceIdThrows()

	/**
	 * lockResource without an active transaction throws LogicException.
	 *
	 * @return void
	 */
	public function testLockResourceRequiresTransaction(): void {
		// Replace the guard with one that reports "not in a transaction".
		$guard = $this->createMock(TransactionalGuard::class);
		$guard->method('inTransaction')->willReturn(false);
		$this->guard = $guard;
		$service = $this->buildService();
		$this->expectException(\LogicException::class);
		$service->lockResource('res-001');
	}//end testLockResourceRequiresTransaction()

	/**
	 * UTC overlap is detected regardless of the input offset (REQ-008).
	 *
	 * @return void
	 */
	public function testUtcOverlapAcrossOffsets(): void {
		$service = $this->buildService();
		// 12:00 CEST (UTC+2) == 10:00 UTC — must collide with bk-001.
		$conflicts = $service->checkConflicts('res-001', '2026-05-21T12:00:00+02:00', '2026-05-21T12:15:00+02:00');
		$this->assertCount(1, $conflicts);
		$this->assertSame('bk-001', $conflicts[0]['bookingId']);
	}//end testUtcOverlapAcrossOffsets()

}//end class
