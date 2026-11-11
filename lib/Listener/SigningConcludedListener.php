<?php

/**
 * Document SigningConcludedEvent Listener.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
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
	 * @param SettingsService $settingsService Shillinq settings (register slug).
	 * @param SigningDelegationService $signingService The document-signing consumer service.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object
	 *                                              surface (ADR-084), aliased in
	 *                                              Application.php.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly SigningDelegationService $signingService,
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
		$concluded = $this->flow()->conclude(payload: $payload);
		if ($concluded !== null) {
			$this->recordOutcome(concluded: $concluded);
		}

	}//end project()

	/**
	 * Record the outcome through the signing service, persist the mirror
	 * (signingRequestRef + signingStatus + signedDocumentRef) plus any local
	 * accounting-consequence delta (REQ-SIGN-006) through OR, and log it.
	 *
	 * @param ConcludedOutcome $concluded The finance object and its conclusion.
	 *
	 * @return void
	 */
	private function recordOutcome(ConcludedOutcome $concluded): void {
		[$updated, $consequence] = $this->applyOutcome(concluded: $concluded);

		$updates = [
			'signingRequestRef' => $updated['signingRequestRef'] ?? $concluded->conclusion['signingRequestId'],
			'signingStatus' => $updated['signingStatus'] ?? $concluded->outcome(),
		];
		if (array_key_exists('signedDocumentRef', $updated) === true) {
			$updates['signedDocumentRef'] = $updated['signedDocumentRef'];
		}

		$this->flow()->finish(concluded: $concluded, updates: ($updates + $consequence));

	}//end recordOutcome()

	/**
	 * Hand the outcome to the signing service.
	 *
	 * The accounting-consequence mutation is captured so it can be persisted
	 * alongside the mirror. onSigningCallback owns the idempotency guard and
	 * fires the consequence exactly once on 'signed'; a repeated conclusion is
	 * a no-op and the consequence stays empty.
	 *
	 * @param ConcludedOutcome $concluded The finance object and its conclusion.
	 *
	 * @return array{0: array<string,mixed>, 1: array<string,mixed>} The updated object and the consequence delta.
	 */
	private function applyOutcome(ConcludedOutcome $concluded): array {
		$consequence = $this->flow()->consequence(concluded: $concluded);
		$updated     = $this->signingService->onSigningCallback(
			$concluded->found->object,
			$concluded->outcome(),
			$concluded->conclusion['signingRequestId'],
			null,
			null,
			$this->signedDocumentValue(signedDocumentRef: $concluded->conclusion['signedDocumentRef']),
			$consequence->apply(...),
			// Activity object type for the REQ-RAP-006 `document_signed`
			// event raised inside onSigningCallback(). This listener is the
			// sole production caller, so omitting it here would leave that
			// event permanently unemitted.
			$concluded->schema(),
		);

		return [$updated, $consequence->delta];

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
	 * The shared projection steps, with this listener's settings.
	 *
	 * @return ConcludedOutcomeFlow The flow.
	 */
	private function flow(): ConcludedOutcomeFlow {
		return new ConcludedOutcomeFlow(
			spec: self::spec(),
			subjects: new ConcludedSubjectStore(settingsService: $this->settingsService, objectService: $this->objectService),
			logger: $this->logger,
		);

	}//end flow()

	/**
	 * This listener's settings for the shared projection.
	 *
	 * @return ConcludedOutcomeSpec The settings.
	 */
	private static function spec(): ConcludedOutcomeSpec {
		return new ConcludedOutcomeSpec(
			listener: 'SigningConcludedListener',
			requestNoun: 'signing request',
			requestIdKey: 'signingRequestId',
			requestRefKey: 'signingRequestRef',
			subjectSchemas: self::SUBJECT_SCHEMAS,
			statusMap: self::STATUS_MAP,
			completedOutcome: 'signed',
			consequenceFields: ['signedAt', 'submissionGateOpen'],
		);

	}//end spec()

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
}//end class
