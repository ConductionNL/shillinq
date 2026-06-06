<?php

/**
 * Unit tests for CogsPosterService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\CogsPosterService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CogsPosterService.
 *
 * Covers REQ-INV-007: balanced JournalEntry posted on outbound movement;
 * WARNING + adjusted status when GL accounts are not configured.
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 */
class CogsPosterServiceTest extends TestCase
{

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
     * The service under test.
     *
     * @var CogsPosterService
     */
    private CogsPosterService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        // phpcs:enable
    }//end setUp()

    /**
     * REQ-INV-007 scenario: COGS entry is posted on outbound movement.
     *
     * A balanced JournalEntry (debit 7000, credit 3000) is saved with
     * journalCode COGS and reference = StockMovement.uuid.
     *
     * @return void
     */
    public function testPostCogsCreatesBalancedJournalEntry(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap(
                    [
                // phpcs:disable CustomSniffs.Functions.NamedParameters
                        [Application::APP_ID, 'register', 'shillinq', 'shillinq'],
                        [Application::APP_ID, 'cogs_account', '7000', '7000'],
                        [Application::APP_ID, 'inventory_account', '3000', '3000'],
                // phpcs:enable
                    ]
                    );

        $this->service = new CogsPosterService(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

        $movement = [
            'itemId'    => 'HP-200-B',
            'warehouse' => 'Magazijn Zuid',
            'quantity'  => 5,
            'uuid'      => 'movement-uuid-001',
            'date'      => '2026-05-01T00:00:00Z',
        ];

        $valuation = [
            'id'       => 'val-001',
            'unitCost' => 89.0,
        ];

        $savedEntry    = null;
        $objectService = $this->buildObjectServiceStub(
            onSave: static function (array $object) use (&$savedEntry): array {
                $savedEntry = $object;
                return $object;
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $this->service->postCogs(
            movement: $movement,
            valuation: $valuation,
            cogsAmount: 445.0,
        );

        self::assertNotNull($savedEntry);
        self::assertSame('COGS', $savedEntry['journalCode']);
        self::assertSame('movement-uuid-001', $savedEntry['reference']);
        self::assertSame(445.0, $savedEntry['debitAmount']);
        self::assertSame(445.0, $savedEntry['creditAmount']);
        self::assertSame('7000', $savedEntry['debitAccountNumber']);
        self::assertSame('3000', $savedEntry['creditAccountNumber']);
    }//end testPostCogsCreatesBalancedJournalEntry()

    /**
     * REQ-INV-007 scenario: missing GL config sets valuation to adjusted and logs WARNING.
     *
     * @return void
     */
    public function testPostCogsMarksAdjustedWhenGlAccountsNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap(
                    [
                // phpcs:disable CustomSniffs.Functions.NamedParameters
                        [Application::APP_ID, 'register', 'shillinq', 'shillinq'],
                        [Application::APP_ID, 'cogs_account', '7000', ''],
                        [Application::APP_ID, 'inventory_account', '3000', ''],
                // phpcs:enable
                    ]
                    );

        $this->service = new CogsPosterService(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

        $movement = [
            'itemId'    => 'HP-200-B',
            'warehouse' => 'Magazijn Zuid',
            'quantity'  => 5,
            'uuid'      => 'movement-uuid-002',
            'date'      => '2026-05-01T00:00:00Z',
        ];

        $valuation = [
            'id'       => 'val-002',
            'unitCost' => 89.0,
        ];

        $this->logger->expects(self::once())->method('warning')
            ->with(
                self::stringContains('GL account numbers not configured'),
                self::anything()
            );

        $savedObject   = null;
        $objectService = $this->buildObjectServiceStub(
            onSave: static function (array $object) use (&$savedObject): array {
                $savedObject = $object;
                return $object;
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $this->service->postCogs(
            movement: $movement,
            valuation: $valuation,
            cogsAmount: 445.0,
        );

        self::assertNotNull($savedObject);
        self::assertTrue(($savedObject['pendingCogs'] ?? false) === true);
        self::assertSame('adjusted', $savedObject['status']);
    }//end testPostCogsMarksAdjustedWhenGlAccountsNotConfigured()

    /**
     * Build a minimal ObjectService-like anonymous stub.
     *
     * @param callable|null $onSave Callback invoked on saveObject.
     *
     * @return object
     */
    private function buildObjectServiceStub(?callable $onSave=null): object
    {
        return new class($onSave) {

            /**
             * @var callable|null
             */
            private $onSave;

            /**
             * @param callable|null $onSave Callback.
             */
            public function __construct(?callable $onSave)
            {
                $this->onSave = $onSave;
            }//end __construct()

            /**
             * @param array<string,mixed> $object   Object to save.
             * @param string              $register Register slug.
             * @param string              $schema   Schema slug.
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $object, string $register, string $schema): array
            {
                if ($this->onSave !== null) {
                    return ($this->onSave)($object);
                }

                return $object;
            }//end saveObject()
        };
    }//end buildObjectServiceStub()
}//end class
