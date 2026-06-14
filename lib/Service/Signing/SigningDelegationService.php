<?php

/**
 * Signing Delegation Service — ADR-031 integration exception path
 *
 * Raises docudesk signingRequests via the ADR-019 integration registry and
 * consumes the signed/declined/expired callback to write the document-signing
 * consumer field set on the originating finance object (REQ-SIGN-001/005).
 * No PKI signing is performed here; shillinq only requests and consumes.
 * Idempotent: a repeated callback for an already-signed object is a no-op.
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
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Signing;

use InvalidArgumentException;
use OCA\Shillinq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * ADR-031 exception-path: raises a docudesk signingRequest via the ADR-019
 * integration registry; consumes the outcome to drive the ACMReport lifecycle
 * and the existing GL/submission consequence.
 *
 * Cross-app calls go exclusively through the integration registry — no
 * hard-coded hostnames (REQ-SIGN-005).
 *
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-6
 */
class SigningDelegationService
{

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
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()


    /**
     * Raise a docudesk signingRequest for a finance object (REQ-SIGN-001).
     *
     * Opens a docudesk signingRequest through the ADR-019 integration registry.
     * Sets signingStatus=requested and stores the returned signingRequestRef on
     * the finance object. The method returns the updated object array ready for
     * the caller to persist via OR ObjectService (ADR-022).
     *
     * No PKI signing is performed here.
     *
     * @param array<string,mixed> $financeObject The finance object (ACMReport etc.).
     * @param object              $registry       The ADR-019 integration registry.
     * @param string              $documentClass  Document class hint for docudesk (e.g. 'acm-report').
     *
     * @return array<string,mixed> Updated finance object with signingStatus=requested.
     *
     * @throws InvalidArgumentException When the object already has a terminal signing status.
     *
     * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-6
     */
    public function requestSignature(array $financeObject, object $registry, string $documentClass='document'): array
    {
        $currentStatus = (string) ($financeObject['signingStatus'] ?? '');

        if ($currentStatus === 'signed') {
            // Idempotent: already signed — no-op.
            return $financeObject;
        }

        // Raise the signingRequest via the ADR-019 registry (no raw HTTP).
        $signingRef = null;
        try {
            $signingRef = $registry->call('docudesk', 'createSigningRequest', [
                'documentClass'   => $documentClass,
                'objectRef'       => (string) ($financeObject['id'] ?? $financeObject['_id'] ?? ''),
                'administrationId' => (string) ($financeObject['administrationId'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'SigningDelegationService: registry call to docudesk failed',
                ['exception' => $e->getMessage()]
            );
            // Fail-soft: propagate so the caller can surface a user-visible error.
            throw $e;
        }

        $financeObject['signingRequestRef'] = is_string($signingRef) ? $signingRef : null;
        $financeObject['signingStatus']     = 'requested';

        $this->logger->info('SigningDelegationService: signingRequest raised', [
            'signingRequestRef' => $financeObject['signingRequestRef'],
        ]);

        return $financeObject;

    }//end requestSignature()


    /**
     * Consume a docudesk signed/declined/expired callback (REQ-SIGN-005).
     *
     * Writes the document-signing consumer field set onto the finance object.
     * On 'signed', triggers the accounting consequence (GL/submission gate)
     * through the caller's consequence callback — shillinq does not advance
     * the lifecycle itself; the consequence callback does (REQ-SIGN-006).
     *
     * Idempotent: if the object's signingStatus is already a terminal value
     * matching the incoming outcome, the method returns the unchanged object
     * and does NOT fire the consequence a second time.
     *
     * @param array<string,mixed> $financeObject        The finance object to update.
     * @param string              $outcome              'signed' | 'declined' | 'expired'.
     * @param string              $signingRequestRef    The docudesk signingRequest id.
     * @param string|null         $signingProvider      eIDAS provider (nullable).
     * @param string|null         $signingLevel         eIDAS level (nullable).
     * @param string|null         $signedDocumentRef    NC Files ref to the signed artifact.
     * @param callable|null       $consequenceCallback  Called on 'signed' outcome to fire the GL consequence.
     *
     * @return array<string,mixed> Updated finance object.
     *
     * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-6
     */
    public function onSigningCallback(
        array $financeObject,
        string $outcome,
        string $signingRequestRef,
        ?string $signingProvider=null,
        ?string $signingLevel=null,
        ?string $signedDocumentRef=null,
        ?callable $consequenceCallback=null
    ): array {
        if (in_array($outcome, self::TERMINAL_STATUSES, true) === false) {
            throw new InvalidArgumentException('Unknown signing outcome: '.$outcome);
        }

        $currentStatus = (string) ($financeObject['signingStatus'] ?? '');

        // Idempotency guard: do not re-fire if already in this terminal state.
        if ($currentStatus === $outcome) {
            $this->logger->info('SigningDelegationService: idempotent callback — already '.$outcome.', no-op.');
            return $financeObject;
        }

        $financeObject['signingRequestRef'] = $signingRequestRef;
        $financeObject['signingStatus']     = $outcome;

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

        $this->logger->info('SigningDelegationService: callback consumed', [
            'outcome'           => $outcome,
            'signingRequestRef' => $signingRequestRef,
        ]);

        return $financeObject;

    }//end onSigningCallback()


}//end class
