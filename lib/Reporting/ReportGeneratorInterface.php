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
 *  - DOCUMENT reports (annual accounts, management letters, statements) render
 *    editable DOCX/ODT and PDF via the PHPOffice libraries bundled in OpenRegister
 *    — PhpOffice\PhpWord (DOCX/ODT), PhpOffice\PhpSpreadsheet (XLSX/CSV) and dompdf
 *    (PDF). OpenRegister is a runtime dependency whose autoloader is always active,
 *    so these classes resolve without adding any office/PDF dependency to shillinq.
 *    Generation uses the DEFAULT templates shipped in lib/Reporting/templates/; if a
 *    user wants to customise those templates they do so in docudesk.
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
 * @spec openspec/changes/reporting-compliance-consolidation/specs/reporting/spec.md
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
	 * e.g. ['xml'] or ['csv']; DOCUMENT reports return ['docx', 'odt', 'pdf'] (the
	 * editable formats first, since docudesk renders them from one template).
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
