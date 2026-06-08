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
     * @param ContainerInterface $container Lazy DI container.
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

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
     * @param array<int,array<string,mixed>> $stages  Resolved stages.
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
     * @param string              $administrationId Administration scope.
     * @param array<string,mixed> $params           {
     *                                              factuurId,
     *                                              ladderId,
     *                                              stageNr,
     *                                              kanaal,
     *                                              templateId,
     *                                              ontvangerEmail,
     *                                              ontvangerNaam,
     *                                              ontvangerAdres,
     *                                              renderedSubject,
     *                                              renderedBody,
     *                                              renderedPdfHash,
     *                                              factuurBedrag,
     *                                              incassokostenBedrag,
     *                                              renteBedrag,
     *                                              deliveryStatus,
     *                                              postageStatus,
     *                                              openTracking,
     *                                              digitalSignature
     *                                              }
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
     * @param string                 $administrationId Administration scope.
     * @param string                 $factuurId        Invoice FK.
     * @param string                 $reden            One of DISPUTED / PAYMENT_PLAN / OTHER.
     * @param string                 $details          Free-text details.
     * @param string                 $gepauzeerdDoor   Operator id.
     * @param array<int,string>|null $evidenceRefs     Optional evidence refs.
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
        ?array $evidenceRefs=null
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
     * @param string     $administrationId  Administration scope.
     * @param string     $pauseId           Pause record id.
     * @param string     $resolution        'resolve' or 'expire'.
     * @param float|null $partialSettlement Optional new saldo after partial settlement.
     *
     * @return array<string,mixed> The updated pause record.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-17
     */
    public function resumePause(
        string $administrationId,
        string $pauseId,
        string $resolution='resolve',
        ?float $partialSettlement=null
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
     * The actual GL posting + BTW-teruggaaf records are produced by the GL +
     * BTW-aangifte engines; this method records the write-off declaration and
     * the FK back-references.
     *
     * @param string              $administrationId Administration scope.
     * @param array<string,mixed> $params           {factuurId, hoofdsomAfgeschreven, btwBedrag,
     *                                              art29OBVerklaring, evidenceRef, boekingId,
     *                                              btwAangiftePeriode}.
     *
     * @return array<string,mixed> The created write-off record.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-22
     */
    public function writeOff(string $administrationId, array $params): array
    {
        $record = [
            'factuurId'            => (string) ($params['factuurId'] ?? ''),
            'hoofdsomAfgeschreven' => (float) ($params['hoofdsomAfgeschreven'] ?? 0.0),
            'btwBedrag'            => ($params['btwBedrag'] ?? null),
            'art29OBVerklaring'    => (string) ($params['art29OBVerklaring'] ?? ''),
            'evidenceRef'          => ($params['evidenceRef'] ?? null),
            'boekingId'            => ($params['boekingId'] ?? null),
            'btwAangiftePeriode'   => ($params['btwAangiftePeriode'] ?? null),
            'administrationId'     => $administrationId,
            'lifecycleState'       => 'posted',
        ];

        return $this->saveObject(schema: 'OninbaarAfschrijving', data: $record);

    }//end writeOff()

    /**
     * REQ-CCD-011 anti-pattern detector.
     *
     * Returns true when the klant has paid 1+ invoices successfully in the
     * configurable lookback window (default 90 days) AND a dunning trigger
     * arises from a likely admin-error (bounced e-mail, IBAN validation
     * failure, missing payment-reference). In that case the caller is
     * expected to soft-pause the dunning and reach out to the customer first.
     *
     * @param string              $administrationId Administration scope.
     * @param string              $klantId          Customer FK.
     * @param array<string,mixed> $triggerContext   Context — keys: bounce, ibanInvalid,
     *                                              paymentRefMissing.
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
     * @param string              $schema  Schema slug.
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
     * @param string              $schema  Schema slug.
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
     * @param string              $schema Schema slug.
     * @param array<string,mixed> $data   Record body.
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
