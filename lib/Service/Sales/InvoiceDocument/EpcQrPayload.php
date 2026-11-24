<?php

/**
 * EPC QR Payload
 *
 * The payload of the European Payments Council "SEPA credit transfer" QR code
 * (EPC069-12, version 002) for an invoice: scanning it in a banking app fills
 * in the transfer to the administration's IBAN for the open amount with the
 * invoice number as the reference (sales-invoice-document REQ-SID-005,
 * design D5). Only a EUR invoice with an IBAN and an open amount gets one.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales\InvoiceDocument;

/**
 * Builds the EPC069-12 payload.
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.4
 */
class EpcQrPayload {

	/**
	 * The largest amount the standard allows.
	 */
	public const MAX_AMOUNT = 999999999.99;

	/**
	 * Field lengths of the standard.
	 */
	private const MAX_NAME = 70;

	/**
	 * Longest unstructured remittance the standard allows.
	 */
	private const MAX_REMITTANCE = 140;

	/**
	 * The payload for a transfer, or null when the invoice cannot carry one.
	 *
	 * Fields, one per line in this order: service tag `BCD`, version `002`,
	 * character set `1` (UTF-8), identification `SCT`, BIC (may be empty),
	 * beneficiary name, IBAN, `EUR` and the amount with two decimals, purpose
	 * (empty), structured reference (empty), unstructured remittance.
	 *
	 * @param array<string, mixed> $creditor  The creditor: `legalName`, `iban` and optionally `bic`.
	 * @param string               $currency  The invoice currency; only EUR qualifies.
	 * @param float                $amount    The open amount.
	 * @param string               $reference The invoice number.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.4
	 */
	public function forTransfer(array $creditor, string $currency, float $amount, string $reference): ?string {
		$iban = strtoupper(str_replace(' ', '', (string)($creditor['iban'] ?? '')));
		$bic = (string)($creditor['bic'] ?? '');
		$name = self::clip(value: (string)($creditor['legalName'] ?? ''), length: self::MAX_NAME);
		$cents = (int)round($amount * 100);
		$qualifies = (strtoupper(trim($currency)) === 'EUR' && preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) === 1
			&& $name !== '' && $cents > 0 && $amount <= self::MAX_AMOUNT);
		if ($qualifies === false) {
			return null;
		}

		$fields = [
			'BCD',
			'002',
			'1',
			'SCT',
			strtoupper(str_replace(' ', '', $bic)),
			$name,
			$iban,
			'EUR' . number_format($cents / 100, 2, '.', ''),
			'',
			'',
			self::clip(value: $reference, length: self::MAX_REMITTANCE),
		];

		return implode("\n", $fields);
	}//end forTransfer()

	/**
	 * A value on one line, trimmed and cut to a length in characters.
	 *
	 * @param string $value  The value.
	 * @param int    $length The most characters allowed.
	 *
	 * @return string
	 */
	private static function clip(string $value, int $length): string {
		return mb_substr(trim(str_replace(["\r", "\n"], ' ', $value)), 0, $length);
	}//end clip()
}//end class
