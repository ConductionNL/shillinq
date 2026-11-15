<?php

/**
 * Contract of SigningDelegationService
 *
 * The public surface of SigningDelegationService that other components call, so they depend
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
 * Contract of SigningDelegationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SigningDelegationServiceInterface {

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
	): array;

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
	): array;
}//end interface
