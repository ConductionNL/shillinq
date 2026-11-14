<?php

/**
 * The EPC069-12 SEPA credit transfer QR payload.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-carries-a-scan-to-pay-code-req-sid-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\InvoiceDocument;

/**
 * Builds the text a banking app reads from a scan-to-pay code: service tag
 * BCD, version 002, character set 1 (UTF-8), identification SCT, BIC,
 * beneficiary name, IBAN, EUR amount, purpose, structured reference and
 * unstructured remittance, one per line. Only the open amount and the
 * invoice number go in, so the code cannot ask for more than is owed.
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-carries-a-scan-to-pay-code-req-sid-005
 */
final class EpcQrPayload {

	/**
	 * The largest amount the format allows.
	 *
	 * @var float
	 */
	private const MAX_AMOUNT = 999999999.99;

	/**
	 * The payload, or null when no valid transfer can be described: a
	 * currency other than EUR, no IBAN, no name, or an amount outside
	 * 0.01 to 999999999.99.
	 *
	 * @param string $name      Beneficiary name, cut to 70 characters.
	 * @param string $iban      Beneficiary IBAN; spaces are removed.
	 * @param float  $amount    The open amount.
	 * @param string $currency  The invoice currency.
	 * @param string $reference The invoice number, cut to 140 characters.
	 * @param string $bic       Beneficiary BIC, optional within the EEA.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.4
	 */
	public static function build(
		string $name,
		string $iban,
		float $amount,
		string $currency,
		string $reference,
		string $bic = '',
	): ?string {
		$iban    = strtoupper((string)preg_replace('/\s+/', '', $iban));
		$name    = trim($name);
		$rounded = round($amount, 2);
		if (strtoupper(trim($currency)) !== 'EUR'
			|| preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1
			|| $name === ''
			|| $rounded < 0.01
			|| $rounded > self::MAX_AMOUNT
		) {
			return null;
		}

		$lines = [
			'BCD',
			'002',
			'1',
			'SCT',
			strtoupper(trim($bic)),
			mb_substr($name, 0, 70),
			$iban,
			'EUR'.number_format($rounded, 2, '.', ''),
			'',
			'',
			mb_substr(trim($reference), 0, 140),
		];

		return implode("\n", $lines);

	}//end build()
}//end class
