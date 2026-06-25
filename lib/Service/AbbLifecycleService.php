<?php

/**
 * AlgemeenBelangBesluit Lifecycle Service (WMO REQ-WMO-005)
 *
 * Pure-logic state-machine helper for the AlgemeenBelangBesluit (ABB) workflow:
 * concept → raadsvoorstel → raadsbesluit → publicatie → acm-notified → bezwaar →
 * geldig → evaluatie-due → herziening / intrekking. Encodes the precondition
 * checks per transition (e.g. publicatie requires gemeenteblad-kenmerk, geldig
 * requires both publicatieDatum and ACM-kenmerk) and the automatic-task
 * generation map (REQ-WMO-005 §c).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Side-effect-free ABB lifecycle state-machine (REQ-WMO-005).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-2
 */
class AbbLifecycleService
{
    /**
     * Valid status transitions (source -> destinations).
     *
     * @var array<string,array<int,string>>
     */
    private const TRANSITIONS = [
        'concept'       => ['raadsvoorstel'],
        'raadsvoorstel' => ['raadsbesluit', 'concept'],
        'raadsbesluit'  => ['publicatie'],
        'publicatie'    => ['acm-notified'],
        'acm-notified'  => ['bezwaar'],
        'bezwaar'       => ['geldig'],
        'geldig'        => ['evaluatie-due', 'intrekking', 'herziening'],
        'evaluatie-due' => ['herziening', 'geldig', 'intrekking'],
        'herziening'    => ['raadsvoorstel', 'intrekking'],
        'intrekking'    => [],
    ];

    /**
     * Validate a desired status transition (REQ-WMO-005).
     *
     * @param string              $fromStatus Current status.
     * @param string              $toStatus   Desired status.
     * @param array<string,mixed> $abb        Full ABB record (used for precondition checks).
     *
     * @return array{ok:bool, error?:string} Result envelope.
     */
    public function canTransition(string $fromStatus, string $toStatus, array $abb): array
    {
        $allowed = self::TRANSITIONS[$fromStatus] ?? [];
        if (in_array($toStatus, $allowed, true) === false) {
            return ['ok' => false, 'error' => sprintf('Transition %s -> %s is not allowed', $fromStatus, $toStatus)];
        }

        // Per-target precondition checks.
        switch ($toStatus) {
            case 'raadsbesluit':
                $kenmerk = trim((string) ($abb['kenmerk'] ?? ''));
                if ($kenmerk === '') {
                    return ['ok' => false, 'error' => 'Transition to raadsbesluit requires kenmerk'];
                }
                break;

            case 'publicatie':
                if (trim((string) ($abb['publicatieGemeenteblad'] ?? '')) === '') {
                    return ['ok' => false, 'error' => 'Transition to publicatie requires publicatieGemeenteblad'];
                }

                if (trim((string) ($abb['publicatieDatum'] ?? '')) === '') {
                    return ['ok' => false, 'error' => 'Transition to publicatie requires publicatieDatum'];
                }
                break;

            case 'acm-notified':
                $acm = (array) ($abb['kennisgevingAcm'] ?? []);
                if (((bool) ($acm['ingediend'] ?? false)) === false) {
                    return ['ok' => false, 'error' => 'Transition to acm-notified requires kennisgevingAcm.ingediend=true'];
                }

                if (trim((string) ($acm['kenmerk'] ?? '')) === '') {
                    return ['ok' => false, 'error' => 'Transition to acm-notified requires kennisgevingAcm.kenmerk'];
                }
                break;

            case 'geldig':
                if (trim((string) ($abb['publicatieDatum'] ?? '')) === '') {
                    return ['ok' => false, 'error' => 'Geldig requires publicatieDatum'];
                }

                $acm = (array) ($abb['kennisgevingAcm'] ?? []);
                if (((bool) ($acm['ingediend'] ?? false)) === false || trim((string) ($acm['kenmerk'] ?? '')) === '') {
                    return ['ok' => false, 'error' => 'Geldig requires ACM kenmerk'];
                }
                break;

            case 'intrekking':
            case 'herziening':
                // Both terminal-ish states; allowed from geldig or evaluatie-due.
                break;

            default:
                break;
        }//end switch

        return ['ok' => true];

    }//end canTransition()

    /**
     * Apply a transition, producing the post-transition ABB plus the task envelope (REQ-WMO-005).
     *
     * @param array<string,mixed> $abb      Current ABB record.
     * @param string              $toStatus Desired new status.
     *
     * @return array{abb:array<string,mixed>, tasks:array<int,array<string,mixed>>} Updated ABB and any auto-generated tasks.
     *
     * @throws InvalidArgumentException When the transition is not allowed.
     */
    public function transition(array $abb, string $toStatus): array
    {
        $fromStatus = (string) ($abb['status'] ?? 'concept');
        $check      = $this->canTransition(fromStatus: $fromStatus, toStatus: $toStatus, abb: $abb);
        if ($check['ok'] === false) {
            throw new InvalidArgumentException($check['error']);
        }

        $abb['status'] = $toStatus;

        // Auto-calculate volgendeEvaluatie when entering geldig.
        if ($toStatus === 'geldig' && trim((string) ($abb['vaststellingsdatum'] ?? '')) !== '') {
            $abb['volgendeEvaluatie'] = $this->calculateNextEvaluation(
                vaststellingsdatum: (string) $abb['vaststellingsdatum'],
                ritme: (string) ($abb['evaluatieRitme'] ?? 'tweejaarlijks')
            );
        }

        return [
            'abb'   => $abb,
            'tasks' => $this->generateTasks(abb: $abb, toStatus: $toStatus),
        ];

    }//end transition()

