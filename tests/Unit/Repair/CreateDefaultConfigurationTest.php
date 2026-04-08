<?php

/**
 * Unit tests for CreateDefaultConfiguration repair step.
 *
 * @spec openspec/changes/core/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\CreateDefaultConfiguration;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test suite for CreateDefaultConfiguration.
 *
 * @spec openspec/changes/core/tasks.md#task-12
 */
class CreateDefaultConfigurationTest extends TestCase
{
    /**
     * The container mock.
     *
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $container;

    /**
     * The logger mock.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * The output mock.
     *
     * @var IOutput|\PHPUnit\Framework\MockObject\MockObject
     */
    private $output;

    /**
     * The repair step under test.
     *
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
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->output = $this->createMock(IOutput::class);

        $this->repairStep = new CreateDefaultConfiguration(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that getName returns a descriptive name.
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-12
     */
    public function testGetNameReturnsDescriptiveName(): void
    {
        $name = $this->repairStep->getName();
        $this->assertStringContainsString('Seed', $name);
        $this->assertStringContainsString('Shillinq', $name);
    }//end testGetNameReturnsDescriptiveName()

    /**
     * Test that seed data is created for all schemas on fresh install.
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-12
     */
    public function testSeedDataCreatedOnFreshInstall(): void
    {
        $objectService = $this->createObjectServiceMock(existingObjects: []);

        $this->container
            ->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        // Expect saveObject to be called for each seed object:
        // 2 organizations + 4 settings + 1 dashboard + 1 data job = 8.
        $objectService
            ->expects($this->exactly(8))
            ->method('saveObject');

        $this->repairStep->run(output: $this->output);
    }//end testSeedDataCreatedOnFreshInstall()

    /**
     * Test that no duplicate objects are created when objects already exist.
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-12
     */
    public function testNoDuplicatesWhenObjectsExist(): void
    {
        $objectService = $this->createObjectServiceMock(
            existingObjects: [['name' => 'Existing Org']]
        );

        $this->container
            ->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        // saveObject should never be called since objects already exist.
        $objectService
            ->expects($this->never())
            ->method('saveObject');

        $this->repairStep->run(output: $this->output);
    }//end testNoDuplicatesWhenObjectsExist()

    /**
     * Test graceful handling when ObjectService is not available.
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-12
     */
    public function testGracefulHandlingWhenObjectServiceUnavailable(): void
    {
        $this->container
            ->expects($this->once())
            ->method('get')
            ->willThrowException(new \RuntimeException('Not available'));

        $this->output
            ->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw — handles exception gracefully.
        $this->repairStep->run(output: $this->output);
    }//end testGracefulHandlingWhenObjectServiceUnavailable()

    /**
     * Create a mock ObjectService with configurable existing objects.
     *
     * @param array $existingObjects The objects getObjects should return
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function createObjectServiceMock(array $existingObjects): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObjects', 'saveObject'])
            ->getMock();

        $mock->method('getObjects')
            ->willReturn($existingObjects);

        return $mock;
    }//end createObjectServiceMock()
}//end class
