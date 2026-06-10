<?php

/**
 * ENSIA Bevinding Auto-Generator
 *
 * REQ-ENSIA-005 — on peer-review completion, scan every Evaluatievraag in
 * the cyclus where volwassenheidsScore < normniveau and produce a concept-
 * Bevinding (type=tekortkoming) capturing the question reference, score
 * gap, and an auto-populated beschrijving.
 *
 * Pure function over a vragen array — no persistence. The caller (typically
 * a lifecycle action on the parent cyclus or a controller endpoint) iterates
 * the returned Bevinding shapes and writes them to OR via ObjectService.
 * Operators may subsequently re-classify or suppress individual bevindingen
 * via the Bevinding lifecycle (open → geaccepteerd).
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
 * @spec openspec/changes/bookkeeping-ensia-zelfevaluatie/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Generates concept Bevinding records from a peer-reviewed ENSIA cyclus
 * (REQ-ENSIA-005).
 *
 * @spec openspec/changes/bookkeeping-ensia-zelfevaluatie/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
class ENSIABevindingGenerator
{


    /**
     * Generate Bevinding records for every Evaluatievraag whose maturity
     * score is strictly below its VNG normniveau (default 3).
     *
     * Only questions that have completed peer-review (peerReviewStatus =
     * akkoord) are considered — questions still in wijziging-gevraagd are
     * out of scope because their answer is still expected to change.
     *
     * Auto-generated records carry:
     *   - cyclusId / administrationId from the parent cyclus
     *   - vraagId pointing at the source Evaluatievraag
     *   - type=tekortkoming
     *   - beschrijving auto-populated with vraagCode + score gap
     *   - status=open
     *   - no owner / streefDatum (operator fills in on triage)
     *
     * @param array<string,mixed>            $cyclus The parent ENSIAJaarcyclus
     *                                               record (provides cyclusId
     *                                               + administrationId).
     * @param array<int,array<string,mixed>> $vragen Every Evaluatievraag in
     *                                               the cyclus.
     *
     * @return array<int,array<string,mixed>> Concept Bevinding records ready
     *                                        for ObjectService::saveObject.
     */
    public function generate(array $cyclus, array $vragen): array
    {
        $cyclusId         = (string) ($cyclus['id'] ?? $cyclus['uuid'] ?? '');
        $administrationId = (string) ($cyclus['administrationId'] ?? '');

        $findings = [];
        foreach ($vragen as $v) {
            $status = (string) ($v['peerReviewStatus'] ?? 'nog-niet-beoordeeld');
            if ($status !== 'akkoord') {
                continue;
            }

            $score      = $v['volwassenheidsScore'] ?? null;
            $normniveau = $v['normniveau'] ?? null;
            if (is_int($score) === false || is_int($normniveau) === false) {
                continue;
            }

            if ($score >= $normniveau) {
                continue;
            }

            $vraagCode = (string) ($v['vraagCode'] ?? '');
            $vraagTxt  = (string) ($v['vraagtekst'] ?? '');
            $vraagId   = (string) ($v['id'] ?? $v['uuid'] ?? '');

            $findings[] = [
                'cyclusId'         => $cyclusId,
                'administrationId' => $administrationId,
                'vraagId'          => $vraagId,
                'type'             => 'tekortkoming',
                'beschrijving'     => sprintf(
                    '%s — %s: volwassenheidsScore %d ligt onder VNG normniveau %d.',
                    $vraagCode,
                    $vraagTxt !== '' ? $vraagTxt : 'evaluatievraag',
                    $score,
                    $normniveau
                ),
                'status'           => 'open',
            ];
        }//end foreach

        return $findings;

    }//end generate()


}//end class
