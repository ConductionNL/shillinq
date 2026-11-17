<?php

/**
 * Contract of DunningRunService
 *
 * The public surface of DunningRunService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\Model\DunningChannelSendResult;

/**
 * Contract of DunningRunService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DunningRunServiceInterface {

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
	 * The stage is sent through the bound channel adapter by DunningStageDispatcher
	 * before the run is saved, and the run records the adapter's outcome. A
	 * caller-supplied `deliveryStatus` is ignored: it is not evidence that
	 * anything left (issue #1687, design D6 of receivables-automatic-dunning).
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $params The run's params: invoiceId, and the keys
	 *                                    DunningLetterComposer::compose() reads (ladderId,
	 *                                    stageNr, channel, templateId, recipient, rendered
	 *                                    letter, amounts and evidence).
	 *
	 * @return array<string,mixed> The executed DunningRun record.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.3
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-012)
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/bookkeeping-credit-control-dunning/spec.md (REQ-CCD-017)
	 */
	public function executeStage(string $administrationId, array $params): array;

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
	 * @param DateTimeImmutable|null $hardDeadline Optional deadline; a payment plan sets its last due date plus grace
	 *                                             (receivables-payment-plans design.md D2). Default: start plus the configured days.
	 *
	 * @return array<string,mixed> The created pause record.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-17
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function pause(
		string $administrationId,
		string $invoiceId,
		string $reason,
		string $details,
		string $pausedBy,
		?array $evidenceRefs = null,
		?DateTimeImmutable $hardDeadline = null,
	): array;

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
	): array;

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
	): DunningChannelSendResult;

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
	public function writeOff(string $administrationId, array $params): array;
}//end interface
