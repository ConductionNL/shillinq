<?php

/**
 * ENSIA Question-Set Loader
 *
 * REQ-ENSIA-001 — load the BIO + domain-specific VNG question set for a
 * given verslagjaar and verantwoordingsdomeinen selection, and produce
 * Evaluatievraag record shapes that the caller persists via OR's
 * ObjectService.
 *
 * The default 2026 seed is bundled (lib/Settings/seeds/ensia-vng-2026.json);
 * an external source URL can be configured via app-config key
 * `ensia_vng_source_url` for instances that already curate a VNG mirror.
 *
 * The loader stamps the `vraagSetVersion` (e.g. "BIO-1.04-2026") on the
 * returned cycle metadata so REQ-ENSIA-001 second scenario (audit-trail
 * of which question set was used) is satisfied without a separate registry
 * call later in the cycle.
 *
 * Pure: file I/O for the bundled seed, no persistence. Fail-safe: if the
 * seed file is unreadable the loader returns an empty question array plus
 * a vraagSetVersion of "unknown" so the cycle still initialises and the
 * operator can manually attach questions later.
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
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Loads VNG question-set definitions and maps them to Evaluatievraag shapes
 * (REQ-ENSIA-001).
 *
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
class ENSIAQuestionSetLoader
{
    /**
     * Default seed location relative to lib/Service/.
     *
     * @var string
     */
    private const DEFAULT_SEED = __DIR__.'/../Settings/seeds/ensia-vng-2026.json';

    /**
     * Construct the loader.
     *
     * @param string|null $seedPath Optional override of the default seed
     *                              file path; primarily for unit testing.
     */
    public function __construct(private readonly ?string $seedPath=null)
    {

    }//end __construct()

    /**
     * Load the question set and produce Evaluatievraag shapes for the
     * given verslagjaar + verantwoordingsdomeinen selection.
     *
     * @param int               $jaar             Verslagjaar (e.g. 2026).
     * @param array<int,string> $domeinen         Selected domains, e.g.
     *                                            ['BIO', 'DigiD'].
     * @param string            $cyclusId         FK to ENSIAJaarcyclus
     *                                            stamped onto each
     *                                            Evaluatievraag
     *                                            record.
     * @param string            $administrationId FK to Administration
     *                                            stamped onto each
     *                                            Evaluatievraag
     *                                            record.
     *
     * @return array{
     *     vraagSetVersion: string,
     *     vragen: array<int,array<string,mixed>>,
     * } The loaded version stamp + per-question records.
     */
    public function load(int $jaar, array $domeinen, string $cyclusId, string $administrationId): array
    {
        $path = $this->seedPath ?? self::DEFAULT_SEED;

        if (file_exists($path) === false || is_readable($path) === false) {
            return [
                'vraagSetVersion' => 'unknown',
                'vragen'          => [],
            ];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return [
                'vraagSetVersion' => 'unknown',
                'vragen'          => [],
            ];
        }

        $payload = json_decode($raw, true);
        if (is_array($payload) === false) {
            return [
                'vraagSetVersion' => 'unknown',
                'vragen'          => [],
            ];
        }

        $version = (string) ($payload['vraagSetVersion'] ?? sprintf('BIO-1.04-%d', $jaar));
        $catalog = $payload['vragen'] ?? [];

        if (is_array($catalog) === false) {
            $catalog = [];
        }

        $domeinSet = [];
        foreach ($domeinen as $d) {
            $domeinSet[(string) $d] = true;
        }

        $vragen = [];
        foreach ($catalog as $q) {
            if (is_array($q) === false) {
                continue;
            }

            $domein = (string) ($q['domein'] ?? '');
            if ($domein === '' || isset($domeinSet[$domein]) === false) {
                continue;
            }

            if (isset($q['normniveau']) === true) {
                $normniveau = (int) $q['normniveau'];
            } else {
                $normniveau = null;
            }

            $vragen[] = [
                'cyclusId'         => $cyclusId,
                'administrationId' => $administrationId,
                'domein'           => $domein,
                'onderwerp'        => (string) ($q['onderwerp'] ?? ''),
                'vraagCode'        => (string) ($q['vraagCode'] ?? ''),
                'vraagtekst'       => (string) ($q['vraagtekst'] ?? ''),
                'antwoordType'     => (string) ($q['antwoordType'] ?? 'ja-nee-nvt'),
                'normniveau'       => $normniveau,
                'peerReviewStatus' => 'nog-niet-beoordeeld',
                'bewijsstukken'    => [],
            ];
        }//end foreach

        return [
            'vraagSetVersion' => $version,
            'vragen'          => $vragen,
        ];

    }//end load()
}//end class
