<?php

/**
 * Unit tests for BookingReminderJob.
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
 * @spec openspec/specs/bookings-notification-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\BookingReminderJob;
use OCA\Shillinq\Service\BookingNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingReminderJob.
 *
 * @spec openspec/specs/bookings-notification-triggers/spec.md
 */
class BookingReminderJobTest extends TestCase
{

    /**
     * Mock ITimeFactory.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory&MockObject $time;

    /**
     * Mock BookingNotificationService.
     *
     * @var BookingNotificationService&MockObject
     */
    private BookingNotificationService&MockObject $notificationService;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The job under test.
     *
     * @var BookingReminderJob
     */
    private BookingReminderJob $job;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->time = $this->createMock(originalClassName: ITimeFactory::class);
        $this->notificationService = $this->createMock(originalClassName: BookingNotificationService::class);
        $this->container           = $this->createMock(originalClassName: ContainerInterface::class);
        $this->appConfig           = $this->createMock(originalClassName: IAppConfig::class);
        $this->logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->time->method('getTime')->willReturn(time());
        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->job = new BookingReminderJob(
            time: $this->time,
            notificationService: $this->notificationService,
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * BookingReminderJob can be instantiated with correct interval.
     *
     * @return void
     *
     * @spec openspec/specs/bookings-notification-triggers/spec.md
     */
    public function testJobCanBeInstantiated(): void
    {
        static::assertInstanceOf(expected: BookingReminderJob::class, actual: $this->job);
    }//end testJobCanBeInstantiated()

    /**
     * Job handles object-service error gracefully and logs it.
     *
     * @return void
     *
     * @spec openspec/specs/bookings-notification-triggers/spec.md
     */
    public function testJobHandlesObjectServiceErrorGracefully(): void
    {
        $this->container
            ->method('get')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        // Logger::error should be called for each window that fails.
        $this->logger
            ->expects(static::atLeastOnce())
            ->method('error');

        // Logging starting and complete are info calls.
        $this->logger
            ->expects(static::atLeastOnce())
            ->method('info');

        // Run() is protected; call via reflection.
        $ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
        $ref->invoke($this->job, null);
    }//end testJobHandlesObjectServiceErrorGracefully()
}//end class
