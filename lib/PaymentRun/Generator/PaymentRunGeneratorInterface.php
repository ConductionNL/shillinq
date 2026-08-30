<?php

/**
 * Payment-run generator interface
 *
 * One implementation per export format. A generator renders an approved
 * PaymentRun into a bank file (SEPA pain.001.001.03 XML or the CSV fallback)
 * and returns a RenderedFile. PaymentRunExportService discovers the generators
 * by glob (mirroring the Reporting ReportGenerationService discovery), runs each
 * supported one, persists the result to Nextcloud Files with tags and records
 * the XML file reference back onto the PaymentRun.
 *
 * Rendering is byte-native — pain.001 via XMLWriter, CSV via fputcsv — so no new
 * composer dependency is added to shillinq (ADR-031 document-generation
 * exception, exactly like the Reporting data generators).
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun\Generator
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

namespace OCA\Shillinq\PaymentRun\Generator;

use OCA\Shillinq\PaymentRun\RenderedFile;

/**
 * Contract for a single payment-run export-format generator.
 */
interface PaymentRunGeneratorInterface {
	/**
	 * The export format this generator produces: 'sepa-pain001' or 'csv'.
	 *
	 * @return string
	 */
	public static function format(): string;

	/**
	 * Render the bank file for the given (validated, approved) PaymentRun.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array
	 *                                         (runNumber, executionDate,
	 *                                         debtorAccountIban, totalAmount,
	 *                                         currency, paymentLines[], ...).
	 *
	 * @return RenderedFile
	 */
	public function render(array $paymentRun): RenderedFile;
}//end interface
