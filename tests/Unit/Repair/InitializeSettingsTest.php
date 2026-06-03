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
 * @spec openspec/changes/spec/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\InitializeSettings;
use OCA\Shillinq\Service\SettingsService;
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
        $this->settingsService->expects($this->atLeastOnce())
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

        $this->repairStep->run(output: $this->output);

    }//end testRunCallsLoadConfigurationAndSeedTemplate()

    /**
     * Test that run() skips seed (not called at all) when administrationId is unset.
     *
     * C2: seeding under a hardcoded "default" id contaminates real tenants.
     *
     * @return void
     */
    public function testRunSkipsSeedWhenAdministrationIdUnset(): void
    {
        $this->settingsService->expects($this->atLeastOnce())
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
                'openregisters'     => true,
                'isAdmin'           => false,
            ]);

        // C2: seedRgsTemplate must NOT be called when administrationId is empty.
        $this->settingsService->expects($this->never())
            ->method('seedRgsTemplate');

        $this->output->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('administration_id'));

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
