<?php

/**
 * Sign-off DecisionConcludedEvent Listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Change shillinq-delegation-via-events (REQ-SIGN-005/006) — consumes the
 * terminal governance-decision outcome decidesk publishes via
 * {@see \OCA\Decidesk\Event\DecisionConcludedEvent}. Filters to
 * `getSourceApp() === 'shillinq'`, resolves the originating finance object
 * (ACMReport / ActuarialValuation / AnnualReport) by the externalReference /
 * subjectId we sent on the matching DecisionRequestedEvent, and projects the
 * `approved` / `rejected` outcome onto it via
 * {@see \OCA\Shillinq\Service\Signing\SignoffDecisionService::onDecisionCallback}.
 *
 * The accounting CONSEQUENCE stays in shillinq (REQ-SIGN-006): on `approved`
 * the consequence callback flips the finance lifecycle (the report becomes
 * submittable / the year-end posting runs) through the existing OR write path.
 * shillinq owns no approval state machine — it only records the decidesk
 * outcome and fires its own GL consequence exactly once (idempotent).
 *
 * Fail-soft: a lookup/projection error logs but is never rethrown into
 * decidesk's synchronous dispatch — recording an already-made decision must
 * not crash decidesk. (This is distinct from the fail-CLOSED request path in
 * SignoffDecisionService::requestSignoff, where a missing decidesk MUST block.)
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Kernel\Contracts\SignoffDecisionServiceInterface;
use OCA\Shillinq\Support\FleetAppId;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Project a concluded decidesk Decision onto the originating shillinq finance
 * object and fire the local GL/lifecycle consequence.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md (REQ-SIGN-005/006)
 */
final class SignoffDecisionConcludedListener implements IEventListener {

	/**
	 * Finance schemas that carry the governance-decision consumer field set.
	 * Matched against the concluded event's subjectSchema, with a fallback
	 * scan when the schema is absent.
	 *
	 * @var array<string>
	 */
	private const SUBJECT_SCHEMAS = ['ACMReport', 'ActuarialValuation', 'AnnualReport'];

