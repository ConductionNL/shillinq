<?php

/**
 * Report generator interface
 *
 * One implementation per report type. A generator renders the report for a given
 * context (period, administration) in one of its supported formats and returns a
 * GeneratedFile. ReportGenerationService discovers generators, runs the one whose
 * reportType() matches, persists the result to Nextcloud Files with tags/metadata,
 * and records a GeneratedReport object.
 *
 * Two flavours of generator:
 *  - DATA reports (SAF-T XML, ledger CSV, SBR/XBRL) render bytes natively in
 *    shillinq.
 *  - DOCUMENT reports (annual accounts, management letters, statements) assemble
 *    a structured report body (data loading + business classification, unchanged
 *    from before) and hand it to docudesk's `DocumentService::generateDocument()`
 *    for rendering into editable ODT ('odf' in docudesk's own vocabulary) or PDF
 *    — shillinq holds no PhpWord/PhpSpreadsheet/dompdf and adds no office/PDF
 *    dependency of its own (ADR-075: docudesk owns document/PDF generation for
 *    the fleet). Template content lives in docudesk under
 *    `namespace: "shillinq"`; this interface's implementations do not author it
 *    (see openspec/changes/reports-via-docudesk).
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

/**
 * Contract for a single report type's generator.
 */
interface ReportGeneratorInterface {
	/**
	 * The ReportCatalogue report-type id this generator produces (e.g. 'saft',
	 * 'trial-balance', 'annual-accounts', 'vat-return').
	 *
	 * @return string
	 */
	public static function reportType(): string;

	/**
	 * The formats this generator can emit, in preference order. DATA reports return
	 * e.g. ['xml'] or ['csv']; DOCUMENT reports return ['odt', 'pdf'] (the editable
	 * format first, mapped internally to docudesk's 'odf'/'pdf' `options.format`
	 * values — docudesk's DocumentService supports no `docx` output).
	 *
	 * @return array<int, string>
	 */
	public static function supportedFormats(): array;

	/**
	 * Render the report.
	 *
	 * @param array<string, mixed> $context `{ period, administrationId, jurisdiction?, ... }`.
	 * @param string $format One of supportedFormats().
	 *
	 * @return GeneratedFile
	 */
	public function generate(array $context, string $format): GeneratedFile;
}//end interface