    /**
     * Generate the automatic tasks for a given status (REQ-WMO-005 §c).
     *
     * @param array<string,mixed> $abb      The ABB record.
     * @param string              $toStatus The new status.
     *
     * @return array<int,array<string,mixed>> Task envelopes.
     */
    public function generateTasks(array $abb, string $toStatus): array
    {
        $tasks   = [];
        $now     = new DateTimeImmutable('now');
        $kenmerk = (string) ($abb['kenmerk'] ?? 'ABB');

        switch ($toStatus) {
            case 'raadsbesluit':
                $due     = $now->add(new DateInterval('P14D'))->format('Y-m-d');
                $tasks[] = [
                    'type'       => 'publish-gemeenteblad',
                    'subject'    => sprintf('Publish in gemeenteblad: %s', $kenmerk),
                    'dueDate'    => $due,
                    'assignedTo' => 'griffier',
                    'abbId'      => (string) ($abb['id'] ?? $abb['_id'] ?? ''),
                ];
                break;

            case 'publicatie':
                $due     = $now->add(new DateInterval('P7D'))->format('Y-m-d');
                $tasks[] = [
                    'type'       => 'notify-acm',
                    'subject'    => sprintf('Notify ACM: %s', $kenmerk),
                    'dueDate'    => $due,
                    'assignedTo' => 'juridisch-beleidsadviseur',
                    'abbId'      => (string) ($abb['id'] ?? $abb['_id'] ?? ''),
                ];
                break;

            case 'acm-notified':
                $due     = $now->add(new DateInterval('P42D'))->format('Y-m-d');
                $tasks[] = [
                    'type'       => 'review-bezwaarschriften',
                    'subject'    => sprintf('Review bezwaarschriften (6 weeks): %s', $kenmerk),
                    'dueDate'    => $due,
                    'assignedTo' => 'juridisch-beleidsadviseur',
                    'abbId'      => (string) ($abb['id'] ?? $abb['_id'] ?? ''),
                ];
                break;

            case 'evaluatie-due':
                $tasks[] = [
                    'type'       => 'evaluate-abb',
                    'subject'    => sprintf('Evaluate ABB: %s', $kenmerk),
                    'dueDate'    => (string) ($abb['volgendeEvaluatie'] ?? $now->format('Y-m-d')),
                    'assignedTo' => 'juridisch-beleidsadviseur',
                    'abbId'      => (string) ($abb['id'] ?? $abb['_id'] ?? ''),
                ];
                break;

            default:
                break;
        }//end switch

        return $tasks;

    }//end generateTasks()

    /**
     * Calculate volgendeEvaluatie based on vaststellingsdatum + ritme.
     *
     * @param string $vaststellingsdatum Vaststellingsdatum (ISO date).
     * @param string $ritme              One of jaarlijks / tweejaarlijks / driejaarlijks.
     *
     * @return string Next evaluation date (ISO).
     */
    public function calculateNextEvaluation(string $vaststellingsdatum, string $ritme): string
    {
        try {
            $base = new DateTimeImmutable($vaststellingsdatum);
        } catch (\Throwable) {
            return '';
        }

        $years = match ($ritme) {
            'jaarlijks'      => 1,
            'tweejaarlijks'  => 2,
            'driejaarlijks'  => 3,
            default          => 2,
        };

        return $base->add(new DateInterval('P'.$years.'Y'))->format('Y-m-d');

    }//end calculateNextEvaluation()

    /**
     * Flag dependent CommercialActivity records when an ABB enters herziening / intrekking (REQ-WMO-005 §dependents).
     *
     * @param array<int,array<string,mixed>> $activities Linked CommercialActivity records.
     * @param array<string,mixed>            $abb        The ABB.
     *
     * @return array<int,array{commercialActivityId:string,reason:string}> Flag envelopes.
     */
    public function flagDependentActivities(array $activities, array $abb): array
    {
        $status = (string) ($abb['status'] ?? '');
        if (in_array($status, ['herziening', 'intrekking'], true) === false) {
            return [];
        }

        $flags   = [];
        $kenmerk = (string) ($abb['kenmerk'] ?? 'ABB');
        if ($status === 'intrekking') {
            $reason = sprintf('Exemption ABB %s ingetrokken; review activity', $kenmerk);
        } else {
            $reason = sprintf('Exemption ABB %s in herziening; review activity', $kenmerk);
        }

        foreach ($activities as $activity) {
            if (is_array($activity) === false) {
                continue;
            }

            $flags[] = [
                'commercialActivityId' => (string) ($activity['id'] ?? $activity['_id'] ?? ''),
                'reason'               => $reason,
            ];
        }

        return $flags;

    }//end flagDependentActivities()
}//end class
