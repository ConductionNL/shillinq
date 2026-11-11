<?php

/**
 * Signing Delegation Service — ADR-031 integration exception path
 *
 * Raises docudesk document e-signature requests via the docudesk
 * IEventDispatcher event contract (DocumentSigningRequestedEvent, synchronous,
 * in-process) and consumes the signed/declined/expired/cancelled outcome —
 * delivered by a SigningConcludedEvent listener — to write the
 * document-signing consumer field set on the originating finance object
 * (REQ-SIGN-001/006). No PKI signing is performed here; shillinq only requests
 * and consumes. Idempotent: a repeated callback for an already-signed object is
 * a no-op. Fail-closed: if docudesk is not installed or did not handle the
 * request, shillinq throws and NEVER marks a document signed on local authority.
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
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Signing;

use InvalidArgumentException;
use OCA\Shillinq\Service\ApprovalActivityEmitter;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Support\FleetAppId;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-031 exception-path: raises a docudesk document signing request via the
 * docudesk IEventDispatcher event contract; consumes the
 * signed/declined/expired/cancelled outcome to drive the finance lifecycle and
 * the existing GL/submission consequence.
 *
 * Transport is the synchronous, in-process docudesk event contract
 * (DocumentSigningRequestedEvent dispatched via IEventDispatcher,
 * SigningConcludedEvent consumed by a listener) — no hard-coded hostnames and
 * no phantom integration registry (REQ-SIGN-001).
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
class SigningDelegationService {

	/**
	 * Terminal signing statuses; a repeated callback is a no-op.
	 *
	 * @var array<string>
	 */
	private const TERMINAL_STATUSES = ['signed', 'declined', 'expired'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shillinq settings (register slug).
	 * @param IEventDispatcher $eventDispatcher Event dispatcher for the docudesk contract.
	 * @param LoggerInterface $logger Logger.
	 * @param ApprovalActivityEmitter|null $activityEmitter Optional Activity emitter for the
	 *                                                      REQ-RAP-006 `document_signed` event;
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
	 * Raise a docudesk document signing request for a finance object (REQ-SIGN-001).
	 *
	 * Dispatches the synchronous, in-process docudesk
	 * {@see \OCA\DocuDesk\Event\DocumentSigningRequestedEvent} via IEventDispatcher.
	 * The dispatch is guarded by class_exists so that, when docudesk is not
	 * installed, shillinq FAILS CLOSED (throws) instead of silently marking a
	 * document signed. After dispatch the synchronous result is read back: the
	 * request is only accepted when the docudesk listener marked the event
	 * handled and returned a signing-request id. Sets signingStatus=requested
	 * and stores the returned signingRequestRef on the finance object. Returns
	 * the updated object for the caller to persist via OR ObjectService
	 * (ADR-022).
	 *
	 * No PKI signing is performed here.
	 *
	 * @param array<string,mixed> $financeObject The finance object (ACMReport etc.).
	 * @param string $subjectSchema The finance schema slug (e.g. 'ACMReport').
	 * @param string $documentClass Document class hint for docudesk (e.g. 'acm-report').
	 * @param string $documentReference NC Files reference to the document to sign (optional).
	 * @param array<int,mixed> $signers Signer descriptors for the signing chain (optional).
	 * @param string $signatureLevel eIDAS level hint (simple|advanced|qualified).
	 * @param string $signingMode docudesk signing mode hint (e.g. 'sequential').
	 *
	 * @return array<string,mixed> Updated finance object with signingStatus=requested.
	 *
	 * @throws RuntimeException When docudesk is not installed or did not handle the request (fail-closed).
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function requestSignature(
		array $financeObject,
		string $subjectSchema = 'document',
		string $documentClass = 'document',
		string $documentReference = '',
		array $signers = [],
		string $signatureLevel = 'advanced',
		string $signingMode = 'sequential',
	): array {
		$request = new SignatureRequest(subjectSchema: $subjectSchema, documentClass: $documentClass);
		$request->documentReference = $documentReference;
		$request->signers           = $signers;
		$request->signatureLevel    = $signatureLevel;
		$request->signingMode       = $signingMode;

		return $this->raiseSignatureRequest(financeObject: $financeObject, request: $request);
	}//end requestSignature()

	/**
	 * Dispatch the signing request and record the request id docudesk returned.
	 *
	 * @param array<string,mixed> $financeObject The finance object to sign.
	 * @param SignatureRequest    $request       What to sign, and how.
	 *
	 * @return array<string,mixed> Updated finance object with signingStatus=requested.
	 *
	 * @throws RuntimeException When filinq is not installed or docudesk did not handle the request.
	 */
	private function raiseSignatureRequest(array $financeObject, SignatureRequest $request): array {
		$currentStatus = (string)($financeObject['signingStatus'] ?? '');

		if ($currentStatus === 'signed') {
			// Idempotent: already signed — no-op.
			return $financeObject;
		}

		$event = $this->buildSigningEvent(financeObject: $financeObject, request: $request);
		$this->eventDispatcher->dispatchTyped($event);

		$financeObject['signingRequestRef'] = $this->acceptedSigningRequestId(event: $event, request: $request);
		$financeObject['signingStatus']     = 'requested';

		$this->logRequestRaised(signingRequestRef: $financeObject['signingRequestRef'], request: $request);

		return $financeObject;

	}//end raiseSignatureRequest()

	/**
	 * Log the raised signing request.
	 *
	 * @param string           $signingRequestRef The id docudesk returned.
	 * @param SignatureRequest $request           The request.
	 *
	 * @return void
	 */
	private function logRequestRaised(string $signingRequestRef, SignatureRequest $request): void {
		$this->logger->info(
			'SigningDelegationService: signingRequest raised via docudesk event',
			[
				'signingRequestRef' => $signingRequestRef,
				'documentClass' => $request->documentClass,
			]
		);

	}//end logRequestRaised()

	/**
	 * Build filinq's signing-request event for the finance object.
	 *
	 * @param array<string,mixed> $financeObject The finance object to sign.
	 * @param SignatureRequest    $request       What to sign, and how; its subjectId is filled in.
	 *
	 * @return object The DocumentSigningRequestedEvent.
	 *
	 * @throws RuntimeException When filinq is not installed (fail closed).
	 */
	private function buildSigningEvent(array $financeObject, SignatureRequest $request): object {
		[$eventClass, $provenanceClass] = $this->requireFilinqEventClasses();

		$request->subjectId = (string)($financeObject['id'] ?? $financeObject['_id'] ?? '');
		$subjectLabel       = (string)($financeObject['name'] ?? $financeObject['title'] ?? $request->subjectId);

		// Constructed through the resolved class names rather than an imported
		// symbol: the import bound this dispatch to one filinq namespace, and
		// named arguments work identically on a dynamic class name.
		return new $eventClass(
			provenance: $this->buildProvenance(provenanceClass: $provenanceClass, request: $request),
			subjectLabel: $subjectLabel,
			documentReference: $this->documentReferenceFor(financeObject: $financeObject, request: $request),
			signers: $request->signers,
			signatureLevel: $request->signatureLevel,
			signingMode: $request->signingMode,
		);

	}//end buildSigningEvent()

	/**
	 * Resolve filinq's signing-request event and provenance classes, or fail closed.
	 *
	 * Fail closed when filinq is not installed — never sign on local
	 * authority. Resolved across every namespace filinq has shipped, so a
	 * rename cannot turn "not installed" into a false negative here; this
	 * guard THROWS, so getting it wrong blocks signing outright rather than
	 * failing silently the way the listener side did.
	 *
	 * @return array{0: string, 1: string} The event and provenance class names.
	 *
	 * @throws RuntimeException When either class is missing.
	 */
	private function requireFilinqEventClasses(): array {
		$eventClass = FleetAppId::resolveClass('filinq', 'Event\\DocumentSigningRequestedEvent');
		$provenanceClass = FleetAppId::resolveClass('filinq', 'Event\\SigningProvenance');
		if ($eventClass === null || $provenanceClass === null) {
			$this->logger->warning(
				'SigningDelegationService: filinq not installed — signing cannot be delegated (fail closed)'
			);
			throw new RuntimeException('filinq is not installed; document signing request cannot be raised.');
		}

		return [$eventClass, $provenanceClass];

	}//end requireFilinqEventClasses()

	/**
	 * Build the SigningProvenance for the request.
	 *
	 * Filinq groups the six provenance fields into SigningProvenance — the
	 * same value object its SigningConcludedEvent already takes, so both
	 * ends of the exchange carry provenance the same way.
	 *
	 * @param string           $provenanceClass The resolved SigningProvenance class.
	 * @param SignatureRequest $request         The request, with its subjectId filled in.
	 *
	 * @return object
	 */
	private function buildProvenance(string $provenanceClass, SignatureRequest $request): object {
		return new $provenanceClass(
			sourceApp: 'shillinq',
			subjectRegister: $this->settingsService->getRegisterSlug(),
			subjectSchema: $request->subjectSchema,
			subjectId: $request->subjectId,
			externalReference: $request->subjectId,
			correlationId: '',
		);

	}//end buildProvenance()

	/**
	 * The document to sign: the requested reference, else the object's own.
	 *
	 * @param array<string,mixed> $financeObject The finance object to sign.
	 * @param SignatureRequest    $request       The request.
	 *
	 * @return string
	 */
	private function documentReferenceFor(array $financeObject, SignatureRequest $request): string {
		if ($request->documentReference !== '') {
			return $request->documentReference;
		}

		return (string)($financeObject['documentReference'] ?? $financeObject['pdfRef'] ?? '');

	}//end documentReferenceFor()

	/**
	 * Read back the request id the docudesk listener wrote, or fail closed.
	 *
	 * @param object           $event   The dispatched signing-request event.
	 * @param SignatureRequest $request The request, for the warning context.
	 *
	 * @return string The signing request id.
	 *
	 * @throws RuntimeException When the event was not handled or carries no id.
	 */
	private function acceptedSigningRequestId(object $event, SignatureRequest $request): string {
		$signingRequestId = $event->getSigningRequestId();
		if ($event->isHandled() === false || $signingRequestId === null || $signingRequestId === '') {
			$this->logger->warning(
				'SigningDelegationService: docudesk did not handle the signing request (fail closed)',
				['subjectSchema' => $request->subjectSchema, 'subjectId' => $request->subjectId, 'documentClass' => $request->documentClass]
			);
			throw new RuntimeException('docudesk did not handle the document signing request.');
		}

		return $signingRequestId;

	}//end acceptedSigningRequestId()

	/**
	 * Consume a docudesk signed/declined/expired callback (REQ-SIGN-001/006).
	 *
	 * Invoked by {@see \OCA\Shillinq\Listener\SigningConcludedListener} when
	 * docudesk dispatches a SigningConcludedEvent for a shillinq subject. Writes
	 * the document-signing consumer field set onto the finance object. On
	 * 'signed', triggers the accounting consequence (GL/submission gate) through
	 * the caller's consequence callback — shillinq does not advance the lifecycle
	 * itself; the consequence callback does (REQ-SIGN-006).
	 *
	 * Idempotent: if the object's signingStatus is already a terminal value
	 * matching the incoming outcome, the method returns the unchanged object
	 * and does NOT fire the consequence a second time.
	 *
	 * @param array<string,mixed> $financeObject The finance object to update.
	 * @param string $outcome 'signed' | 'declined' | 'expired'.
	 * @param string $signingRequestRef The docudesk signingRequest id.
	 * @param string|null $signingProvider eIDAS provider (nullable).
	 * @param string|null $signingLevel eIDAS level (nullable).
	 * @param string|null $signedDocumentRef NC Files ref to the signed artifact.
	 * @param callable|null $consequenceCallback Called on 'signed' outcome to fire the GL consequence.
	 * @param string $subjectSchema Finance schema slug of $financeObject, used as the
	 *                              Activity object type for the REQ-RAP-006
	 *                              `document_signed` event. Empty string skips the emit.
	 *
	 * @return array<string,mixed> Updated finance object.
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function onSigningCallback(
		array $financeObject,
		string $outcome,
		string $signingRequestRef,
		?string $signingProvider = null,
		?string $signingLevel = null,
		?string $signedDocumentRef = null,
		?callable $consequenceCallback = null,
		string $subjectSchema = '',
	): array {
		$conclusion = new SigningConclusion(outcome: $outcome, signingRequestRef: $signingRequestRef);
		$conclusion->signingProvider   = $signingProvider;
		$conclusion->signingLevel      = $signingLevel;
		$conclusion->signedDocumentRef = $signedDocumentRef;
		$conclusion->consequence       = $consequenceCallback;
		$conclusion->subjectSchema     = $subjectSchema;

		return $this->applyConclusion(financeObject: $financeObject, conclusion: $conclusion);
	}//end onSigningCallback()

	/**
	 * Write the conclusion onto the finance object, unless it is already in that state.
	 *
	 * @param array<string,mixed> $financeObject The finance object to update.
	 * @param SigningConclusion   $conclusion    What docudesk concluded.
	 *
	 * @return array<string,mixed> Updated finance object.
	 *
	 * @throws InvalidArgumentException When the outcome is not a terminal status.
	 */
	private function applyConclusion(array $financeObject, SigningConclusion $conclusion): array {
		$outcome = $conclusion->outcome;
		$this->assertTerminalOutcome(outcome: $outcome);

		// Idempotency guard: do not re-fire if already in this terminal state.
		if ((string)($financeObject['signingStatus'] ?? '') === $outcome) {
			$this->logger->info('SigningDelegationService: idempotent callback — already ' . $outcome . ', no-op.');
			return $financeObject;
		}

		$financeObject = $conclusion->writeOnto(financeObject: $financeObject);
		$this->fireSignedConsequences(financeObject: $financeObject, conclusion: $conclusion);
		$this->logConclusionConsumed(conclusion: $conclusion);

		return $financeObject;

	}//end applyConclusion()

	/**
	 * Log the consumed callback.
	 *
	 * @param SigningConclusion $conclusion What docudesk concluded.
	 *
	 * @return void
	 */
	private function logConclusionConsumed(SigningConclusion $conclusion): void {
		$this->logger->info(
			'SigningDelegationService: callback consumed',
			[
				'outcome' => $conclusion->outcome,
				'signingRequestRef' => $conclusion->signingRequestRef,
			]
		);

	}//end logConclusionConsumed()

	/**
	 * Refuse an outcome that is not signed, declined or expired.
	 *
	 * @param string $outcome The reported outcome.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the outcome is unknown.
	 */
	private function assertTerminalOutcome(string $outcome): void {
		if (in_array($outcome, self::TERMINAL_STATUSES, true) === false) {
			throw new InvalidArgumentException('Unknown signing outcome: ' . $outcome);
		}

	}//end assertTerminalOutcome()

	/**
	 * On 'signed', fire the accounting consequence and record the signature Activity.
	 *
	 * @param array<string,mixed> $financeObject The updated finance object.
	 * @param SigningConclusion   $conclusion    What docudesk concluded.
	 *
	 * @return void
	 */
	private function fireSignedConsequences(array $financeObject, SigningConclusion $conclusion): void {
		if ($conclusion->outcome !== 'signed') {
			return;
		}

		if ($conclusion->consequence !== null) {
			// Fire the accounting consequence exactly once (REQ-SIGN-006).
			($conclusion->consequence)($financeObject);
		}

		// REQ-RAP-006 row 4 (`document_signed`): "SigningAuthority signature
		// recorded". This is the point at which shillinq records the signature
		// — and it sits AFTER the idempotency guard, so a repeated
		// docudesk conclusion cannot publish a duplicate Activity entry.
		if ($conclusion->subjectSchema !== '' && $this->activityEmitter !== null) {
			$this->emitDocumentSigned(financeObject: $financeObject, subjectSchema: $conclusion->subjectSchema);
		}

	}//end fireSignedConsequences()

	/**
	 * Publish the `document_signed` Activity for the finance object.
	 *
	 * The actor is left to the emitter's session/'system' fallback: the signer
	 * is a docudesk identity, not a Nextcloud session user, so attributing
	 * it to the current session would be wrong.
	 *
	 * @param array<string,mixed> $financeObject The signed finance object.
	 * @param string              $subjectSchema Its finance schema slug (Activity object type).
	 *
	 * @return void
	 */
	private function emitDocumentSigned(array $financeObject, string $subjectSchema): void {
		$this->activityEmitter?->emitDocumentSigned(
			objectType:  $subjectSchema,
			objectId:    (string)($financeObject['id'] ?? ''),
			actorUid:    '',
			summaryHint: sprintf('%s %s', $subjectSchema, (string)($financeObject['id'] ?? ''))
		);

	}//end emitDocumentSigned()
}//end class
