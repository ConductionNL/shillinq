<?php

/**
 * Statement entry matcher
 *
 * Claims parsed CAMT.053 statement entries for PaymentRun lines, each entry at
 * most once: by the line's deterministic EndToEndId, or — for a line still
 * unmatched — by (creditorIban, amount) within half a cent.
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
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

/**
 * The statement entries of one reconciliation, and which are already claimed.
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */
final class StatementEntryMatcher {

	/**
	 * Cent tolerance for amount-fallback equality (rounding slack).
	 *
	 * @var float
	 */
	private const AMOUNT_EPSILON = 0.005;

	/**
	 * Entry index => true once an entry has matched a line.
	 *
	 * @var array<int|string, bool>
	 */
	private array $claimed = [];

	/**
	 * Hold the parsed statement entries.
	 *
	 * @param array<int, array<string, mixed>> $entries The parsed statement entries.
	 */
	public function __construct(
		private readonly array $entries,
	) {
	}//end __construct()

	/**
	 * Claim the first unclaimed entry carrying this (non-empty) EndToEndId.
	 *
	 * @param string $endToEnd The line's EndToEndId.
	 *
	 * @return bool True when an entry was claimed.
	 *
	 * @spec openspec/specs/payment-run-sepa-export/spec.md
	 */
	public function claimByEndToEnd(string $endToEnd): bool {
		foreach ($this->entries as $entryIndex => $entry) {
			if (isset($this->claimed[$entryIndex]) === false && $entry['endToEndId'] !== '' && $entry['endToEndId'] === $endToEnd) {
				$this->claimed[$entryIndex] = true;
				return true;
			}
		}

		return false;
	}//end claimByEndToEnd()

	/**
	 * Claim the first unclaimed entry paying the line's creditor IBAN the
	 * line's amount.
	 *
	 * @param array<string, mixed> $line The run payment line.
	 *
	 * @return bool True when an entry was claimed.
	 *
	 * @spec openspec/specs/payment-run-sepa-export/spec.md
	 */
	public function claimByCreditorAmount(array $line): bool {
		foreach ($this->entries as $entryIndex => $entry) {
			if (isset($this->claimed[$entryIndex]) === false && $this->paysLine(entry: $entry, line: $line) === true) {
				$this->claimed[$entryIndex] = true;
				return true;
			}
		}

		return false;
	}//end claimByCreditorAmount()

	/**
	 * Whether an entry pays the line: same (non-empty) creditor IBAN, case
	 * and surrounding space ignored, and an amount within half a cent of the
	 * line amount rounded to cents.
	 *
	 * @param array<string, mixed> $entry The statement entry.
	 * @param array<string, mixed> $line  The run payment line.
	 *
	 * @return bool True when the entry pays the line.
	 */
	private function paysLine(array $entry, array $line): bool {
		$lineAmount = round((float)($line['amount'] ?? 0), 2);
		$lineIban = strtoupper(trim((string)($line['creditorIban'] ?? '')));
		$entryIban = strtoupper(trim($entry['creditorIban']));

		return $lineIban !== '' && $entryIban === $lineIban
			&& abs($entry['amount'] - $lineAmount) <= self::AMOUNT_EPSILON;
	}//end paysLine()
}//end class
