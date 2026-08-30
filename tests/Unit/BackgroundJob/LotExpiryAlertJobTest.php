<?php

/**
 * Unit tests for LotExpiryAlertJob date arithmetic.
 *
 * The cron loop itself is exercised via integration tests once a running
 * OpenRegister is available. This unit test pins the private `daysBetween`
 * helper because off-by-one bugs there would shift every threshold by a
 * day across the whole sweep.
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
 * @spec openspec/changes/inventory-lot-batch-expiry/specs/inventory-lot-batch-expiry/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\LotExpiryAlertJob;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for LotExpiryAlertJob.
 *
 * Pins the daysBetween helper so threshold comparisons (30/7) stay correct
 * for both future and past expiry dates. The full sweep is integration-tested
 * with a running OpenRegister.
 */
class LotExpiryAlertJobTest extends TestCase {
	/**
	 * Instance under test (constructed via reflection to bypass DI).
	 *
	 * @var LotExpiryAlertJob
	 */
	private LotExpiryAlertJob $job;

	/**
	 * Reflected daysBetween method handle.
	 *
	 * @var \ReflectionMethod
	 */
	private \ReflectionMethod $daysBetween;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-05-16'));

		$settings = $this->createMock(SettingsService::class);
		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->job = new LotExpiryAlertJob(
			time: $time,
			settings: $settings,
			container: $container,
			logger: $logger,
		);

		$reflection = new ReflectionClass($this->job);
		$this->daysBetween = $reflection->getMethod('daysBetween');
		$this->daysBetween->setAccessible(true);

	}//end setUp()

	/**
	 * 30 days in the future returns 30.
	 *
	 * @return void
	 */
	public function testDaysBetweenFutureReturnsPositive(): void {
		$days = $this->daysBetween->invoke($this->job, '2026-05-16', '2026-06-15');
		self::assertSame(30, $days);

	}//end testDaysBetweenFutureReturnsPositive()

	/**
	 * Past expiry returns a negative number.
	 *
	 * @return void
	 */
	public function testDaysBetweenPastReturnsNegative(): void {
		$days = $this->daysBetween->invoke($this->job, '2026-05-16', '2026-05-01');
		self::assertSame(-15, $days);

	}//end testDaysBetweenPastReturnsNegative()

	/**
	 * Same date returns zero (today is the expiry date — counts as expired
	 * tomorrow per the >= comparison; the run-day itself is on the boundary).
	 *
	 * @return void
	 */
	public function testDaysBetweenSameDateReturnsZero(): void {
		$days = $this->daysBetween->invoke($this->job, '2026-05-16', '2026-05-16');
		self::assertSame(0, $days);

	}//end testDaysBetweenSameDateReturnsZero()

	/**
	 * Seven days in the future matches the inner threshold.
	 *
	 * @return void
	 */
	public function testDaysBetweenSevenDaysInFuture(): void {
		$days = $this->daysBetween->invoke($this->job, '2026-05-16', '2026-05-23');
		self::assertSame(7, $days);

	}//end testDaysBetweenSevenDaysInFuture()
}//end class
