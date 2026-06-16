<?php

/**
 * Unit tests for the PayrollWkrHandoffService.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-001-bruto-netto-berekening.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PayrollCalculator;
use OCA\Shillinq\Service\PayrollWkrHandoffService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Asserts the WKR loonsom payload sums fiscaalLoon over the period.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollWkrHandoffServiceTest extends TestCase
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
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturn('shillinq');

    }//end setUp()

    /**
     * Build the service over an in-memory ObjectService stub.
     *
     * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
     *
     * @return PayrollWkrHandoffService
     */
    private function buildService(array $data): PayrollWkrHandoffService
    {
        $stub = new class($data) {

            /**
             * Schema => rows.
             *
             * @var array<string,array<int,array<string,mixed>>>
             */
            private array $data;

            /**
             * Active schema.
             *
             * @var string
             */
            private string $schema = '';

            /**
             * Constructor.
             *
             * @param array<string,array<int,array<string,mixed>>> $data Data.
             */
            public function __construct(array $data)
            {
                $this->data = $data;
            }//end __construct()

            /**
             * Fluent register setter (no-op).
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
                $this->schema = $schema;
                return $this;
            }//end setSchema()

            /**
             * Filtered findAll.
             *
             * @param array<string,mixed> $params Query parameters.
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $params=[]): array
            {
                $rows    = ($this->data[$this->schema] ?? []);
                $filters = ($params['filters'] ?? []);
                if ($filters === []) {
                    return $rows;
                }

                return array_values(
                    array_filter(
                        $rows,
                        static function (array $row) use ($filters): bool {
                            foreach ($filters as $key => $value) {
                                if (($row[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );
            }//end findAll()
        };

        $this->container->method('get')->willReturn($stub);

        return new PayrollWkrHandoffService(
            container: $this->container,
            appConfig: $this->appConfig,
            calculator: new PayrollCalculator(),
        );

    }//end buildService()

    /**
     * Sums fiscaalLoon for the period (scoped to one administration).
     *
     * @return void
     */
    public function testLoonsomSumsFiscaalLoonForPeriod(): void
    {
        $svc = $this->buildService(
            data: [
                'LoonStrook' => [
                    ['periodeId' => 'lp-1', 'administrationId' => 'adm-1', 'fiscaalLoon' => 4940.00],
                    ['periodeId' => 'lp-1', 'administrationId' => 'adm-1', 'fiscaalLoon' => 6700.00],
                    ['periodeId' => 'lp-1', 'administrationId' => 'adm-1', 'fiscaalLoon' => 3210.50],
                    // Different period -> excluded.
                    ['periodeId' => 'lp-2', 'administrationId' => 'adm-1', 'fiscaalLoon' => 9999.00],
                    // Different admin -> excluded.
                    ['periodeId' => 'lp-1', 'administrationId' => 'adm-2', 'fiscaalLoon' => 9999.00],
                ],
            ]
        );

        $payload = $svc->toWkrLoonsomPayload(administrationId: 'adm-1', periodeId: 'lp-1');

        $this->assertSame('lp-1', $payload['periodeId']);
        $this->assertSame('adm-1', $payload['administrationId']);
        $this->assertEqualsWithDelta(14850.50, $payload['loonsom'], 0.005);
        $this->assertSame(3, $payload['aantalStroken']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertSame('LoonStrook', $payload['source']);

    }//end testLoonsomSumsFiscaalLoonForPeriod()

    /**
     * Returns a zero loonsom when there are no loonstroken for the period.
     *
     * @return void
     */
    public function testLoonsomZeroForEmptyPeriod(): void
    {
        $svc = $this->buildService(data: ['LoonStrook' => []]);

        $payload = $svc->toWkrLoonsomPayload(administrationId: 'adm-1', periodeId: 'lp-1');

        $this->assertSame(0.0, $payload['loonsom']);
        $this->assertSame(0, $payload['aantalStroken']);

    }//end testLoonsomZeroForEmptyPeriod()
}//end class
