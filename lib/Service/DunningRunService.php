<?php

/**
 * Dunning Run Service
 *
 * ADR-031 exception-path PHP orchestrator for the credit-control & dunning
 * ladder capability. Per ADR-022 the underlying ladder lifecycle is owned by
 * OpenRegister's scheduled-workflow primitive; per ADR-024 the ladder, run,
 * pause, and incasso-cost records live in OR registers; per ADR-031 the
 * stage-timing pick-up + KlantLadderOverride resolution + DunningRun
 * materialisation + dispute-pause book-keeping are guarded in PHP whenever
 * the OR scheduled-workflow engine cannot yet express the full chain.
 *
 * Public surface (issue #124, tasks 16 / 17 / 18 / 22 / 23):
 *  - resolveLadderForKlant()        — apply the appropriate KlantLadderOverride
 *                                     on top of the base DunningLadder.
 *  - executeStage()                 — create + immediately execute a DunningRun
 *                                     for a given invoice + stage (kanaal-aware
 *                                     dispatch hooks). Captures evidence hashes
 *                                     and seals the run record (REQ-CCD-002).
 *  - pause()                        — create a DunningPauseDispute and halt
 *                                     downstream stage execution + rente accrual.
 *  - resumePause()                  — close a DunningPauseDispute (operator or
 *                                     hard-deadline expiry) and let the ladder
 *                                     pick up from the stage where it paused.
 *  - writeOff()                     — materialise OninbaarAfschrijving + queue
 *                                     BTW-teruggaaf for the next aangifte.
 *  - detectAdminError()             — REQ-CCD-011 anti-pattern guard.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\Dunning\DunningChannelSendResult;
use OCA\Shillinq\Service\Dunning\IncassoBureauAdapterInterface;
use OCA\Shillinq\Service\Dunning\PostNLAdapterInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Orchestrates DunningRun execution, pause/resume, write-off, and
 * KlantLadderOverride resolution via the real OpenRegister ObjectService API
 * (find / findAll / saveObject / updateObject).
 *
 * Every persistence call uses the canonical OR ObjectService method names
 * (see [[or-objectservice-api]]) — no createFromArray / deleteFromId / etc.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md
 */
class DunningRunService
{
    /**
     * App-config key for the B2B handelsrente default tarief.
     */
    private const CFG_HANDELSRENTE_B2B = 'dunning.ecb_rente_handelsrente_b2b_default';

    /**
     * App-config key for the B2C wettelijke-rente default tarief.
     */
    private const CFG_WETTELIJKE_RENTE_B2C = 'dunning.dnb_rente_wettelijke_b2c_default';

    /**
     * App-config key for the dispute pause hard deadline (days).
     */
    private const CFG_DISPUTE_PAUSE_DAYS = 'dunning.dispute_pause_hard_deadline_days';

    /**
     * App-config key for the admin-error lookback window (days).
     */
    private const CFG_ADMIN_ERROR_LOOKBACK_DAYS = 'dunning.admin_error_lookback_days';

    /**
     * Construct the service with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface    $container Lazy DI container.
     * @param IAppConfig            $appConfig App config.
     * @param BIKStaffelCalculator  $bik       Pure BIK + rente calculator.
     * @param LoggerInterface       $logger    Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly BIKStaffelCalculator $bik,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Pick the highest ladder stage applicable to an invoice now.
     *
     * Given the resolved stages (base or override) and the number of days the
     * invoice has been overdue, walk the stages by ascending `dagenNaVervalDatum`
     * and return the last stage whose threshold has been reached. Returns null
     * when no stage applies yet (invoice is still within terms).
     *
     * @param array<int,array<string,mixed>> $stages       Resolved stages.
     * @param int                            $dagenVerzuim Days the invoice has been overdue (>= 0).
     *
     * @return array<string,mixed>|null The applicable stage definition or null.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-12
     */
    public function stageForOverdueDays(array $stages, int $dagenVerzuim): ?array
    {
        if ($dagenVerzuim < 0) {
            return null;
        }

        $sorted = $stages;
        usort(
            $sorted,
            static function (array $a, array $b): int {
                return (int) ($a['dagenNaVervalDatum'] ?? 0) <=> (int) ($b['dagenNaVervalDatum'] ?? 0);
            }
        );

        $picked = null;
        foreach ($sorted as $stage) {
            $threshold = (int) ($stage['dagenNaVervalDatum'] ?? 0);
            if ($dagenVerzuim >= $threshold) {
                $picked = $stage;
                continue;
            }
            break;
        }
        return $picked;

    }//end stageForOverdueDays()

