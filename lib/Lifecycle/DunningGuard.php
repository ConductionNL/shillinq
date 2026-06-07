<?php

/**
 * Dunning Guard
 *
 * ADR-031 exception-path guard that evaluates the dunning cadence + escalation
 * level for an overdue AR invoice (REQ-AR-005). Used as a shape-neutral fallback
 * while OpenRegister's dunning-workflow extension is not yet stable: it computes
 * which dunningLevel (reminder1 / reminder2 / formal-notice / collection) is due
 * for an invoice given its dueDate and the elapsed days. DunningRecord objects
 * are still written declaratively by the caller; this guard only decides the
 * level so no app-local dunning service/table is introduced.
 *
 * ADR-031 exception reason: OR's dunning-workflow extension (cadence escalation
 * over time) is referenced by the AR spec but is still draft. When the OR
 * extension lands, replace this guard with the declarative workflow reference
 * and delete this file. Tracked as the OR dunning-workflow gap.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Evaluates the due dunning level for an overdue AR invoice.
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.2
 */
class DunningGuard
{
    /**
     * Default cadence: days overdue at which each dunning level becomes due.
     *
     * Reminder1 at +14, reminder2 at +30, formal-notice at +45, collection at +60.
     * Customisable per administration via the OR dunning-policy record; this map
     * is the fallback when no policy is configured.
     *
     * @var array<string,int>
     */
    private const DEFAULT_CADENCE = [
        'reminder1'     => 14,
        'reminder2'     => 30,
        'formal-notice' => 45,
        'collection'    => 60,
    ];

    /**
     * Construct the guard.
     *
     * @param LoggerInterface $logger Logger for diagnostics.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the dunning level due for an invoice, or null if none is yet due.
     *
     * Given the invoice due date and the reference date (defaults to today),
     * returns the highest cadence level whose overdue-day threshold has been
     * passed, excluding any level already issued (passed in $alreadyIssued).
     * Returns null when nothing new is due. Fail-safe: returns null on any
     * exception so the caller never escalates on bad input.
     *
     * @param string            $dueDate       Invoice due date (Y-m-d).
     * @param array<int,string> $alreadyIssued Dunning levels already issued for this invoice.
     * @param string|null       $referenceDate Reference date (Y-m-d); defaults to today.
     * @param array<string,int> $cadence       Optional per-administration cadence override.
     *
     * @return string|null The dunning level to issue, or null when none is due.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.2
     */
    public function dueLevel(
        string $dueDate,
        array $alreadyIssued=[],
        ?string $referenceDate=null,
        array $cadence=[]
    ): ?string {
        try {
            $due = new DateTimeImmutable($dueDate);
            $ref = new DateTimeImmutable($referenceDate ?? 'today');

            $daysOverdue = (int) $ref->diff($due)->format('%r%a');
            // Diff %r%a is negative when $ref is after $due; invert to "days overdue".
            $daysOverdue = (-1 * $daysOverdue);
            if ($daysOverdue < 0) {
                return null;
            }

            $map = self::DEFAULT_CADENCE;
            if (empty($cadence) === false) {
                $map = $cadence;
            }

            $dueLevel = null;
            foreach ($map as $level => $threshold) {
                if ($daysOverdue >= $threshold && in_array($level, $alreadyIssued, true) === false) {
                    $dueLevel = $level;
                }
            }

            return $dueLevel;
        } catch (\Throwable $e) {
            $this->logger->error(
                'DunningGuard: dunning-level evaluation failed — no escalation',
                ['dueDate' => $dueDate, 'exception' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end dueLevel()
}//end class
