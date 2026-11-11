<?php

/**
 * GL Transaction Lines
 *
 * The GLLine children of a GL transaction as the rule checks read them, and
 * the double-entry balance over them. The AML / banking checks and the
 * ledger-integrity checks both judge a posting by these lines; this is the one
 * reading both share.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Reads the lines of a GL transaction and judges their balance.
 */
final class GlTransactionLines {
	/**
	 * The GLLine children of a transaction (injected under `lines`), normalised to a
	 * list of arrays.
	 *
	 * @param array<string, mixed> $transaction The GL transaction.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function of(array $transaction): array {
		$lines = ($transaction['lines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		return array_values(array_filter($lines, 'is_array'));
	}//end of()

	/**
	 * Double-entry balance: Σ debit-side amounts = Σ credit-side amounts (to the
	 * cent). Fail-closed on no lines or an unsided line.
	 *
	 * @param array<string, mixed> $transaction The GL transaction, with `lines`.
	 *
	 * @return bool
	 */
	public static function balanced(array $transaction): bool {
		$lines = self::of(transaction: $transaction);
		if ($lines === []) {
			return false;
		}

		$net = 0;
		foreach ($lines as $line) {
			$signed = self::signedCents(line: $line);
			if ($signed === null) {
				return false;
			}

			$net += $signed;
		}

		return $net === 0;
	}//end balanced()

	/**
	 * A line's amount in cents, positive on the debit side and negative on the
	 * credit side; null for a line with neither side.
	 *
	 * @param array<string, mixed> $line The GL line.
	 *
	 * @return int|null
	 */
	private static function signedCents(array $line): ?int {
		$cents = (int)round(((float)($line['amount'] ?? 0)) * 100);

		return match ((string)($line['side'] ?? '')) {
			'debit' => $cents,
			'credit' => -$cents,
			default => null,
		};
	}//end signedCents()
}//end class
