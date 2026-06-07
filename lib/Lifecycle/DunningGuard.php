<?php

/**
 * Dunning Guard
 *
 * Single-method lifecycle seam evaluating the dunning escalation cadence for
 * an overdue AR invoice. Thin PHP seam per ADR-031 §"PHP guards remain a
 * legitimate seam" — used only while OpenRegister's dunning-workflow extension
 * is not yet stable (Risk 2 in proposal.md). Once OR's extension lands this
 * guard is removed and the cadence is consumed declaratively per ADR-022.
 *
 * The guard writes DunningRecord objects declaratively via OR's ObjectService
 * real API; it carries no app-local dunning table and no orchestration loop.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-accounts-receivable-core/spec.md (REQ-AR-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * Evaluates the dunning escalation level for an overdue AR invoice.
 *
 * Referenced from the AR dunning flow as the ADR-031 fallback while OR's
 * dunning-workflow extension is draft. Exactly one public method.
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.2
 */
class DunningGuard
{
    /**
     * Default dunning cadence thresholds in days past due, per escalation level.
     *
     * Operator-configurable through OR's dunning-policy once the extension is
     * stable; these are the spec defaults (REQ-AR-005).
     *
     * @var array<string,int>
     */
    private const CADENCE = [
        'reminder1'     => 14,
        'reminder2'     => 30,
        'formal-notice' => 45,
        'collection'    => 60,
    ];

    /**
     * Resolve the dunning level an overdue invoice has reached given the number
     * of days it is past due.
     *
     * Returns the highest cadence level whose threshold has been crossed, or
     * null when the invoice is not yet past the first reminder threshold. Pure
     * function — no IO, no side effects — so it is trivially unit-testable and
     * cannot grow into a service.
     *
     * @param int $daysPastDue Number of days the invoice is past its due date.
     *
     * @return string|null One of reminder1/reminder2/formal-notice/collection, or null.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.2
     */
    public function levelForDaysPastDue(int $daysPastDue): ?string
    {
        $level = null;
        foreach (self::CADENCE as $candidate => $threshold) {
            if ($daysPastDue >= $threshold) {
                $level = $candidate;
            }
        }

        return $level;

    }//end levelForDaysPastDue()
}//end class
