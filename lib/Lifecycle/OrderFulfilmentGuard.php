<?php

/**
 * OpdrachtUitvoering Guard
 *
 * ADR-031 exception-path lifecycle guard for the OpdrachtUitvoering completion
 * transition (in-progress → completed). Enforces the evidenceItem gate of REQ-004:
 * a delivery milestone may only be marked completed when at least one proof-of-
 * delivery (evidenceItem) is attached. The evidence live as docudesk file
 * references on the OpdrachtUitvoering record (ADR-022 / design D4).
 *
 * Referenced from the OpdrachtUitvoering schema's
 * x-openregister-lifecycle.transitions.voltooien.requires in
 * lib/Settings/register.d/20-bookkeeping-tenderned-integratie.json.
 *
 * ADR-031 exception reason: the "at least one non-empty attachment" existence
 * check on an embedded array is a per-record completeness rule the declarative
 * lifecycle DSL cannot yet express inside a `requires:` clause. Replace with a
 * declarative condition when the engine supports array-length predicates.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Completion precondition guard for the OpdrachtUitvoering schema per REQ-004.
 *
 * Fail-closed: any unexpected exception denies the completion (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
 */
class OrderFulfilmentGuard
{
    /**
     * Construct the guard with DI dependencies.
     *
     * @param LoggerInterface $logger Logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Precondition for the voltooien (in-progress → completed) transition.
     *
     * REQ-004: the delivery can only be completed when at least one evidenceItem
     * (proof of delivery) is attached. A evidenceItem is considered valid when it
     * carries a non-empty documentId, so an empty placeholder object cannot
     * satisfy the gate.
     *
     * Fail-closed: returns false on any exception (denies completion) per CWE-863.
     *
     * @param array<string, mixed> $fulfilment OpdrachtUitvoering object array supplied by OR.
     *
     * @return bool True when the delivery may be marked completed.
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
     */
    public function canVoltooien(array $fulfilment): bool
    {
        try {
            if ($this->hasValidBewijsstuk(fulfilment: $fulfilment) === false) {
                $this->logger->info(
                    'OrderFulfilmentGuard: no evidenceItem attached — denying completion (REQ-004)',
                    [
                        'commitmentId' => ($fulfilment['commitmentId'] ?? 'unknown'),
                        'milestoneId'     => ($fulfilment['milestoneId'] ?? 'unknown'),
                    ]
                );
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'OrderFulfilmentGuard: canVoltooien failed — denying completion (fail-closed)',
                [
                    'commitmentId' => ($fulfilment['commitmentId'] ?? 'unknown'),
                    'exception'      => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end canVoltooien()

    /**
     * Determine whether the delivery carries at least one valid evidenceItem.
     *
     * A evidenceItem is valid when it is an array carrying a non-empty documentId.
     * Scalar entries or entries without a documentId do not satisfy REQ-004.
     *
     * @param array<string, mixed> $fulfilment OpdrachtUitvoering object array.
     *
     * @return bool True when at least one valid evidenceItem exists.
     */
    private function hasValidBewijsstuk(array $fulfilment): bool
    {
        $evidence = ($fulfilment['evidence'] ?? []);
        if (is_array($evidence) === false) {
            return false;
        }

        foreach ($evidence as $evidenceItem) {
            if (is_array($evidenceItem) === false) {
                continue;
            }

            if (trim((string) ($evidenceItem['documentId'] ?? '')) !== '') {
                return true;
            }
        }

        return false;

    }//end hasValidBewijsstuk()
}//end class
