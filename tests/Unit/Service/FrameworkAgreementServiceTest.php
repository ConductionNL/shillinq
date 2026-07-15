<?php

/**
 * Unit tests for FrameworkAgreementService.
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
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Lifecycle\FrameworkAgreementDrawdownGuard;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FrameworkAgreementService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proves the call-off drawdown (REQ-PG-004): within-ceiling increments
 * drawnAmount, over-ceiling is blocked with no write.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FrameworkAgreementServiceTest extends TestCase
{
    /**
     * Build the service + real guard over one shared in-memory stub.
     *
     * @param array<int,array<string,mixed>> $agreements FrameworkAgreement rows.
     * @param InMemoryObjectServiceStub|null $stubOut    Receives the stub for post-assertions.
     *
     * @return FrameworkAgreementService
     */
    private function buildService(array $agreements, ?InMemoryObjectServiceStub &$stubOut=null): FrameworkAgreementService
    {
        $stub    = new InMemoryObjectServiceStub(['FrameworkAgreement' => $agreements]);
        $stubOut = $stub;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($stub);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('shillinq');

        $logger = $this->createMock(LoggerInterface::class);

        $administrationContext = $this->createMock(AdministrationContextService::class);
        $administrationContext->method('canAccess')->willReturn(true);

        $guard = new FrameworkAgreementDrawdownGuard(container: $container, appConfig: $appConfig, logger: $logger);

        return new FrameworkAgreementService(
            container: $container,
            appConfig: $appConfig,
            administrationContext: $administrationContext,
            drawdownGuard: $guard,
            logger: $logger,
        );
    }//end buildService()

    /**
     * A near-ceiling active agreement (5 000 000 ceiling, 4 800 000 drawn).
     *
     * @return array<string,mixed>
     */
    private function nearCeilingAgreement(): array
    {
        return [
            'id'               => 'fa-1',
            'administrationId' => 'adm-1',
            'agreementNumber'  => 'FA-1',
            'ceilingAmount'    => 5000000,
            'drawnAmount'      => 4800000,
            'statusCode'       => 'active',
            'validFrom'        => '2026-01-01',
            'validUntil'       => '2028-12-31',
        ];
    }//end nearCeilingAgreement()

    /**
     * A within-ceiling call-off increments drawnAmount.
     *
     * @return void
     */
    public function testCallOffWithinCeilingIncrementsDrawnAmount(): void
    {
        $service = $this->buildService(agreements: [$this->nearCeilingAgreement()]);

        $updated = $service->recordCallOff(administrationId: 'adm-1', frameworkAgreementId: 'FA-1', addCents: 100000);
        self::assertSame(4900000, $updated['drawnAmount']);
    }//end testCallOffWithinCeilingIncrementsDrawnAmount()

    /**
     * An over-ceiling call-off is blocked and nothing is drawn down.
     *
     * @return void
     */
    public function testCallOffOverCeilingIsBlockedWithNoWrite(): void
    {
        $service = $this->buildService(agreements: [$this->nearCeilingAgreement()], stubOut: $stub);

        try {
            $service->recordCallOff(administrationId: 'adm-1', frameworkAgreementId: 'FA-1', addCents: 300000);
            self::fail('Expected the over-ceiling call-off to be blocked.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('ceiling', $e->getMessage());
        }

        self::assertSame([], $stub->saved, 'No FrameworkAgreement drawdown should have been persisted.');
    }//end testCallOffOverCeilingIsBlockedWithNoWrite()
}//end class
