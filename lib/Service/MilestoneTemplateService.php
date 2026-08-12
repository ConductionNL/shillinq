<?php

/**
 * Milestone Template Service
 *
 * Pure (environment-free) service that turns a TenderNed contract into a
 * milestone plan (REQ-003) and a vendor cashflow forecast (REQ-008).
 *
 * generatePlan() selects a milestone template by opdrachttype from
 * lib/Settings/seeds/milestone-templates.json and distributes the milestone
 * dates across the contract term (looptijdStart..looptijdEind). The resulting
 * mijlpalen array is written onto the Verplichting on activation; the
 * contractmanager may edit it afterwards (design D3 — templates, not inference).
 *
 * buildCashflowForecast() distributes a contract value across the milestone
 * dates by percentage so an MKB supplier can plan cashflow from won contracts
 * (REQ-008 / design Q2 — each award maps to exactly one obligation, no
 * double-counting).
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * Deterministic milestone-plan generation (REQ-003) and cashflow forecast
 * (REQ-008) from milestone templates and contract terms.
 *
 * Stateless and environment-free: it reads the bundled milestone-templates.json
 * and performs date/percentage arithmetic only, so it is fully unit-testable
 * without a Nextcloud runtime.
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-3
 */
class MilestoneTemplateService
{

    /**
     * Absolute path to the milestone-templates seed file.
     *
     * @var string
     */
    private string $templatesPath;

    /**
     * Construct the service.
     *
     * @param string|null $templatesPath Optional override for the templates file
     *                                   (used by tests). Defaults to the bundled
     *                                   lib/Settings/seeds/milestone-templates.json.
     */
    public function __construct(?string $templatesPath=null)
    {
        $this->templatesPath = ($templatesPath ?? __DIR__.'/../Settings/seeds/milestone-templates.json');

    }//end __construct()

    /**
     * Return the template definition for a given opdrachttype.
     *
     * Falls back to the 'other' template when the requested type is unknown, so
     * every contract receives a usable plan (design D3 fallback).
     *
     * @param string $opdrachttype Contract type (levering-in-fases / dienstverlening-doorlopend / other).
     *
     * @return array<string, mixed> The matching template, or the 'other' fallback.
     *
     * @throws \RuntimeException When the templates file is missing or malformed.
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-3
     */
    public function getTemplate(string $opdrachttype): array
    {
        $templates = $this->loadTemplates();

        $fallback = null;
        foreach ($templates as $template) {
            if (($template['opdrachttype'] ?? '') === $opdrachttype) {
                return $template;
            }

            if (($template['opdrachttype'] ?? '') === 'other') {
                $fallback = $template;
            }
        }

        if ($fallback !== null) {
            return $fallback;
        }

        throw new RuntimeException('No milestone template found for opdrachttype "'.$opdrachttype.'" and no "other" fallback.');

    }//end getTemplate()

    /**
     * Generate a milestone plan for a contract term (REQ-003).
     *
     * Each template milestone is placed at looptijdStart + fractionOfTerm × term,
     * clamped to the contract end, and assigned a stable mijlpaalId. Every
     * generated milestone starts in the 'planned' status with no invoice number.
     *
     * @param string $opdrachttype  Contract type used to select the template.
     * @param string $looptijdStart Contract start date (ISO 8601, e.g. "2026-02-01").
     * @param string $looptijdEind  Contract end date (ISO 8601).
     *
     * @return array<int, array<string, mixed>> Ordered mijlpalen ready for the Verplichting.
     *
     * @throws \InvalidArgumentException When the dates are unparseable or end is not after start.
     * @throws \RuntimeException         When the templates file is missing or malformed.
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-3
     */
    public function generatePlan(string $opdrachttype, string $looptijdStart, string $looptijdEind): array
    {
        $start = strtotime($looptijdStart);
        $end   = strtotime($looptijdEind);
        if ($start === false || $end === false) {
            throw new InvalidArgumentException('looptijdStart and looptijdEind must be valid dates.');
        }

        if ($end <= $start) {
            throw new InvalidArgumentException('looptijdEind must be after looptijdStart.');
        }

        $template    = $this->getTemplate(opdrachttype: $opdrachttype);
        $termSeconds = ($end - $start);
        $mijlpalen   = [];
        $index       = 0;
        foreach (($template['mijlpalen'] ?? []) as $row) {
            $index++;
            $fraction = (float) ($row['fractionOfTerm'] ?? 0.0);
            $fraction = max(0.0, min(1.0, $fraction));
            $datum    = ($start + (int) round($termSeconds * $fraction));
            if ($datum > $end) {
                $datum = $end;
            }

            $mijlpalen[] = [
                'mijlpaalId'      => 'MS-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'datum'           => gmdate('Y-m-d', $datum),
                'description'    => (string) ($row['label'] ?? ('Mijlpaal '.$index)),
                'percentage'      => (float) ($row['percentage'] ?? 0.0),
                'opleveringsType' => (string) ($row['opleveringsType'] ?? 'deeloplevering'),
                'status'          => 'planned',
                'factuurnummer'   => null,
            ];
        }//end foreach

        return $mijlpalen;

    }//end generatePlan()

