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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\Dunning\DunningChannelSendResult;
use OCA\Shillinq\Service\Dunning\EvidenceRetentionEnforcer;
use OCA\Shillinq\Service\Dunning\IncassoBureauAdapterInterface;
use OCA\Shillinq\Service\Dunning\PostNLAdapterInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Orchestrates DunningRun execution, pause/resume, write-off, and
 * KlantLadderOverride resolution via the real OpenRegister ObjectService API
 * (find / findAll / saveObject / updateObject).
 *
 * Every persistence call uses the canonical OR ObjectService method names
 * (see [[or-objectservice-api]]) — no createFromArray / deleteFromId / etc.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): this service covers the full dunning
 * ladder/pause/channel-dispatch surface; early-return refactor and
 * variable renames deferred pending a dedicated pass.
 */
class DunningRunService {
	/**
	 * App-config key for the dispute pause hard deadline (days).
	 */
	private const CFG_DISPUTE_PAUSE_DAYS = 'dunning.dispute_pause_hard_deadline_days';

	/**
	 * App-config key for the admin-error lookback window (days).
	 */
	private const CFG_ADMIN_ERROR_LOOKBACK_DAYS = 'dunning.admin_error_lookback_days';

	/**
	 * The `kanaal` enum value for a collection-agency API dispatch.
	 *
	 * Matches `LogIncassoBureauAdapter` and the `channel` enum declared in
	 * `lib/Settings/register.d/bookkeeping-credit-control-dunning.json`, so a
	 * result this service builds itself (a refusal, before any adapter is
	 * reached) carries the same channel as one the adapter builds.
	 */
	private const INCASSO_CHANNEL = 'COLLECTION_AGENCY_API';

