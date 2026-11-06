<?php

/**
 * Object Request Bank Matcher
 *
 * Finds the pending object payment requests whose `paymentReference` a bank
 * line quotes as a whole token, in its remittance text or its end-to-end
 * reference, compared without case, and decides what the match is
 * (REQ-ORS-003, design D3): exactly one request and the line's amount equal
 * to the amount still open is confirmed at once; another amount, or more than
 * one request, is a candidate for the bookkeeper; no reference is nothing.
 *
 * An ADR-031 exception of the same kind as ExactMatchBooker: a per-target
 * comparison no declared predicate can express. It writes nothing.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Bank
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Bank;

use OCA\Shillinq\Service\PaymentRequestFinder;
use OCA\Shillinq\Service\PaymentSettlementService;

/**
 * Bank line to object payment request.
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
 */
class ObjectRequestBankMatcher {
	/**
	 * The line settles the one request it quotes.
	 *
	 * @var string
	 */
	public const DECISION_CONFIRM = 'confirm';

	/**
	 * The line quotes a request but the bookkeeper decides.
	 *
	 * @var string
	 */
	public const DECISION_CANDIDATE = 'candidate';

	/**
	 * The line quotes no request.
	 *
	 * @var string
	 */
	public const DECISION_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param PaymentRequestFinder $finder Reads the pending requests with a reference.
	 * @param PaymentSettlementService $settlements Reports the amount still open.
	 */
	public function __construct(
		private readonly PaymentRequestFinder $finder,
		private readonly PaymentSettlementService $settlements,
	) {
	}//end __construct()

	/**
	 * Decide what a bank line is to the object requests.
	 *
	 * @param array<string, mixed> $line The bank statement line.
	 *
	 * @return array{decision: string, requests: array<int, array<string, mixed>>} The decision and the requests it names.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function match(array $line): array {
		$amount = round((float)($line['amount'] ?? 0), 2);
		$text = mb_strtolower(
			implode(' ', [(string)($line['remittanceInfo'] ?? ''), (string)($line['endToEndRef'] ?? ''), (string)($line['reference'] ?? '')])
		);
		if ($amount <= 0.0 || trim($text) === '') {
			return ['decision' => self::DECISION_NONE, 'requests' => []];
		}

		$quoted = array_values(
			array_filter(
				$this->finder->pendingWithReference(),
				fn (array $request): bool => $this->quotes(text: $text, reference: (string)$request['paymentReference'])
			)
		);

		if ($quoted === []) {
			return ['decision' => self::DECISION_NONE, 'requests' => []];
		}

		if (count($quoted) === 1 && abs($this->openAmount(request: $quoted[0]) - $amount) < 0.005) {
			return ['decision' => self::DECISION_CONFIRM, 'requests' => $quoted];
		}

		return ['decision' => self::DECISION_CANDIDATE, 'requests' => $quoted];
	}//end match()

	/**
	 * Whether the text holds the reference as a whole token.
	 *
	 * @param string $text The lower-cased line text.
	 * @param string $reference The request's reference.
	 *
	 * @return bool True when it is quoted.
	 */
	private function quotes(string $text, string $reference): bool {
		$needle = mb_strtolower(trim($reference));
		if ($needle === '') {
			return false;
		}

		return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $text) === 1;
	}//end quotes()

	/**
	 * The amount still open on a request.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return float The open amount.
	 */
	private function openAmount(array $request): float {
		$report = $this->settlements->report(request: $request);
		$due = (float)($report['due'] ?? ($request['amount'] ?? 0));

		return round(max(0.0, $due - (float)($report['settled'] ?? 0)), 2);
	}//end openAmount()
}//end class
