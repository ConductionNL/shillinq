<?php

/**
 * Docudesk-unavailable exception
 *
 * Thrown by a document report generator when docudesk is not installed, or
 * its rendering services cannot be resolved from the container
 * (AbstractDocumentReportGenerator::docudeskAvailable()). Distinct from a
 * generic rendering failure so ReportGenerationService can record a visible
 * `status: 'unavailable'` GeneratedReport and return a distinguishable
 * `docudesk-unavailable` error code — per ADR-081 rule 7 ("A source app MUST
 * degrade gracefully when the receiver is absent — an unsent allocation is a
 * visible pending state, never a silent drop") and mirroring hrmq's
 * `skipped-no-docudesk` outcome.
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
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

use RuntimeException;

/**
 * Signals that docudesk (the fleet's document-rendering owner, ADR-075) is
 * not available to render a document report.
 */
final class DocudeskUnavailableException extends RuntimeException {

}//end class
