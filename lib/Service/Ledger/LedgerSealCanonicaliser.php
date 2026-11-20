<?php

/**
 * Ledger Seal Canonicaliser
 *
 * The content a posted GLTransaction is sealed over, and the seal itself
 * (ledger-sealed-entries design D1). The content is the posted record: the
 * header fields and the lines sorted by line number, each amount in integer
 * cents, keys sorted at every level, no whitespace. `state` is left out, so a
 * reversal, which changes only the reversed transaction's state, keeps its
 * seal (REQ-LSE-003). OpenRegister's own metadata (`@self`, timestamps) is
 * left out too: it changes on reads and migrations nobody tampered with.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Ledger
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Ledger;

/**
 * Canonical posted content and the chained seal over it.
 *
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md
 */
final class LedgerSealCanonicaliser {

	/**
	 * The previous seal of an administration's first sealed transaction.
	 *
	 * @var string
	 */
	public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * The header fields that are sealed (design D1).
	 *
	 * @var list<string>
	 */
	private const HEADER_FIELDS = [
		'administrationId',
		'transactionNumber',
		'postingDate',
		'periodId',
		'currency',
		'description',
		'sourceReference',
		'journalEntryId',
		'reversesTransactionId',
	];

	/**
	 * The line fields that are sealed besides the amount (design D1).
	 *
	 * @var list<string>
	 */
	private const LINE_FIELDS = [
		'accountNumber',
		'side',
		'currency',
		'costCenterCode',
		'costCarrierCode',
		'projectCode',
		'dimensions',
	];

	/**
	 * The canonical JSON of a posted transaction and its lines.
	 *
	 * @param array<string,mixed>       $transaction The GLTransaction.
	 * @param list<array<string,mixed>> $lines       Its GLLine rows, in any order.
	 *
	 * @return string The canonical content.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-reversal-does-not-break-the-chain-req-lse-003
	 */
	public function canonical(array $transaction, array $lines): string {
		$header = [];
		foreach (self::HEADER_FIELDS as $field) {
			$header[$field] = ($transaction[$field] ?? null);
		}

		$sorted = array_values($lines);
		usort(
			$sorted,
			static fn (array $a, array $b): int => [(int)($a['lineNumber'] ?? 0), self::text(value: ($a['accountNumber'] ?? ''))]
				<=> [(int)($b['lineNumber'] ?? 0), self::text(value: ($b['accountNumber'] ?? ''))]
		);

		$header['lines'] = array_map(fn (array $line): array => $this->line(line: $line), $sorted);

		return (string)json_encode(self::sortKeys(value: $header), (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
	}//end canonical()

	/**
	 * The seal: SHA-256 over the previous seal and the canonical content.
	 *
	 * @param string $previousSealHash The previous seal, or GENESIS.
	 * @param string $canonical        The canonical content.
	 *
	 * @return string The seal, 64 lowercase hex characters.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-posted-transaction-is-sealed-into-its-administrations-chain-req-lse-001
	 */
	public function seal(string $previousSealHash, string $canonical): string {
		return hash('sha256', $previousSealHash . $canonical);
	}//end seal()

	/**
	 * An amount in integer cents, from a number or a decimal string.
	 *
	 * A decimal string is read digit by digit, so `"363.10"` is 36310 on
	 * every PHP version and never passes through a float.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return integer The amount in cents.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-posted-transaction-is-sealed-into-its-administrations-chain-req-lse-001
	 */
	public static function cents(mixed $amount): int {
		$text = trim(self::text(value: $amount));
		if (is_float($amount) === true) {
			$text = number_format($amount, 2, '.', '');
		}

		if (preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $text, $parts) !== 1 || ($parts[2] === '' && ($parts[3] ?? '') === '')) {
			return 0;
		}

		$fraction = substr(str_pad(($parts[3] ?? ''), 2, '0'), 0, 2);
		$cents = (((int)$parts[2]) * 100) + (int)$fraction;

		if ($parts[1] === '-') {
			return -$cents;
		}

		return $cents;
	}//end cents()

	/**
	 * One line's sealed fields.
	 *
	 * @param array<string,mixed> $line The GLLine.
	 *
	 * @return array<string,mixed> The sealed fields.
	 */
	private function line(array $line): array {
		$sealed = ['amount' => self::cents(amount: ($line['amount'] ?? 0))];
		foreach (self::LINE_FIELDS as $field) {
			$sealed[$field] = ($line[$field] ?? null);
		}

		return $sealed;
	}//end line()

	/**
	 * A value with the keys of every associative array sorted.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, keys sorted.
	 */
	private static function sortKeys(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$sorted = array_map(static fn (mixed $item): mixed => self::sortKeys(value: $item), $value);
		if (array_is_list($sorted) === false) {
			ksort($sorted, SORT_STRING);
		}

		return $sorted;
	}//end sortKeys()

	/**
	 * A scalar as text; anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '';
	}//end text()
}//end class
