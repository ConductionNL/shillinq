<?php

/**
 * Uren Tally Service
 *
 * End-of-day idempotent tally service per REQ-URC-001. Sums UrenDagregistratie
 * entries for a given (ondernemingId, datum) pair, applies the reistijd-cap
 * (REISTIJD_ZAKELIJK ≤ 4 uur/dag) via UrenDagregistratieGuard::pasReistijdCapToe,
 * filters categories that have telTMee=false on the UrenCategorie definition,
 * and produces a per-day total that the year-tally batch adds to
 * UrencriteriumYear.lopendeUren.
 *
 * Idempotent by construction: the service is a pure aggregator over the supplied
 * collection. Calling it twice on the same (entries, datum) yields the same total.
 * The scheduler's idempotency check (REQ-URC-001) is the caller's responsibility:
 * once a tally for (ondernemingId, datum) is written, the scheduler must skip the
 * day. This service does not write OR records — it returns the canonical patch
 * shape the caller persists.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Guard\UrenDagregistratieGuard;
use Psr\Log\LoggerInterface;

/**
 * Aggregates UrenDagregistratie into a per-day total with the reistijd-cap applied.
 *
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
 */
final class UrenTallyService
{

    /**
     * Categories that do NOT count toward the urencriterium total.
     *
     * The UrenCategorie seed has telTMee=true for every standard category, so this
     * list is empty by default. The constant is kept as the integration point for
     * future categories (e.g. a future "NIET_TELLEND" debug category).
     *
     * @var array<int, string>
     */
    private const NON_COUNTING_CATEGORIES = [];

    /**
     * Construct the service.
     *
     * @param UrenDagregistratieGuard $guard  The reistijd-cap policy owner.
     * @param LoggerInterface         $logger Diagnostics logger.
     */
    public function __construct(
        private readonly UrenDagregistratieGuard $guard,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Aggregate UrenDagregistratie entries for one day.
     *
     * Returns:
     *  - totaalUren: float — sum of counted hours after cap and category filter.
     *  - perCategorie: array<string, float> — counted hours per category.
     *  - overages: array<int, array{categorie: string, ingevoerd: float, geteld: float, notitie: string}>
     *    — entries whose hours exceeded a category cap.
     *
     * @param array<int, array<string, mixed>> $entries Day entries.
     *
     * @return array{totaalUren: float, perCategorie: array<string, float>, overages: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
     */
    public function tallyDag(array $entries): array
    {
        $totaal       = 0.0;
        $perCategorie = [];
        $overages     = [];

        foreach ($entries as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $categorie = (string) ($entry['categorie'] ?? '');
            if ($categorie === '' || in_array($categorie, self::NON_COUNTING_CATEGORIES, true) === true) {
                continue;
            }

            $uren    = (float) ($entry['uren'] ?? 0);
            $capInfo = $this->guard->pasReistijdCapToe(categorie: $categorie, uren: $uren);
            $geteld  = $capInfo['getoldeUren'];
            $notitie = $capInfo['capNotitie'];

            $totaal += $geteld;
            $perCategorie[$categorie] = (($perCategorie[$categorie] ?? 0.0) + $geteld);

            if ($notitie !== null) {
                $overages[] = [
                    'categorie' => $categorie,
                    'ingevoerd' => $uren,
                    'geteld'    => $geteld,
                    'notitie'   => $notitie,
                ];
            }
        }//end foreach

        return [
            'totaalUren'   => $totaal,
            'perCategorie' => $perCategorie,
            'overages'     => $overages,
        ];

    }//end tallyDag()

    /**
     * Aggregate YTD UrenDagregistratie entries into a UrencriteriumYear patch.
     *
     * Sums every counted hour across the supplied YTD entries and returns the
     * canonical {lopendeUren, berekendOp} patch shape. The caller persists this
     * onto the UrencriteriumYear record.
     *
     * @param array<int, array<string, mixed>> $entries YTD entries.
     * @param string                           $now     ISO-8601 berekendOp timestamp.
     *
     * @return array{lopendeUren: float, berekendOp: string}
     *
     * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
     */
    public function tallyYearToDate(array $entries, string $now): array
    {
        $totaal = 0.0;
        foreach ($entries as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $categorie = (string) ($entry['categorie'] ?? '');
            if ($categorie === '' || in_array($categorie, self::NON_COUNTING_CATEGORIES, true) === true) {
                continue;
            }

            $uren    = (float) ($entry['uren'] ?? 0);
            $cap     = $this->guard->pasReistijdCapToe(categorie: $categorie, uren: $uren);
            $totaal += $cap['getoldeUren'];
        }

        $this->logger->info(
            'UrenTallyService: YTD tally complete',
            ['totaalUren' => $totaal, 'berekendOp' => $now]
        );

        return [
            'lopendeUren' => $totaal,
            'berekendOp'  => $now,
        ];

    }//end tallyYearToDate()
}//end class
