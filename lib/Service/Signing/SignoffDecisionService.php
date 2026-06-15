<?php

/**
 * Sign-off Decision Service — ADR-031 integration exception path
 *
 * Raises decidesk Decisions (signature-as-method) via the ADR-019 integration
 * registry and consumes the approved/rejected outcome to write the governance
 * decision consumer field set on the originating finance object
 * (REQ-SIGN-002/005). No approval logic is implemented here; shillinq only
 * requests and consumes. Idempotent: a repeated callback is a no-op.
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
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-7
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
 * ADR-031 exception-path: raises a decidesk Decision (signature-as-method)
 * via the ADR-019 integration registry; consumes the approved/rejected outcome
 * to drive the finance lifecycle and the existing GL consequence.
 *
 * All decidesk calls go through the integration registry — no hard-coded
 * hostnames (REQ-SIGN-005).
 *
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-7
 */
class SignoffDecisionService
{

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
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()


    /**
     * Raise a decidesk Decision for governance sign-off (REQ-SIGN-002).
     *
     * Opens a decidesk Decision (signature-as-method) through the ADR-019
     * integration registry. Sets decisionOutcome=pending and stores the
     * returned decisionRef. Returns the updated object for the caller to
     * persist via OR ObjectService (ADR-022).
     *
     * No approval logic is implemented in shillinq.
     *
     * @param array<string,mixed> $financeObject The finance object (ACMReport/ActuarialValuation/AnnualReport).
     * @param object              $registry       The ADR-019 integration registry.
     * @param string              $decisionType   Decision type hint for decidesk (e.g. 'sign-off', 'adoption').
     *
     * @return array<string,mixed> Updated finance object with decisionOutcome=pending.
     *
     * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-7
     */
    public function requestSignoff(array $financeObject, object $registry, string $decisionType='sign-off'): array
    {
        $currentOutcome = (string) ($financeObject['decisionOutcome'] ?? '');

        if ($currentOutcome === 'approved') {
            // Idempotent: already approved — no-op.
            return $financeObject;
        }

        // Raise the Decision via the ADR-019 registry (no raw HTTP).
        $decisionRef = null;
        try {
            $decisionRef = $registry->call('decidesk', 'createDecision', [
                'decisionType'    => $decisionType,
                'method'          => 'signature',
                'objectRef'       => (string) ($financeObject['id'] ?? $financeObject['_id'] ?? ''),
                'administrationId' => (string) ($financeObject['administrationId'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'SignoffDecisionService: registry call to decidesk failed',
                ['exception' => $e->getMessage()]
            );
            throw $e;
        }

        $financeObject['decisionRef']     = is_string($decisionRef) ? $decisionRef : null;
        $financeObject['decisionOutcome'] = 'pending';

        $this->logger->info('SignoffDecisionService: Decision raised', [
            'decisionRef'  => $financeObject['decisionRef'],
            'decisionType' => $decisionType,
        ]);

        return $financeObject;

    }//end requestSignoff()


    /**
     * Consume a decidesk approved/rejected decision callback (REQ-SIGN-005).
     *
     * Writes the governance-decision consumer field set onto the finance object.
     * On 'approved', triggers the accounting consequence through the caller's
     * consequence callback — shillinq does not own the approval transition
     * (REQ-SIGN-006).
     *
     * Idempotent: if the object's decisionOutcome is already a terminal value
     * matching the incoming outcome, returns the unchanged object without firing
     * the consequence a second time.
     *
     * @param array<string,mixed> $financeObject       The finance object to update.
     * @param string              $outcome             'approved' | 'rejected'.
     * @param string              $decisionRef         The decidesk Decision id.
     * @param callable|null       $consequenceCallback Called on 'approved' to fire the GL consequence.
     *
     * @return array<string,mixed> Updated finance object.
     *
     * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-7
     */
    public function onDecisionCallback(
        array $financeObject,
        string $outcome,
        string $decisionRef,
        ?callable $consequenceCallback=null
    ): array {
        if (in_array($outcome, self::TERMINAL_OUTCOMES, true) === false) {
            throw new InvalidArgumentException('Unknown decision outcome: '.$outcome);
        }

        $currentOutcome = (string) ($financeObject['decisionOutcome'] ?? '');

        // Idempotency guard.
        if ($currentOutcome === $outcome) {
            $this->logger->info('SignoffDecisionService: idempotent callback — already '.$outcome.', no-op.');
            return $financeObject;
        }

        $financeObject['decisionRef']     = $decisionRef;
        $financeObject['decisionOutcome'] = $outcome;

        if ($outcome === 'approved' && $consequenceCallback !== null) {
            // Fire the accounting consequence exactly once (REQ-SIGN-006).
            $consequenceCallback($financeObject);
        }

        $this->logger->info('SignoffDecisionService: callback consumed', [
            'outcome'     => $outcome,
            'decisionRef' => $decisionRef,
        ]);

        return $financeObject;

    }//end onDecisionCallback()


}//end class
