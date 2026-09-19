<?php

/**
 * Payment Settlement Service
 *
 * Money does not only arrive through the gateway. A citizen pays cash or pin at
 * the counter, a company transfers the amount to the bank, a council waives the
 * fee. All three have to be recorded against the request, and none of them may
 * overwrite what the provider said.
 *
 * That is the whole design. A settlement is an APPEND. The provider's own state
 * stays exactly where it was, and the state a human reads is DERIVED from the
 * two together. A capture that lands after a counter payment is then still
 * visible, and the request reports an overpayment rather than one record
 * silently replacing the other and the refund never happening.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;

/**
 * Appends settlements and derives the state a human reads.
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
 */
final class PaymentSettlementService {
	/**
	 * The ways money can arrive other than through the gateway.
	 *
	 * @var array<int, string>
	 */
	public const METHODS = ['cash', 'pin', 'bank-transfer', 'waived', 'other'];

	/**
	 * Nothing has arrived.
	 *
	 * @var string
	 */
	public const REPORTED_OPEN = 'open';

	/**
	 * Something arrived, but not the whole amount.
	 *
	 * @var string
	 */
	public const REPORTED_PART_PAID = 'partly-paid';

	/**
	 * The amount arrived.
	 *
	 * @var string
	 */
	public const REPORTED_PAID = 'paid';

	/**
	 * More than the amount arrived, and somebody is owed a refund.
	 *
	 * @var string
	 */
	public const REPORTED_OVERPAID = 'overpaid';

	/**
	 * The request will not be paid: it failed, expired or was voided and nothing
	 * was settled by hand either.
	 *
	 * @var string
	 */
	public const REPORTED_UNPAYABLE = 'unpayable';

	/**
	 * The sum could not be done, so this request owes an unknown amount.
	 *
	 * 🔴 THIS STATE EXISTS SO THAT A MISSING AMOUNT NEVER READS AS ZERO. A row
	 * whose `amount` is absent or unreadable used to fall through every branch
	 * below and report `open` with `due` of 0.00, which renders as a request
	 * with nothing left to pay. Those are opposite facts. A reader that cannot
	 * do the sum says so and the numbers come back null, so a caller that
	 * prints one has to decide what to print rather than being handed a zero.
	 *
	 * @var string
	 */
	public const REPORTED_INDETERMINATE = 'indeterminate';

	/**
	 * Provider states in which the gateway itself has the money.
	 *
	 * @var array<int, string>
	 */
	private const PROVIDER_PAID_STATES = ['captured', 'captured_unapplied'];

	/**
	 * Provider states from which no money will come.
	 *
	 * @var array<int, string>
	 */
	private const PROVIDER_DEAD_STATES = ['failed', 'expired', 'voided'];

	/**
	 * Build one settlement record, refusing anything that could not be checked
	 * later.
	 *
	 * @param array<string, mixed> $input The settlement as the caller gave it: method, amount, reference, reason.
	 * @param string $actor The user recording it.
	 * @param string $settledAt When, as an ISO-8601 instant; now when empty.
	 *
	 * @return array<string, mixed> The settlement record.
	 *
	 * @throws InvalidArgumentException When the method, the amount or the evidence is missing.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function build(array $input, string $actor, string $settledAt = ''): array {
		$method = (string)($input['method'] ?? '');
		if (in_array($method, self::METHODS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown settlement method "%s"; expected one of %s.', $method, implode(', ', self::METHODS))
			);
		}

		$amount = ($input['amount'] ?? null);
		if (is_numeric($amount) === false || (float)$amount < 0.0) {
			throw new InvalidArgumentException('A settlement needs the amount that arrived.');
		}

		if ($actor === '') {
			throw new InvalidArgumentException('A settlement nobody is named for cannot be questioned later; the actor is required.');
		}

		$reference = trim((string)($input['reference'] ?? ''));
		$reason = trim((string)($input['reason'] ?? ''));

		if ($method === 'waived') {
			// A waiver has no money and therefore no bank evidence. What it needs
			// instead is the reason it was granted.
			if ($reason === '') {
				throw new InvalidArgumentException('A waived fee needs the reason it was waived.');
			}
		} elseif ($reference === '') {
			throw new InvalidArgumentException('A settlement needs the reference a later bank reconciliation will match on.');
		}

		$when = $settledAt;
		if ($when === '') {
			$when = gmdate('Y-m-d\TH:i:s\Z');
		}

		return [
			'method' => $method,
			'amount' => (float)$amount,
			'reference' => $reference,
			'actor' => $actor,
			'settledAt' => $when,
			'reason' => $reason,
		];
	}//end build()

	/**
	 * Append a settlement to a request WITHOUT touching its provider state.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param array<string, mixed> $settlement The settlement built above.
	 *
	 * @return array<string, mixed> The request with the settlement appended.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function append(array $request, array $settlement): array {
		$settlements = ($request['settlements'] ?? []);
		if (is_array($settlements) === false) {
			$settlements = [];
		}

		$settlements[] = $settlement;
		$request['settlements'] = $settlements;

		return $request;
	}//end append()

	/**
	 * How much has been settled by hand.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return float The total. Zero when a settlement cannot be read, because
	 *               this signature cannot say "unknown"; report() can, and a
	 *               caller that needs to tell the two apart asks that instead.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function settledAmount(array $request): float {
		$cents = $this->settledCents(request: $request);
		if ($cents === null) {
			return 0.0;
		}

		return (float)($cents / 100);
	}//end settledAmount()

	/**
	 * The amount a request says it owes, or null when that cannot be read.
	 *
	 * A caller asking this is about to fall back on the request's own amount,
	 * and null is its instruction to refuse rather than to fall back on zero.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return float|null The amount, or null when it is missing or not a number.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function amountOf(array $request): ?float {
		$cents = $this->cents(value: ($request['amount'] ?? null));
		if ($cents === null) {
			return null;
		}

		return (float)($cents / 100);
	}//end amountOf()

	/**
	 * One money value as whole cents, or null when it cannot be read.
	 *
	 * 🔑 THE SUM IS DONE IN CENTS, NOT IN FLOATS. `4.35 + 0.10 >= 4.45` is
	 * false in binary floating point, so a request paid in full in two parts
	 * reported `partly-paid` and, in dossiq, blocked the case of a citizen who
	 * had paid everything. Whole cents are exact and the comparison then means
	 * what it says.
	 *
	 * @param mixed $value The amount as it was stored.
	 *
	 * @return int|null The amount in cents, or null when it is missing, not a
	 *                  number, negative or too large to be one.
	 */
	private function cents(mixed $value): ?int {
		if (is_bool($value) === true || is_numeric($value) === false) {
			return null;
		}

		$cents = round(((float)$value * 100));
		if (is_finite($cents) === false || $cents < 0.0 || $cents > 1.0e+15) {
			return null;
		}

		return (int)$cents;
	}//end cents()

