<?php

/**
 * Rendered payment-run file value object
 *
 * The in-memory result of a payment-run generator: the rendered bytes plus the
 * filename, MIME type and short format label. PaymentRunExportService persists
 * this to Nextcloud Files under /Shillinq/PaymentRuns/<administrationId>/ and
 * records the file reference back onto the PaymentRun. Mirrors the Reporting
 * GeneratedFile value object exactly — the same store-bytes-and-tag pattern.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

/**
 * Immutable rendered payment-run-export payload.
 */
final class RenderedFile {
	/**
	 * Construct the rendered-file value object.
	 *
	 * @param string $fileName Suggested file name including extension.
	 * @param string $mimeType MIME type (e.g. text/xml, text/csv).
	 * @param string $format Short format label: sepa-pain001 | csv.
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
