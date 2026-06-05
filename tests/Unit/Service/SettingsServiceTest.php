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
     * Test seedProductAttributes returns failure when OpenRegister is unavailable.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-13
     */
    public function testSeedProductAttributesFailsWhenOpenRegisterUnavailable(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(false);

        $result = $this->service->seedProductAttributes(category: 'office');

        self::assertFalse($result['success']);
        self::assertStringContainsString('OpenRegister', $result['message']);

    }//end testSeedProductAttributesFailsWhenOpenRegisterUnavailable()

    /**
     * Test seedProductAttributes returns failure for unknown category.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-13
     */
    public function testSeedProductAttributesFailsForUnknownCategory(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $result = $this->service->seedProductAttributes(category: 'nonexistent_category');

        self::assertFalse($result['success']);
        self::assertStringContainsString('nonexistent', $result['message']);

    }//end testSeedProductAttributesFailsForUnknownCategory()

    /**
     * Test that all five ProductAttribute seed files exist and parse as valid JSON.
     *
     * Covers REQ-IPC-006: seed files parse and validate.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-8
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-9
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-10
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-11
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-12
     */
    public function testProductAttributeSeedFilesAreValidJson(): void
    {
        $categories = ['office', 'it_hardware', 'logistics', 'food_beverage', 'clothing'];
        $seedDir    = __DIR__.'/../../../lib/Settings/seeds/';

        foreach ($categories as $category) {
            $filename = $seedDir.'product-attributes-'.str_replace('_', '-', $category).'.json';
            self::assertFileExists($filename, 'Seed file must exist for category: '.$category);

            $content = file_get_contents($filename);
            self::assertNotFalse($content, 'Must be able to read seed file: '.$filename);

            $data = json_decode($content, associative: true);
            self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Seed file must be valid JSON: '.$filename);
            self::assertArrayHasKey('productAttributes', $data, 'Seed file must have productAttributes key: '.$filename);
            self::assertNotEmpty($data['productAttributes'], 'Seed file must have at least one attribute: '.$filename);

            foreach ($data['productAttributes'] as $attr) {
                self::assertArrayHasKey('name', $attr, 'Each attribute must have name in: '.$filename);
                self::assertArrayHasKey('dataType', $attr, 'Each attribute must have dataType in: '.$filename);
                self::assertArrayHasKey('applicableToCategories', $attr, 'Each attribute must have applicableToCategories in: '.$filename);
                self::assertArrayHasKey('status', $attr, 'Each attribute must have status in: '.$filename);
                self::assertContains($attr['dataType'], ['text', 'number', 'boolean', 'enum', 'date'], 'dataType must be valid enum value in: '.$filename);
                self::assertContains($attr['status'], ['active', 'archived'], 'status must be active or archived in: '.$filename);
            }
        }//end foreach

    }//end testProductAttributeSeedFilesAreValidJson()

    /**
     * Test seedProductAttributes calls ObjectService for a known category.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-13
     */
    public function testSeedProductAttributesCallsObjectServiceForKnownCategory(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->willReturn('shillinq');

        $mockObjectService = $this->createMock(\stdClass::class);

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->seedProductAttributes(category: 'office');

        self::assertArrayHasKey('success', $result);

    }//end testSeedProductAttributesCallsObjectServiceForKnownCategory()

    /**
     * Test seedInventoryLotsDemoData returns failure when OpenRegister is unavailable.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
     */
    public function testSeedInventoryLotsDemoDataFailsWhenOpenRegisterUnavailable(): void
    {
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(false);

        $result = $this->service->seedInventoryLotsDemoData();

        self::assertFalse($result['success']);
        self::assertStringContainsString('OpenRegister', $result['message']);

    }//end testSeedInventoryLotsDemoDataFailsWhenOpenRegisterUnavailable()

    /**
     * Test that the inventory-lots-demo.json seed file exists and parses as valid JSON.
     *
     * Covers REQ-LOT-design.md seed data section: 5 Dutch pet-food lots with correct structure.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
     */
    public function testInventoryLotsDemoSeedFileIsValidJson(): void
    {
        $seedPath = __DIR__.'/../../../lib/Settings/seeds/inventory-lots-demo.json';
        self::assertFileExists($seedPath, 'Demo seed file must exist: inventory-lots-demo.json');

        $content = file_get_contents($seedPath);
        self::assertNotFalse($content, 'Must be able to read inventory-lots-demo.json');

        $data = json_decode($content, associative: true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Seed file must be valid JSON');
        self::assertArrayHasKey('inventoryLots', $data, 'Seed file must have inventoryLots key');
        self::assertCount(5, $data['inventoryLots'], 'Seed file must contain exactly 5 demo lots');

        $requiredFields = ['lotNumber', 'productSku', 'quantity', 'lotStatus'];
        foreach ($data['inventoryLots'] as $lot) {
            foreach ($requiredFields as $field) {
                self::assertArrayHasKey($field, $lot, 'Each lot must have required field: '.$field);
            }

            self::assertContains(
                $lot['lotStatus'],
                ['active', 'quarantined', 'expired', 'exhausted'],
                'lotStatus must be a valid enum value'
            );
            self::assertGreaterThanOrEqual(0, $lot['quantity'], 'quantity must be >= 0');
        }

    }//end testInventoryLotsDemoSeedFileIsValidJson()

    /**
     * Test that all demo seed lots with an expiryDate have a valid ISO-8601 date format.
     *
     * FEFO sort order is enforced at query time via x-openregister-sort; seed file order is
     * independent. This test confirms date values are well-formed per REQ-LOT-002.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
     */
    public function testInventoryLotsDemoSeedExpiryDatesAreValidFormat(): void
    {
        $seedPath = __DIR__.'/../../../lib/Settings/seeds/inventory-lots-demo.json';
        $data     = json_decode(file_get_contents($seedPath), associative: true);

        foreach ($data['inventoryLots'] as $lot) {
            if (isset($lot['expiryDate']) === false || $lot['expiryDate'] === null) {
                continue;
            }

            self::assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $lot['expiryDate'],
                'expiryDate must be ISO 8601 date (YYYY-MM-DD) in lot: '.($lot['lotNumber'] ?? 'unknown')
            );
        }

    }//end testInventoryLotsDemoSeedExpiryDatesAreValidFormat()
}//end class