	/**
	 * Everything settled by hand, in cents, or null when one entry cannot be read.
	 *
	 * A settlement whose amount is unreadable is NOT skipped. Skipping it makes
	 * the total too low, which turns a paid request into a partly-paid one, and
	 * that is the same wrong number from the other side.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return int|null The total in cents, or null when any entry is unreadable.
	 */
	private function settledCents(array $request): ?int {
		$settlements = ($request['settlements'] ?? []);
		if (is_array($settlements) === false) {
			return null;
		}

		$total = 0;
		foreach ($settlements as $settlement) {
			if (is_array($settlement) === false) {
				return null;
			}

			$cents = $this->cents(value: ($settlement['amount'] ?? null));
			if ($cents === null) {
				return null;
			}

			$total += $cents;
		}

		return $total;
	}//end settledCents()

	/**
	 * The state a human reads, derived from the provider state and the
	 * settlements together.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array{state: string, settled: float|null, due: float|null, over: float|null} The report.
	 *                                                                                     `due` and `over` are null only
	 *                                                                                     when the amount could not be
	 *                                                                                     read, and `settled` is null
	 *                                                                                     only when a settlement could
	 *                                                                                     not be read.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function report(array $request): array {
		$dueCents = $this->cents(value: ($request['amount'] ?? null));
		$settledCents = $this->settledCents(request: $request);
		$providerState = (string)($request['state'] ?? 'pending');

		if ($dueCents === null || $settledCents === null) {
			// The sum cannot be done. A provider state that says no money will
			// ever come is still a fact about this request, and it does not
			// need the amount, so it is reported. Everything else says plainly
			// that the amount is unknown, with no number attached, because the
			// number this branch used to invent was zero.
			if ($settledCents === 0
				&& in_array($providerState, self::PROVIDER_DEAD_STATES, true) === true
			) {
				return ['state' => self::REPORTED_UNPAYABLE, 'settled' => 0.0, 'due' => null, 'over' => null];
			}

			// What IS readable is still reported: a counter payment that was
			// recorded happened, whatever the request says it owes.
			$settledSoFar = null;
			if ($settledCents !== null) {
				$settledSoFar = (float)($settledCents / 100);
			}

			return [
				'state' => self::REPORTED_INDETERMINATE,
				'settled' => $settledSoFar,
				'due' => null,
				'over' => null,
			];
		}

		if (in_array($providerState, self::PROVIDER_PAID_STATES, true) === true) {
			$settledCents += $dueCents;
		}

		$overCents = max(0, ($settledCents - $dueCents));
		$settled = (float)($settledCents / 100);
		$due = (float)($dueCents / 100);

		if ($overCents > 0) {
			return ['state' => self::REPORTED_OVERPAID, 'settled' => $settled, 'due' => $due, 'over' => (float)($overCents / 100)];
		}

		if ($dueCents > 0 && $settledCents >= $dueCents) {
			return ['state' => self::REPORTED_PAID, 'settled' => $settled, 'due' => $due, 'over' => 0.0];
		}

		if ($settledCents > 0) {
			return ['state' => self::REPORTED_PART_PAID, 'settled' => $settled, 'due' => $due, 'over' => 0.0];
		}

		if (in_array($providerState, self::PROVIDER_DEAD_STATES, true) === true) {
			return ['state' => self::REPORTED_UNPAYABLE, 'settled' => 0.0, 'due' => $due, 'over' => 0.0];
		}

		return ['state' => self::REPORTED_OPEN, 'settled' => 0.0, 'due' => $due, 'over' => 0.0];
	}//end report()
}//end class
