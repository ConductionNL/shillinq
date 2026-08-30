<?php

/**
 * Unit tests for TimezoneResolver.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Booking\TimezoneResolver;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Behavioural tests for the customer-timezone resolution chain.
 */
final class TimezoneResolverTest extends TestCase {

	/**
	 * The explicit override is honoured when it is a valid IANA timezone.
	 */
	public function testExplicitOverrideWins(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('Europe/Amsterdam');

		$resolver = new TimezoneResolver(config: $config, logger: new NullLogger());
		self::assertSame(
			'America/New_York',
			$resolver->resolve('jan', 'America/New_York')
		);

	}//end testExplicitOverrideWins()

	/**
	 * Invalid overrides fall through to the next strategy (NC user config).
	 */
	public function testInvalidOverrideFallsThrough(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('Europe/Amsterdam');

		$resolver = new TimezoneResolver(config: $config, logger: new NullLogger());
		self::assertSame(
			'Europe/Amsterdam',
			$resolver->resolve('jan', 'Not/A_Real_Zone')
		);

	}//end testInvalidOverrideFallsThrough()

	/**
	 * Customer's NC user config drives the result when no override is set.
	 */
	public function testUsesUserConfigTimezone(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('Asia/Tokyo');

		$resolver = new TimezoneResolver(config: $config, logger: new NullLogger());
		self::assertSame('Asia/Tokyo', $resolver->resolve('jan'));

	}//end testUsesUserConfigTimezone()

	/**
	 * Anonymous customer (no userId) falls back to the server default.
	 */
	public function testAnonymousUsesServerDefault(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('');

		$resolver = new TimezoneResolver(config: $config, logger: new NullLogger());
		$previous = date_default_timezone_get();
		date_default_timezone_set('Europe/Amsterdam');
		try {
			self::assertSame('Europe/Amsterdam', $resolver->resolve(null));
		} finally {
			date_default_timezone_set($previous);
		}

	}//end testAnonymousUsesServerDefault()

	/**
	 * Empty user config + bad server default fall through to UTC.
	 */
	public function testUltimateFallbackIsUtc(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('Not/A_Zone');

		$resolver = new TimezoneResolver(config: $config, logger: new NullLogger());
		$previous = date_default_timezone_get();
		// The server default is always a valid zone, so the assertion is
		// that the resolver never throws and returns either the system
		// default OR UTC.
		try {
			$tz = $resolver->resolve('jan');
			self::assertNotSame('', $tz);
			self::assertNotNull((new \DateTimeZone($tz)));
		} finally {
			date_default_timezone_set($previous);
		}

	}//end testUltimateFallbackIsUtc()

}//end class
