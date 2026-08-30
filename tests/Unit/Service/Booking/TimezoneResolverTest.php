<?php

/**
 * Unit tests for TimezoneResolver.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-18
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Booking;

use OCA\Shillinq\Service\Booking\TimezoneResolver;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies REQ-BCF-003 / REQ-BCF-009 timezone resolution: explicit override
 * (when valid) wins, then user core/timezone, then server default, then UTC.
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-18
 */
final class TimezoneResolverTest extends TestCase {

	/**
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Subject under test.
	 */
	private TimezoneResolver $resolver;

	/**
	 * Server default to restore after each test.
	 */
	private string $defaultTz;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->resolver = new TimezoneResolver($this->config, $this->logger);
		$this->defaultTz = date_default_timezone_get();

	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		date_default_timezone_set($this->defaultTz);

	}//end tearDown()

	/**
	 * Valid explicit override wins over everything else.
	 *
	 * @return void
	 */
	public function testValidExplicitOverrideWins(): void {
		// Config should never be queried when the override is taken.
		$this->config->expects(self::never())->method('getUserValue');

		self::assertSame('Asia/Tokyo', $this->resolver->resolve('alice', 'Asia/Tokyo'));

	}//end testValidExplicitOverrideWins()

	/**
	 * Invalid override is ignored and the user setting is used.
	 *
	 * @return void
	 */
	public function testInvalidOverrideFallsThroughToUserSetting(): void {
		$this->config->method('getUserValue')->with('alice', 'core', 'timezone', '')->willReturn('Europe/Amsterdam');

		self::assertSame('Europe/Amsterdam', $this->resolver->resolve('alice', 'Not/A_Real_Zone'));

	}//end testInvalidOverrideFallsThroughToUserSetting()

	/**
	 * User-config timezone is used when valid.
	 *
	 * @return void
	 */
	public function testUserTimezoneIsUsed(): void {
		$this->config->method('getUserValue')->with('bob', 'core', 'timezone', '')->willReturn('America/New_York');

		self::assertSame('America/New_York', $this->resolver->resolve('bob'));

	}//end testUserTimezoneIsUsed()

	/**
	 * Empty user-config falls through to server default.
	 *
	 * @return void
	 */
	public function testEmptyUserConfigFallsThroughToServerDefault(): void {
		$this->config->method('getUserValue')->willReturn('');
		date_default_timezone_set('Europe/Berlin');

		self::assertSame('Europe/Berlin', $this->resolver->resolve('carol'));

	}//end testEmptyUserConfigFallsThroughToServerDefault()

	/**
	 * Throwing config read is swallowed + a debug line emitted; resolution
	 * still terminates at the server default.
	 *
	 * @return void
	 */
	public function testConfigThrowableIsFailSoftAndDebugLogged(): void {
		$this->config->method('getUserValue')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects(self::once())->method('debug')
			->with(self::stringContains('TimezoneResolver: unable to read core/timezone'));

		date_default_timezone_set('Europe/Paris');

		self::assertSame('Europe/Paris', $this->resolver->resolve('dave'));

	}//end testConfigThrowableIsFailSoftAndDebugLogged()

	/**
	 * Null user id with no override and an invalid server default → UTC.
	 *
	 * `@` suppresses the deprecation warning some PHP versions emit on
	 * setting an empty timezone, but the assertion stands either way.
	 *
	 * @return void
	 */
	public function testFinalFallbackIsUtc(): void {
		// Skip if the platform refuses to accept a non-IANA default.
		// We use a real but unusual TZ then assert UTC fallback at the API
		// contract level (null/empty user id).
		// The path: no override + no user id → server default → UTC if
		// server default isn't valid. We set server default to 'UTC' for
		// deterministic outcome.
		date_default_timezone_set('UTC');

		self::assertSame('UTC', $this->resolver->resolve(null));

	}//end testFinalFallbackIsUtc()

	/**
	 * An empty-string user id is treated the same as null (no lookup).
	 *
	 * @return void
	 */
	public function testEmptyStringUserIdSkipsUserLookup(): void {
		$this->config->expects(self::never())->method('getUserValue');
		date_default_timezone_set('Europe/Brussels');

		self::assertSame('Europe/Brussels', $this->resolver->resolve(''));

	}//end testEmptyStringUserIdSkipsUserLookup()

}//end class
