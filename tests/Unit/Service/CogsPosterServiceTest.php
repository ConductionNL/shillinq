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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CogsPosterService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CogsPosterService.
 *
 * Covers COGS JournalEntry creation, GL account validation, pendingCogs marking,
 * and adjusted status on non-positive cogsAmount.
 *
 * @covers \OCA\Shillinq\Service\CogsPosterService
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 */
class CogsPosterServiceTest extends TestCase
{

    /**
     * Mock LoggerInterface shared across tests.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->logger = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters

    }//end setUp()

    /**
     * Creates a balanced two-line JournalEntry when GL accounts are configured.
     *
     * GL accounts: cogsAccount=7000, inventoryAccount=3000.
     * Movement: uuid=mv-001, itemId=HP-200-B, quantity=5.
     * cogsAmount=445.00.
     * Expected: debit 7000 = 445.00, credit 3000 = 445.00, journalCode=COGS.
     *
     * @return void
     */
    public function testPostCogsCreatesBalancedJournalEntry(): void
    {
        $movement = [
            'uuid'     => 'mv-001',
            'itemId'   => 'HP-200-B',
            'quantity' => 5.0,
        ];

        $valuation = [
            'id'               => 'val-001',
            'administrationId' => 'admin-001',
            'quantity'         => 95.0,
            'unitCost'         => 89.0,
        ];

        $savedObject = null;

        $objectService = $this->buildObjectServiceStub(
            onSave: static function (array $object) use (&$savedObject): array {
                $savedObject = $object;
                return $object;
            }
        );

        $service = $this->buildService(
            cogsAccount: '7000',
            inventoryAccount: '3000',
            objectService: $objectService,
        );

        $service->postCogs(movement: $movement, valuation: $valuation, cogsAmount: 445.0);

        self::assertIsArray(actual: $savedObject);
        self::assertSame(expected: 'COGS', actual: $savedObject['journalCode']);
        self::assertSame(expected: 'mv-001', actual: $savedObject['reference']);
        self::assertIsArray(actual: $savedObject['lines']);
        self::assertCount(expectedCount: 2, haystack: $savedObject['lines']);

        // Find debit and credit lines.
        $debitLine  = null;
        $creditLine = null;
        foreach ($savedObject['lines'] as $line) {
            if ($line['side'] === 'debit') {
                $debitLine = $line;
            } else if ($line['side'] === 'credit') {
                $creditLine = $line;
            }
        }

        self::assertIsArray(actual: $debitLine);
        self::assertSame(expected: '7000', actual: $debitLine['accountNumber']);
        self::assertSame(expected: 445.0, actual: $debitLine['amount']);

        self::assertIsArray(actual: $creditLine);
        self::assertSame(expected: '3000', actual: $creditLine['accountNumber']);
        self::assertSame(expected: 445.0, actual: $creditLine['amount']);

        // Verify the journal entry is balanced (debit equals credit).
        self::assertSame(expected: $debitLine['amount'], actual: $creditLine['amount']);

    }//end testPostCogsCreatesBalancedJournalEntry()

    /**
     * Marks valuation pendingCogs=true and logs a warning when GL accounts are not configured.
     *
     * When cogs_account is empty, the service must NOT create a JournalEntry.
     *
     * @return void
     */
    public function testPostCogsMarksValuationPendingWhenGlNotConfigured(): void
    {
        $movement = [
            'uuid'     => 'mv-nocfg-001',
            'quantity' => 10.0,
        ];

        $valuation = [
            'id'               => 'val-nocfg-001',
            'administrationId' => 'admin-001',
            'quantity'         => 90.0,
            'unitCost'         => 5.0,
        ];

        $savedObjects = [];
        $journalSaved = false;

        $objectService = $this->buildObjectServiceStub(
            onSave: static function (array $object) use (&$savedObjects, &$journalSaved): array {
                $savedObjects[] = $object;
                if (isset($object['journalCode']) === true) {
                    $journalSaved = true;
                }

                return $object;
            }
        );

        $service = $this->buildService(
            cogsAccount: '',
            inventoryAccount: '3000',
            objectService: $objectService,
        );

        $this->logger->expects($this->once())->method('warning');

        $service->postCogs(movement: $movement, valuation: $valuation, cogsAmount: 50.0);

        // Must not create a JournalEntry.
        self::assertFalse(condition: $journalSaved);

        // Must save the valuation with pendingCogs=true.
        $valuationSave = null;
        foreach ($savedObjects as $obj) {
            if (isset($obj['pendingCogs']) === true) {
                $valuationSave = $obj;
                break;
            }
        }

        self::assertIsArray(actual: $valuationSave);
        self::assertTrue(condition: $valuationSave['pendingCogs']);

    }//end testPostCogsMarksValuationPendingWhenGlNotConfigured()

