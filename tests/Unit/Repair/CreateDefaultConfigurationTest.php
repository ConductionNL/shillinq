<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for CreateDefaultConfiguration repair step.
 *
 * @spec openspec/changes/core/tasks.md#task-12.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\CreateDefaultConfiguration;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the CreateDefaultConfiguration repair step.
 *
 * @spec openspec/changes/core/tasks.md#task-12.2
 */
class CreateDefaultConfigurationTest extends TestCase
{
    /**
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $container;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var IOutput|\PHPUnit\Framework\MockObject\MockObject
     */
    private $output;

    /**
     * @var CreateDefaultConfiguration
     */
    private CreateDefaultConfiguration $repairStep;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->output    = $this->createMock(IOutput::class);

        $this->repairStep = new CreateDefaultConfiguration(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that repair step creates seed data for all four schemas on fresh install.
     *
     * @spec openspec/changes/core/tasks.md#task-12.2
     *
     * @return void
     */
    public function testRunSeedsAllFourSchemas(): void
    {
        $objectService = $this->createObjectServiceMock(existingData: []);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($objectService);

        // On fresh install, searchObjects returns empty, so createObject should be called.
        // 2 orgs + 4 settings + 1 dashboard + 1 dataJob = 8 creates.
        $objectService->expects($this->exactly(8))
            ->method('createObject');

        $this->repairStep->run(output: $this->output);
    }//end testRunSeedsAllFourSchemas()

    /**
     * Test that existing objects are not duplicated.
     *
     * @spec openspec/changes/core/tasks.md#task-12.2
     *
     * @return void
     */
    public function testRunSkipsDuplicateObjects(): void
    {
        $objectService = $this->createObjectServiceMock(existingData: ['exists']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($objectService);

        // All searches return non-empty, so no creates should happen.
        $objectService->expects($this->never())
            ->method('createObject');

        $this->repairStep->run(output: $this->output);
    }//end testRunSkipsDuplicateObjects()

    /**
     * Test that the repair step handles missing ObjectService gracefully.
     *
     * @spec openspec/changes/core/tasks.md#task-12.2
     *
     * @return void
     */
    public function testRunHandlesMissingObjectService(): void
    {
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('Service not found'));

        $this->output->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw.
        $this->repairStep->run(output: $this->output);
    }//end testRunHandlesMissingObjectService()

    /**
     * Test that getName returns the correct step name.
     *
     * @return void
     */
    public function testGetNameReturnsExpectedString(): void
    {
        $this->assertSame(
            'Seed Shillinq default configuration data',
            $this->repairStep->getName()
        );
    }//end testGetNameReturnsExpectedString()

    /**
     * Create a mock ObjectService with optional pre-existing data.
     *
     * @param array $existingData Data returned by searchObjects
     *
     * @return object
     */
    private function createObjectServiceMock(array $existingData): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['searchObjects', 'createObject'])
            ->getMock();

        $mock->method('searchObjects')
            ->willReturn($existingData);

        return $mock;
    }//end createObjectServiceMock()
}//end class
