<?php

/**
 * Contract of SignoffDecisionService
 *
 * The public surface of SignoffDecisionService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of SignoffDecisionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SignoffDecisionServiceInterface {

	/**
	 * Consume a decidesk approved/rejected decision outcome (REQ-SIGN-005).
	 *
	 * Invoked by {@see \OCA\Shillinq\Listener\SignoffDecisionConcludedListener}
	 * when decidesk dispatches a DecisionConcludedEvent for a shillinq subject.
	 * Writes the governance-decision consumer field set onto the finance object.
	 * On 'approved', triggers the accounting consequence through the caller's
	 * consequence callback — shillinq does not own the approval transition
	 * (REQ-SIGN-006).
	 *
	 * Idempotent: if the object's decisionOutcome is already a terminal value
	 * matching the incoming outcome, returns the unchanged object without firing
	 * the consequence a second time.
	 *
	 * @param array<string,mixed> $financeObject The finance object to update.
	 * @param string $outcome 'approved' | 'rejected'.
	 * @param string $decisionRef The decidesk Decision id.
	 * @param callable|null $consequenceCallback Called on 'approved' to fire the GL consequence.
	 * @param string $subjectSchema Finance schema slug of $financeObject, used as the
	 *                              Activity object type for the REQ-RAP-006
	 *                              `decision_made` event. Empty string skips the emit.
	 *
	 * @return array<string,mixed> Updated finance object.
	 *
	 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function onDecisionCallback(
		array $financeObject,
		string $outcome,
		string $decisionRef,
		?callable $consequenceCallback = null,
		string $subjectSchema = '',
	): array;

	/**
	 * Raise a decidesk Decision for governance sign-off (REQ-SIGN-002).
	 *
	 * Dispatches the synchronous, in-process decidesk
	 * {@see \OCA\Decidesk\Event\DecisionRequestedEvent} via IEventDispatcher.
	 * The dispatch is guarded by class_exists so that, when decidesk is not
	 * installed, shillinq FAILS CLOSED (throws) instead of silently advancing.
	 * After dispatch the synchronous result is read back: the decision is only
	 * accepted when the decidesk listener marked the event handled and returned
	 * a decision id. Sets decisionOutcome=pending and stores the returned
	 * decisionRef. Returns the updated object for the caller to persist via OR
	 * ObjectService (ADR-022).
	 *
	 * No approval logic is implemented in shillinq.
	 *
	 * @param array<string,mixed> $financeObject The finance object (ACMReport/ActuarialValuation/AnnualReport).
	 * @param string $subjectSchema The finance schema slug (e.g. 'ACMReport').
	 * @param string $decisionType Decision type hint for decidesk (e.g. 'sign-off', 'adoption').
	 *
	 * @return array<string,mixed> Updated finance object with decisionOutcome=pending.
	 *
	 * @throws RuntimeException When decidesk is not installed or did not handle the request (fail-closed).
	 *
	 * @spec openspec/changes/shillinq-delegation-via-events/tasks.md
	 */
	public function requestSignoff(array $financeObject, string $subjectSchema, string $decisionType = 'sign-off'): array;
}//end interface
