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
 * @spec openspec/changes/reporting-compliance-consolidation/specs/reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

/**
 * Immutable rendered-report payload.
 */
final class GeneratedFile
{
    /**
     * Construct an immutable rendered-report payload.
     *
     * @param string $fileName Suggested file name including extension.
     * @param string $mimeType MIME type (e.g. application/pdf, text/xml, text/csv).
     * @param string $format   Short format label: pdf | xml | xbrl | csv | json.
     * @param string $content  The rendered file bytes.
     */
    public function __construct(
        public readonly string $fileName,
        public readonly string $mimeType,
        public readonly string $format,
        public readonly string $content,
    ) {

    }//end __construct()
}//end class
