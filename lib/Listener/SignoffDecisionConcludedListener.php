<?php

/**
 * Sign-off DecisionConcludedEvent Listener.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
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
	 * @param SettingsService $settingsService Shillinq settings (register slug).
	 * @param SignoffDecisionService $signoffService The sign-off consumer service.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object
	 *                                              surface (ADR-084), aliased in
	 *                                              Application.php.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly SignoffDecisionService $signoffService,
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
			$this->logger->warning(
				'SignoffDecisionConcludedListener: projection failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
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

		$found = $this->locateSubject(payload: $payload);
		if ($found !== null) {
			$this->recordOutcome(found: $found, conclusion: ($payload + ['outcome' => $outcome]));
		}

	}//end project()

	/**
	 * Find the finance object the decision belongs to, logging and answering
	 * null when there is no subject id or no matching object.
	 *
	 * @param array<string,string> $payload The normalised payload.
	 *
	 * @return FoundSubject|null The finance object, or null.
	 */
	private function locateSubject(array $payload): FoundSubject|null {
		$subjectId = $this->subjects()->subjectId(payload: $payload);
		if ($subjectId === '') {
			$this->logger->info(
				'SignoffDecisionConcludedListener: no subject id on concluded decision (skipping)',
				['decisionRef' => $payload['decisionId']]
			);
			return null;
		}

		return $this->locateOrLog(payload: $payload, subjectId: $subjectId);

	}//end locateSubject()

	/**
	 * Search the subject schemas, logging when nothing matches.
	 *
	 * @param array<string,string> $payload   The normalised payload.
	 * @param string               $subjectId The resolved subject id.
	 *
	 * @return FoundSubject|null The finance object, or null.
	 */
	private function locateOrLog(array $payload, string $subjectId): FoundSubject|null {
		$found = $this->subjects()->locate(payload: $payload, knownSchemas: self::SUBJECT_SCHEMAS);
		if ($found === null) {
			$this->logger->info(
				'SignoffDecisionConcludedListener: no matching finance object (skipping)',
				['decisionRef' => $payload['decisionId'], 'subjectId' => $subjectId]
			);
		}

		return $found;

	}//end locateOrLog()

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

		$this->logConsumed(found: $found, conclusion: $conclusion);

	}//end recordOutcome()

	/**
	 * Log the consumed outcome.
	 *
	 * @param FoundSubject          $found      The finance object.
	 * @param array<string,string> $conclusion The payload plus the mapped outcome.
	 *
	 * @return void
	 */
	private function logConsumed(FoundSubject $found, array $conclusion): void {
		$this->logger->info(
			'SignoffDecisionConcludedListener: outcome consumed',
			[
				'schema' => $found->ref->schema,
				'subjectId' => $found->ref->id,
				'outcome' => $conclusion['outcome'],
				'decisionRef' => $conclusion['decisionId'],
			]
		);

	}//end logConsumed()

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
				$consequence = $this->consequenceDelta(object: $object, outcome: $conclusion['outcome']);
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
		return new ConcludedSubjectStore(settingsService: $this->settingsService, objectService: $this->objectService);

	}//end subjects()

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
		if ($outcome !== 'approved') {
			return $object;
		}

		// The accounting consequence flips the finance lifecycle to the
		// post-sign-off state through the existing OR write path. shillinq owns
		// no approval logic — it records the decidesk outcome and opens its own
		// downstream gate (submission / adoption posting) exactly once.
		$object['signoffApprovedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$object['signoffGateOpen'] = true;

		$this->logger->info(
			'SignoffDecisionConcludedListener: accounting consequence applied',
			[
				'schema' => $schema,
				'outcome' => $outcome,
			]
		);

		return $object;
	}//end applyAccountingConsequence()

	/**
	 * Extract the accounting-consequence fields written by
	 * {@see self::applyAccountingConsequence} so they can be persisted with the
	 * mirror in a single OR write.
	 *
	 * @param array<string,mixed> $object The finance object after the consequence.
	 * @param string $outcome 'approved' | 'rejected'.
	 *
	 * @return array<string,mixed> The consequence delta (empty on non-approved).
	 */
	private function consequenceDelta(array $object, string $outcome): array {
		if ($outcome !== 'approved') {
			return [];
		}

		$delta = [];
		foreach (['signoffApprovedAt', 'signoffGateOpen'] as $key) {
			if (array_key_exists($key, $object) === true) {
				$delta[$key] = $object[$key];
			}
		}

		return $delta;
	}//end consequenceDelta()
}//end class
