<?php

/**
 * Unit tests for RetireSubsidieSchema.
 *
 * Verifies that a Subsidie row is only deleted once a matching Order exists
 * (migratedFrom.schema=Subsidie, migratedFrom.key=<key>) — the data-safety
 * invariant that prevents losing unmigrated data — after the fold target was
 * repointed from the retired `Grant` extension schema to the shipped `Order`
 * primitive (abstract-order-primitive).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\RetireSubsidieSchema;
use OCA\Shillinq\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RetireSubsidieSchema.
 */
class RetireSubsidieSchemaTest extends TestCase
{

    /**
     * Settings service mock.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * Container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Group manager mock (resolves the admin IUser).
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * DB connection mock — tableExists() returns false so the schema-row-drop
     * branch is a no-op and the test stays focused on the object-delete logic.
     *
     * @var IDBConnection&MockObject
     */
    private IDBConnection&MockObject $db;

    /**
     * Output mock.
     *
     * @var IOutput&MockObject
     */
    private IOutput&MockObject $output;

    /**
     * Set up shared fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->db     = $this->createMock(IDBConnection::class);
        $this->output = $this->createMock(IOutput::class);

        $this->settingsService->method('getRegisterSlug')->willReturn('shillinq');
        $this->db->method('tableExists')->willReturn(false);

        $admin = $this->createMock(IUser::class);
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$admin]);
        $this->groupManager->method('get')->with('admin')->willReturn($group);

    }//end setUp()

    /**
     * Build a fake, schema-aware ObjectService supporting deleteObject.
     *
     * @param array<string,array<int,array<string,mixed>>> $recordsBySchema Records keyed by schema.
     *
     * @return object
     */
    private function fakeObjectService(array $recordsBySchema): object
    {
        return new class($recordsBySchema) {

            /**
             * @var array<string,array<int,array<string,mixed>>>
             */
            private array $recordsBySchema;

            /**
             * @var array<int,string>
             */
            private array $deleted = [];

            private string $currentSchema = '';

            /**
             * @param array<string,array<int,array<string,mixed>>> $recordsBySchema
             */
            public function __construct(array $recordsBySchema)
            {
                $this->recordsBySchema = $recordsBySchema;

            }//end __construct()

            public function setRegister(string $register): static
            {
                return $this;

            }//end setRegister()

            public function setSchema(string $schema): static
            {
                $this->currentSchema = $schema;
                return $this;

            }//end setSchema()

            /**
             * @param array<string,mixed> $params
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $params): array
            {
                $rows    = ($this->recordsBySchema[$this->currentSchema] ?? []);
                $filters = ($params['filters'] ?? []);
                if ($filters === []) {
                    return $rows;
                }

                return array_values(
                    array_filter(
                        $rows,
                        function (array $row) use ($filters): bool {
                            foreach ($filters as $path => $expected) {
                                if ($this->dotGet($row, (string) $path) !== $expected) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );

            }//end findAll()

            public function deleteObject(string $id, bool $_rbac, bool $_multitenancy): void
            {
                $this->deleted[] = $id;

            }//end deleteObject()

            /**
             * @return array<int,string>
             */
            public function deletedIds(): array
            {
                return $this->deleted;

            }//end deletedIds()

            /**
             * @param array<string,mixed> $row
             */
            private function dotGet(array $row, string $path): mixed
            {
                $segments = explode('.', $path);
                $cursor   = $row;
                foreach ($segments as $segment) {
                    if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
                        return null;
                    }

                    $cursor = $cursor[$segment];
                }

                return $cursor;

            }//end dotGet()
        };

    }//end fakeObjectService()

    /**
     * A Subsidie with a matching folded Order (migratedFrom marker) is deleted.
     */
    public function testDeletesSubsidieWithMatchingOrder(): void
    {
        $subsidie = ['id' => 'sub-1', 'subsidieNumber' => 'SUB-2026-001'];
        $order    = ['migratedFrom' => ['schema' => 'Subsidie', 'key' => 'SUB-2026-001']];

        $fakeOs = $this->fakeObjectService(
            [
                'Subsidie'       => [$subsidie],
                'OrderPrimitive' => [$order],
            ]
        );
        $this->container->method('get')->willReturn($fakeOs);

        $step = new RetireSubsidieSchema(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            db: $this->db,
            container: $this->container,
        );
        $step->run($this->output);

        self::assertSame(['sub-1'], $fakeOs->deletedIds());

    }//end testDeletesSubsidieWithMatchingOrder()

    /**
     * A Subsidie with NO matching folded Order is left in place (data-safety).
     */
    public function testKeepsUnmigratedSubsidie(): void
    {
        $subsidie = ['id' => 'sub-2', 'subsidieNumber' => 'SUB-2026-002'];

        $fakeOs = $this->fakeObjectService(['Subsidie' => [$subsidie]]);
        $this->container->method('get')->willReturn($fakeOs);

        $step = new RetireSubsidieSchema(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            db: $this->db,
            container: $this->container,
        );
        $step->run($this->output);

        self::assertSame([], $fakeOs->deletedIds(), 'a Subsidie without a folded Order must never be deleted');

    }//end testKeepsUnmigratedSubsidie()

    /**
     * An Order folded from a DIFFERENT source schema (e.g. PurchaseOrder) must
     * not be mistaken as proof this Subsidie was migrated.
     */
    public function testDoesNotMatchOrderFromDifferentSourceSchema(): void
    {
        $subsidie       = ['id' => 'sub-3', 'subsidieNumber' => 'SUB-2026-003'];
        $unrelatedOrder = ['migratedFrom' => ['schema' => 'PurchaseOrder', 'key' => 'SUB-2026-003']];

        $fakeOs = $this->fakeObjectService(
            [
                'Subsidie'       => [$subsidie],
                'OrderPrimitive' => [$unrelatedOrder],
            ]
        );
        $this->container->method('get')->willReturn($fakeOs);

        $step = new RetireSubsidieSchema(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            db: $this->db,
            container: $this->container,
        );
        $step->run($this->output);

        self::assertSame([], $fakeOs->deletedIds());

    }//end testDoesNotMatchOrderFromDifferentSourceSchema()

    /**
     * Empty Subsidie set is handled gracefully.
     */
    public function testEmptySubsidieSetHandledGracefully(): void
    {
        $fakeOs = $this->fakeObjectService([]);
        $this->container->method('get')->willReturn($fakeOs);

        $step = new RetireSubsidieSchema(
            settingsService: $this->settingsService,
            logger: $this->logger,
            groupManager: $this->groupManager,
            db: $this->db,
            container: $this->container,
        );
        $step->run($this->output);

        self::assertSame([], $fakeOs->deletedIds());

    }//end testEmptySubsidieSetHandledGracefully()
}//end class
