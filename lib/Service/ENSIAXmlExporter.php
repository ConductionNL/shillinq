<?php

/**
 * ENSIA XML Exporter
 *
 * REQ-ENSIA-007 — render a college-approved ENSIAJaarcyclus into an
 * ENSIA-XSD-compliant XML document for upload to the landelijke
 * ENSIA-portal. No direct portal API exists in 2026 — the operator
 * downloads the artifact and uploads it manually.
 *
 * The XML carries:
 *   - organisation identification (KvK + naam) per REQ-ENSIA-007
 *   - per-domein question answers + maturity scores
 *   - per-question evidence document SHA-256 hashes for integrity
 *   - college-brief file reference + signature timestamp
 *   - cycle status + submission timestamp
 *
 * Pure rendering — no persistence; the caller archives the returned
 * string on ENSIAJaarcyclus for traceability.
 *
 * The XML is built with DOMDocument (construction only — no parsing of
 * untrusted input, so no XXE surface) and entity-escaped.
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

use DOMDocument;
use DOMElement;

/**
 * Renders ENSIAJaarcyclus records into ENSIA-XSD-compliant XML for the
 * landelijke ENSIA-portal (REQ-ENSIA-007).
 *
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
class ENSIAXmlExporter
{
    /**
     * ENSIA-portal target namespace per VNG XSD.
     *
     * @var string
     */
    private const NS = 'urn:vng:ensia:zelfevaluatie:v1';

    /**
     * Lifecycle precondition: may a cyclus be exported / submitted?
     *
     * Per REQ-ENSIA-007, the cyclus MUST be in `college-akkoord` status
     * AND carry a verklaringFile file-reference before XML export is
     * permitted (a missing signed verklaring is a portal-rejection cause).
     *
     * @param array<string,mixed> $cyclus The ENSIAJaarcyclus record.
     *
     * @return bool True when export is permitted.
     */
    public function canExport(array $cyclus): bool
    {
        $status = (string) ($cyclus['status'] ?? '');
        if ($status !== 'college-akkoord' && $status !== 'ingediend') {
            return false;
        }

        $verklaringFile = (string) ($cyclus['verklaringFile'] ?? '');
        return $verklaringFile !== '';

    }//end canExport()

    /**
     * Render the cyclus into ENSIA-XSD-compliant XML.
     *
     * @param array<string,mixed>            $cyclus      ENSIAJaarcyclus record.
     * @param array<int,array<string,mixed>> $vragen      All Evaluatievraag children of the cyclus.
     * @param string|null                    $submittedAt Optional submission timestamp override; defaults to now.
     *
     * @return string The XML string.
     */
    public function render(array $cyclus, array $vragen, ?string $submittedAt=null): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput       = true;
        $doc->preserveWhiteSpace = false;

        $root = $doc->createElementNS(self::NS, 'ensiaZelfevaluatie');
        $doc->appendChild($root);

        // Organisation.
        $org   = $cyclus['organisatie'] ?? [];
        $orgEl = $doc->createElement('organisatie');
        $orgEl->appendChild($doc->createElement('kvk', (string) ($org['kvk'] ?? '')));
        $orgEl->appendChild($doc->createElement('naam', (string) ($org['naam'] ?? '')));
        $root->appendChild($orgEl);

        // Cycle metadata.
        $root->appendChild($doc->createElement('jaar', (string) ($cyclus['jaar'] ?? '')));
        $root->appendChild($doc->createElement('status', (string) ($cyclus['status'] ?? '')));
        $root->appendChild($doc->createElement('vraagSetVersion', (string) ($cyclus['vraagSetVersion'] ?? '')));
        $root->appendChild($doc->createElement('verklaringFile', (string) ($cyclus['verklaringFile'] ?? '')));
        $root->appendChild(
            $doc->createElement('submittedAt', $submittedAt ?? (new \DateTimeImmutable('now'))->format(DATE_ATOM))
        );

        // Group questions per domein.
        $byDomein = [];
        foreach ($vragen as $v) {
            $domein = (string) ($v['domein'] ?? 'BIO');
            if (isset($byDomein[$domein]) === false) {
                $byDomein[$domein] = [];
            }

            $byDomein[$domein][] = $v;
        }

        $domeinenEl = $doc->createElement('verantwoordingsdomeinen');
        foreach ($byDomein as $domein => $domeinVragen) {
            $domeinEl = $doc->createElement('domein');
            $domeinEl->setAttribute('code', $domein);

            foreach ($domeinVragen as $v) {
                $vraagEl = $doc->createElement('vraag');
                $vraagEl->setAttribute('code', (string) ($v['vraagCode'] ?? ''));
                $vraagEl->appendChild($doc->createElement('antwoordType', (string) ($v['antwoordType'] ?? '')));

                $antwoord = $v['antwoord'] ?? null;
                if ($antwoord !== null) {
                    $vraagEl->appendChild($doc->createElement('antwoord', (string) $antwoord));
                }

                $score = $v['volwassenheidsScore'] ?? null;
                if ($score !== null) {
                    $vraagEl->appendChild($doc->createElement('volwassenheidsScore', (string) $score));
                }

                $toelichting = (string) ($v['toelichting'] ?? '');
                if ($toelichting !== '') {
                    $vraagEl->appendChild($doc->createElement('toelichting', $toelichting));
                }

                $peerReviewStatus = (string) ($v['peerReviewStatus'] ?? '');
                if ($peerReviewStatus !== '') {
                    $vraagEl->appendChild($doc->createElement('peerReviewStatus', $peerReviewStatus));
                }

                $bewijsstukken = $v['bewijsstukken'] ?? [];
                if (is_array($bewijsstukken) === true && count($bewijsstukken) > 0) {
                    $bewijsEl = $doc->createElement('bewijsstukken');
                    foreach ($bewijsstukken as $bw) {
                        $bewijsstukEl = $doc->createElement('bewijsstuk');
                        $bewijsstukEl->appendChild(
                            $doc->createElement('fileRef', (string) ($bw['fileRef'] ?? ''))
                        );
                        $bewijsstukEl->appendChild(
                            $doc->createElement('omschrijving', (string) ($bw['omschrijving'] ?? ''))
                        );
                        $sha = (string) ($bw['sha256'] ?? '');
                        if ($sha !== '') {
                            $bewijsstukEl->appendChild($doc->createElement('sha256', $sha));
                        }

                        $bewijsEl->appendChild($bewijsstukEl);
                    }

                    $vraagEl->appendChild($bewijsEl);
                }

                $domeinEl->appendChild($vraagEl);
            }//end foreach

            $domeinenEl->appendChild($domeinEl);
        }//end foreach

        $root->appendChild($domeinenEl);

        $xml = $doc->saveXML();
        if ($xml === false) {
            // SaveXML cannot fail on construction-only DOMDocument; defensive
            // fallback returns an empty signed envelope.
            return '<?xml version="1.0" encoding="UTF-8"?><ensiaZelfevaluatie/>';
        }

        return $xml;

    }//end render()
}//end class
