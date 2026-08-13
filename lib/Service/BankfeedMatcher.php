<?php

/**
 * Shillinq Bankfeed Matcher
 *
 * Single-method fuzzy-matcher that pairs a PSD2 bank-feed transaction with the
 * most likely candidate AR invoice / projection by combining amount equality,
 * reference-string Levenshtein similarity, and date proximity into a 0-1
 * confidence score.
 *
 * ADR-031 EXCEPTION: this is the only PHP service authored by the
 * zzp-cashflow-13wk change. It exists as a temporary bridge until OR's
 * bank-matching aggregation extension stabilises (the schema-side matching
 * primitive is on the OR roadmap; this service is the bridge in the
 * meantime). The method is intentionally tight (~60 LOC of business logic)
 * and contains no other concerns.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-23
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Single-method fuzzy-matcher for PSD2 bank-feed transactions vs AR projections.
 *
 * @psalm-api
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-23
 */
class BankfeedMatcher {
	/**
	 * Match a single bank-feed transaction against candidate AR projections.
	 *
	 * The score combines three signals, each in [0,1]:
	 *  - amountScore: 1.0 when amounts match to the cent, falls off linearly to 0 at 5% delta.
	 *  - referenceScore: Levenshtein similarity between the transaction reference
	 *    and the candidate's arInvoiceId / klantId.
	 *  - dateScore: 1.0 when transaction date equals verwachtOntvangstDatum,
	 *    falls off linearly to 0 at +/- 14 days.
	 *
	 * Confidence = weighted average (amount 0.5, reference 0.3, date 0.2).
	 *
	 * @param array<string,mixed> $transaction Bank-feed transaction. Expected keys:
	 *                                         amount (float), reference (string),
	 *                                         valueDate (Y-m-d).
	 * @param list<array<string,mixed>> $candidateInvoices Candidate AR projections.
	 *
	 * @return array{arInvoiceId:?string,confidence:float} Best match + confidence, or null arInvoiceId if no match >= 0.5.
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-23
	 */
	public function matchTransaction(array $transaction, array $candidateInvoices): array {
		$txAmount = (float)($transaction['amount'] ?? 0.0);
		$txReference = (string)($transaction['reference'] ?? '');
		$txDate = (string)($transaction['valueDate'] ?? '');

		$bestScore = 0.0;
		$bestInvoiceId = null;

		foreach ($candidateInvoices as $candidate) {
			$candAmount = (float)($candidate['outstandingAmount'] ?? 0.0);
			$candId = (string)($candidate['arInvoiceId'] ?? '');
			$candCustomer = (string)($candidate['customerId'] ?? '');
			$candDate = (string)($candidate['expectedReceiptDate'] ?? '');

			// Amount signal — 1.0 at zero delta, 0 at >= 5% delta.
			$amountScore = 0.0;
			if ($candAmount > 0.0) {
				$deltaPct = (abs($txAmount - $candAmount) / $candAmount);
				if ($deltaPct < 0.05) {
					$amountScore = (1.0 - ($deltaPct / 0.05));
				}
			}

			// Reference signal — best similarity between tx reference and id or klant.
			$refScore1 = $this->similarity(needle: $txReference, haystack: $candId);
			$refScore2 = $this->similarity(needle: $txReference, haystack: $candCustomer);
			$referenceScore = max($refScore1, $refScore2);

			// Date signal — 1.0 at equal, 0 at 14-day delta.
			$dateScore = 0.0;
			if ($txDate !== '' && $candDate !== '') {
				$deltaDays = (int)abs((strtotime($txDate) - strtotime($candDate)) / 86400);
				if ($deltaDays < 14) {
					$dateScore = (1.0 - ($deltaDays / 14));
				}
			}

			$confidence = (($amountScore * 0.5) + ($referenceScore * 0.3) + ($dateScore * 0.2));

			if ($confidence > $bestScore) {
				$bestScore = $confidence;
				$bestInvoiceId = $candId;
			}
		}//end foreach

		if ($bestScore < 0.5) {
			return [
				'arInvoiceId' => null,
				'confidence' => $bestScore,
			];
		}

		return [
			'arInvoiceId' => $bestInvoiceId,
			'confidence' => $bestScore,
		];

	}//end matchTransaction()

	/**
	 * Normalised Levenshtein similarity in [0,1].
	 *
	 * @param string $needle Reference string (transaction reference).
	 * @param string $haystack Candidate string (invoice ID or customer ID).
	 *
	 * @return float Similarity in [0,1].
	 */
	private function similarity(string $needle, string $haystack): float {
		if ($needle === '' || $haystack === '') {
			return 0.0;
		}

		$needleLower = strtolower($needle);
		$haystackLower = strtolower($haystack);

		if (str_contains($needleLower, $haystackLower) === true
			|| str_contains($haystackLower, $needleLower) === true
		) {
			return 1.0;
		}

		$maxLen = max(strlen($needleLower), strlen($haystackLower));
		if ($maxLen === 0) {
			return 0.0;
		}

		$distance = levenshtein($needleLower, $haystackLower);

		return max(0.0, (1.0 - ($distance / $maxLen)));
	}//end similarity()
}//end class
