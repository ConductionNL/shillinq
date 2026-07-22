<?php

/**
 * Unit tests for OrdersAuditCommand.
 *
 * Verifies the `occ shillinq:orders:audit` count-equality guard (REQ-ORD-003):
 * exit 0 when every Subsidie/PurchaseOrder/DBAOpdracht row has a matching
 * folded Order, exit 1 with a MISMATCH line otherwise.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Command
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

namespace OCA\Shillinq\Tests\Unit\Command;

use OCA\Shillinq\Command\OrdersAuditCommand;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for OrdersAuditCommand.
 */
class OrdersAuditCommandTest extends TestCase
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
     * Set up shared fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

    }//end setUp()

    /**
     * Build a fake, schema-aware ObjectService.
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
                        static function (array $row) use ($filters): bool {
                            foreach ($filters as $path => $expected) {
                                if (($row['migratedFrom']['schema'] ?? null) !== $expected) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );

            }//end findAll()
        };

    }//end fakeObjectService()

    /**
     * PASS (exit 0) when every source row has a matching folded Order.
     */
    public function testPassesWhenAllRowsMigrated(): void
    {
        $fakeOs = $this->fakeObjectService(
            [
                'Subsidie'      => [['id' => 's1'], ['id' => 's2']],
                'PurchaseOrder' => [['id' => 'p1']],
                'DBAOpdracht'   => [],
                'Order'         => [
                    ['migratedFrom' => ['schema' => 'Subsidie', 'key' => 's1']],
                    ['migratedFrom' => ['schema' => 'Subsidie', 'key' => 's2']],
                    ['migratedFrom' => ['schema' => 'PurchaseOrder', 'key' => 'p1']],
                ],
            ]
        );
        $this->container->method('get')->willReturn($fakeOs);

        $command  = new OrdersAuditCommand($this->settingsService, $this->container);
        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('PASS', $tester->getDisplay());

    }//end testPassesWhenAllRowsMigrated()

    /**
     * FAIL (exit 1) when a source schema has unmigrated rows.
     */
    public function testFailsWhenRowsUnmigrated(): void
    {
        $fakeOs = $this->fakeObjectService(
            [
                'Subsidie'      => [['id' => 's1'], ['id' => 's2']],
                'PurchaseOrder' => [],
                'DBAOpdracht'   => [],
                'Order'         => [
                    ['migratedFrom' => ['schema' => 'Subsidie', 'key' => 's1']],
                ],
            ]
        );
        $this->container->method('get')->willReturn($fakeOs);

        $command  = new OrdersAuditCommand($this->settingsService, $this->container);
        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('MISMATCH', $tester->getDisplay());
        self::assertStringContainsString('FAIL', $tester->getDisplay());

    }//end testFailsWhenRowsUnmigrated()

    /**
     * A fresh tenant with zero source rows is a valid PASS (no-op).
     */
    public function testPassesOnFreshTenantWithNoSourceRows(): void
    {
        $fakeOs = $this->fakeObjectService([]);
        $this->container->method('get')->willReturn($fakeOs);

        $command  = new OrdersAuditCommand($this->settingsService, $this->container);
        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);

    }//end testPassesOnFreshTenantWithNoSourceRows()
}//end class
