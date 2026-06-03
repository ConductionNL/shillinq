<?php

/**
 * Unit tests for InitializeSettings repair step.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-provincies-bbv-variant/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\InitializeSettings;
use OCA\Shillinq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for InitializeSettings repair step.
 */
class InitializeSettingsTest extends TestCase
{

    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IOutput.
     *
     * @var IOutput&MockObject
     */
    private IOutput&MockObject $output;

    /**
     * The repair step under test.
     *
     * @var InitializeSettings
     */
    private InitializeSettings $repairStep;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->output          = $this->createMock(IOutput::class);

        $this->repairStep = new InitializeSettings(
            settingsService: $this->settingsService,
            logger: $this->logger,
            container: $this->container,
        );

    }//end setUp()

    /**
     * Test that getName returns a non-empty descriptive string.
     *
     * @return void
     */
    public function testGetNameReturnsDescriptiveString(): void
    {
        $name = $this->repairStep->getName();

        self::assertIsString($name);
        self::assertNotEmpty($name);

    }//end testGetNameReturnsDescriptiveString()

    /**
     * Test that run() skips configuration when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testRunSkipsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->expects($this->once())
            ->method('isOpenRegisterAvailable')
            ->willReturn(false);

        $this->settingsService->expects($this->never())
            ->method('loadConfigurationForced');

        $this->output->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('OpenRegister'));

        $this->repairStep->run(output: $this->output);

    }//end testRunSkipsWhenOpenRegisterUnavailable()

    /**
     * Test that run() calls loadConfiguration and seedRgsTemplate on success.
     *
     * @return void
     */
    public function testRunCallsLoadConfigurationAndSeedTemplate(): void
    {
        $this->settingsService->expects($this->once())
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method('loadConfigurationForced')
            ->willReturn(['success' => true, 'version' => '0.2.0']);

        $this->settingsService->expects($this->atLeastOnce())
            ->method('getSettings')
            ->willReturn([
                'rgs_template'      => 'mkb',
                'administration_id' => 'adm-1',
                'register'          => '',
                'gov_provincie'     => '',
                'openregisters'     => true,
                'isAdmin'           => false,
            ]);

        $this->settingsService->expects($this->once())
            ->method('seedRgsTemplate')
            ->with(
                templateVariant: 'mkb',
                administrationId: 'adm-1'
            )
            ->willReturn(['success' => true, 'seeded' => 150, 'skipped' => 0]);

        // gov_provincie not enabled — kerntaken seed must NOT run.
        $this->settingsService->expects($this->never())
            ->method('seedBbvProvinciesKerntaken');

        $mockAppConfig = $this->createMock(IAppConfig::class);
        $mockAppConfig->method('getValueString')->willReturn('0');

        $this->container->method('get')
            ->willReturn($mockAppConfig);

        $this->repairStep->run(output: $this->output);

    }//end testRunCallsLoadConfigurationAndSeedTemplate()

    /**
     * Test that run() seeds BBV provincies kerntaken when gov_provincie is enabled.
     *
     * Per REQ-PRB-003 the seed must run idempotently when featureFlags.gov-provincie is on.
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-provincies-bbv-variant/tasks.md#task-10
     */
    public function testRunSeedsKerntakenWhenGovProvincieEnabled(): void
    {
        $this->settingsService->expects($this->once())
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method('loadConfigurationForced')
            ->willReturn(['success' => true, 'version' => '0.4.0']);

        $this->settingsService->expects($this->atLeastOnce())
            ->method('getSettings')
            ->willReturn([
                'rgs_template'      => 'bbv',
                'administration_id' => 'adm-provincie-1',
                'register'          => '',
                'gov_provincie'     => '1',
                'openregisters'     => true,
                'isAdmin'           => false,
            ]);

        $this->settingsService->expects($this->once())
            ->method('seedRgsTemplate')
            ->willReturn(['success' => true, 'seeded' => 120, 'skipped' => 0]);

        $this->settingsService->expects($this->once())
            ->method('seedBbvProvinciesKerntaken')
            ->with(administrationId: 'adm-provincie-1')
            ->willReturn(['success' => true, 'seeded' => 7, 'skipped' => 0]);

        $mockAppConfig = $this->createMock(IAppConfig::class);
        $mockAppConfig->method('getValueString')->willReturn('1');

        $this->container->method('get')
            ->willReturn($mockAppConfig);

        $this->repairStep->run(output: $this->output);

    }//end testRunSeedsKerntakenWhenGovProvincieEnabled()

    /**
     * Test that run() skips seed (not called at all) when administrationId is unset.
     *
     * C2: seeding under a hardcoded "default" id contaminates real tenants.
     *
     * @return void
     */
    public function testRunSkipsSeedWhenAdministrationIdUnset(): void
    {
        $this->settingsService->expects($this->once())
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method('loadConfigurationForced')
            ->willReturn(['success' => true, 'version' => '0.2.0']);

        $this->settingsService->expects($this->atLeastOnce())
            ->method('getSettings')
            ->willReturn([
                'rgs_template'      => 'mkb',
                'administration_id' => '',
                'register'          => '',
                'gov_provincie'     => '',
                'openregisters'     => true,
                'isAdmin'           => false,
            ]);

        // C2: seedRgsTemplate must NOT be called when administrationId is empty.
        $this->settingsService->expects($this->never())
            ->method('seedRgsTemplate');

        $this->output->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('administration_id'));

        $mockAppConfig = $this->createMock(IAppConfig::class);
        $mockAppConfig->method('getValueString')->willReturn('0');

        $this->container->method('get')
            ->willReturn($mockAppConfig);

        $this->repairStep->run(output: $this->output);

    }//end testRunSkipsSeedWhenAdministrationIdUnset()

    /**
     * Test that run() reports a warning and skips seeding when loadConfiguration fails.
     *
     * H2: the seed must not run against an uninitialised register.
     *
     * @return void
     */
    public function testRunSkipsSeedWhenLoadConfigurationFails(): void
    {
        $this->settingsService->expects($this->once())
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method('loadConfigurationForced')
            ->willReturn(['success' => false, 'message' => 'Config import error']);

        // H2: seedRgsTemplate must NOT be called when schema import failed.
        $this->settingsService->expects($this->never())
            ->method('seedRgsTemplate');

        $this->output->expects($this->atLeastOnce())
            ->method('warning');

        $this->repairStep->run(output: $this->output);

    }//end testRunSkipsSeedWhenLoadConfigurationFails()

}//end class
