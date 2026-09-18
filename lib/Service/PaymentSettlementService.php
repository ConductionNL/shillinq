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

		return [
			'method' => $method,
			'amount' => (float)$amount,
			'reference' => $reference,
			'actor' => $actor,
			'settledAt' => ($settledAt === '' ? gmdate('Y-m-d\TH:i:s\Z') : $settledAt),
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
	 * @return float The total.
	 */
	public function settledAmount(array $request): float {
		$total = 0.0;
		foreach (($request['settlements'] ?? []) as $settlement) {
			if (is_array($settlement) === true && is_numeric($settlement['amount'] ?? null) === true) {
				$total += (float)$settlement['amount'];
			}
		}

		return $total;
	}//end settledAmount()

	/**
	 * The state a human reads, derived from the provider state and the
	 * settlements together.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array{state: string, settled: float, due: float, over: float} The report.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function report(array $request): array {
		$due = (float)($request['amount'] ?? 0);
		$settled = $this->settledAmount($request);
		$providerState = (string)($request['state'] ?? 'pending');

		if (in_array($providerState, self::PROVIDER_PAID_STATES, true) === true) {
			$settled += $due;
		}

		$over = max(0.0, round(($settled - $due), 2));

		if ($over > 0.0) {
			return ['state' => self::REPORTED_OVERPAID, 'settled' => $settled, 'due' => $due, 'over' => $over];
		}

		if ($due > 0.0 && $settled >= $due) {
			return ['state' => self::REPORTED_PAID, 'settled' => $settled, 'due' => $due, 'over' => 0.0];
		}

		if ($settled > 0.0) {
			return ['state' => self::REPORTED_PART_PAID, 'settled' => $settled, 'due' => $due, 'over' => 0.0];
		}

		if (in_array($providerState, self::PROVIDER_DEAD_STATES, true) === true) {
			return ['state' => self::REPORTED_UNPAYABLE, 'settled' => 0.0, 'due' => $due, 'over' => 0.0];
		}

		return ['state' => self::REPORTED_OPEN, 'settled' => 0.0, 'due' => $due, 'over' => 0.0];
	}//end report()
}//end class
