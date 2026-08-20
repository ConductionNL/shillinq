<?php

/**
 * Generated report file value object
 *
 * The in-memory result of a report generator: the rendered bytes plus the
 * filename, MIME type and format label. ReportGenerationService persists this to
 * Nextcloud Files and records a GeneratedReport object pointing at it.
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
 * Immutable rendered-report payload.
 */
final class GeneratedFile {
	/**
	 * Construct an immutable rendered-report payload.
	 *
	 * @param string $fileName Suggested file name including extension.
	 * @param string $mimeType MIME type (e.g. application/pdf, text/xml, text/csv).
	 * @param string $format Short format label: pdf | xml | xbrl | csv | json.
	 * @param string $content The rendered file bytes.
	 */
	public function __construct(
		public readonly string $fileName,
		public readonly string $mimeType,
		public readonly string $format,
		public readonly string $content,
	) {

	}//end __construct()
}//end class
