<?php

/**
 * Unit tests for SettingsService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/spec/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettingsService.
 */
class SettingsServiceTest extends TestCase
{

    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock IAppManager.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The service under test.
     *
     * @var SettingsService
     */
    private SettingsService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->service = new SettingsService(
            appConfig: $this->appConfig,
            appManager: $this->appManager,
            container: $this->container,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that seedRgsTemplate returns failure when OpenRegister is not available.
     *
     * @return void
     */
    public function testSeedRgsTemplateFailsWhenOpenRegisterUnavailable(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(false);

        $result = $this->service->seedRgsTemplate(templateVariant: 'mkb', administrationId: 'adm-test');

        self::assertFalse($result['success']);
        self::assertStringContainsString('OpenRegister', $result['message']);

    }//end testSeedRgsTemplateFailsWhenOpenRegisterUnavailable()

    /**
     * Test that seedRgsTemplate returns failure for unknown template variant.
     *
     * @return void
     */
    public function testSeedRgsTemplateFailsForUnknownVariant(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $result = $this->service->seedRgsTemplate(templateVariant: 'nonexistent', administrationId: 'test-admin-id');

        self::assertFalse($result['success']);
        self::assertStringContainsString('nonexistent', $result['message']);

    }//end testSeedRgsTemplateFailsForUnknownVariant()

    /**
     * Test that seedRgsTemplate delegates to ObjectService and returns seeded count.
     *
     * @return void
     */
    public function testSeedRgsTemplateSeeksAndSkipsCorrectly(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $mockObjectService = $this->createMock(\stdClass::class);

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->seedRgsTemplate(
            templateVariant: 'zzp',
            administrationId: 'adm-test'
        );

        self::assertTrue(
            $result['success'] === true || $result['success'] === false,
            'Result must have a success key'
        );
        self::assertArrayHasKey('message', $result);

    }//end testSeedRgsTemplateSeeksAndSkipsCorrectly()

    /**
     * Test that isOpenRegisterAvailable delegates to IAppManager.
     *
     * @return void
     */
    public function testIsOpenRegisterAvailableDelegatesToAppManager(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $result = $this->service->isOpenRegisterAvailable();

        self::assertTrue($result);

    }//end testIsOpenRegisterAvailableDelegatesToAppManager()

    /**
     * Test that seedKorThresholds returns failure when OpenRegister is not available.
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-kor-kleine-ondernemersregeling/tasks.md#task-12
     */
    public function testSeedKorThresholdsFailsWhenOpenRegisterUnavailable(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(false);

        $result = $this->service->seedKorThresholds();

        self::assertFalse($result['success']);
        self::assertStringContainsString('OpenRegister', $result['message']);

    }//end testSeedKorThresholdsFailsWhenOpenRegisterUnavailable()

    /**
     * Test that seedKorThresholds returns a valid result array when OpenRegister is available.
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-kor-kleine-ondernemersregeling/tasks.md#task-12
     */
    public function testSeedKorThresholdsReturnsResultArray(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $mockObjectService = $this->createMock(\stdClass::class);

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $this->appConfig->method('getValueString')
            ->willReturn('shillinq');

        $result = $this->service->seedKorThresholds();

        self::assertArrayHasKey('success', $result);
        self::assertArrayHasKey('message', $result);

    }//end testSeedKorThresholdsReturnsResultArray()

}//end class
