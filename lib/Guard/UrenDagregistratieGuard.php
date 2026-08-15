<?php

/**
 * Uren Dagregistratie Guard
 *
 * ADR-031 exception-path lifecycle guard for the UrenDagregistratie save
 * precondition. Before any daily hour-ledger entry is persisted, this guard
 * enforces and normalises the fiscal-ledger invariants that the declarative
 * OpenRegister lifecycle DSL cannot yet express:
 *   1. The reistijd-cap of 4 hours/day: REISTIJD_ZAKELIJK entries that exceed the
 *      cap are accepted but only 4 hours are counted (getoldeUren), with an audit
 *      note recorded on capNotitie (REQ-URC-001).
 *   2. Categories that require evidence (e.g. SCHOLING) may not be finalised
 *      without a bewijs reference (REQ-URC-004).
 *   3. Backfill rules (REQ-URC-017): entries up to 7 days old are accepted with an
 *      auto-stamped "Backfill T+N dagen" label; entries older than 7 days require
 *      an explicit reden AND a bewijs reference.
 *
 * Referenced from the UrenDagregistratie schema's
 * x-openregister-lifecycle.preconditions.save in
 * lib/Settings/register.d/20-zzp-urencriterium-tracker.json (declared on a status
 * field by the implementing UI; for now the guard is invoked directly by callers).
 *
 * ADR-031 exception reason: the per-day reistijd cap, the conditional evidence
 * requirement and the date-relative backfill rule are arithmetic / temporal policy
 * rules the declarative lifecycle DSL cannot express. They live here so manual
 * entry, agenda-import and the tally batch all write the same validated shape.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zzp-urencriterium-tracker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use Psr\Log\LoggerInterface;

/**
 * Save precondition guard for the UrenDagregistratie schema per REQ-URC-001/004/017.
 *
 * Fail-closed: any unexpected exception denies the save (CWE-863).
 *
 * @spec openspec/specs/zzp-urencriterium-tracker/spec.md
 */
class UrenDagregistratieGuard
{

    /**
     * Maximum number of reistijd hours counted per day (REQ-URC-001).
     */
    public const REISTIJD_CAP_PER_DAY = 4.0;

    /**
     * Number of days within which a backfill is accepted without reden + bewijs.
     */
    public const BACKFILL_FREE_DAYS = 7;

    /**
     * Categories that require an evidence reference before the entry is finalised.
     *
     * @var array<int, string>
     */
    private const EVIDENCE_REQUIRED_CATEGORIES = ['TRAINING', 'FICTION_ZEZ'];

