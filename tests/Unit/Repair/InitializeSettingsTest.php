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
use ReflectionMethod;

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
     * Mock BbvSeedService (constructor arg #5).
     *
     * @var BbvSeedService&MockObject
     */
    private BbvSeedService&MockObject $bbvSeedService;

    /**
     * Mock StatementManifestService (constructor arg #2).
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
            manifestService: $this->manifestService,
            logger: $this->logger,
            container: $this->container,
            bbvSeedService: $this->bbvSeedService,
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
            ->method('loadConfiguration');

        $this->output->expects($this->once())
            ->method('warning')
            ->with($this->stringContains(string: 'OpenRegister'));

        $this->repairStep->run(output: $this->output);

    }//end testRunSkipsWhenOpenRegisterUnavailable()

    /**
     * Test that run() calls loadConfiguration, seedRgsTemplate, seedAllocationRules
     * and the BBV stam-data seeder on success.
     *
     * @return void
     *
     * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
     */
    public function testRunCallsLoadConfigurationAndSeedTemplate(): void
    {
        $this->settingsService->expects($this->once())
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method('loadConfiguration')
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

        // The BBV stam-data catalogues (Taakveld / EconomischeCategorie /
        // BeleidsIndicator / BbvAccountMapping) MUST be seeded on every run —
        // bookkeeping-bbv-compliance §Seed Data. This expectation is what stops
        // the injection being dropped again as "unused" (it was, in 8c773b6a,
        // and the catalogues silently stopped loading).
        $this->bbvSeedService->expects($this->once())
            ->method('seedAll')
            ->willReturn(
                    [
                        'success' => true,
                        'counts'  => ['Taakveld' => ['seeded' => 53, 'skipped' => 0]],
                    ]
                    );

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
            ->method('loadConfiguration')
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
            ->method('loadConfiguration')
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

    /**
     * Test that seedInventoryLotsDemoData is NOT called when APP_ENV != development.
     *
     * Demo data must never seed on production environments per REQ-LOT design.md.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
     */
    public function testDemoSeedSkippedWhenNotDevelopmentEnv(): void
    {
        putenv('APP_ENV=production');

        $this->settingsService->expects($this->once())
            ->method('isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method('loadConfiguration')
            ->willReturn(['success' => true, 'version' => '0.2.0', 'skipped' => false]);

        $this->settingsService->expects($this->atLeastOnce())
            ->method('getSettings')
            ->willReturn(['rgs_template' => 'mkb', 'administration_id' => '']);

        // The demo-lot seed (which delegates to SettingsService::seedInventoryLots)
        // must NOT run when APP_ENV != development.
        $this->settingsService->expects($this->never())
            ->method('seedInventoryLots');

        $this->repairStep->run(output: $this->output);

        putenv('APP_ENV=');

    }//end testDemoSeedSkippedWhenNotDevelopmentEnv()

    /**
     * seedBbvMappingsForMunicipalAdministrations must normalise OpenRegister
     * ObjectEntity rows (not just plain arrays) before reading fields off
     * them. OpenRegister development returns ObjectEntity instances from
     * findAll(), and array-indexing one directly (`$row['field']`) throws
     * "Cannot use object of type OCA\OpenRegister\Db\ObjectEntity as array"
     * (issue #508) — which the outer try/catch in run() previously swallowed
     * as "Could not auto-configure Shillinq: ...".
     *
     * @return void
     */
    public function testSeedBbvMappingsForMunicipalAdministrationsHandlesObjectEntityRows(): void
    {
        // A fake ObjectEntity: no ArrayAccess, only getObject() — exactly the
        // shape OpenRegister's real OCA\OpenRegister\Db\ObjectEntity has.
        $administrationEntity = new class {
            /**
             * Mirrors OpenRegister's ObjectEntity::getObject().
             *
             * @return array<string,mixed>
             */
            public function getObject(): array
            {
                return [
                    'id'                 => 'uuid-1',
                    'administrationCode' => 'ADM-001',
                    'administrationType' => 'gemeente',
                ];
            }
        };

        $objectService = new class ($administrationEntity) {
            /**
             * @param object $administrationEntity Fake ObjectEntity row to return from findAll().
             */
            public function __construct(private object $administrationEntity)
            {
            }

            public function setRegister(string $register): static
            {
                return $this;
            }

            public function setSchema(string $schema): static
            {
                return $this;
            }

            /**
             * @param array<string,mixed> $params
             * @return array<int,object>
             */
            public function findAll(array $params=[]): array
            {
                return [$this->administrationEntity];
            }
        };

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

        // Reaching here with the correct id/type proves the ObjectEntity was
        // normalised — a raw array-access bug would instead throw \TypeError
        // before this call is ever made.
        $this->settingsService->expects($this->once())
            ->method('seedBbvAccountMappings')
            ->with(administrationId: 'ADM-001', administrationType: 'gemeente')
            ->willReturn(['success' => true, 'seeded' => 2, 'skipped' => 0]);

        $method = new ReflectionMethod(InitializeSettings::class, 'seedBbvMappingsForMunicipalAdministrations');
        $method->setAccessible(true);

        // Must not throw "Cannot use object of type ObjectEntity as array".
        $method->invoke($this->repairStep, $this->output);

    }//end testSeedBbvMappingsForMunicipalAdministrationsHandlesObjectEntityRows()

    /**
     * asArray() normalises ObjectEntity-shaped rows (getObject()/jsonSerialize())
     * as well as plain arrays and unusable values, per issue #508.
     *
     * @return void
     */
    public function testAsArrayNormalisesRowsOfEveryShape(): void
    {
        $method = new ReflectionMethod(InitializeSettings::class, 'asArray');
        $method->setAccessible(true);

        // Plain array passes through unchanged.
        self::assertSame(['a' => 1], $method->invoke($this->repairStep, ['a' => 1]));

        // jsonSerialize() takes precedence when present.
        $jsonSerializable = new class implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['b' => 2];
            }
        };
        self::assertSame(['b' => 2], $method->invoke($this->repairStep, $jsonSerializable));

        // getObject() is used when jsonSerialize() is absent (real ObjectEntity shape).
        $getObjectOnly = new class {
            public function getObject(): array
            {
                return ['c' => 3];
            }
        };
        self::assertSame(['c' => 3], $method->invoke($this->repairStep, $getObjectOnly));

        // Unusable values normalise to an empty array rather than throwing.
        self::assertSame([], $method->invoke($this->repairStep, new \stdClass()));
        self::assertSame([], $method->invoke($this->repairStep, null));

    }//end testAsArrayNormalisesRowsOfEveryShape()
}//end class
