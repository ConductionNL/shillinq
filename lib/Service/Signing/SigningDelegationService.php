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
use OCA\DocuDesk\Event\DocumentSigningRequestedEvent;
use OCA\DocuDesk\Event\SigningProvenance;
use OCA\Shillinq\Service\ApprovalActivityEmitter;
use OCA\Shillinq\Service\SettingsService;
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
		$currentStatus = (string)($financeObject['signingStatus'] ?? '');

		if ($currentStatus === 'signed') {
			// Idempotent: already signed — no-op.
			return $financeObject;
		}

		// Fail closed when docudesk is not installed — never sign on local authority.
		if (class_exists(DocumentSigningRequestedEvent::class) === false) {
			$this->logger->warning(
				'SigningDelegationService: docudesk not installed — signing cannot be delegated (fail closed)'
			);
			throw new RuntimeException('docudesk is not installed; document signing request cannot be raised.');
		}

		$subjectId = (string)($financeObject['id'] ?? $financeObject['_id'] ?? '');
		$subjectLabel = (string)($financeObject['name'] ?? $financeObject['title'] ?? $subjectId);
		$docReference = $documentReference;
		if ($docReference === '') {
			$docReference = (string)($financeObject['documentReference'] ?? $financeObject['pdfRef'] ?? '');
		}

		// DocuDesk groups the six provenance fields into SigningProvenance —
		// the same value object its SigningConcludedEvent already took, so both
		// ends of the exchange now carry provenance the same way
		// (ConductionNL/docudesk). The read surface is unchanged; only
		// construction moved.
		$event = new DocumentSigningRequestedEvent(
			provenance: new SigningProvenance(
				sourceApp: 'shillinq',
				subjectRegister: $this->settingsService->getRegisterSlug(),
				subjectSchema: $subjectSchema,
				subjectId: $subjectId,
				externalReference: $subjectId,
				correlationId: '',
			),
			subjectLabel: $subjectLabel,
			documentReference: $docReference,
			signers: $signers,
			signatureLevel: $signatureLevel,
			signingMode: $signingMode,
		);

		$this->eventDispatcher->dispatchTyped($event);

		// Read back the synchronous result the docudesk listener wrote.
		$signingRequestId = $event->getSigningRequestId();
		if ($event->isHandled() === false || $signingRequestId === null || $signingRequestId === '') {
			$this->logger->warning(
				'SigningDelegationService: docudesk did not handle the signing request (fail closed)',
				['subjectSchema' => $subjectSchema, 'subjectId' => $subjectId, 'documentClass' => $documentClass]
			);
			throw new RuntimeException('docudesk did not handle the document signing request.');
		}

		$financeObject['signingRequestRef'] = $signingRequestId;
		$financeObject['signingStatus'] = 'requested';

		$this->logger->info(
			'SigningDelegationService: signingRequest raised via docudesk event',
			[
				'signingRequestRef' => $financeObject['signingRequestRef'],
				'documentClass' => $documentClass,
			]
		);

		return $financeObject;
	}//end requestSignature()

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
		if (in_array($outcome, self::TERMINAL_STATUSES, true) === false) {
			throw new InvalidArgumentException('Unknown signing outcome: ' . $outcome);
		}

		$currentStatus = (string)($financeObject['signingStatus'] ?? '');

		// Idempotency guard: do not re-fire if already in this terminal state.
		if ($currentStatus === $outcome) {
			$this->logger->info('SigningDelegationService: idempotent callback — already ' . $outcome . ', no-op.');
			return $financeObject;
		}

		$financeObject['signingRequestRef'] = $signingRequestRef;
		$financeObject['signingStatus'] = $outcome;

		if ($signingProvider !== null) {
			$financeObject['signingProvider'] = $signingProvider;
		}

		if ($signingLevel !== null) {
			$financeObject['signingLevel'] = $signingLevel;
		}

		if ($signedDocumentRef !== null) {
			$financeObject['signedDocumentRef'] = $signedDocumentRef;
		}

		if ($outcome === 'signed' && $consequenceCallback !== null) {
			// Fire the accounting consequence exactly once (REQ-SIGN-006).
			$consequenceCallback($financeObject);
		}

		// REQ-RAP-006 row 4 (`document_signed`): "SigningAuthority signature
		// recorded". This is the point at which shillinq records the signature
		// — and it sits AFTER the idempotency guard above, so a repeated
		// docudesk conclusion cannot publish a duplicate Activity entry. The
		// actor is left to the emitter's session/'system' fallback: the signer
		// is a docudesk identity, not a Nextcloud session user, so attributing
		// it to the current session would be wrong.
		if ($outcome === 'signed' && $subjectSchema !== '' && $this->activityEmitter !== null) {
			$this->activityEmitter->emitDocumentSigned(
				objectType:  $subjectSchema,
				objectId:    (string)($financeObject['id'] ?? ''),
				actorUid:    '',
				summaryHint: sprintf('%s %s', $subjectSchema, (string)($financeObject['id'] ?? ''))
			);
		}

		$this->logger->info(
			'SigningDelegationService: callback consumed',
			[
				'outcome' => $outcome,
				'signingRequestRef' => $signingRequestRef,
			]
		);

		return $financeObject;
	}//end onSigningCallback()
}//end class
