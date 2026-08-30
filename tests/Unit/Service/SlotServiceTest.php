<?php

/**
 * Unit tests for SlotService.
 *
 * Covers slot enumeration: operational-hours respect, conflict exclusion,
 * past-time filtering, and the allowOverlap escape hatch (REQ-WSW-002).
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\SlotService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SlotService.
 *
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-14
 */
class SlotServiceTest extends TestCase {

	/**
	 * DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Settings stub.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Cache factory stub (always returns "no cache" so tests stay deterministic).
	 *
	 * @var ICacheFactory&MockObject
	 */
	private ICacheFactory&MockObject $cacheFactory;

	/**
	 * Time factory stub. Frozen to 2026-05-22T00:00:00Z (one day before the slot date).
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $time;

	/**
	 * Logger stub.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var SlotService
	 */
	private SlotService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$this->time->method('getTime')->willReturn(strtotime('2026-05-21T00:00:00Z'));

		$this->service = new SlotService(
			container: $this->container,
			settings: $this->settings,
			cacheFactory: $this->cacheFactory,
			time: $this->time,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Slots are enumerated across the full operational-hours window when no
	 * conflicting appointments exist.
	 *
	 * @return void
	 */
	public function testEnumerateAllSlotsWhenWindowEmpty(): void {
		$slots = $this->service->enumerateSlotsPublic(
			date: '2026-05-22',
			openingTime: '09:00',
			closingTime: '10:00',
			durationMinutes: 30,
			existingAppointments: [],
			allowOverlap: false,
		);

		// 09:00-09:30 and 09:15-09:45 and 09:30-10:00 — three 15-min-stepped slots fit.
		self::assertCount(3, $slots);
		self::assertSame('2026-05-22T09:00:00Z', $slots[0]['startTime']);
		self::assertSame('2026-05-22T09:30:00Z', $slots[0]['endTime']);
		self::assertSame('2026-05-22T09:30:00Z', $slots[2]['startTime']);
		self::assertSame('2026-05-22T10:00:00Z', $slots[2]['endTime']);

	}//end testEnumerateAllSlotsWhenWindowEmpty()

	/**
	 * Slots overlapping an existing appointment are excluded.
	 *
	 * @return void
	 */
	public function testConflictingSlotsExcluded(): void {
		$existing = [
			[
				'startTime' => '2026-05-22T09:15:00Z',
				'endTime' => '2026-05-22T09:45:00Z',
			],
		];

		$slots = $this->service->enumerateSlotsPublic(
			date: '2026-05-22',
			openingTime: '09:00',
			closingTime: '10:00',
			durationMinutes: 30,
			existingAppointments: $existing,
			allowOverlap: false,
		);

		// 09:00-09:30 overlaps (09:15), 09:15-09:45 overlaps, 09:30-10:00 overlaps (09:45).
		// Only nothing fits — all three candidates conflict.
		self::assertSame([], $slots);

	}//end testConflictingSlotsExcluded()

	/**
	 * allowOverlap=true bypasses the conflict check.
	 *
	 * @return void
	 */
	public function testAllowOverlapBypassesConflictCheck(): void {
		$existing = [
			[
				'startTime' => '2026-05-22T09:15:00Z',
				'endTime' => '2026-05-22T09:45:00Z',
			],
		];

		$slots = $this->service->enumerateSlotsPublic(
			date: '2026-05-22',
			openingTime: '09:00',
			closingTime: '10:00',
			durationMinutes: 30,
			existingAppointments: $existing,
			allowOverlap: true,
		);

		self::assertCount(3, $slots);

	}//end testAllowOverlapBypassesConflictCheck()

	/**
	 * Slots starting before `now` are excluded.
	 *
	 * @return void
	 */
	public function testPastSlotsExcluded(): void {
		// Reset the SUT with a frozen clock on the same day as the slot.
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(strtotime('2026-05-22T09:30:00Z'));
		$service = new SlotService(
			container: $this->container,
			settings: $this->settings,
			cacheFactory: $this->cacheFactory,
			time: $time,
			logger: $this->logger,
		);

		$slots = $service->enumerateSlotsPublic(
			date: '2026-05-22',
			openingTime: '09:00',
			closingTime: '10:00',
			durationMinutes: 30,
			existingAppointments: [],
			allowOverlap: false,
		);

		// Only the 09:30-10:00 candidate is still in the future.
		self::assertCount(1, $slots);
		self::assertSame('2026-05-22T09:30:00Z', $slots[0]['startTime']);

	}//end testPastSlotsExcluded()

	/**
	 * The opening > closing window is rejected as empty.
	 *
	 * @return void
	 */
	public function testEmptyWindowReturnsNoSlots(): void {
		$slots = $this->service->enumerateSlotsPublic(
			date: '2026-05-22',
			openingTime: '17:00',
			closingTime: '09:00',
			durationMinutes: 30,
			existingAppointments: [],
			allowOverlap: false,
		);

		self::assertSame([], $slots);

	}//end testEmptyWindowReturnsNoSlots()

	/**
	 * Duration longer than the window yields no slots.
	 *
	 * @return void
	 */
	public function testDurationExceedingWindowYieldsNoSlots(): void {
		$slots = $this->service->enumerateSlotsPublic(
			date: '2026-05-22',
			openingTime: '09:00',
			closingTime: '09:30',
			durationMinutes: 45,
			existingAppointments: [],
			allowOverlap: false,
		);

		self::assertSame([], $slots);

	}//end testDurationExceedingWindowYieldsNoSlots()

}//end class