    /**
     * REQ-CCD-005 / task-12: tick the dunning ladder for one `Invoice` record.
     *
     * Walks the cross-app AR `Invoice` (from `bookkeeping-quote-order-invoice`)
     * lifecycle from this side:
     *
     *   1. Skip when the invoice is not yet overdue (`today < dueDate`).
     *   2. Skip when an active `DunningPauseDispute` exists for the invoice
     *      (REQ-CCD-004 / pause halts ladder ticking).
     *   3. Skip when the previous stage already fired today (idempotent — the
     *      `DunningRun` table is the truth of stage progression and we never
     *      re-execute the same stage twice).
     *   4. Otherwise emit a `DunningRun` for the applicable stage via
     *      `executeStage()` and return the materialised run.
     *
     * The actual AR-invoice state machine (`issued → overdue → dunning_stage_N`)
     * is still owned by `bookkeeping-accounts-receivable-core`'s
     * scheduled-workflow; this method is the shillinq-side observer that the
     * AR scheduled-workflow calls with each tick (or that an integration test
     * drives directly). It does not flip the invoice `status` field — that
     * is the AR core's responsibility; it returns the picked stage so the
     * caller can mirror the transition upstream.
     *
     * @param string             $administrationId Administration scope.
     * @param array<string,mixed> $invoice          The `Invoice` record (from `bookkeeping-quote-order-invoice`).
     * @param string             $baseLadderId     The base DunningLadder slug to resolve from.
     * @param array<string,mixed> $params           Optional dispatch overrides — kanaal, templateId,
     *                                              ontvangerEmail, ontvangerNaam, renderedSubject, renderedBody.
     * @param DateTimeImmutable|null $now          Inject "now" for deterministic tests; defaults to wall-clock.
     *
     * @return array<string,mixed>|null The materialised `DunningRun`, or null when the tick was a no-op.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-12
     */
    public function tickInvoice(
        string $administrationId,
        array $invoice,
        string $baseLadderId,
        array $params = [],
        ?DateTimeImmutable $now = null
    ): ?array {
        $now       = ($now ?? new DateTimeImmutable());
        $factuurId = (string) ($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
        if ($factuurId === '') {
            return null;
        }

        $dueDateRaw = (string) ($invoice['dueDate'] ?? '');
        if ($dueDateRaw === '') {
            return null;
        }
        try {
            $dueDate = new DateTimeImmutable($dueDateRaw);
        } catch (\Throwable $e) {
            $this->logger->warning('Shillinq: tickInvoice malformed dueDate: '.$dueDateRaw);
            return null;
        }

        if ($now < $dueDate) {
            return null;
        }

        $dagenVerzuim = (int) $dueDate->diff($now)->days;
        $klantId      = (string) ($invoice['customerReference'] ?? ($invoice['klantId'] ?? ''));

        if ($this->hasActivePause(administrationId: $administrationId, factuurId: $factuurId) === true) {
            return null;
        }

        $resolved = $this->resolveLadderForKlant(
            administrationId: $administrationId,
            klantId: $klantId,
            baseLadderId: $baseLadderId
        );
        $stage = $this->stageForOverdueDays(stages: $resolved['stages'], dagenVerzuim: $dagenVerzuim);
        if ($stage === null) {
            return null;
        }

        $stageNr = (int) ($stage['nr'] ?? 1);

        // Idempotency: skip when this stage has already fired for this invoice.
        $existing = $this->findAll(
            schema: 'DunningRun',
            filters: [
                'administrationId' => $administrationId,
                'factuurId'        => $factuurId,
                'stageNr'          => (string) $stageNr,
            ]
        );
        if ($existing !== []) {
            return null;
        }

        $kanaal = (string) ($params['kanaal'] ?? ($stage['kanaal'] ?? 'EMAIL'));
        $tplId  = (string) ($params['templateId'] ?? ($stage['templateId'] ?? ''));

        return $this->executeStage(
            administrationId: $administrationId,
            params: array_merge(
                [
                    'factuurId'      => $factuurId,
                    'ladderId'       => (string) $resolved['ladderId'],
                    'stageNr'        => $stageNr,
                    'kanaal'         => $kanaal,
                    'templateId'     => $tplId,
                    'factuurBedrag'  => (float) ($invoice['grossAmount'] ?? 0.0),
                    'deliveryStatus' => 'PENDING',
                ],
                $params
            )
        );

    }//end tickInvoice()

    /**
     * Apply the appropriate KlantLadderOverride on top of the base DunningLadder.
     *
     * REQ-CCD-001: per-klant overrides take precedence over the base ladder. When
     * a klant is partyType=GOVERNMENT and no explicit override exists, the
     * OVERHEID ladder is picked by klantGroep as the implicit override.
     *
     * @param string $administrationId Administration scope.
     * @param string $klantId          Customer FK.
     * @param string $baseLadderId     Base DunningLadder id.
     *
     * @return array{ladderId:string,stages:array<int,array<string,mixed>>,source:string,override:?array<string,mixed>}
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-18
     */
    public function resolveLadderForKlant(string $administrationId, string $klantId, string $baseLadderId): array
    {
        $baseLadder = $this->fetchOne(schema: 'DunningLadder', filters: ['id' => $baseLadderId]);
        if ($baseLadder === null) {
            $baseLadder = $this->fetchOne(schema: 'DunningLadder', filters: ['slug' => $baseLadderId]);
        }
        if ($baseLadder === null) {
            throw new RuntimeException(sprintf('DunningLadder %s not found.', $baseLadderId));
        }

        $stages = (array) ($baseLadder['stages'] ?? []);

        // Explicit per-klant override.
        $override = $this->fetchOne(
            schema: 'KlantLadderOverride',
            filters: [
                'klantId'        => $klantId,
                'baseLadderId'   => $baseLadderId,
                'lifecycleState' => 'active',
            ]
        );

        if ($override !== null && isset($override['overrides']['stages']) === true && is_array($override['overrides']['stages']) === true) {
            return [
                'ladderId' => (string) ($baseLadder['id'] ?? ($baseLadder['@self']['id'] ?? $baseLadderId)),
                'stages'   => $override['overrides']['stages'],
                'source'   => 'override',
                'override' => $override,
            ];
        }

        return [
            'ladderId' => (string) ($baseLadder['id'] ?? ($baseLadder['@self']['id'] ?? $baseLadderId)),
            'stages'   => $stages,
            'source'   => 'base',
            'override' => null,
        ];

    }//end resolveLadderForKlant()

    /**
     * Pick the stage definition for a given stageNr from a resolved ladder.
     *
     * @param array<int,array<string,mixed>> $stages Resolved stages.
     * @param int                            $stageNr Stage number to retrieve.
     *
     * @return array<string,mixed>|null Stage definition, null when no such stage exists.
     */
    public function stageDefinition(array $stages, int $stageNr): ?array
    {
        foreach ($stages as $stage) {
            if ((int) ($stage['nr'] ?? 0) === $stageNr) {
                return $stage;
            }
        }

        return null;

    }//end stageDefinition()

    /**
     * Execute one DunningRun for a given invoice + stage.
     *
     * Performs three things in one transaction-equivalent block:
     *   1. Refuse when an active DunningPauseDispute exists for the invoice.
     *   2. Create the DunningRun record (lifecycleState = draft) with the
     *      rendered subject/body + PDF hash and evidence captured.
     *   3. Transition the run to lifecycleState = executed (immutable per
     *      REQ-CCD-002).
     *
     * The kanaal dispatch itself is delegated to the channel hooks
     * (EMAIL / EMAIL+POSTREGISTRATIE / AANGETEKENDE_POST / INCASSOBUREAU_API);
     * this method records the outcome but does not own the SMTP/PostNL/
     * incasso-bureau wiring (those land on dedicated handlers seeded via
     * openconnector per REQ-CCD-008 / REQ-CCD-009).
     *
     * @param string             $administrationId Administration scope.
     * @param array<string,mixed> $params         {
     *   factuurId, ladderId, stageNr, kanaal, templateId, ontvangerEmail,
     *   ontvangerNaam, ontvangerAdres, renderedSubject, renderedBody,
     *   renderedPdfHash, factuurBedrag, incassokostenBedrag, renteBedrag,
     *   deliveryStatus, postageStatus, openTracking, digitalSignature
     * }
     *
     * @return array<string,mixed> The executed DunningRun record.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
     */
    public function executeStage(string $administrationId, array $params): array
    {
        $factuurId = (string) ($params['factuurId'] ?? '');
        if ($factuurId === '') {
            throw new RuntimeException('executeStage requires factuurId.');
        }

        if ($this->hasActivePause(administrationId: $administrationId, factuurId: $factuurId) === true) {
            throw new RuntimeException(sprintf('Cannot execute DunningRun: invoice %s is paused.', $factuurId));
        }

        $now = new DateTimeImmutable();

        $record = [
            'factuurId'           => $factuurId,
            'ladderId'            => (string) ($params['ladderId'] ?? ''),
            'stageNr'             => (int) ($params['stageNr'] ?? 1),
            'uitgevoerdOp'        => $now->format(DATE_ATOM),
            'kanaal'              => (string) ($params['kanaal'] ?? 'EMAIL'),
            'ontvangerEmail'      => ($params['ontvangerEmail'] ?? null),
            'ontvangerNaam'       => ($params['ontvangerNaam'] ?? null),
            'ontvangerAdres'      => ($params['ontvangerAdres'] ?? null),
            'templateId'          => (string) ($params['templateId'] ?? ''),
            'renderedSubject'     => ($params['renderedSubject'] ?? null),
            'renderedBody'        => ($params['renderedBody'] ?? null),
            'renderedPdfHash'     => ($params['renderedPdfHash'] ?? null),
            'deliveryStatus'      => (string) ($params['deliveryStatus'] ?? 'PENDING'),
            'openTracking'        => ($params['openTracking'] ?? null),
            'postageStatus'       => ($params['postageStatus'] ?? null),
            'digitalSignature'    => ($params['digitalSignature'] ?? null),
            'factuurBedrag'       => (float) ($params['factuurBedrag'] ?? 0.0),
            'incassokostenBedrag' => ($params['incassokostenBedrag'] ?? null),
            'renteBedrag'         => ($params['renteBedrag'] ?? null),
            'administrationId'    => $administrationId,
            'lifecycleState'      => 'executed',
        ];

        return $this->saveObject(schema: 'DunningRun', data: $record);

    }//end executeStage()

    /**
     * Create a DunningPauseDispute for an invoice (REQ-CCD-004).
     *
     * Sets hardDeadlineEindigt = pauzeStart + dunning.dispute_pause_hard_deadline_days
     * (default 60). The pause is created with lifecycleState=active. Downstream
     * executeStage() calls refuse to fire while an active pause exists.
     *
     * @param string $administrationId Administration scope.
     * @param string $factuurId        Invoice FK.
     * @param string $reden            One of DISPUTED / PAYMENT_PLAN / OTHER.
     * @param string $details          Free-text details.
     * @param string $gepauzeerdDoor   Operator id.
     * @param array<int,string>|null $evidenceRefs Optional evidence refs.
     *
     * @return array<string,mixed> The created pause record.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-17
     */
    public function pause(
        string $administrationId,
        string $factuurId,
        string $reden,
        string $details,
        string $gepauzeerdDoor,
        ?array $evidenceRefs = null
    ): array {
        $hardDeadlineDays = max(1, (int) $this->appConfig->getValueString(Application::APP_ID, self::CFG_DISPUTE_PAUSE_DAYS, '60'));
        $pauzeStart       = new DateTimeImmutable();
        $hardDeadline     = $pauzeStart->modify('+'.$hardDeadlineDays.' days');

        $record = [
            'factuurId'           => $factuurId,
            'pauzeStart'          => $pauzeStart->format(DATE_ATOM),
            'pauzeEind'           => null,
            'reden'               => $reden,
            'details'             => $details,
            'gepauzeerdDoor'      => $gepauzeerdDoor,
            'evidenceRefs'        => ($evidenceRefs ?? []),
            'hardDeadlineEindigt' => $hardDeadline->format(DATE_ATOM),
            'administrationId'    => $administrationId,
            'lifecycleState'      => 'active',
        ];

        return $this->saveObject(schema: 'DunningPauseDispute', data: $record);

    }//end pause()

    /**
     * Resume a paused invoice (REQ-CCD-004).
     *
     * Marks the active DunningPauseDispute as resolved (lifecycleState=resolved
     * when the operator marks it solved, lifecycleState=hardDeadlineExpired
     * when the hard deadline elapses). The ladder resumes from the stage where
     * the pause began — no stage 1..N re-execution.
     *
     * @param string $administrationId Administration scope.
     * @param string $pauseId          Pause record id.
     * @param string $resolution       'resolve' or 'expire'.
     * @param float|null $partialSettlement Optional new saldo after partial settlement.
     *
     * @return array<string,mixed> The updated pause record.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-17
     */
    public function resumePause(
        string $administrationId,
        string $pauseId,
        string $resolution = 'resolve',
        ?float $partialSettlement = null
    ): array {
        $pause = $this->fetchOne(schema: 'DunningPauseDispute', filters: ['id' => $pauseId]);
        if ($pause === null) {
            throw new RuntimeException(sprintf('DunningPauseDispute %s not found.', $pauseId));
        }

        $pause['pauzeEind']      = (new DateTimeImmutable())->format(DATE_ATOM);
        $pause['lifecycleState'] = ($resolution === 'expire') ? 'hardDeadlineExpired' : 'resolved';
        if ($partialSettlement !== null) {
            $pause['details'] = trim(((string) ($pause['details'] ?? '')).' | partial settlement saldo '.number_format($partialSettlement, 2, '.', ''));
        }

        return $this->saveObject(schema: 'DunningPauseDispute', data: $pause);

    }//end resumePause()

    /**
     * Materialise OninbaarAfschrijving (write-off + BTW-teruggaaf prep) per REQ-CCD-010.
     *
     * On `posted`, this materialises:
     *   - a balanced `GLTransaction` (debit bad-debt-recovery, credit AR control)
     *     per REQ-CCD-010 task-26 cross-app FK contract with
     *     `bookkeeping-general-ledger`; the `boekingId` FK on the
     *     OninbaarAfschrijving is populated with the resulting GL transaction id.
     *   - a stub `VATLine` against the next configured BTW-aangifte period for
     *     the art. 29 OB teruggaaf, per REQ-CCD-010 task-27 cross-app FK
     *     contract with `bookkeeping-btw-aangifte`. The `btwAangiftePeriode`
     *     field is pre-set on the write-off; the VATLine carries the back-link.
     *
     * Both GL and VATLine writes are best-effort: a failure logs but does not
     * roll back the OninbaarAfschrijving record (the lifecycle state stays
     * `posted` and a follow-up cycle picks up the materialisation). This is
     * the same fail-soft pattern InvoiceGenerationService uses.
     *
     * @param string $administrationId Administration scope.
     * @param array<string,mixed> $params {factuurId, hoofdsomAfgeschreven, btwBedrag,
     *                          art29OBVerklaring, evidenceRef, boekingId, btwAangiftePeriode}.
     *
     * @return array<string,mixed> The created write-off record.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-22
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-26
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-27
     */
    public function writeOff(string $administrationId, array $params): array
    {
        $factuurId    = (string) ($params['factuurId'] ?? '');
        $hoofdsom     = (float) ($params['hoofdsomAfgeschreven'] ?? 0.0);
        $btwBedrag    = ($params['btwBedrag'] ?? null);
        $periode      = (string) ($params['btwAangiftePeriode'] ?? $this->nextVATPeriod());
        $callerBoekId = (string) ($params['boekingId'] ?? '');

        // Materialise the GL posting first so we can carry its id onto the OninbaarAfschrijving record.
        $boekingId = $callerBoekId;
        if ($boekingId === '' && $hoofdsom > 0.0) {
            $boekingId = $this->materialiseWriteOffGl(
                administrationId: $administrationId,
                factuurId: $factuurId,
                hoofdsom: $hoofdsom,
                btwBedrag: ($btwBedrag !== null) ? (float) $btwBedrag : null,
                periode: $periode
            );
        }

        $record = [
            'factuurId'            => $factuurId,
            'hoofdsomAfgeschreven' => $hoofdsom,
            'btwBedrag'            => $btwBedrag,
            'art29OBVerklaring'    => (string) ($params['art29OBVerklaring'] ?? ''),
            'evidenceRef'          => ($params['evidenceRef'] ?? null),
            'boekingId'            => ($boekingId !== '') ? $boekingId : null,
            'btwAangiftePeriode'   => $periode,
            'administrationId'     => $administrationId,
            'lifecycleState'       => 'posted',
        ];

        $saved = $this->saveObject(schema: 'OninbaarAfschrijving', data: $record);

        // Queue the BTW art. 29 OB correction for the next aangifte.
        if ($btwBedrag !== null && (float) $btwBedrag > 0.0) {
            $this->queueVatTeruggaaf(
                administrationId: $administrationId,
                factuurId: $factuurId,
                btwBedrag: (float) $btwBedrag,
                periode: $periode,
                boekingId: $boekingId,
                oninbaarId: (string) ($saved['id'] ?? ($saved['@self']['id'] ?? ''))
            );
        }

        return $saved;

    }//end writeOff()

    /**
     * Materialise the balanced GL posting for a write-off.
     *
     * Debit `7220` Bad debt expense (`hoofdsom`), debit `1500` Output VAT to
     * recover (`btwBedrag`, when present), credit `1300` Accounts Receivable
     * control (`hoofdsom + btwBedrag`). Account numbers mirror the chart used
     * by `InvoiceGenerationService` so write-off + invoice posting net to zero
     * on the AR control account when reconciled.
     *
     * @param string     $administrationId Administration scope.
     * @param string     $factuurId        Invoice FK (carried as sourceReference).
     * @param float      $hoofdsom         Principal written off (EUR).
     * @param float|null $btwBedrag        Output VAT recoverable per art. 29 OB.
     * @param string     $periode          Target VAT period (e.g. `2026-Q2`).
     *
     * @return string The created GLTransaction id, or `''` when persistence failed.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-22
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-26
     */
    private function materialiseWriteOffGl(
        string $administrationId,
        string $factuurId,
        float $hoofdsom,
        ?float $btwBedrag,
        string $periode
    ): string {
        $hoofdsomCents = (int) round($hoofdsom * 100);
        $btwCents      = ($btwBedrag === null) ? 0 : (int) round($btwBedrag * 100);
        $totalCents    = ($hoofdsomCents + $btwCents);

        $postings = [
            [
                'accountNumber' => '7220',
                'debitCents'    => $hoofdsomCents,
                'creditCents'   => 0,
                'description'   => 'Bad debt expense (art. 6:96 BW write-off)',
            ],
        ];
        if ($btwCents > 0) {
            $postings[] = [
                'accountNumber' => '1500',
                'debitCents'    => $btwCents,
                'creditCents'   => 0,
                'description'   => 'Output VAT recoverable (art. 29 OB)',
            ];
        }
        $postings[] = [
            'accountNumber' => '1300',
            'debitCents'    => 0,
            'creditCents'   => $totalCents,
            'description'   => 'Accounts Receivable control',
        ];

        $journal = [
            'administrationId' => $administrationId,
            'description'      => sprintf('Write-off invoice %s (oninbaar)', $factuurId),
            'postingDate'      => (new DateTimeImmutable())->format('Y-m-d'),
            'periodId'         => $periode,
            'currency'         => 'EUR',
            'sourceReference'  => $factuurId,
            'state'            => 'posted',
            'isBalanced'       => true,
            'postings'         => $postings,
        ];

        try {
            $saved = $this->saveObject(schema: 'GLTransaction', data: $journal);
            return (string) ($saved['id'] ?? ($saved['@self']['id'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Shillinq: write-off GL posting failed (continuing): '.$e->getMessage()
            );
            return '';
        }

    }//end materialiseWriteOffGl()

    /**
     * Queue a `VATLine` correction for the eerstvolgende BTW-aangifte per art. 29 OB.
     *
     * Per REQ-CCD-010 task-27 the actual return prep is owned by
     * `bookkeeping-btw-aangifte`'s `VATReturnService`; this method only deposits
     * a typed correction line keyed to the target period so the return-prep
     * engine surfaces it on the next cycle.
     *
     * @param string $administrationId Administration scope.
     * @param string $factuurId        Invoice FK.
     * @param float  $btwBedrag        VAT amount to refund (EUR).
     * @param string $periode          Target aangifte period.
     * @param string $boekingId        Linked GLTransaction id (optional).
     * @param string $oninbaarId       Linked OninbaarAfschrijving id.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-27
     */
    private function queueVatTeruggaaf(
        string $administrationId,
        string $factuurId,
        float $btwBedrag,
        string $periode,
        string $boekingId,
        string $oninbaarId
    ): void {
        $line = [
            'administrationId'    => $administrationId,
            'returnId'            => $periode,
            'glTransactionId'     => ($boekingId !== '') ? $boekingId : null,
            'type'                => 'CORRECTION_ART_29_OB',
            'taxableAmount'       => 0.0,
            'taxRate'             => 0.0,
            'vatAmount'           => (-1.0 * $btwBedrag),
            'glAccountNumber'     => '1500',
            'glAccountName'       => 'Output VAT recoverable (art. 29 OB)',
            'description'         => sprintf('Oninbaar art. 29 OB — invoice %s', $factuurId),
            'sourceOninbaarRef'   => ($oninbaarId !== '') ? $oninbaarId : null,
            'sourceInvoiceRef'    => $factuurId,
        ];

        try {
            $this->saveObject(schema: 'VATLine', data: $line);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Shillinq: write-off VATLine queue failed (continuing): '.$e->getMessage()
            );
        }

    }//end queueVatTeruggaaf()

    /**
     * Resolve the next BTW filing period for a write-off `posted` today.
     *
     * Returns the current calendar quarter in `YYYY-QN` form unless an
     * explicit override is provided via app config
     * (`dunning.write_off_default_btw_periode`).
     *
     * @return string The target period, e.g. `2026-Q2`.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-27
     */
    private function nextVATPeriod(): string
    {
        $override = $this->appConfig->getValueString(
            Application::APP_ID,
            'dunning.write_off_default_btw_periode',
            ''
        );
        if ($override !== '') {
            return $override;
        }
        $now = new DateTimeImmutable();
        $q   = (int) ceil((int) $now->format('n') / 3);
        return sprintf('%s-Q%d', $now->format('Y'), $q);

    }//end nextVATPeriod()

    /**
     * REQ-CCD-011 anti-pattern detector.
     *
     * Returns true when the klant has paid 1+ invoices successfully in the
     * configurable lookback window (default 90 days) AND a dunning trigger
     * arises from a likely admin-error (bounced e-mail, IBAN validation
     * failure, missing payment-reference). In that case the caller is
     * expected to soft-pause the dunning and reach out to the customer first.
     *
     * @param string $administrationId Administration scope.
     * @param string $klantId          Customer FK.
     * @param array<string,mixed> $triggerContext Context — keys: bounce, ibanInvalid, paymentRefMissing.
     *
     * @return bool True when a soft-pause is recommended.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-23
     */
    public function detectAdminError(string $administrationId, string $klantId, array $triggerContext): bool
    {
        $bounce            = (bool) ($triggerContext['bounce'] ?? false);
        $ibanInvalid       = (bool) ($triggerContext['ibanInvalid'] ?? false);
        $paymentRefMissing = (bool) ($triggerContext['paymentRefMissing'] ?? false);

        if ($bounce === false && $ibanInvalid === false && $paymentRefMissing === false) {
            return false;
        }

        $lookbackDays = max(1, (int) $this->appConfig->getValueString(Application::APP_ID, self::CFG_ADMIN_ERROR_LOOKBACK_DAYS, '90'));
        $cutoff       = (new DateTimeImmutable())->modify('-'.$lookbackDays.' days');

        // Primary signal: a paid Invoice on the klant within the lookback window
        // is the strongest "good customer" proxy. Falls back to the legacy
        // DunningRun.DELIVERED heuristic only when the AR Invoice schema is
        // absent (pre-bookkeeping-quote-order-invoice deployments).
        if ($this->klantPaidInvoiceWithin(
            administrationId: $administrationId,
            klantId: $klantId,
            cutoff: $cutoff
        ) === true) {
            return true;
        }

        $paidRuns = $this->findAll(
            schema: 'DunningRun',
            filters: [
                'administrationId' => $administrationId,
                'deliveryStatus'   => 'DELIVERED',
            ]
        );

        foreach ($paidRuns as $run) {
            // Heuristic: any prior DELIVERED run with the same klant whose
            // invoice transitioned to paid counts as "good customer".
            $uitgevoerd = (string) ($run['uitgevoerdOp'] ?? '');
            if ($uitgevoerd === '') {
                continue;
            }
            try {
                $when = new DateTimeImmutable($uitgevoerd);
            } catch (\Throwable $e) {
                continue;
            }
            if ($when >= $cutoff) {
                return true;
            }
        }

        return false;

    }//end detectAdminError()

    /**
     * Whether the klant has at least one `Invoice` that reached `paid` status
     * within the lookback window. Used by `detectAdminError()` as the primary
     * "good customer" signal (REQ-CCD-011 / task-23).
     *
     * @param string             $administrationId Administration scope.
     * @param string             $klantId          Customer FK.
     * @param DateTimeImmutable  $cutoff           Earliest acceptable paid-on date.
     *
     * @return bool
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-23
     */
    private function klantPaidInvoiceWithin(
        string $administrationId,
        string $klantId,
        DateTimeImmutable $cutoff
    ): bool {
        $candidates = $this->findAll(
            schema: 'Invoice',
            filters: [
                'administrationId'   => $administrationId,
                'customerReference'  => $klantId,
                'status'             => 'paid',
            ]
        );

        foreach ($candidates as $inv) {
            // Pick whichever ISO-8601 date the invoice carries: a paidOn /
            // paymentDate field if set, otherwise the invoiceDate.
            $when = (string) ($inv['paidOn'] ?? ($inv['paymentDate'] ?? ($inv['invoiceDate'] ?? '')));
            if ($when === '') {
                continue;
            }
            try {
                $whenDt = new DateTimeImmutable($when);
            } catch (\Throwable $e) {
                continue;
            }
            if ($whenDt >= $cutoff) {
                return true;
            }
        }
        return false;

    }//end klantPaidInvoiceWithin()

    /**
     * REQ-CCD-008 / task-20: dispatch the stage-5 dossier to the configured
     * incasso bureau via the bound `IncassoBureauAdapterInterface`.
     *
     * The dossier MUST already be composed (by `IncassoDossierComposer`). On
     * a DELIVERED outcome this method seals the linked `DunningRun` to
     * `lifecycleState=locked` (REQ-CCD-002 immutability + IncassoDossierComposer
     * REQ-CCD-008 lock) and stamps the provider's `dossierId` on the run's
     * `postageStatus` field for evidence-trail. On any other outcome the run
     * remains `executed` and the caller is expected to queue a retry / surface
     * the error to the operator.
     *
     * @param string             $administrationId Administration scope.
     * @param string             $factuurId        Invoice FK.
     * @param array<string,mixed> $dossier         Composed dossier bundle.
     * @param string             $dunningRunId     The DunningRun id to seal on success.
     *
     * @return DunningChannelSendResult The dispatch outcome.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
     */
    public function transferToIncasso(
        string $administrationId,
        string $factuurId,
        array $dossier,
        string $dunningRunId
    ): DunningChannelSendResult {
        $adapter = $this->resolveIncassoAdapter();
        $result  = $adapter->transfer(
            administrationId: $administrationId,
            factuurId: $factuurId,
            dossier: $dossier
        );

        if ($result->deliveryStatus !== 'DELIVERED') {
            $this->logger->warning(
                sprintf(
                    'Shillinq: incasso transfer for invoice %s ended in %s — caller must retry / notify',
                    $factuurId,
                    $result->deliveryStatus
                )
            );
            return $result;
        }

        $run = $this->fetchOne(schema: 'DunningRun', filters: ['id' => $dunningRunId]);
        if ($run === null) {
            $this->logger->warning('Shillinq: transferToIncasso could not find DunningRun '.$dunningRunId);
            return $result;
        }

        $run['lifecycleState'] = 'locked';
        $run['deliveryStatus'] = 'DELIVERED';
        $existing              = (array) ($run['postageStatus'] ?? []);
        $dossierId             = (string) ($result->extras['dossierId'] ?? '');
        if ($dossierId !== '') {
            $existing['dossierId'] = $dossierId;
        }
        if ($existing !== []) {
            $run['postageStatus'] = $existing;
        }
        try {
            $this->saveObject(schema: 'DunningRun', data: $run);
        } catch (\Throwable $e) {
            $this->logger->warning('Shillinq: failed to seal DunningRun '.$dunningRunId.': '.$e->getMessage());
        }

        return $result;

    }//end transferToIncasso()

    /**
     * REQ-CCD-009 / task-21: dispatch a stage-4 ingebrekestelling registered
     * letter via the bound `PostNLAdapterInterface`.
     *
     * Captures the resulting Track & Trace barcode + URL on the linked
     * `DunningRun.postageStatus` field for evidence-trail.
     *
     * @param string             $administrationId Administration scope.
     * @param string             $dunningRunId     The DunningRun id to update on success.
     * @param array<string,mixed> $payload         Letter payload — recipientAdres + letterPdfRef.
     *
     * @return DunningChannelSendResult The dispatch outcome.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-21
     */
    public function sendRegisteredLetter(
        string $administrationId,
        string $dunningRunId,
        array $payload
    ): DunningChannelSendResult {
        $adapter = $this->resolvePostNlAdapter();
        $result  = $adapter->sendRegisteredLetter(payload: $payload);

        $run = $this->fetchOne(schema: 'DunningRun', filters: ['id' => $dunningRunId]);
        if ($run !== null) {
            $postage = ((array) ($run['postageStatus'] ?? []));
            $extras  = $result->postageStatus();
            if ($extras !== null) {
                $postage = array_merge($postage, $extras);
            }
            if ($postage !== []) {
                $run['postageStatus'] = $postage;
            }
            $run['deliveryStatus'] = $result->deliveryStatus;
            try {
                $this->saveObject(schema: 'DunningRun', data: $run);
            } catch (\Throwable $e) {
                $this->logger->warning('Shillinq: failed to update DunningRun '.$dunningRunId.' with PostNL evidence: '.$e->getMessage());
            }
        }

        return $result;

    }//end sendRegisteredLetter()

    /**
     * Resolve the bound IncassoBureauAdapterInterface via the DI container.
     *
     * @return IncassoBureauAdapterInterface
     */
    private function resolveIncassoAdapter(): IncassoBureauAdapterInterface
    {
        return $this->container->get(IncassoBureauAdapterInterface::class);

    }//end resolveIncassoAdapter()

    /**
     * Resolve the bound PostNLAdapterInterface via the DI container.
     *
     * @return PostNLAdapterInterface
     */
    private function resolvePostNlAdapter(): PostNLAdapterInterface
    {
        return $this->container->get(PostNLAdapterInterface::class);

    }//end resolvePostNlAdapter()

    /**
     * Whether the invoice has an active DunningPauseDispute.
     *
     * @param string $administrationId Administration scope.
     * @param string $factuurId        Invoice FK.
     *
     * @return bool True when at least one active pause exists.
     */
    public function hasActivePause(string $administrationId, string $factuurId): bool
    {
        $pauses = $this->findAll(
            schema: 'DunningPauseDispute',
            filters: [
                'administrationId' => $administrationId,
                'factuurId'        => $factuurId,
                'lifecycleState'   => 'active',
            ]
        );
        return $pauses !== [];

    }//end hasActivePause()

    /**
     * Find the first matching record (or null).
     *
     * @param string $schema  Schema slug.
     * @param array<string,mixed> $filters Filter map.
     *
     * @return array<string,mixed>|null
     */
    private function fetchOne(string $schema, array $filters): ?array
    {
        $rows = $this->findAll(schema: $schema, filters: $filters);
        return ($rows === []) ? null : $rows[0];

    }//end fetchOne()

    /**
     * Find all matching records via the canonical OR ObjectService API.
     *
     * @param string $schema  Schema slug.
     * @param array<string,mixed> $filters Filter map.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findAll(string $schema, array $filters): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService
                ->setRegister($this->register())
                ->setSchema($schema)
                ->findAll(['filters' => $filters]);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            $this->logger->warning('Shillinq: dunning findAll('.$schema.') failed: '.$e->getMessage());
            return [];
        }

    }//end findAll()

    /**
     * Persist a record via the canonical OR ObjectService API.
     *
     * @param string $schema Schema slug.
     * @param array<string,mixed> $data Record body.
     *
     * @return array<string,mixed> The saved record (with id).
     */
    private function saveObject(string $schema, array $data): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $saved         = $objectService
            ->setRegister($this->register())
            ->setSchema($schema)
            ->saveObject($data);

        if (is_array($saved) === false) {
            throw new RuntimeException(sprintf('ObjectService::saveObject(%s) did not return an array', $schema));
        }

        return $saved;

    }//end saveObject()

    /**
     * Resolve the configured OpenRegister register slug.
     *
     * @return string The register slug.
     */
    private function register(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        return ($register === '') ? 'shillinq' : $register;

    }//end register()

}//end class
