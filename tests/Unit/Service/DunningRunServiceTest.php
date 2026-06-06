<?php

/**
 * Unit tests for DunningRunService.
 *
 * Tests the orchestration logic with an in-memory ObjectService stub so the
 * tests stay hermetic — no Nextcloud bootstrap, no OR runtime. Covers:
 *   - resolveLadderForKlant() with and without an active override (REQ-CCD-001)
 *   - executeStage() refuses while a pause is active (REQ-CCD-004)
 *   - executeStage() materialises lifecycleState=executed (REQ-CCD-002)
 *   - pause() sets the 60-day hard deadline (REQ-CCD-004)
 *   - resumePause() flips to resolved / hardDeadlineExpired (REQ-CCD-004)
 *   - writeOff() captures art29OBVerklaring (REQ-CCD-010)
 *   - detectAdminError() flags good customers + admin error context (REQ-CCD-011)
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BIKStaffelCalculator;
use OCA\Shillinq\Service\DunningRunService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__.'/InMemoryObjectService.php';

/**
 * DunningRunService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DunningRunServiceTest extends TestCase
{
    /**
     * @return DunningRunService
     */
    private function makeService(InMemoryObjectService $os): DunningRunService
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($os);

        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                $values = [
                    'register'                                       => 'shillinq',
                    'dunning.dispute_pause_hard_deadline_days'       => '60',
                    'dunning.admin_error_lookback_days'              => '90',
                ];
                return $values[$key] ?? $default;
            }
        );

        return new DunningRunService(
            container: $container,
            appConfig: $appConfig,
            bik: new BIKStaffelCalculator(),
            logger: new NullLogger(),
        );

    }//end makeService()

    /**
     * REQ-CCD-001: resolveLadderForKlant returns base ladder when no override.
     *
     * @return void
     */
    public function testResolveLadderFallsBackToBaseWhenNoOverride(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'DunningLadder', rows: [
            [
                'id'     => 'ladder-1',
                'stages' => [['nr' => 1, 'dagenNaVervalDatum' => 0, 'naam' => 'Reminder', 'kanaal' => 'EMAIL']],
            ],
        ]);

        $service  = $this->makeService(os: $os);
        $resolved = $service->resolveLadderForKlant(administrationId: 'adm-1', klantId: 'klant-1', baseLadderId: 'ladder-1');

        self::assertSame('base', $resolved['source']);
        self::assertNull($resolved['override']);
        self::assertCount(1, $resolved['stages']);

    }//end testResolveLadderFallsBackToBaseWhenNoOverride()

    /**
     * REQ-CCD-001: active override replaces the base ladder's stages.
     *
     * @return void
     */
    public function testResolveLadderUsesActiveOverride(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'DunningLadder', rows: [
            ['id' => 'ladder-1', 'stages' => [['nr' => 1, 'kanaal' => 'EMAIL']]],
        ]);
        $os->seed(schema: 'KlantLadderOverride', rows: [
            [
                'id'             => 'ovr-1',
                'klantId'        => 'klant-1',
                'baseLadderId'   => 'ladder-1',
                'lifecycleState' => 'active',
                'overrides'      => [
                    'stages' => [
                        ['nr' => 1, 'kanaal' => 'EMAIL'],
                        ['nr' => 2, 'kanaal' => 'EMAIL'],
                    ],
                ],
            ],
        ]);

        $service  = $this->makeService(os: $os);
        $resolved = $service->resolveLadderForKlant(administrationId: 'adm-1', klantId: 'klant-1', baseLadderId: 'ladder-1');

        self::assertSame('override', $resolved['source']);
        self::assertCount(2, $resolved['stages']);

    }//end testResolveLadderUsesActiveOverride()

    /**
     * REQ-CCD-004: executeStage refuses while an active pause exists.
     *
     * @return void
     */
    public function testExecuteStageRefusesWhilePaused(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'DunningPauseDispute', rows: [
            [
                'id'               => 'pause-1',
                'administrationId' => 'adm-1',
                'factuurId'        => 'inv-1',
                'lifecycleState'   => 'active',
            ],
        ]);

        $service = $this->makeService(os: $os);
        $this->expectException(RuntimeException::class);
        $service->executeStage(administrationId: 'adm-1', params: [
            'factuurId' => 'inv-1',
            'ladderId'  => 'ladder-1',
            'stageNr'   => 1,
            'templateId' => 'tpl-1',
            'kanaal'    => 'EMAIL',
        ]);

    }//end testExecuteStageRefusesWhilePaused()

    /**
     * REQ-CCD-002: executeStage persists a DunningRun in lifecycleState=executed.
     *
     * @return void
     */
    public function testExecuteStagePersistsExecutedRun(): void
    {
        $os      = new InMemoryObjectService();
        $service = $this->makeService(os: $os);

        $persisted = $service->executeStage(administrationId: 'adm-1', params: [
            'factuurId'        => 'inv-1',
            'ladderId'         => 'ladder-1',
            'stageNr'          => 1,
            'templateId'       => 'tpl-stage1',
            'kanaal'           => 'EMAIL',
            'ontvangerEmail'   => 'klant@example.nl',
            'renderedSubject'  => 'Reminder factuur',
            'renderedBody'     => 'Vriendelijk verzoek',
            'deliveryStatus'   => 'DELIVERED',
            'factuurBedrag'    => 1234.56,
        ]);

        self::assertSame('executed', $persisted['lifecycleState']);
        self::assertSame('EMAIL', $persisted['kanaal']);
        self::assertSame(1234.56, $persisted['factuurBedrag']);
        self::assertNotNull($persisted['uitgevoerdOp']);

    }//end testExecuteStagePersistsExecutedRun()

    /**
     * REQ-CCD-004: pause sets hardDeadlineEindigt at pauzeStart + 60 days.
     *
     * @return void
     */
    public function testPauseSetsSixtyDayHardDeadline(): void
    {
        $os      = new InMemoryObjectService();
        $service = $this->makeService(os: $os);

        $pause = $service->pause(
            administrationId: 'adm-1',
            factuurId: 'inv-1',
            reden: 'DISPUTED',
            details: 'Klant betwist',
            gepauzeerdDoor: 'user-1',
        );

        self::assertSame('active', $pause['lifecycleState']);
        $start    = new \DateTimeImmutable((string) $pause['pauzeStart']);
        $deadline = new \DateTimeImmutable((string) $pause['hardDeadlineEindigt']);
        self::assertSame(60, (int) $start->diff($deadline)->days);

    }//end testPauseSetsSixtyDayHardDeadline()

    /**
     * REQ-CCD-004: resumePause flips lifecycleState (resolve / expire).
     *
     * @return void
     */
    public function testResumePauseFlipsLifecycleState(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'DunningPauseDispute', rows: [
            ['id' => 'pause-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'active'],
        ]);
        $service = $this->makeService(os: $os);

        $resolved = $service->resumePause(administrationId: 'adm-1', pauseId: 'pause-1', resolution: 'resolve');
        self::assertSame('resolved', $resolved['lifecycleState']);
        self::assertNotNull($resolved['pauzeEind']);

        $os2 = new InMemoryObjectService();
        $os2->seed(schema: 'DunningPauseDispute', rows: [
            ['id' => 'pause-2', 'administrationId' => 'adm-1', 'lifecycleState' => 'active'],
        ]);
        $service2 = $this->makeService(os: $os2);
        $expired  = $service2->resumePause(administrationId: 'adm-1', pauseId: 'pause-2', resolution: 'expire');
        self::assertSame('hardDeadlineExpired', $expired['lifecycleState']);

    }//end testResumePauseFlipsLifecycleState()

    /**
     * REQ-CCD-010: writeOff materialises the OninbaarAfschrijving record.
     *
     * @return void
     */
    public function testWriteOffPersistsRecord(): void
    {
        $os      = new InMemoryObjectService();
        $service = $this->makeService(os: $os);

        $persisted = $service->writeOff(administrationId: 'adm-1', params: [
            'factuurId'            => 'inv-1',
            'hoofdsomAfgeschreven' => 4200.00,
            'btwBedrag'            => 882.00,
            'art29OBVerklaring'    => 'Faillissement vonnis 2026-04-12',
            'btwAangiftePeriode'   => '2026-Q2',
        ]);

        self::assertSame('posted', $persisted['lifecycleState']);
        self::assertSame(4200.0, $persisted['hoofdsomAfgeschreven']);
        self::assertStringContainsString('Faillissement', (string) $persisted['art29OBVerklaring']);

    }//end testWriteOffPersistsRecord()

    /**
     * REQ-CCD-011: detectAdminError flags good customers + admin-error trigger.
     *
     * @return void
     */
    public function testAdminErrorDetectorFlagsGoodCustomers(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'DunningRun', rows: [
            [
                'id'               => 'dr-1',
                'administrationId' => 'adm-1',
                'deliveryStatus'   => 'DELIVERED',
                'uitgevoerdOp'     => (new \DateTimeImmutable('-30 days'))->format(DATE_ATOM),
            ],
        ]);

        $service = $this->makeService(os: $os);

        self::assertTrue($service->detectAdminError(
            administrationId: 'adm-1',
            klantId: 'klant-1',
            triggerContext: ['ibanInvalid' => true]
        ));

        // No trigger context — no flag, even with prior runs.
        self::assertFalse($service->detectAdminError(
            administrationId: 'adm-1',
            klantId: 'klant-1',
            triggerContext: []
        ));

    }//end testAdminErrorDetectorFlagsGoodCustomers()

}//end class
