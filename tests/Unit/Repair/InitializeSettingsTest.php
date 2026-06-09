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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\InitializeSettings;
use OCA\Shillinq\Service\BbvSeedService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\StatementManifestService;
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
     * Mock BbvSeedService.
     *
     * @var BbvSeedService&MockObject
     */
    private BbvSeedService&MockObject $bbvSeedService;

    /**
     * Mock StatementManifestService.
     *
     * @var StatementManifestService&MockObject
     */
    private StatementManifestService&MockObject $manifestService;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

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

        $this->settingsService = $this->createMock(originalClassName: SettingsService::class);
        $this->bbvSeedService  = $this->createMock(originalClassName: BbvSeedService::class);
        $this->manifestService = $this->createMock(originalClassName: StatementManifestService::class);
        $this->container       = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger          = $this->createMock(originalClassName: LoggerInterface::class);
        $this->output          = $this->createMock(originalClassName: IOutput::class);

        $this->repairStep = new InitializeSettings(
            settingsService: $this->settingsService,
            bbvSeedService: $this->bbvSeedService,
            manifestService: $this->manifestService,
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

        self::assertIsString(actual: $name);
        self::assertNotEmpty(actual: $name);

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
            ->with($this->stringContains(string: 'OpenRegister'));

        $this->repairStep->run(output: $this->output);

    }//end testRunSkipsWhenOpenRegisterUnavailable()

    /**
     * Test that run() calls loadConfiguration, seedRgsTemplate, seedAllocationRules, and seedProductAttributes on success.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-13
     */
    public function testRunCallsLoadConfigurationAndSeedTemplate(): void
    {
        $this->settingsService->expects($this->once())
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method('loadConfigurationForced')
            ->willReturn(['success' => true, 'version' => '0.3.0']);

        // The default Administration seed (Task 14) runs first; stub it green.
        $this->settingsService->method('seedDefaultAdministration')
            ->willReturn(['success' => true, 'seeded' => 1, 'skipped' => 0]);

        $this->settingsService->expects($this->atLeastOnce())
            ->method('getSettings')
            ->willReturn(
                    [
                        'rgs_template'      => 'mkb',
                        'administration_id' => 'adm-1',
                        'register'          => '',
                        'openregisters'     => true,
                        'isAdmin'           => false,
                    ]
                    );

        $this->settingsService->expects($this->once())
            ->method('seedRgsTemplate')
            ->with(
                templateVariant: 'mkb',
                administrationId: 'adm-1'
            )
            ->willReturn(['success' => true, 'seeded' => 150, 'skipped' => 0]);

        $this->settingsService->expects($this->once())
            ->method('seedAllocationRules')
            ->with(administrationId: 'adm-1')
            ->willReturn(['success' => true, 'seeded' => 3, 'skipped' => 0]);

        $this->settingsService->method('seedRj270Stages')
            ->willReturn(['success' => true, 'seeded' => 5, 'skipped' => 0]);

        $this->settingsService->method('seedRateCardTemplates')
            ->willReturn(['success' => true, 'seeded' => 2, 'skipped' => 0]);

        $this->settingsService->method('seedSelectielijst')
            ->willReturn(['success' => true, 'seeded' => 100, 'skipped' => 0]);

        // ProductAttribute seeds are called once per category (5 categories) per REQ-IPC-007.
        $this->settingsService->expects($this->exactly(count: 5))
            ->method('seedProductAttributes')
            ->willReturn(['success' => true, 'seeded' => 12, 'skipped' => 0]);

        $this->settingsService->method('getRegisterSlug')
            ->willReturn('shillinq');

        // Container get() throws for ScheduledWorkflowMapper so the workflow
        // registrations (IV3, FixedAssets, BCF) exit via their inner catch blocks
        // without reaching the outer try/catch in run().
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('Not available in test'));

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
        $this->settingsService->expects($this->once())
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method('loadConfigurationForced')
            ->willReturn(['success' => true, 'version' => '0.2.0']);

        // The default Administration is seeded regardless of administration_id (REQ-MA-001);
        // stub it green so it does not emit an unrelated warning in this C2 assertion.
        $this->settingsService->method('seedDefaultAdministration')
            ->willReturn(['success' => true, 'seeded' => 1, 'skipped' => 0]);

        $this->settingsService->expects($this->atLeastOnce())
            ->method('getSettings')
            ->willReturn(
                    [
                        'rgs_template'      => 'mkb',
                        'administration_id' => '',
                        'register'          => '',
                        'openregisters'     => true,
                        'isAdmin'           => false,
                    ]
                    );

        // C2: seedRgsTemplate and seedAllocationRules must NOT be called when administrationId is empty.
        $this->settingsService->expects($this->never())
            ->method('seedRgsTemplate');

        $this->settingsService->expects($this->never())
            ->method('seedAllocationRules');

        $this->output->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains(string: 'administration_id'));

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

        // H2: seedRgsTemplate and seedAllocationRules must NOT be called when schema import failed.
        $this->settingsService->expects($this->never())
            ->method('seedRgsTemplate');

        $this->settingsService->expects($this->never())
            ->method('seedAllocationRules');

        $this->output->expects($this->atLeastOnce())
            ->method('warning');

        $this->repairStep->run(output: $this->output);

    }//end testRunSkipsSeedWhenLoadConfigurationFails()
}//end class
