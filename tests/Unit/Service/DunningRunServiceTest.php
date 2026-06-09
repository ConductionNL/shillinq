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
     * Task-12: stageForOverdueDays picks the highest stage whose threshold has been reached.
     *
     * @return void
     */
    public function testStageForOverdueDaysPicksHighestApplicable(): void
    {
        $service = $this->makeService(os: new InMemoryObjectService());
        $stages  = [
            ['nr' => 1, 'dagenNaVervalDatum' => 0,  'kanaal' => 'EMAIL'],
            ['nr' => 2, 'dagenNaVervalDatum' => 14, 'kanaal' => 'EMAIL'],
            ['nr' => 3, 'dagenNaVervalDatum' => 30, 'kanaal' => 'EMAIL+POSTREGISTRATIE'],
            ['nr' => 4, 'dagenNaVervalDatum' => 60, 'kanaal' => 'AANGETEKENDE_POST'],
            ['nr' => 5, 'dagenNaVervalDatum' => 90, 'kanaal' => 'INCASSOBUREAU_API'],
        ];

        self::assertSame(1, (int) $service->stageForOverdueDays(stages: $stages, dagenVerzuim: 0)['nr']);
        self::assertSame(2, (int) $service->stageForOverdueDays(stages: $stages, dagenVerzuim: 20)['nr']);
        self::assertSame(3, (int) $service->stageForOverdueDays(stages: $stages, dagenVerzuim: 45)['nr']);
        self::assertSame(5, (int) $service->stageForOverdueDays(stages: $stages, dagenVerzuim: 200)['nr']);
        self::assertNull($service->stageForOverdueDays(stages: $stages, dagenVerzuim: -1));

    }//end testStageForOverdueDaysPicksHighestApplicable()

    /**
     * Task-12: tickInvoice emits a DunningRun for the applicable stage when the
     * invoice has crossed the threshold and no prior run exists.
     *
     * @return void
     */
    public function testTickInvoiceEmitsRunForApplicableStage(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'DunningLadder', rows: [
            [
                'id'     => 'ladder-1',
                'stages' => [
                    ['nr' => 1, 'dagenNaVervalDatum' => 0,  'kanaal' => 'EMAIL', 'templateId' => 'tpl-1'],
                    ['nr' => 2, 'dagenNaVervalDatum' => 14, 'kanaal' => 'EMAIL', 'templateId' => 'tpl-2'],
                ],
            ],
        ]);
        $service = $this->makeService(os: $os);

        $now     = new \DateTimeImmutable('2026-06-09T12:00:00Z');
        $invoice = [
            'id'          => 'inv-1',
            'dueDate'     => '2026-05-20',
            'grossAmount' => 8400.00,
            'customerReference' => 'klant-1',
        ];

        $run = $service->tickInvoice(
            administrationId: 'adm-1',
            invoice: $invoice,
            baseLadderId: 'ladder-1',
            params: [],
            now: $now
        );

        self::assertNotNull($run);
        self::assertSame(2, (int) $run['stageNr']);
        self::assertSame('EMAIL', $run['kanaal']);
        self::assertSame(8400.0, (float) $run['factuurBedrag']);
        self::assertSame('executed', $run['lifecycleState']);

    }//end testTickInvoiceEmitsRunForApplicableStage()

    /**
     * Task-12: tickInvoice is a no-op while an active DunningPauseDispute exists.
     *
     * @return void
     */
    public function testTickInvoiceSkipsWhilePaused(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'DunningLadder', rows: [
            ['id' => 'ladder-1', 'stages' => [['nr' => 1, 'dagenNaVervalDatum' => 0, 'kanaal' => 'EMAIL']]],
        ]);
        $os->seed(schema: 'DunningPauseDispute', rows: [
            [
                'administrationId' => 'adm-1',
                'factuurId'        => 'inv-1',
                'lifecycleState'   => 'active',
            ],
        ]);
        $service = $this->makeService(os: $os);

        $result = $service->tickInvoice(
            administrationId: 'adm-1',
            invoice: ['id' => 'inv-1', 'dueDate' => '2026-05-20', 'grossAmount' => 100.0],
            baseLadderId: 'ladder-1',
            params: [],
            now: new \DateTimeImmutable('2026-06-09T12:00:00Z')
        );

        self::assertNull($result);

    }//end testTickInvoiceSkipsWhilePaused()

    /**
     * Task-12: tickInvoice is a no-op when the same stage has already fired for the invoice.
     *
     * @return void
     */
    public function testTickInvoiceIsIdempotentPerStage(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'DunningLadder', rows: [
            ['id' => 'ladder-1', 'stages' => [['nr' => 1, 'dagenNaVervalDatum' => 0, 'kanaal' => 'EMAIL']]],
        ]);
        $os->seed(schema: 'DunningRun', rows: [
            [
                'administrationId' => 'adm-1',
                'factuurId'        => 'inv-1',
                'stageNr'          => 1,
                'lifecycleState'   => 'executed',
            ],
        ]);
        $service = $this->makeService(os: $os);

        $result = $service->tickInvoice(
            administrationId: 'adm-1',
            invoice: ['id' => 'inv-1', 'dueDate' => '2026-05-20', 'grossAmount' => 100.0],
            baseLadderId: 'ladder-1',
            params: [],
            now: new \DateTimeImmutable('2026-06-09T12:00:00Z')
        );

        self::assertNull($result);

    }//end testTickInvoiceIsIdempotentPerStage()

    /**
     * Task-12: tickInvoice is a no-op when the invoice is still within terms.
     *
     * @return void
     */
    public function testTickInvoiceSkipsWhenWithinTerms(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'DunningLadder', rows: [
            ['id' => 'ladder-1', 'stages' => [['nr' => 1, 'dagenNaVervalDatum' => 0, 'kanaal' => 'EMAIL']]],
        ]);
        $service = $this->makeService(os: $os);

        $result = $service->tickInvoice(
            administrationId: 'adm-1',
            invoice: ['id' => 'inv-1', 'dueDate' => '2026-07-01', 'grossAmount' => 100.0],
            baseLadderId: 'ladder-1',
            params: [],
            now: new \DateTimeImmutable('2026-06-09T12:00:00Z')
        );

        self::assertNull($result);

    }//end testTickInvoiceSkipsWhenWithinTerms()

    /**
     * REQ-CCD-010 / task-26: writeOff materialises a balanced GLTransaction
     * (debit bad-debt + VAT-recover, credit AR control).
     *
     * @return void
     */
    public function testWriteOffMaterialisesBalancedGlPosting(): void
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

        $glRows = $os->dump(schema: 'GLTransaction');
        self::assertCount(1, $glRows, 'one GL transaction materialised');
        $journal = $glRows[0];

        // boekingId on the OninbaarAfschrijving points at the GL transaction.
        self::assertSame($journal['id'], $persisted['boekingId']);
        self::assertSame('inv-1', $journal['sourceReference']);
        self::assertSame('posted', $journal['state']);
        self::assertTrue((bool) $journal['isBalanced']);

        // 3 postings: debit bad-debt 420000c + debit VAT-recover 88200c, credit AR 508200c.
        $postings = (array) $journal['postings'];
        self::assertCount(3, $postings);

        $debit  = 0;
        $credit = 0;
        foreach ($postings as $line) {
            $debit  += (int) $line['debitCents'];
            $credit += (int) $line['creditCents'];
        }
        self::assertSame($debit, $credit, 'GL posting must balance');
        self::assertSame(508200, $debit, 'debit total = hoofdsom + btw in cents');

    }//end testWriteOffMaterialisesBalancedGlPosting()

    /**
     * REQ-CCD-010 / task-27: writeOff queues a `VATLine` correction line keyed
     * to the next aangifte period with the FK back to the OninbaarAfschrijving.
     *
     * @return void
     */
    public function testWriteOffQueuesArt29ObCorrectionVatLine(): void
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

        $lines = $os->dump(schema: 'VATLine');
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertSame('2026-Q2', $line['returnId']);
        self::assertSame('CORRECTION_ART_29_OB', $line['type']);
        self::assertSame(-882.0, (float) $line['vatAmount']);
        self::assertSame($persisted['id'], $line['sourceOninbaarRef']);
        self::assertSame('inv-1', $line['sourceInvoiceRef']);

    }//end testWriteOffQueuesArt29ObCorrectionVatLine()

    /**
     * Task-22: when the caller supplies its own `boekingId`, the write-off
     * reuses it instead of materialising a duplicate GL posting (idempotent
     * for callers that already produced the journal upstream).
     *
     * @return void
     */
    public function testWriteOffHonorsCallerProvidedBoekingId(): void
    {
        $os      = new InMemoryObjectService();
        $service = $this->makeService(os: $os);

        $persisted = $service->writeOff(administrationId: 'adm-1', params: [
            'factuurId'            => 'inv-1',
            'hoofdsomAfgeschreven' => 1000.00,
            'btwBedrag'            => 210.00,
            'art29OBVerklaring'    => 'Schuldsanering',
            'boekingId'            => 'caller-gl-7',
        ]);

        self::assertSame('caller-gl-7', $persisted['boekingId']);
        self::assertCount(0, $os->dump(schema: 'GLTransaction'), 'caller boekingId skips re-posting');

    }//end testWriteOffHonorsCallerProvidedBoekingId()

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

    /**
     * Task-23: detectAdminError prefers the AR `Invoice.paid` history over the
     * legacy DunningRun heuristic once the AR core is present.
     *
     * @return void
     */
    public function testAdminErrorDetectorPrefersInvoicePaidHistory(): void
    {
        $os = new InMemoryObjectService();
        $os->seed(schema: 'Invoice', rows: [
            [
                'id'                 => 'inv-1',
                'administrationId'   => 'adm-1',
                'customerReference'  => 'klant-1',
                'status'             => 'paid',
                'paidOn'             => (new \DateTimeImmutable('-15 days'))->format('Y-m-d'),
            ],
        ]);
        $service = $this->makeService(os: $os);

        self::assertTrue($service->detectAdminError(
            administrationId: 'adm-1',
            klantId: 'klant-1',
            triggerContext: ['paymentRefMissing' => true]
        ));

        // No matching paid invoice + no DunningRun history → no flag.
        $os2     = new InMemoryObjectService();
        $service2 = $this->makeService(os: $os2);
        self::assertFalse($service2->detectAdminError(
            administrationId: 'adm-1',
            klantId: 'klant-other',
            triggerContext: ['paymentRefMissing' => true]
        ));

    }//end testAdminErrorDetectorPrefersInvoicePaidHistory()

}//end class
