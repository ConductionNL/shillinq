<?php

/**
 * Unit tests for ComplianceSeeder.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-archiefwet-retention/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ComplianceSeeder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ComplianceSeeder.
 *
 * Covers:
 * - seedAll imports the real seed files into their schemas (counts > 0)
 * - re-run is idempotent: already-present records are skipped, not duplicated
 * - failure when ObjectService cannot be resolved
 */
class ComplianceSeederTest extends TestCase
{

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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

    }//end setUp()

    /**
     * seedAll imports the real seed files; counts are positive on a fresh store.
     *
     * @return void
     */
    public function testSeedAllImportsRecords(): void
    {
        $store = new \stdClass();
        $this->container->method('get')->willReturn($this->buildObjectServiceStub(existing: false));

        $seeder = new ComplianceSeeder(
            container: $this->container,
            logger: $this->logger,
            registerSlug: 'shillinq'
        );

        $result = $seeder->seedAll();

        self::assertTrue($result['success']);
        self::assertArrayHasKey('VatTariff', $result['counts']);
        self::assertArrayHasKey('RetentionRule', $result['counts']);
        self::assertGreaterThan(0, $result['counts']['VatTariff']['seeded']);
        self::assertGreaterThan(0, $result['counts']['RetentionRule']['seeded']);
        self::assertSame(0, $result['counts']['VatTariff']['skipped']);

    }//end testSeedAllImportsRecords()

    /**
     * Re-run is idempotent: when records already exist, they are skipped.
     *
     * @return void
     */
    public function testSeedAllSkipsExistingRecords(): void
    {
        $this->container->method('get')->willReturn($this->buildObjectServiceStub(existing: true));

        $seeder = new ComplianceSeeder(
            container: $this->container,
            logger: $this->logger,
            registerSlug: 'shillinq'
        );

        $result = $seeder->seedAll();

        self::assertTrue($result['success']);
        self::assertSame(0, $result['counts']['VatTariff']['seeded']);
        self::assertGreaterThan(0, $result['counts']['VatTariff']['skipped']);

    }//end testSeedAllSkipsExistingRecords()

    /**
     * Failure when ObjectService cannot be resolved.
     *
     * @return void
     */
    public function testSeedAllFailsWhenObjectServiceUnavailable(): void
    {
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('ObjectService not found'));

        $seeder = new ComplianceSeeder(
            container: $this->container,
            logger: $this->logger,
            registerSlug: 'shillinq'
        );

        $result = $seeder->seedAll();

        self::assertFalse($result['success']);

    }//end testSeedAllFailsWhenObjectServiceUnavailable()

    /**
     * Build an ObjectService stub. When $existing is true, findAll() returns a
     * non-empty array (record already present → skip); otherwise empty (seed).
     *
     * @param bool $existing Whether records already exist in the store.
     *
     * @return object
     */
    private function buildObjectServiceStub(bool $existing): object
    {
        return new class($existing) {

            private bool $existing;

            private int $saveCount = 0;

            public function __construct(bool $existing)
            {
                $this->existing = $existing;
            }//end __construct()

            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * @param  array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                if ($this->existing === true) {
                    return [['id' => 'existing']];
                }

                return [];
            }//end findAll()

            /**
             * @param array<string,mixed> $object
             */
            public function saveObject(array $object, string $register, string $schema): array
            {
                $this->saveCount++;
                return $object;
            }//end saveObject()
        };
    }//end buildObjectServiceStub()
}//end class