	/**
	 * Decidesk statuses that map to a terminal shillinq outcome.
	 *
	 * @var array<string,string>
	 */
	private const STATUS_MAP = [
		'approved' => 'approved',
		'rejected' => 'rejected',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterLocationInterface $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param SignoffDecisionServiceInterface $signoffService The sign-off consumer service.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object
	 *                                              surface (ADR-084), aliased in
	 *                                              Application.php.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterLocationInterface $registerLocation,
		private readonly SignoffDecisionServiceInterface $signoffService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle a decidesk DecisionConcludedEvent.
	 *
	 * Fail-soft: any error logs and returns; never bubbles back into decidesk's
	 * dispatch.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function handle(Event $event): void {
		// Match against EVERY namespace decidiq has shipped this event under.
		// Pinned to \OCA\Decidesk alone, this listener stopped receiving
		// anything the moment decidiq renamed.
		if (FleetAppId::isInstanceOf($event, 'decidiq', 'Event\DecisionConcludedEvent') === false) {
			return;
		}

		try {
			$this->project(payload: $this->readPayload(event: $event));
		} catch (Throwable $e) {
			$this->log()->projectionFailed(error: $e);
		}

	}//end handle()

	/**
	 * Project a shillinq-originated, terminal decision outcome onto its
	 * finance object.
	 *
	 * @param array<string,string> $payload The normalised payload.
	 *
	 * @return void
	 */
	private function project(array $payload): void {
		// Filter to shillinq-originated decisions only.
		if ($payload['sourceApp'] !== 'shillinq') {
			return;
		}

		$outcome = self::STATUS_MAP[$payload['status']] ?? null;
		if ($outcome === null) {
			// Withdrawn / pending / unknown — no terminal projection.
			return;
		}

		$found = $this->lookup()->find(payload: $payload);
		if ($found !== null) {
			$this->recordOutcome(found: $found, conclusion: ($payload + ['outcome' => $outcome]));
		}

	}//end project()

	/**
	 * Record the outcome through the sign-off service, persist the mirror
	 * (decisionRef + decisionOutcome) plus any local accounting-consequence
	 * delta (REQ-SIGN-006) through OR, and log it.
	 *
	 * @param FoundSubject          $found      The finance object.
	 * @param array<string,string> $conclusion The payload plus the mapped outcome.
	 *
	 * @return void
	 */
	private function recordOutcome(FoundSubject $found, array $conclusion): void {
		[$updated, $consequence] = $this->applyOutcome(found: $found, conclusion: $conclusion);

		$updates = [
			'decisionRef' => $updated['decisionRef'] ?? $conclusion['decisionId'],
			'decisionOutcome' => $updated['decisionOutcome'] ?? $conclusion['outcome'],
		];
		$this->subjects()->persist(ref: $found->ref, updates: ($updates + $consequence));

		$this->log()->consumed(found: $found, conclusion: $conclusion);

	}//end recordOutcome()

	/**
	 * Hand the outcome to the sign-off service.
	 *
	 * The accounting-consequence mutation is captured so it can be persisted
	 * alongside the mirror. onDecisionCallback owns the idempotency guard and
	 * fires the consequence exactly once on 'approved'; a repeated conclusion
	 * is a no-op and the consequence stays empty.
	 *
	 * @param FoundSubject          $found      The finance object.
	 * @param array<string,string> $conclusion The payload plus the mapped outcome.
	 *
	 * @return array{0: array<string,mixed>, 1: array<string,mixed>} The updated object and the consequence delta.
	 */
	private function applyOutcome(FoundSubject $found, array $conclusion): array {
		$consequence = [];
		$updated = $this->signoffService->onDecisionCallback(
			$found->object,
			$conclusion['outcome'],
			$conclusion['decisionId'],
			function (array $object) use ($found, $conclusion, &$consequence): array {
				$object = $this->applyAccountingConsequence(schema: $found->ref->schema, object: $object, outcome: $conclusion['outcome']);
				$consequence = $this->consequence()->delta(object: $object, outcome: $conclusion['outcome']);
				return $object;
			},
			// Activity object type for the REQ-RAP-006 `decision_made`
			// event raised inside onDecisionCallback(). This listener is the
			// sole production caller, so omitting it here would leave that
			// event permanently unemitted.
			$found->ref->schema,
		);

		return [$updated, $consequence];

	}//end applyOutcome()

	/**
	 * The register access for the concluded subject.
	 *
	 * @return ConcludedSubjectStore The store.
	 */
	private function subjects(): ConcludedSubjectStore {
		return new ConcludedSubjectStore(registerLocation: $this->registerLocation, objectService: $this->objectService);

	}//end subjects()

	/**
	 * The subject lookup of this listener.
	 *
	 * @return ConcludedSubjectLookup The lookup.
	 */
	private function lookup(): ConcludedSubjectLookup {
		return new ConcludedSubjectLookup(store: $this->subjects(), log: $this->log(), knownSchemas: self::SUBJECT_SCHEMAS, request: 'decision');

	}//end lookup()

	/**
	 * The log lines of this listener.
	 *
	 * @return ConcludedOutcomeLog The log.
	 */
	private function log(): ConcludedOutcomeLog {
		return new ConcludedOutcomeLog(logger: $this->logger, listener: 'SignoffDecisionConcludedListener', refKey: 'decisionRef', idKey: 'decisionId');

	}//end log()

	/**
	 * The accounting consequence of a completing outcome (REQ-SIGN-006).
	 *
	 * @return ConcludedOutcomeConsequence The consequence.
	 */
	private function consequence(): ConcludedOutcomeConsequence {
		return new ConcludedOutcomeConsequence(completing: 'approved', stampField: 'signoffApprovedAt', gateField: 'signoffGateOpen');

	}//end consequence()

	/**
	 * Read the cross-app event into a shape this app owns.
	 *
	 * The event class lives in decidiq and its namespace has moved once
	 * already, so naming it in a type hint here would pin this listener to one
	 * decidiq release. Reading it into a plain array at the boundary keeps every
	 * method below fully typed, and gathers the accessor names — the real
	 * cross-app contract — into one readable place.
	 *
	 * @param Event $event The dispatched decidiq DecisionConcludedEvent.
	 *
	 * @return array{sourceApp: string, status: string, decisionId: string,
	 *     externalReference: string, subjectId: string,
	 *     subjectSchema: string} The normalised payload.
	 *
	 * @psalm-suppress UndefinedMethod
	 * @phpstan-ignore-next-line
	 */
	private function readPayload(Event $event): array {
		return [
			'sourceApp'         => (string)$event->getSourceApp(),
			'status'            => (string)$event->getStatus(),
			'decisionId'        => (string)$event->getDecisionId(),
			'externalReference' => (string)$event->getExternalReference(),
			'subjectId'         => (string)($event->getSubjectId() ?? ''),
			'subjectSchema'     => (string)($event->getSubjectSchema() ?? ''),
		];
	}//end readPayload()

	/**
	 * Apply the local accounting consequence on an approved sign-off
	 * (REQ-SIGN-006). The consequence stays in shillinq: on `approved` the
	 * finance lifecycle is flipped through the existing OR write path so the
	 * report becomes submittable / the adoption posting can run. On `rejected`
	 * no lifecycle advance happens.
	 *
	 * @param string $schema The finance schema.
	 * @param array<string,mixed> $object The finance object (already carrying the mirror).
	 * @param string $outcome 'approved' | 'rejected'.
	 *
	 * @return array<string,mixed> The (possibly mutated) finance object.
	 */
	private function applyAccountingConsequence(string $schema, array $object, string $outcome): array {
		if ($this->consequence()->appliesTo(outcome: $outcome) === false) {
			return $object;
		}

		$this->log()->consequenceApplied(schema: $schema, outcome: $outcome);

		return $this->consequence()->apply(object: $object, outcome: $outcome);
	}//end applyAccountingConsequence()
}//end class
