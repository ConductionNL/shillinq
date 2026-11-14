<?php

/**
 * Document SigningConcludedEvent Listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Change shillinq-signing-via-events (REQ-SIGN-001/006) — consumes the terminal
 * document-signing outcome filinq publishes via its SigningConcludedEvent,
 * matched under every namespace filinq has shipped it under. Filters to
 * `getSourceApp() === 'shillinq'`, resolves the originating finance object
 * (ACMReport / AnnualReport / ManagementLetter) by the externalReference /
 * subjectId we sent on the matching DocumentSigningRequestedEvent, and projects
 * the `signed` / `declined` / `expired` / `cancelled` status onto it via
 * {@see \OCA\Shillinq\Service\Signing\SigningDelegationService::onSigningCallback}.
 *
 * The accounting CONSEQUENCE stays in shillinq (REQ-SIGN-006): on `signed` the
 * consequence callback opens the finance submission gate (the report becomes
 * submittable) through the existing OR write path. shillinq owns no signing
 * engine — it only records the docudesk outcome and fires its own GL/submission
 * consequence exactly once (idempotent).
 *
 * Fail-soft: a lookup/projection error logs but is never rethrown into
 * docudesk's synchronous dispatch — recording an already-completed signature
 * must not crash docudesk. (This is distinct from the fail-CLOSED request path
 * in SigningDelegationService::requestSignature, where a missing docudesk MUST
 * block.)
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
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Kernel\Contracts\SigningDelegationServiceInterface;
use OCA\Shillinq\Support\FleetAppId;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Project a concluded docudesk signing request onto the originating shillinq
 * finance object and fire the local GL/submission consequence on `signed`.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md (REQ-SIGN-001/006)
 */
final class SigningConcludedListener implements IEventListener {

	/**
	 * Finance schemas that carry the document-signing consumer field set.
	 * Matched against the concluded event's subjectSchema, with a fallback
	 * scan when the schema is absent.
	 *
	 * @var array<string>
	 */
	private const SUBJECT_SCHEMAS = ['ACMReport', 'AnnualReport', 'ManagementLetter'];