    /**
     * Construct the guard.
     *
     * @param LoggerInterface $logger Logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Save precondition for the UrenDagregistratie schema.
     *
     * Validates the evidence requirement and backfill rule. Returns true only when
     * every check passes. Fail-closed: returns false on any exception per CWE-863.
     *
     * @param array<string, mixed> $entry UrenDagregistratie object array supplied by OR.
     *
     * @return bool True when the record may be saved.
     *
     * @spec openspec/specs/zzp-urencriterium-tracker/spec.md
     */
    public function validateOnSave(array $entry): bool
    {
        try {
            if ($this->evidenceRequirementMet(entry: $entry) === false) {
                return false;
            }

            return $this->backfillRuleMet(entry: $entry);
        } catch (\Throwable $e) {
            $this->logger->error(
                'UrenDagregistratieGuard: validateOnSave failed — denying save (fail-closed)',
                [
                    'enterpriseId' => ($entry['enterpriseId'] ?? 'unknown'),
                    'exception'    => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end validateOnSave()

    /**
     * Apply the reistijd-cap to an entry, returning the counted hours and an
     * optional audit note (REQ-URC-001).
     *
     * For REISTIJD_ZAKELIJK entries above the cap, only REISTIJD_CAP_PER_DAY hours
     * are counted and a "Reistijd-cap toegepast: N uur niet meegeteld" note is
     * produced. All other categories count their hours unchanged.
     *
     * @param string $categorie The category code.
     * @param float  $uren      The registered hours.
     *
     * @return array{getoldeUren: float, capNotitie: ?string} Counted hours + note.
     *
     * @spec openspec/specs/zzp-urencriterium-tracker/spec.md
     */
    public function pasReistijdCapToe(string $categorie, float $uren): array
    {
        if ($categorie !== 'TRAVEL_TIME_BUSINESS' || $uren <= self::REISTIJD_CAP_PER_DAY) {
            return ['getoldeUren' => $uren, 'capNotitie' => null];
        }

        $nietGeteld = ($uren - self::REISTIJD_CAP_PER_DAY);
        $notitie    = sprintf(
            'Reistijd-cap toegepast: %s uur niet meegeteld',
            rtrim(rtrim(number_format($nietGeteld, 2, '.', ''), '0'), '.')
        );

        return [
            'getoldeUren' => self::REISTIJD_CAP_PER_DAY,
            'capNotitie'  => $notitie,
        ];

    }//end pasReistijdCapToe()

    /**
     * Compute the backfill label for an entry given its work date and the date it
     * is registered (REQ-URC-017).
     *
     * @param string $datum             Work date (Y-m-d).
     * @param string $registratieMoment Registration timestamp (any strtotime value).
     *
     * @return string|null "Backfill T+N dagen" when registered after the work date,
     *                     or null when registered on the same day or earlier.
     *
     * @spec openspec/specs/zzp-urencriterium-tracker/spec.md
     */
    public function bepaalBackfillLabel(string $datum, string $registratieMoment): ?string
    {
        $days = $this->backfillDays(datum: $datum, registratieMoment: $registratieMoment);
        if ($days === null || $days <= 0) {
            return null;
        }

        return 'Backfill T+'.$days.' dagen';

    }//end bepaalBackfillLabel()

    /**
     * Verify that an entry in an evidence-requiring category carries a bewijs
     * reference (REQ-URC-004 / REQ-URC-008).
     *
     * @param array<string, mixed> $entry UrenDagregistratie object array.
     *
     * @return bool True when no evidence is required, or evidence is present.
     */
    private function evidenceRequirementMet(array $entry): bool
    {
        $categorie = (string) ($entry['category'] ?? '');
        if (in_array($categorie, self::EVIDENCE_REQUIRED_CATEGORIES, true) === false) {
            return true;
        }

        $bewijs = (string) ($entry['backfillBewijs'] ?? '');
        if ($bewijs === '') {
            $this->logger->info(
                'UrenDagregistratieGuard: category requires evidence but none supplied — denying save',
                ['category' => $categorie]
            );
            return false;
        }

        return true;

    }//end evidenceRequirementMet()

    /**
     * Verify the backfill rule: entries older than BACKFILL_FREE_DAYS require both a
     * reden and a bewijs reference (REQ-URC-017).
     *
     * @param array<string, mixed> $entry UrenDagregistratie object array.
     *
     * @return bool True when within the free window, or reden + bewijs present.
     */
    private function backfillRuleMet(array $entry): bool
    {
        $days = $this->backfillDays(
            datum: (string) ($entry['datum'] ?? ''),
            registratieMoment: (string) ($entry['registratieMoment'] ?? '')
        );

        if ($days === null || $days <= self::BACKFILL_FREE_DAYS) {
            return true;
        }

        $reden  = (string) ($entry['backfillReason'] ?? '');
        $bewijs = (string) ($entry['backfillBewijs'] ?? '');
        if ($reden === '' || $bewijs === '') {
            $this->logger->info(
                'UrenDagregistratieGuard: backfill older than 7 days requires reden + bewijs — denying save',
                [
                    'days'      => $days,
                    'hasReden'  => ($reden !== ''),
                    'hasBewijs' => ($bewijs !== ''),
                ]
            );
            return false;
        }

        return true;

    }//end backfillRuleMet()

    /**
     * Compute the number of whole days between a work date and the registration
     * moment. Returns null when either value is unparseable or no registration
     * moment is given (treated as same-day, not a backfill).
     *
     * @param string $datum             Work date (Y-m-d).
     * @param string $registratieMoment Registration timestamp.
     *
     * @return int|null Whole days of delay, or null when not computable.
     */
    private function backfillDays(string $datum, string $registratieMoment): ?int
    {
        if ($datum === '' || $registratieMoment === '') {
            return null;
        }

        $work = strtotime($datum);
        $reg  = strtotime($registratieMoment);
        if ($work === false || $reg === false) {
            return null;
        }

        // Compare calendar days at UTC midnight to avoid intra-day rounding.
        $workDay = strtotime(gmdate('Y-m-d', $work).'T00:00:00Z');
        $regDay  = strtotime(gmdate('Y-m-d', $reg).'T00:00:00Z');
        if ($workDay === false || $regDay === false) {
            return null;
        }

        return (int) floor((($regDay - $workDay) / 86400));

    }//end backfillDays()
}//end class
