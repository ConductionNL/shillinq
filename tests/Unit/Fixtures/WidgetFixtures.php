<?php

/**
 * Widget test fixtures.
 *
 * Stable seed records reused by WidgetAuthServiceTest, SlotServiceTest, and
 * WidgetApiControllerTest. Keeps the sample business + key + services +
 * resources in one place so multiple tests see the same canonical data.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Fixtures
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-17
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Fixtures;

/**
 * Static fixture provider for the widget unit + integration tests.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class WidgetFixtures {

	public const SAMPLE_BUSINESS_ID = 'salon-demo';
	public const SAMPLE_API_KEY = 'bk_live_demo-fixture-key-not-secret';
	public const SAMPLE_DATE = '2026-05-22';
	public const SAMPLE_RESOURCE_ID = 'res-chair-1';
	public const SAMPLE_SERVICE_ID = 'svc-haircut';

	/**
	 * Three sample services covering short / medium / long durations.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function services(): array {
		return [
			[
				'serviceId' => 'svc-haircut',
				'name' => 'Haircut',
				'duration' => 45,
				'price' => 35.0,
				'currency' => 'EUR',
				'description' => 'Standard haircut.',
				'status' => 'active',
			],
			[
				'serviceId' => 'svc-color',
				'name' => 'Color',
				'duration' => 120,
				'price' => 75.0,
				'currency' => 'EUR',
				'description' => 'Full hair colouring.',
				'status' => 'active',
			],
			[
				'serviceId' => 'svc-manicure',
				'name' => 'Manicure',
				'duration' => 30,
				'price' => 25.0,
				'currency' => 'EUR',
				'description' => 'Standard manicure.',
				'status' => 'active',
			],
		];

	}//end services()

	/**
	 * Two sample resources sharing the same operational hours.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function resources(): array {
		return [
			[
				'resourceId' => 'res-chair-1',
				'name' => 'Chair 1',
				'openingTime' => '09:00',
				'closingTime' => '18:00',
				'allowOverlap' => false,
				'status' => 'active',
			],
			[
				'resourceId' => 'res-chair-2',
				'name' => 'Chair 2',
				'openingTime' => '09:00',
				'closingTime' => '18:00',
				'allowOverlap' => false,
				'status' => 'active',
			],
		];

	}//end resources()

	/**
	 * Three sample booked appointments covering morning, midday, and
	 * afternoon windows on the canonical demo date.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function appointments(): array {
		return [
			[
				'appointmentId' => 'apt-fixture-1',
				'serviceId' => 'svc-haircut',
				'resourceId' => 'res-chair-1',
				'startTime' => '2026-05-22T10:00:00Z',
				'endTime' => '2026-05-22T10:45:00Z',
				'status' => 'confirmed',
			],
			[
				'appointmentId' => 'apt-fixture-2',
				'serviceId' => 'svc-color',
				'resourceId' => 'res-chair-1',
				'startTime' => '2026-05-22T11:30:00Z',
				'endTime' => '2026-05-22T13:30:00Z',
				'status' => 'confirmed',
			],
			[
				'appointmentId' => 'apt-fixture-3',
				'serviceId' => 'svc-manicure',
				'resourceId' => 'res-chair-2',
				'startTime' => '2026-05-22T14:00:00Z',
				'endTime' => '2026-05-22T14:30:00Z',
				'status' => 'confirmed',
			],
		];

	}//end appointments()
}//end class