	/**
	 * Docudesk statuses that map to a terminal shillinq signingStatus. A
	 * `cancelled` request is, like an `expired` one, a non-completing terminal
	 * outcome that must not open the submission gate, so it maps to `expired`.
	 *
	 * @var array<string,string>
	 */
	private const STATUS_MAP = [
		'signed' => 'signed',
		'declined' => 'declined',
		'expired' => 'expired',
		'cancelled' => 'expired',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterLocationInterface $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param SigningDelegationServiceInterface $signingService The document-signing consumer service.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object
	 *                                              surface (ADR-084), aliased in
	 *                                              Application.php.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterLocationInterface $registerLocation,
		private readonly SigningDelegationServiceInterface $signingService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle a docudesk SigningConcludedEvent.
	 *
	 * Fail-soft: any error logs and returns; never bubbles back into docudesk's
	 * dispatch.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function handle(Event $event): void {
		// Match against EVERY namespace filinq has shipped this event under.
		// Pinned to \OCA\DocuDesk alone, this listener stopped receiving
		// anything the moment filinq renamed — and a signed document that never
		// comes back looks exactly like a document nobody has signed yet.
		if (FleetAppId::isInstanceOf($event, 'filinq', 'Event\SigningConcludedEvent') === false) {
			return;
		}

		try {
			$this->project(payload: $this->readPayload(event: $event));
		} catch (Throwable $e) {
			$this->logger->warning(
				'SigningConcludedListener: projection failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Project a shillinq-originated, terminal signing outcome onto its
	 * finance object.
	 *
	 * @param array<string,string> $payload The normalised payload.
	 *
	 * @return void
	 */
	private function project(array $payload): void {
		// Filter to shillinq-originated signing requests only.
		if ($payload['sourceApp'] !== 'shillinq') {
			return;
		}

		$outcome = self::STATUS_MAP[$payload['status']] ?? null;
		if ($outcome === null) {
			// Unknown / non-terminal status — no projection.
			return;
		}

		$found = $this->locateSubject(payload: $payload);
		if ($found !== null) {
			$this->recordOutcome(found: $found, conclusion: ($payload + ['outcome' => $outcome]));
		}

	}//end project()

	/**
	 * Find the finance object the signing request belongs to, logging and
	 * answering null when there is no subject id or no matching object.
	 *
	 * @param array<string,string> $payload The normalised payload.
	 *
	 * @return FoundSubject|null The finance object, or null.
	 */
	private function locateSubject(array $payload): FoundSubject|null {
		$subjectId = $this->subjects()->subjectId(payload: $payload);
		if ($subjectId === '') {
			$this->logger->info(
				'SigningConcludedListener: no subject id on concluded signing request (skipping)',
				['signingRequestRef' => $payload['signingRequestId']]
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
				'SigningConcludedListener: no matching finance object (skipping)',
				['signingRequestRef' => $payload['signingRequestId'], 'subjectId' => $subjectId]
			);
		}

		return $found;

	}//end locateOrLog()

	/**
	 * Record the outcome through the signing service, persist the mirror
	 * (signingRequestRef + signingStatus + signedDocumentRef) plus any local
	 * accounting-consequence delta (REQ-SIGN-006) through OR, and log it.
	 *
	 * @param FoundSubject          $found      The finance object.
	 * @param array<string,string> $conclusion The payload plus the mapped outcome.
	 *
	 * @return void
	 */
	private function recordOutcome(FoundSubject $found, array $conclusion): void {
		[$updated, $consequence] = $this->applyOutcome(found: $found, conclusion: $conclusion);

		$updates = [
			'signingRequestRef' => $updated['signingRequestRef'] ?? $conclusion['signingRequestId'],
			'signingStatus' => $updated['signingStatus'] ?? $conclusion['outcome'],
		];
		if (array_key_exists('signedDocumentRef', $updated) === true) {
			$updates['signedDocumentRef'] = $updated['signedDocumentRef'];
		}

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
			'SigningConcludedListener: outcome consumed',
			[
				'schema' => $found->ref->schema,
				'subjectId' => $found->ref->id,
				'outcome' => $conclusion['outcome'],
				'signingRequestRef' => $conclusion['signingRequestId'],
			]
		);

	}//end logConsumed()

	/**
	 * Hand the outcome to the signing service.
	 *
	 * The accounting-consequence mutation is captured so it can be persisted
	 * alongside the mirror. onSigningCallback owns the idempotency guard and
	 * fires the consequence exactly once on 'signed'; a repeated conclusion is
	 * a no-op and the consequence stays empty.
	 *
	 * @param FoundSubject          $found      The finance object.
	 * @param array<string,string> $conclusion The payload plus the mapped outcome.
	 *
	 * @return array{0: array<string,mixed>, 1: array<string,mixed>} The updated object and the consequence delta.
	 */
	private function applyOutcome(FoundSubject $found, array $conclusion): array {
		$consequence = [];
		$updated = $this->signingService->onSigningCallback(
			$found->object,
			$conclusion['outcome'],
			$conclusion['signingRequestId'],
			null,
			null,
			$this->signedDocumentValue(signedDocumentRef: $conclusion['signedDocumentRef']),
			function (array $object) use ($found, $conclusion, &$consequence): array {
				$object = $this->applyAccountingConsequence(schema: $found->ref->schema, object: $object, outcome: $conclusion['outcome']);
				$consequence = $this->consequenceDelta(object: $object, outcome: $conclusion['outcome']);
				return $object;
			},
			// Activity object type for the REQ-RAP-006 `document_signed`
			// event raised inside onSigningCallback(). This listener is the
			// sole production caller, so omitting it here would leave that
			// event permanently unemitted.
			$found->ref->schema,
		);

		return [$updated, $consequence];

	}//end applyOutcome()

	/**
	 * The signed document reference to record, or null when the event
	 * carries none.
	 *
	 * @param string $signedDocumentRef The event's signed document reference.
	 *
	 * @return string|null The reference, or null.
	 */
	private function signedDocumentValue(string $signedDocumentRef): string|null {
		if ($signedDocumentRef !== '') {
			return $signedDocumentRef;
		}

		return null;

	}//end signedDocumentValue()

	/**
	 * The register access for the concluded subject.
	 *
	 * @return ConcludedSubjectStore The store.
	 */
	private function subjects(): ConcludedSubjectStore {
		return new ConcludedSubjectStore(registerLocation: $this->registerLocation, objectService: $this->objectService);

	}//end subjects()

	/**
	 * Read the cross-app event into a shape this app owns.
	 *
	 * The event class lives in filinq and its namespace has moved once already,
	 * so naming it in a type hint here would pin this listener to one filinq
	 * release. Reading it into a plain array at the boundary keeps every method
	 * below fully typed, and gathers the accessor names — the real cross-app
	 * contract — into one readable place.
	 *
	 * @param Event $event The dispatched filinq SigningConcludedEvent.
	 *
	 * @return array{sourceApp: string, status: string, signingRequestId: string,
	 *     signedDocumentRef: string, externalReference: string,
	 *     subjectId: string, subjectSchema: string} The normalised payload.
	 *
	 * @psalm-suppress UndefinedMethod
	 * @phpstan-ignore-next-line
	 */
	private function readPayload(Event $event): array {
		return [
			'sourceApp'         => (string)$event->getSourceApp(),
			'status'            => (string)$event->getStatus(),
			'signingRequestId'  => (string)$event->getSigningRequestId(),
			'signedDocumentRef' => (string)$event->getSignedDocumentRef(),
			'externalReference' => (string)$event->getExternalReference(),
			'subjectId'         => (string)($event->getSubjectId() ?? ''),
			'subjectSchema'     => (string)($event->getSubjectSchema() ?? ''),
		];
	}//end readPayload()

	/**
	 * Apply the local accounting consequence on a completed signature
	 * (REQ-SIGN-006). The consequence stays in shillinq: on `signed` the finance
	 * submission gate opens through the existing OR write path so the report
	 * becomes submittable. On any non-signed outcome no gate opens.
	 *
	 * @param string $schema The finance schema.
	 * @param array<string,mixed> $object The finance object (already carrying the mirror).
	 * @param string $outcome 'signed' | 'declined' | 'expired'.
	 *
	 * @return array<string,mixed> The (possibly mutated) finance object.
	 */
	private function applyAccountingConsequence(string $schema, array $object, string $outcome): array {
		if ($outcome !== 'signed') {
			return $object;
		}

		// The accounting consequence opens the finance submission gate through
		// the existing OR write path. shillinq owns no signing engine — it
		// records the docudesk outcome and opens its own downstream gate
		// (submission) exactly once.
		$object['signedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$object['submissionGateOpen'] = true;

		$this->logger->info(
			'SigningConcludedListener: accounting consequence applied',
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
	 * @param string $outcome 'signed' | 'declined' | 'expired'.
	 *
	 * @return array<string,mixed> The consequence delta (empty on non-signed).
	 */
	private function consequenceDelta(array $object, string $outcome): array {
		if ($outcome !== 'signed') {
			return [];
		}

		$delta = [];
		foreach (['signedAt', 'submissionGateOpen'] as $key) {
			if (array_key_exists($key, $object) === true) {
				$delta[$key] = $object[$key];
			}
		}

		return $delta;
	}//end consequenceDelta()
}//end class
