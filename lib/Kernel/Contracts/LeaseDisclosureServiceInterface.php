<?php

/**
 * Contract of LeaseDisclosureService
 *
 * The public surface of LeaseDisclosureService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of LeaseDisclosureService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeaseDisclosureServiceInterface {

	/**
	 * Render the disclosure note for the Phase-2 PDF pipeline (Task 10.2).
	 *
	 * Wraps exportDisclosureNoteToHtml in the docudesk-render envelope:
	 *   - kind: 'lease-disclosure-note'
	 *   - status: 'pending-pdf-pipeline'  (rendered when docudesk lands)
	 *   - html : self-contained HTML body
	 *
	 * The envelope shape is what the docudesk integration will consume
	 * to produce the final signed PDF (REQ-LD-004(1)). Until the
	 * pipeline lands the caller can still surface the HTML to the
	 * operator as a print-friendly preview.
	 *
	 * @param array<string,mixed> $disclosure The disclosure payload.
	 * @param string $language 'en' or 'nl'.
	 *
	 * @return array<string,mixed> The render envelope.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function exportDisclosureNoteToPDF(array $disclosure, string $language = 'en'): array;

	/**
	 * Flatten the disclosure payload into CSV rows (REQ-LD-001, task 10.3).
	 *
	 * Produces a header + one row per disclosure figure suitable for direct
	 * download. The structured payload (generateForPeriod / aggregateFromLeases)
	 * is the canonical input: this method just walks the rouByClass / maturity
	 * maps and the scalar totals into a flat 2-column table (label,value) plus
	 * a section-tag column so the consumer can group on import.
	 *
	 * No PDF / docudesk dependency — pure RFC 4180 CSV string. Tests cover the
	 * flattening shape; the streaming response wrapper lands with the
	 * controller surface.
	 *
	 * @param array<string,mixed> $disclosure The structured disclosure payload.
	 *
	 * @return string CSV body (RFC 4180, comma delimited, LF terminated).
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function exportToCSV(array $disclosure): string;

	/**
	 * Skeleton XBRL/ESEF export for the bookkeeping-sbr-xbrl-reporting
	 * integration (Task 10.4).
	 *
	 * Emits an iXBRL fragment with the IFRS 16 disclosure facts the
	 * SBR XBRL pipeline will tag against the EFRAG taxonomy. The full
	 * ESEF wrapper + taxonomy linking lands with the
	 * `bookkeeping-sbr-xbrl-reporting` change; this skeleton stamps the
	 * facts in a stable contextRef so the consumer can map them.
	 *
	 * Pure-logic helper: NO XBRL validation is performed; the
	 * sbr-xbrl-reporting service will validate against the taxonomy.
	 *
	 * @param array<string,mixed> $disclosure The disclosure payload.
	 *
	 * @return array<string,mixed> { status, contextRef, facts:array<string,string|float|int> }
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function exportToXBRL(array $disclosure): array;

	/**
	 * Generate the disclosure table for a fiscal period (REQ-LD-001).
	 *
	 * @param string $administrationId Administration scope (server-resolved, ADR-005).
	 * @param string $fiscalPeriod Fiscal period label (e.g. "2026").
	 *
	 * @return array<string,mixed> The LeaseDisclosureTable payload.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function generateForPeriod(string $administrationId, string $fiscalPeriod): array;
}//end interface
