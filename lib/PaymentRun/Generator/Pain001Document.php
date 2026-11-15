<?php

/**
 * The pain.001 document one SepaPain001Generator render writes.
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

use XMLWriter;

/**
 * The open writer, the run number (end-to-end id stem) and the currency
 * shared by the steps of one render. Built and used only by
 * SepaPain001Generator.
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */
final class Pain001Document {
	/**
	 * Constructor.
	 *
	 * @param XMLWriter $writer    The open XML writer.
	 * @param string    $runNumber The PaymentRun runNumber, or ''.
	 * @param string    $currency  ISO 4217 currency code.
	 */
	public function __construct(
		public readonly XMLWriter $writer,
		public readonly string $runNumber,
		public readonly string $currency,
	) {

	}//end __construct()
}//end class