    /**
     * Sum the percentage across a milestone plan.
     *
     * Used by the percentage-validation rule (design validation rules): a plan
     * should sum to 100%. The caller decides whether a non-100 sum is a warning
     * (partial contract) or should be flagged.
     *
     * @param array<int, array<string, mixed>> $mijlpalen Milestone plan.
     *
     * @return float The summed percentage.
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
     */
    public function sumPercentage(array $mijlpalen): float
    {
        $sum = 0.0;
        foreach ($mijlpalen as $mijlpaal) {
            $sum += (float) ($mijlpaal['percentage'] ?? 0.0);
        }

        // Round to two decimals to absorb floating-point template noise (e.g. 12 × 8.33).
        return round($sum, 2);

    }//end sumPercentage()

    /**
     * Build a cashflow forecast distributing a contract value across milestones (REQ-008).
     *
     * Each milestone receives percentage/100 × contractWaarde, rounded to cents.
     * The last entry absorbs any rounding remainder so the forecast total exactly
     * equals contractWaarde (no cent drift across deelfacturen).
     *
     * @param float                            $contractWaarde Contract value (excl. BTW).
     * @param array<int, array<string, mixed>> $mijlpalen      Milestone plan with datum + percentage.
     *
     * @return array<int, array<string, mixed>> Forecast entries: datum, omschrijving, bedrag.
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-3
     */
    public function buildCashflowForecast(float $contractWaarde, array $mijlpalen): array
    {
        $forecast  = [];
        $allocated = 0.0;
        $count     = count($mijlpalen);
        $index     = 0;
        foreach ($mijlpalen as $mijlpaal) {
            $index++;
            $percentage = (float) ($mijlpaal['percentage'] ?? 0.0);

            // Final entry absorbs the rounding remainder so the total equals contractWaarde.
            $bedrag = round((($contractWaarde * $percentage) / 100.0), 2);
            if ($index === $count) {
                $bedrag = round(($contractWaarde - $allocated), 2);
            }

            $allocated += $bedrag;

            $forecast[] = [
                'datum'        => (string) ($mijlpaal['datum'] ?? ''),
                'description' => (string) ($mijlpaal['description'] ?? ''),
                'percentage'   => $percentage,
                'amount'       => $bedrag,
            ];
        }//end foreach

        return $forecast;

    }//end buildCashflowForecast()

    /**
     * Load and decode the milestone-templates seed file.
     *
     * @return array<int, array<string, mixed>> The list of templates.
     *
     * @throws \RuntimeException When the file is missing, unreadable, or malformed.
     */
    private function loadTemplates(): array
    {
        if (file_exists($this->templatesPath) === false) {
            throw new RuntimeException('Milestone templates file not found at '.$this->templatesPath);
        }

        $content = file_get_contents($this->templatesPath);
        if ($content === false) {
            throw new RuntimeException('Failed to read milestone templates file.');
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse milestone templates file: '.json_last_error_msg());
        }

        $templates = ($data['templates'] ?? []);
        if (is_array($templates) === false || count($templates) === 0) {
            throw new RuntimeException('Milestone templates file contains no templates.');
        }

        return $templates;

    }//end loadTemplates()
}//end class