	/**
	 * Construct the service with lazy DI of OR's ObjectService.
	 *
	 * @param ContainerInterface $container Lazy DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
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
	 * @param array<int,array<string,mixed>> $stages Resolved stages.
	 * @param int $daysInArrears Days the invoice has been overdue (>= 0).
	 *
	 * @return array<string,mixed>|null The applicable stage definition or null.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-12
	 */
	public function stageForOverdueDays(array $stages, int $daysInArrears): ?array {
		if ($daysInArrears < 0) {
			return null;
		}

		$sorted = $stages;
		usort(
			$sorted,
			static function (array $a, array $b): int {
				return (int)($a['daysAfterExpiryDate'] ?? 0) <=> (int)($b['daysAfterExpiryDate'] ?? 0);
			}
		);

		$picked = null;
		foreach ($sorted as $stage) {
			$threshold = (int)($stage['daysAfterExpiryDate'] ?? 0);
			if ($daysInArrears >= $threshold) {
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
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $invoice The `Invoice` record (from `bookkeeping-quote-order-invoice`).
	 * @param string $baseLadderId The base DunningLadder slug to resolve from.
	 * @param array<string,mixed> $params Optional dispatch overrides — kanaal,
	 *                                    templateId, ontvangerEmail, ontvangerNaam,
	 *                                    renderedSubject, renderedBody.
	 * @param DateTimeImmutable|null $now Inject "now" for deterministic tests; defaults to wall-clock.
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
		?DateTimeImmutable $now = null,
	): ?array {
		$now = ($now ?? new DateTimeImmutable());
		$invoiceId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
		if ($invoiceId === '') {
			return null;
		}

		$dueDateRaw = (string)($invoice['dueDate'] ?? '');
		if ($dueDateRaw === '') {
			return null;
		}

		try {
			$dueDate = new DateTimeImmutable($dueDateRaw);
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: tickInvoice malformed dueDate: ' . $dueDateRaw);
			return null;
		}

		if ($now < $dueDate) {
			return null;
		}

		$daysInArrears = (int)$dueDate->diff($now)->days;
		$customerId = (string)($invoice['customerReference'] ?? ($invoice['customerId'] ?? ''));

		if ($this->hasActivePause(administrationId: $administrationId, invoiceId: $invoiceId) === true) {
			return null;
		}

		$resolved = $this->resolveLadderForKlant(
			administrationId: $administrationId,
			customerId: $customerId,
			baseLadderId: $baseLadderId
		);
		$stage = $this->stageForOverdueDays(stages: $resolved['stages'], daysInArrears: $daysInArrears);
		if ($stage === null) {
			return null;
		}

		$stageNr = (int)($stage['nr'] ?? 1);

		// Idempotency: skip when this stage has already fired for this invoice.
		$existing = $this->findAll(
			schema: 'DunningRun',
			filters: [
				'administrationId' => $administrationId,
				'invoiceId' => $invoiceId,
				'stageNr' => (string)$stageNr,
			]
		);
		if ($existing !== []) {
			return null;
		}

		$channel = (string)($params['channel'] ?? ($stage['channel'] ?? 'EMAIL'));
		$tplId = (string)($params['templateId'] ?? ($stage['templateId'] ?? ''));

		return $this->executeStage(
			administrationId: $administrationId,
			params: array_merge(
				[
					'invoiceId' => $invoiceId,
					'ladderId' => (string)$resolved['ladderId'],
					'stageNr' => $stageNr,
					'channel' => $channel,
					'templateId' => $tplId,
					'invoiceAmount' => (float)($invoice['grossAmount'] ?? 0.0),
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
	 * @param string $customerId Customer FK.
	 * @param string $baseLadderId Base DunningLadder id.
	 *
	 * @return array{ladderId:string,stages:array<int,array<string,mixed>>,source:string,override:?array<string,mixed>}
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-18
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $administrationId is not
	 *     read in this method body — fetchOne()/findAll() resolve by id/slug
	 *     alone without an explicit administration filter here. Flagged
	 *     during issue #506 as worth verifying whether OpenRegister's
	 *     ObjectService already scopes this by tenant by default; not
	 *     changed in this style/quality-only pass.
	 */
	public function resolveLadderForKlant(string $administrationId, string $customerId, string $baseLadderId): array {
		$baseLadder = $this->fetchById(
			schema: 'DunningLadder',
			id: $baseLadderId,
			fallbackProperty: 'slug'
		);

		if ($baseLadder === null) {
			throw new RuntimeException(sprintf('DunningLadder %s not found.', $baseLadderId));
		}

		$stages = (array)($baseLadder['stages'] ?? []);

		// Explicit per-klant override.
		$override = $this->fetchOne(
			schema: 'KlantLadderOverride',
			filters: [
				'customerId' => $customerId,
				'baseLadderId' => $baseLadderId,
				'lifecycleState' => 'active',
			]
		);

		if ($override !== null && isset($override['overrides']['stages']) === true && is_array($override['overrides']['stages']) === true) {
			return [
				'ladderId' => (string)($baseLadder['id'] ?? ($baseLadder['@self']['id'] ?? $baseLadderId)),
				'stages' => $override['overrides']['stages'],
				'source' => 'override',
				'override' => $override,
			];
		}

		return [
			'ladderId' => (string)($baseLadder['id'] ?? ($baseLadder['@self']['id'] ?? $baseLadderId)),
			'stages' => $stages,
			'source' => 'base',
			'override' => null,
		];

	}//end resolveLadderForKlant()

	/**
	 * Pick the stage definition for a given stageNr from a resolved ladder.
	 *
	 * @param array<int,array<string,mixed>> $stages Resolved stages.
	 * @param int $stageNr Stage number to retrieve.
	 *
	 * @return array<string,mixed>|null Stage definition, null when no such stage exists.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-18
	 */
	public function stageDefinition(array $stages, int $stageNr): ?array {
		foreach ($stages as $stage) {
			if ((int)($stage['nr'] ?? 0) === $stageNr) {
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
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $params {
	 *                                    factuurId,
	 *                                    ladderId,
	 *                                    stageNr,
	 *                                    kanaal,
	 *                                    templateId,
	 *                                    ontvangerEmail,
	 *                                    ontvangerNaam,
	 *                                    ontvangerAdres,
	 *                                    renderedSubject,
	 *                                    renderedBody,
	 *                                    renderedPdfHash,
	 *                                    factuurBedrag,
	 *                                    incassokostenBedrag,
	 *                                    renteBedrag,
	 *                                    deliveryStatus,
	 *                                    postageStatus,
	 *                                    openTracking,
	 *                                    digitalSignature
	 *                                    }
	 *
	 * @return array<string,mixed> The executed DunningRun record.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
	 */
	public function executeStage(string $administrationId, array $params): array {
		$invoiceId = (string)($params['invoiceId'] ?? '');
		if ($invoiceId === '') {
			throw new RuntimeException('executeStage requires factuurId.');
		}

		if ($this->hasActivePause(administrationId: $administrationId, invoiceId: $invoiceId) === true) {
			throw new RuntimeException(sprintf('Cannot execute DunningRun: invoice %s is paused.', $invoiceId));
		}

		$now = new DateTimeImmutable();

		$record = [
			'invoiceId' => $invoiceId,
			'ladderId' => (string)($params['ladderId'] ?? ''),
			'stageNr' => (int)($params['stageNr'] ?? 1),
			'executedOn' => $now->format(DATE_ATOM),
			'channel' => (string)($params['channel'] ?? 'EMAIL'),
			'recipientEmail' => ($params['recipientEmail'] ?? null),
			'recipientName' => ($params['recipientName'] ?? null),
			'recipientAddress' => ($params['recipientAddress'] ?? null),
			'templateId' => (string)($params['templateId'] ?? ''),
			'renderedSubject' => ($params['renderedSubject'] ?? null),
			'renderedBody' => ($params['renderedBody'] ?? null),
			'renderedPdfHash' => ($params['renderedPdfHash'] ?? null),
			'deliveryStatus' => (string)($params['deliveryStatus'] ?? 'PENDING'),
			'openTracking' => ($params['openTracking'] ?? null),
			'postageStatus' => ($params['postageStatus'] ?? null),
			'digitalSignature' => ($params['digitalSignature'] ?? null),
			'invoiceAmount' => (float)($params['invoiceAmount'] ?? 0.0),
			'collectionCostAmount' => ($params['collectionCostAmount'] ?? null),
			'interestAmount' => ($params['interestAmount'] ?? null),
			'administrationId' => $administrationId,
			'lifecycleState' => 'executed',
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
	 * @param string $invoiceId Invoice FK.
	 * @param string $reason One of DISPUTED / PAYMENT_PLAN / OTHER.
	 * @param string $details Free-text details.
	 * @param string $pausedBy Operator id.
	 * @param array<int,string>|null $evidenceRefs Optional evidence refs.
	 *
	 * @return array<string,mixed> The created pause record.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-17
	 */
	public function pause(
		string $administrationId,
		string $invoiceId,
		string $reason,
		string $details,
		string $pausedBy,
		?array $evidenceRefs = null,
	): array {
		$hardDeadlineDays = max(1, (int)$this->appConfig->getValueString(Application::APP_ID, self::CFG_DISPUTE_PAUSE_DAYS, '60'));
		$pauseStart = new DateTimeImmutable();
		$hardDeadline = $pauseStart->modify('+' . $hardDeadlineDays . ' days');

		$refs = ($evidenceRefs ?? []);
		if ($refs !== []) {
			(new EvidenceRetentionEnforcer())->validateEvidenceRefs(uris: $refs);
		}

		$record = [
			'invoiceId' => $invoiceId,
			'pauseStart' => $pauseStart->format(DATE_ATOM),
			'pauseEnd' => null,
			'reason' => $reason,
			'details' => $details,
			'pausedBy' => $pausedBy,
			'evidenceRefs' => $refs,
			'hardDeadlineEindigt' => $hardDeadline->format(DATE_ATOM),
			'administrationId' => $administrationId,
			'lifecycleState' => 'active',
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
	 * @param string $pauseId Pause record id.
	 * @param string $resolution 'resolve' or 'expire'.
	 * @param float|null $partialSettlement Optional new saldo after partial settlement.
	 *
	 * @return array<string,mixed> The updated pause record.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-17
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $administrationId is not
	 *     read in this method body. Flagged during issue #506 as worth
	 *     verifying whether OpenRegister's ObjectService already scopes this
	 *     by tenant by default; not changed in this style/quality-only pass.
	 */
	public function resumePause(
		string $administrationId,
		string $pauseId,
		string $resolution = 'resolve',
		?float $partialSettlement = null,
	): array {
		$pause = $this->fetchById(schema: 'DunningPauseDispute', id: $pauseId);
		if ($pause === null) {
			throw new RuntimeException(sprintf('DunningPauseDispute %s not found.', $pauseId));
		}

		// Defence in depth: $administrationId was accepted and then IGNORED.
		//
		// fetchById() resolves by id ALONE, so before this check the parameter
		// appeared exactly once in the whole method — its own declaration. A
		// dead tenant parameter is what enabled the resumePause IDOR in the
		// first place: it reads as scoped at every call site while scoping
		// nothing. The controller layer closes the live hole; this closes it
		// again at the service, so the guarantee does not depend on one caller
		// remembering.
		//
		// The message is deliberately IDENTICAL to the not-found case above:
		// a distinct "wrong administration" error would confirm the object
		// exists, turning the guard into an existence oracle — the same
		// masking rule AdministrationContextService::canAccess() follows when
		// it answers 404 rather than 403.
		//
		// administrationId is `required` on DunningPauseDispute, so a stored
		// row always carries one; an empty argument is treated as "no scope
		// asserted" and left to the caller's own guard rather than silently
		// matching everything.
		$pauseAdministrationId = (string)($pause['administrationId'] ?? '');
		if ($administrationId !== '' && $pauseAdministrationId !== $administrationId) {
			throw new RuntimeException(sprintf('DunningPauseDispute %s not found.', $pauseId));
		}

		$pause['pauseEnd'] = (new DateTimeImmutable())->format(DATE_ATOM);
		if ($resolution === 'expire') {
			$pause['lifecycleState'] = 'hardDeadlineExpired';
		} else {
			$pause['lifecycleState'] = 'resolved';
		}

		if ($partialSettlement !== null) {
			$detail = trim((string)($pause['details'] ?? ''));
			$pause['details'] = $detail . ' | partial settlement saldo ' . number_format($partialSettlement, 2, '.', '');
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
	 *                                    art29OBVerklaring, evidenceRef, boekingId,
	 *                                    btwAangiftePeriode}.
	 *
	 * @return array<string,mixed> The created write-off record.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-22
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-26
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-27
	 */
	public function writeOff(string $administrationId, array $params): array {
		$invoiceId = (string)($params['invoiceId'] ?? '');
		$principal = (float)($params['principalDepreciated'] ?? 0.0);
		$vatAmount = ($params['vatAmount'] ?? null);
		$period = (string)($params['vatTaxReturnPeriod'] ?? $this->nextVATPeriod());
		$callerBoekId = (string)($params['entryId'] ?? '');

		// Materialise the GL posting first so we can carry its id onto the OninbaarAfschrijving record.
		$entryId = $callerBoekId;
		if ($entryId === '' && $principal > 0.0) {
			$vatCast = null;
			if ($vatAmount !== null) {
				$vatCast = (float)$vatAmount;
			}

			$entryId = $this->materialiseWriteOffGl(
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				principal: $principal,
				vatAmount: $vatCast,
				period: $period
			);
		}

		$entryIdValue = null;
		if ($entryId !== '') {
			$entryIdValue = $entryId;
		}

		$record = [
			'invoiceId' => $invoiceId,
			'principalDepreciated' => $principal,
			'vatAmount' => $vatAmount,
			'art29OBDeclaration' => (string)($params['art29OBDeclaration'] ?? ''),
			'evidenceRef' => ($params['evidenceRef'] ?? null),
			'entryId' => $entryIdValue,
			'vatTaxReturnPeriod' => $period,
			'administrationId' => $administrationId,
			'lifecycleState' => 'posted',
		];

		$saved = $this->saveObject(schema: 'OninbaarAfschrijving', data: $record);

		// Queue the BTW art. 29 OB correction for the next aangifte.
		if ($vatAmount !== null && (float)$vatAmount > 0.0) {
			$this->queueVatRefund(
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				vatAmount: (float)$vatAmount,
				period: $period,
				entryId: $entryId,
				oninbaarId: (string)($saved['id'] ?? ($saved['@self']['id'] ?? ''))
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
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceId Invoice FK (carried as sourceReference).
	 * @param float $principal Principal written off (EUR).
	 * @param float|null $vatAmount Output VAT recoverable per art. 29 OB.
	 * @param string $period Target VAT period (e.g. `2026-Q2`).
	 *
	 * @return string The created GLTransaction id, or `''` when persistence failed.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-22
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-26
	 */
	private function materialiseWriteOffGl(
		string $administrationId,
		string $invoiceId,
		float $principal,
		?float $vatAmount,
		string $period,
	): string {
		$principalCents = (int)round($principal * 100);
		$vatCents = 0;
		if ($vatAmount !== null) {
			$vatCents = (int)round($vatAmount * 100);
		}

		$totalCents = ($principalCents + $vatCents);

		$postings = [
			[
				'accountNumber' => '7220',
				'debitCents' => $principalCents,
				'creditCents' => 0,
				'description' => 'Bad debt expense (art. 6:96 BW write-off)',
			],
		];
		if ($vatCents > 0) {
			$postings[] = [
				'accountNumber' => '1500',
				'debitCents' => $vatCents,
				'creditCents' => 0,
				'description' => 'Output VAT recoverable (art. 29 OB)',
			];
		}

		$postings[] = [
			'accountNumber' => '1300',
			'debitCents' => 0,
			'creditCents' => $totalCents,
			'description' => 'Accounts Receivable control',
		];

		$journal = [
			'administrationId' => $administrationId,
			'description' => sprintf('Write-off invoice %s (oninbaar)', $invoiceId),
			'postingDate' => (new DateTimeImmutable())->format('Y-m-d'),
			'periodId' => $period,
			'currency' => 'EUR',
			'sourceReference' => $invoiceId,
			'state' => 'posted',
			'isBalanced' => true,
			'postings' => $postings,
		];

		try {
			$saved = $this->saveObject(schema: 'GLTransaction', data: $journal);
			return (string)($saved['id'] ?? ($saved['@self']['id'] ?? ''));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: write-off GL posting failed (continuing): ' . $e->getMessage()
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
	 * @param string $invoiceId Invoice FK.
	 * @param float $vatAmount VAT amount to refund (EUR).
	 * @param string $period Target aangifte period.
	 * @param string $entryId Linked GLTransaction id (optional).
	 * @param string $oninbaarId Linked OninbaarAfschrijving id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-27
	 */
	private function queueVatRefund(
		string $administrationId,
		string $invoiceId,
		float $vatAmount,
		string $period,
		string $entryId,
		string $oninbaarId,
	): void {
		$glTxRef = null;
		if ($entryId !== '') {
			$glTxRef = $entryId;
		}

		$oninbaarRef = null;
		if ($oninbaarId !== '') {
			$oninbaarRef = $oninbaarId;
		}

		$line = [
			'administrationId' => $administrationId,
			'returnId' => $period,
			'glTransactionId' => $glTxRef,
			'type' => 'CORRECTION_ART_29_OB',
			'taxableAmount' => 0.0,
			'taxRate' => 0.0,
			'vatAmount' => (-1.0 * $vatAmount),
			'glAccountNumber' => '1500',
			'glAccountName' => 'Output VAT recoverable (art. 29 OB)',
			'description' => sprintf('Oninbaar art. 29 OB — invoice %s', $invoiceId),
			'sourceOninbaarRef' => $oninbaarRef,
			'sourceInvoiceRef' => $invoiceId,
		];

		try {
			$this->saveObject(schema: 'VATLine', data: $line);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: write-off VATLine queue failed (continuing): ' . $e->getMessage()
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
	private function nextVATPeriod(): string {
		$override = $this->appConfig->getValueString(
			Application::APP_ID,
			'dunning.write_off_default_btw_periode',
			''
		);
		if ($override !== '') {
			return $override;
		}

		$now = new DateTimeImmutable();
		$q = (int)ceil((int)$now->format('n') / 3);
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
	 * @param string $customerId Customer FK.
	 * @param array<string,mixed> $triggerContext Context — keys: bounce, ibanInvalid,
	 *                                            paymentRefMissing.
	 *
	 * @return bool True when a soft-pause is recommended.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-23
	 */
	public function detectAdminError(string $administrationId, string $customerId, array $triggerContext): bool {
		$bounce = (bool)($triggerContext['bounce'] ?? false);
		$ibanInvalid = (bool)($triggerContext['ibanInvalid'] ?? false);
		$paymentRefMissing = (bool)($triggerContext['paymentRefMissing'] ?? false);

		if ($bounce === false && $ibanInvalid === false && $paymentRefMissing === false) {
			return false;
		}

		$lookbackDays = max(1, (int)$this->appConfig->getValueString(Application::APP_ID, self::CFG_ADMIN_ERROR_LOOKBACK_DAYS, '90'));
		$cutoff = (new DateTimeImmutable())->modify('-' . $lookbackDays . ' days');

		// Primary signal: a paid Invoice on the klant within the lookback window
		// is the strongest "good customer" proxy. Falls back to the legacy
		// DunningRun.DELIVERED heuristic only when the AR Invoice schema is
		// absent (pre-bookkeeping-quote-order-invoice deployments).
		if ($this->customerPaidInvoiceWithin(
			administrationId: $administrationId,
			customerId: $customerId,
			cutoff: $cutoff
		) === true
		) {
			return true;
		}

		$paidRuns = $this->findAll(
			schema: 'DunningRun',
			filters: [
				'administrationId' => $administrationId,
				'deliveryStatus' => 'DELIVERED',
			]
		);

		foreach ($paidRuns as $run) {
			// Heuristic: any prior DELIVERED run with the same klant whose
			// invoice transitioned to paid counts as "good customer".
			$uitgevoerd = (string)($run['executedOn'] ?? '');
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
	 * @param string $administrationId Administration scope.
	 * @param string $customerId Customer FK.
	 * @param DateTimeImmutable $cutoff Earliest acceptable paid-on date.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-23
	 */
	private function customerPaidInvoiceWithin(
		string $administrationId,
		string $customerId,
		DateTimeImmutable $cutoff,
	): bool {
		$candidates = $this->findAll(
			schema: 'Invoice',
			filters: [
				'administrationId' => $administrationId,
				'customerReference' => $customerId,
				'status' => 'paid',
			]
		);

		foreach ($candidates as $inv) {
			// Pick whichever ISO-8601 date the invoice carries: a paidOn /
			// paymentDate field if set, otherwise the invoiceDate.
			$when = (string)($inv['paidOn'] ?? ($inv['paymentDate'] ?? ($inv['invoiceDate'] ?? '')));
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
	 * The dossier MUST already be composed (by `IncassoDossierComposer`). The
	 * linked `DunningRun` is sealed to `lifecycleState=locked` (REQ-CCD-002
	 * immutability + IncassoDossierComposer REQ-CCD-008 lock) and the
	 * provider's `dossierId` is stamped on the run's `postageStatus` field for
	 * evidence-trail. On any outcome other than DELIVERED the seal is released
	 * and the run returns to `executed`, so the caller can queue a retry /
	 * surface the error to the operator.
	 *
	 * ## Ordering — the seal is CLAIMED BEFORE the dossier leaves
	 *
	 * 🔴 This method used to call the adapter FIRST and only then look the run
	 * up, with `filters: ['id' => …]` — a shape real OpenRegister answers with
	 * zero rows (see `fetchById()`). The dossier was therefore handed to the
	 * collection agency while the run was NEVER sealed, the `dossierId` was
	 * never stamped, and nothing stopped the same stage-5 dossier being
	 * dispatched to the agency again. Two green unit tests could not see it
	 * because their double matched `id` as a plain array key.
	 *
	 * A dispatch to a debt-collection agency is not revocable, so the write
	 * that records it must not be able to fail after it. The order is:
	 *
	 *  1. resolve the run BY IDENTITY — absent means FAIL CLOSED, no dispatch;
	 *  2. refuse a run already sealed `locked` — that dossier is already with
	 *     the agency, and re-dispatching it is the harm this seal prevents;
	 *  3. resolve the adapter, which throws when unbound — before the seal, so
	 *     a configuration error cannot lock a run it never dispatched;
	 *  4. WRITE the seal (`locked`, delivery PENDING) — if that write fails,
	 *     nothing is dispatched;
	 *  5. dispatch;
	 *  6. stamp the provider's `dossierId` on the sealed run.
	 *
	 * On a non-DELIVERED outcome the seal is released back to `executed`. If
	 * THAT write fails the run stays `locked`, i.e. it fails closed: a
	 * blocked retry is recoverable by an operator, a duplicate dossier at a
	 * collection agency is not.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceId Invoice FK.
	 * @param array<string,mixed> $dossier Composed dossier bundle.
	 * @param string $dunningRunId The DunningRun id to seal on success.
	 *
	 * @return DunningChannelSendResult The dispatch outcome.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
	 */
	public function transferToIncasso(
		string $administrationId,
		string $invoiceId,
		array $dossier,
		string $dunningRunId,
	): DunningChannelSendResult {
		// 1. Resolve the run BEFORE anything leaves the building. A dossier
		// dispatched against a run this app cannot find is a dossier with no
		// evidence trail and no re-dispatch guard, so absent = refuse.
		$run = $this->fetchById(schema: 'DunningRun', id: $dunningRunId);
		if ($run === null) {
			$this->logger->error(
				'Shillinq: transferToIncasso could not find DunningRun ' . $dunningRunId
				. ' — dossier NOT dispatched'
			);
			return new DunningChannelSendResult(
				channel: self::INCASSO_CHANNEL,
				deliveryStatus: 'FAILED',
				errorMessage: sprintf('DunningRun %s not found — dossier not dispatched.', $dunningRunId)
			);
		}

		// 2. A sealed run has already been handed to the agency. Report the
		// dossier that IS with them rather than sending a second one.
		if ((string)($run['lifecycleState'] ?? '') === 'locked') {
			$this->logger->warning(
				'Shillinq: DunningRun ' . $dunningRunId . ' is already sealed — refusing to re-dispatch'
			);
			return new DunningChannelSendResult(
				channel: self::INCASSO_CHANNEL,
				deliveryStatus: 'DELIVERED',
				extras: [
					'dossierId' => (string)(((array)($run['postageStatus'] ?? []))['dossierId'] ?? ''),
					'alreadyTransferred' => true,
				]
			);
		}

		// 3. Resolve the adapter BEFORE the seal is written. It is a pure
		// container lookup with no side effect, and it throws when the binding
		// is missing — after the seal that would leave the run permanently
		// `locked` over a configuration error, with nothing dispatched.
		$adapter = $this->resolveIncassoAdapter();

		// 4. Claim the seal. A failure here costs a retry; a failure after the
		// dispatch would cost a duplicate dossier.
		$sealed = $run;
		$sealed['lifecycleState'] = 'locked';
		$sealed['deliveryStatus'] = 'PENDING';
		try {
			$this->saveObject(schema: 'DunningRun', data: $sealed);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: failed to seal DunningRun ' . $dunningRunId
				. ' — dossier NOT dispatched: ' . $e->getMessage()
			);
			return new DunningChannelSendResult(
				channel: self::INCASSO_CHANNEL,
				deliveryStatus: 'FAILED',
				errorMessage: sprintf('Could not seal DunningRun %s — dossier not dispatched.', $dunningRunId)
			);
		}

		// 5. Dispatch.
		$result = $adapter->transfer(
			administrationId: $administrationId,
			invoiceId: $invoiceId,
			dossier: $dossier
		);

		if ($result->deliveryStatus !== 'DELIVERED') {
			$this->logger->warning(
				sprintf(
					'Shillinq: incasso transfer for invoice %s ended in %s — caller must retry / notify',
					$invoiceId,
					$result->deliveryStatus
				)
			);
			$this->releaseIncassoSeal(run: $run, dunningRunId: $dunningRunId, outcome: $result->deliveryStatus);
			return $result;
		}

		// 6. Stamp the provider's evidence on the already-sealed run.
		$sealed['deliveryStatus'] = 'DELIVERED';
		$existing = (array)($sealed['postageStatus'] ?? []);
		$dossierId = (string)($result->extras['dossierId'] ?? '');
		if ($dossierId !== '') {
			$existing['dossierId'] = $dossierId;
		}

		if ($existing !== []) {
			$sealed['postageStatus'] = $existing;
		}

		try {
			$this->saveObject(schema: 'DunningRun', data: $sealed);
		} catch (\Throwable $e) {
			// The seal itself is already persisted, so the run cannot be
			// re-dispatched; only the provider's dossierId is missing. Loud,
			// because the evidence trail now needs manual repair.
			$this->logger->error(
				'Shillinq: DunningRun ' . $dunningRunId . ' was dispatched and sealed but its dossierId '
				. 'could not be stamped: ' . $e->getMessage()
			);
		}

		return $result;
	}//end transferToIncasso()

	/**
	 * Release the pre-dispatch seal after a non-DELIVERED outcome.
	 *
	 * The run goes back to `executed` so the operator can retry, and its
	 * `deliveryStatus` records the provider's verdict — a run whose transfer
	 * BOUNCED or FAILED must not be left reading `DELIVERED` from an earlier
	 * channel attempt. When the release itself fails the run STAYS `locked` —
	 * deliberately: a blocked retry is recoverable, a duplicate dossier at a
	 * collection agency is not.
	 *
	 * @param array<string,mixed> $run          The run as it was before sealing.
	 * @param string              $dunningRunId The run's identifier, for logging.
	 * @param string              $outcome      The adapter's delivery status.
	 *
	 * @return void
	 */
	private function releaseIncassoSeal(array $run, string $dunningRunId, string $outcome): void {
		$run['deliveryStatus'] = $outcome;
		try {
			$this->saveObject(schema: 'DunningRun', data: $run);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: DunningRun ' . $dunningRunId . ' stays sealed after a ' . $outcome
				. ' transfer — the seal could not be released: ' . $e->getMessage()
			);
		}
	}//end releaseIncassoSeal()

	/**
	 * REQ-CCD-009 / task-21: dispatch a stage-4 ingebrekestelling registered
	 * letter via the bound `PostNLAdapterInterface`.
	 *
	 * Captures the resulting Track & Trace barcode + URL on the linked
	 * `DunningRun.postageStatus` field for evidence-trail.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $dunningRunId The DunningRun id to update on success.
	 * @param array<string,mixed> $payload Letter payload — recipientAdres +
	 *                                     letterPdfRef.
	 *
	 * @return DunningChannelSendResult The dispatch outcome.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-21
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $administrationId is not
	 *     read in this method body. Flagged during issue #506 as worth
	 *     verifying whether OpenRegister's ObjectService already scopes this
	 *     by tenant by default; not changed in this style/quality-only pass.
	 */
	public function sendRegisteredLetter(
		string $administrationId,
		string $dunningRunId,
		array $payload,
	): DunningChannelSendResult {
		$adapter = $this->resolvePostNlAdapter();
		$result = $adapter->sendRegisteredLetter(payload: $payload);

		$run = $this->fetchById(schema: 'DunningRun', id: $dunningRunId);
		if ($run !== null) {
			$postage = ((array)($run['postageStatus'] ?? []));
			$extras = $result->postageStatus();
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
				$this->logger->warning('Shillinq: failed to update DunningRun ' . $dunningRunId . ' with PostNL evidence: ' . $e->getMessage());
			}
		}

		return $result;
	}//end sendRegisteredLetter()

	/**
	 * Resolve the bound IncassoBureauAdapterInterface via the DI container.
	 *
	 * @return IncassoBureauAdapterInterface
	 */
	private function resolveIncassoAdapter(): IncassoBureauAdapterInterface {
		return $this->container->get(IncassoBureauAdapterInterface::class);
	}//end resolveIncassoAdapter()

	/**
	 * Resolve the bound PostNLAdapterInterface via the DI container.
	 *
	 * @return PostNLAdapterInterface
	 */
	private function resolvePostNlAdapter(): PostNLAdapterInterface {
		return $this->container->get(PostNLAdapterInterface::class);
	}//end resolvePostNlAdapter()

	/**
	 * Whether the invoice has an active DunningPauseDispute.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceId Invoice FK.
	 *
	 * @return bool True when at least one active pause exists.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-17
	 */
	public function hasActivePause(string $administrationId, string $invoiceId): bool {
		$pauses = $this->findAll(
			schema: 'DunningPauseDispute',
			filters: [
				'administrationId' => $administrationId,
				'invoiceId' => $invoiceId,
				'lifecycleState' => 'active',
			]
		);
		return $pauses !== [];
	}//end hasActivePause()

	/**
	 * Find the first matching record (or null).
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filter map.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end fetchOne()

	/**
	 * Look one record up by its IDENTIFIER.
	 *
	 * ⚠️ NOT `fetchOne(schema: …, filters: ['id' => …])`. `filters` addresses
	 * the object's JSON properties; the ObjectEntity's `id` is its own column,
	 * merged into the serialised output only afterwards. That shape matches
	 * ZERO rows against real OpenRegister for every value, uuids included, and
	 * it does so silently — no exception, nothing logged, an empty result
	 * indistinguishable from a genuinely absent record. Every identifier
	 * lookup in this service used to be written that way, so each one took its
	 * not-found branch on every call in production while passing green under a
	 * double that matched `id` as a plain array key.
	 *
	 * `ObjectIdentifier::findOne()` uses `find()`, which resolves the entity by
	 * id/uuid/slug, and gives it its own try/catch because `find()` THROWS on a
	 * miss rather than returning null.
	 *
	 * @param string      $schema           Schema slug.
	 * @param string      $id               The record's uuid, id or slug.
	 * @param string|null $fallbackProperty JSON property to match when $id is
	 *                                      not an identifier the engine resolves.
	 *
	 * @return array<string,mixed>|null The record, or null when genuinely absent.
	 */
	private function fetchById(string $schema, string $id, ?string $fallbackProperty = null): ?array {
		try {
			$scoped = $this->objectService
				->setRegister($this->register())
				->setSchema($schema);
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: dunning scope for ' . $schema . ' failed: ' . $e->getMessage());
			return null;
		}

		return ObjectIdentifier::findOne(
			scoped: $scoped,
			id: $id,
			fallbackProperty: $fallbackProperty
		);
	}//end fetchById()

	/**
	 * Find all matching records via the canonical OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filter map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
			return $rows;
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: dunning findAll(' . $schema . ') failed: ' . $e->getMessage());
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
	private function saveObject(string $schema, array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($data);

		return $saved->jsonSerialize();
	}//end saveObject()

	/**
	 * Resolve the configured OpenRegister register slug.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