    /**
     * Marks valuation status=adjusted and logs a warning when cogsAmount is exactly zero.
     *
     * @return void
     */
    public function testPostCogsMarksAdjustedWhenCogsAmountIsZero(): void
    {
        $movement = ['uuid' => 'mv-zero-001', 'quantity' => 5.0];

        $valuation = [
            'id'               => 'val-zero-001',
            'administrationId' => 'admin-001',
            'quantity'         => 100.0,
            'unitCost'         => 0.0,
        ];

        $savedObject  = null;
        $journalSaved = false;

        $objectService = $this->buildObjectServiceStub(
            onSave: static function (array $object) use (&$savedObject, &$journalSaved): array {
                if (isset($object['journalCode']) === true) {
                    $journalSaved = true;
                } else {
                    $savedObject = $object;
                }

                return $object;
            }
        );

        $service = $this->buildService(
            cogsAccount: '7000',
            inventoryAccount: '3000',
            objectService: $objectService,
        );

        $this->logger->expects($this->once())->method('warning');

        $service->postCogs(movement: $movement, valuation: $valuation, cogsAmount: 0.0);

        self::assertFalse(condition: $journalSaved);
        self::assertIsArray(actual: $savedObject);
        self::assertSame(expected: 'adjusted', actual: $savedObject['status']);

    }//end testPostCogsMarksAdjustedWhenCogsAmountIsZero()

    /**
     * Marks valuation status=adjusted and logs a warning when cogsAmount is negative.
     *
     * @return void
     */
    public function testPostCogsMarksAdjustedWhenCogsAmountIsNegative(): void
    {
        $movement = ['uuid' => 'mv-neg-001', 'quantity' => 5.0];

        $valuation = [
            'id'               => 'val-neg-001',
            'administrationId' => 'admin-001',
            'quantity'         => 100.0,
            'unitCost'         => -1.0,
        ];

        $savedObject  = null;
        $journalSaved = false;

        $objectService = $this->buildObjectServiceStub(
            onSave: static function (array $object) use (&$savedObject, &$journalSaved): array {
                if (isset($object['journalCode']) === true) {
                    $journalSaved = true;
                } else {
                    $savedObject = $object;
                }

                return $object;
            }
        );

        $service = $this->buildService(
            cogsAccount: '7000',
            inventoryAccount: '3000',
            objectService: $objectService,
        );

        $this->logger->expects($this->once())->method('warning');

        $service->postCogs(movement: $movement, valuation: $valuation, cogsAmount: -5.0);

        self::assertFalse(condition: $journalSaved);
        self::assertIsArray(actual: $savedObject);
        self::assertSame(expected: 'adjusted', actual: $savedObject['status']);

    }//end testPostCogsMarksAdjustedWhenCogsAmountIsNegative()

    /**
     * Build a fully wired CogsPosterService with isolated mocks per test.
     *
     * A fresh ContainerInterface mock is created for each call so that
     * willReturn() configuration does not bleed across tests.
     *
     * @param string $cogsAccount      GL account for COGS expense ('' = not configured).
     * @param string $inventoryAccount GL account for inventory asset ('' = not configured).
     * @param object $objectService    The ObjectService stub to return from the container.
     *
     * @return CogsPosterService
     */
    private function buildService(string $cogsAccount, string $inventoryAccount, object $objectService): CogsPosterService
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $appConfig = $this->createMock(IAppConfig::class);
        $container = $this->createMock(ContainerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        $appConfig->method('getValueString')
            ->willReturnCallback(
                static function (string $appId, string $key, string $default='', bool $lazy=false) use ($cogsAccount, $inventoryAccount): string {
                    if ($key === 'cogs_account') {
                        return $cogsAccount;
                    }

                    if ($key === 'inventory_account') {
                        return $inventoryAccount;
                    }

                    if ($key === 'register') {
                        return 'shillinq';
                    }

                    return $default;
                }
            );

        $container->method('get')->willReturn($objectService);

        return new CogsPosterService(
            container: $container,
            appConfig: $appConfig,
            logger: $this->logger,
        );

    }//end buildService()

    /**
     * Build an anonymous ObjectService stub with a configurable saveObject callback.
     *
     * @param callable|null $onSave Callback invoked with the saved object; returns the saved array.
     *
     * @return object
     */
    private function buildObjectServiceStub(?callable $onSave=null): object
    {
        return new class($onSave) {

            /**
             * Callback invoked on saveObject.
             *
             * @var callable|null
             */
            private $onSave;

            /**
             * Constructor.
             *
             * @param callable|null $onSave Save callback.
             */
            public function __construct(?callable $onSave)
            {
                $this->onSave = $onSave;
            }//end __construct()

            /**
             * Fluent register setter.
             *
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * Fluent schema setter.
             *
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * Delegate to onSave callback if set, otherwise return the object unchanged.
             *
             * @param array<string,mixed> $object The object to save.
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $object): array
            {
                if ($this->onSave !== null) {
                    return ($this->onSave)($object);
                }

                return $object;
            }//end saveObject()
        };
    }//end buildObjectServiceStub()
}//end class
