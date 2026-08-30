<?php

/**
 * Sign-off Decision Service — ADR-031 integration exception path
 *
 * Raises decidesk Decisions (signature-as-method) via the decidesk
 * IEventDispatcher event contract (DecisionRequestedEvent, synchronous,
 * in-process) and consumes the approved/rejected outcome — delivered by a
 * DecisionConcludedEvent listener — to write the governance decision consumer
 * field set on the originating finance object (REQ-SIGN-002/005). No approval
 * logic is implemented here; shillinq only requests and consumes. Idempotent:
 * a repeated callback is a no-op. Fail-closed: if decidesk is not installed or
 * did not handle the request, shillinq throws and never auto-approves.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Signing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Signing;

use InvalidArgumentException;
use OCA\Decidesk\Event\DecisionRequestedEvent;
use OCA\Shillinq\Service\ApprovalActivityEmitter;
use OCA\Shillinq\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-031 exception-path: raises a decidesk Decision (signature-as-method)
 * via the decidesk IEventDispatcher event contract; consumes the
 * approved/rejected outcome to drive the finance lifecycle and the existing GL
 * consequence.
 *
 * Transport is the synchronous, in-process decidesk event contract
 * (DecisionRequestedEvent dispatched via IEventDispatcher, DecisionConcludedEvent
 * consumed by a listener) — no hard-coded hostnames and no phantom integration
 * registry (REQ-SIGN-005).
 *
 * @spec openspec/changes/shillinq-delegation-via-events/tasks.md
 */
class SignoffDecisionService {

	/**
	 * Terminal decision outcomes; repeated callbacks are a no-op.
	 *
	 * @var array<string>
	 */
	private const TERMINAL_OUTCOMES = ['approved', 'rejected'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shillinq settings (register slug).
	 * @param IEventDispatcher $eventDispatcher Event dispatcher for the decidesk contract.
	 * @param LoggerInterface $logger Logger.
	 * @param ApprovalActivityEmitter|null $activityEmitter Optional Activity emitter for the
	 *                                                      REQ-RAP-006 `decision_made` event;
	 *                                                      nullable so unit tests need not wire
	 *                                                      IActivityManager.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly ?ApprovalActivityEmitter $activityEmitter = null,
	) {
	}//end __construct()

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
	public function requestSignoff(array $financeObject, string $subjectSchema, string $decisionType = 'sign-off'): array {
		$currentOutcome = (string)($financeObject['decisionOutcome'] ?? '');

		if ($currentOutcome === 'approved') {
			// Idempotent: already approved — no-op.
			return $financeObject;
		}

		// Fail closed when decidesk is not installed — never auto-approve.
		if (class_exists(DecisionRequestedEvent::class) === false) {
			$this->logger->warning(
				'SignoffDecisionService: decidesk not installed — sign-off cannot be delegated (fail closed)'
			);
			throw new RuntimeException('decidesk is not installed; sign-off decision cannot be raised.');
		}

		$subjectId = (string)($financeObject['id'] ?? $financeObject['_id'] ?? '');
		$subjectLabel = (string)($financeObject['name'] ?? $financeObject['title'] ?? $subjectId);

		$event = new DecisionRequestedEvent(
			sourceApp: 'shillinq',
			subjectRegister: $this->settingsService->getRegisterSlug(),
			subjectSchema: $subjectSchema,
			subjectId: $subjectId,
			subjectLabel: $subjectLabel,
			decisionType: $decisionType,
			actorId: '',
			payload: [
				'title' => $subjectLabel,
				'text' => 'Governance sign-off requested by shillinq for ' . $subjectSchema . ' ' . $subjectId . '.',
				'decisionDate' => gmdate('Y-m-d\TH:i:s\Z'),
			],
			externalReference: $subjectId,
			correlationId: '',
		);

		$this->eventDispatcher->dispatchTyped($event);

		// Read back the synchronous result the decidesk listener wrote.
		$decisionId = $event->getDecisionId();
		if ($event->isHandled() === false || $decisionId === null || $decisionId === '') {
			$this->logger->warning(
				'SignoffDecisionService: decidesk did not handle the decision request (fail closed)',
				['subjectSchema' => $subjectSchema, 'subjectId' => $subjectId]
			);
			throw new RuntimeException('decidesk did not handle the sign-off decision request.');
		}

		$financeObject['decisionRef'] = $decisionId;
		$financeObject['decisionOutcome'] = 'pending';

		$this->logger->info(
			'SignoffDecisionService: Decision raised via decidesk event',
			[
				'decisionRef' => $decisionId,
				'decisionType' => $decisionType,
			]
		);

		return $financeObject;
	}//end requestSignoff()

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
	): array {
		if (in_array($outcome, self::TERMINAL_OUTCOMES, true) === false) {
			throw new InvalidArgumentException('Unknown decision outcome: ' . $outcome);
		}

		$currentOutcome = (string)($financeObject['decisionOutcome'] ?? '');

		// Idempotency guard.
		if ($currentOutcome === $outcome) {
			$this->logger->info('SignoffDecisionService: idempotent callback — already ' . $outcome . ', no-op.');
			return $financeObject;
		}

		$financeObject['decisionRef'] = $decisionRef;
		$financeObject['decisionOutcome'] = $outcome;

		if ($outcome === 'approved' && $consequenceCallback !== null) {
			// Fire the accounting consequence exactly once (REQ-SIGN-006).
			$consequenceCallback($financeObject);
		}

		// REQ-RAP-006 row 5 (`decision_made`): "Decision status changed".
		// Both terminal outcomes are reported (approved AND rejected) — the
		// event carries the new status as a subject parameter, so suppressing
		// the rejection would leave the audit feed showing only the decisions
		// that went through. Placed after the idempotency guard above, so a
		// repeated decidesk conclusion cannot publish a duplicate entry.
		if ($subjectSchema !== '' && $this->activityEmitter !== null) {
			$this->activityEmitter->emitDecisionMade(
				objectType:  $subjectSchema,
				objectId:    (string)($financeObject['id'] ?? ''),
				actorUid:    '',
				newStatus:   $outcome,
				summaryHint: sprintf('%s %s', $subjectSchema, (string)($financeObject['id'] ?? ''))
			);
		}

		$this->logger->info(
			'SignoffDecisionService: callback consumed',
			[
				'outcome' => $outcome,
				'decisionRef' => $decisionRef,
			]
		);

		return $financeObject;
	}//end onDecisionCallback()
}//end class
