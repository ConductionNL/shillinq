<?php

/**
 * OSS Consolidated Payment Reconciliation
 *
 * ADR-031 exception-path service that reconciles the consolidated euro payment to
 * the Belastingdienst against an OssReturn (REQ-OSS-008). It matches a bank
 * transaction (amount + Belastingdienst IBAN) to the return's total VAT payable,
 * permits the transition to `paid`, and compares the per-country distribution the
 * Belastingdienst confirms back through the OSS portal against the declared
 * per-country totals so any discrepancy is surfaced. Money comparison is performed
 * in integer cents to avoid float drift.
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
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Matches OSS payments to returns and surfaces per-country distribution discrepancies.
 *
 * Pure logic with no persistence: the caller transitions the OssReturn / OssPayment
 * lifecycle on the returned decisions.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class OssPaymentReconciliation {
	/**
	 * Convert a money amount to integer cents.
	 *
	 * @param mixed $amount Money amount.
	 *
	 * @return int Whole cents.
	 */
	private function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Decide whether a bank transaction matches the OSS return total (REQ-OSS-008).
	 *
	 * Returns true when the transaction amount equals the return's totalVatAmount
	 * (to the cent) and the transaction is addressed to the Belastingdienst IBAN.
	 *
	 * @param array<string,mixed> $ossReturn The submitted/accepted OssReturn.
	 * @param array<string,mixed> $transaction Bank transaction (amount + ibanTo).
	 * @param string $expectedIban The Belastingdienst IBAN to match against.
	 *
	 * @return bool True when the transaction reconciles the return.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function matches(array $ossReturn, array $transaction, string $expectedIban): bool {
		$returnCents = $this->toCents(amount: ($ossReturn['totalVatAmount'] ?? 0));
		$txCents = $this->toCents(amount: ($transaction['amount'] ?? 0));
		if ($returnCents !== $txCents || $returnCents === 0) {
			return false;
		}

		$txIban = (string)($transaction['ibanTo'] ?? ($transaction['counterpartyIban'] ?? ''));
		return $this->normaliseIban(iban: $txIban) === $this->normaliseIban(iban: $expectedIban);
	}//end matches()

	/**
	 * Lifecycle precondition: may an OssReturn / OssPayment be marked paid (REQ-OSS-008)?
	 *
	 * Permits the transition only when a matching bank transaction has been linked
	 * (bankTransactionId present) and the amount equals the return total.
	 *
	 * @param array<string,mixed> $ossPayment The OssPayment object array.
	 * @param array<string,mixed> $ossReturn The OssReturn being settled.
	 *
	 * @return bool True when the paid transition is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function canMarkPaid(array $ossPayment, array $ossReturn): bool {
		if (empty($ossPayment['bankTransactionId']) === true) {
			return false;
		}

		return $this->toCents(amount: ($ossPayment['amount'] ?? 0)) === $this->toCents(amount: ($ossReturn['totalVatAmount'] ?? 0));
	}//end canMarkPaid()

	/**
	 * Compare confirmed per-country distribution to the declared return (REQ-OSS-008).
	 *
	 * Returns 'reconciled' when every confirmed country amount equals the declared
	 * per-line VAT total (to the cent) and the country sets match, otherwise
	 * 'discrepancy' together with the per-country differences (confirmed - declared,
	 * in float money).
	 *
	 * @param array<string,mixed> $ossReturn The OssReturn carrying declared line items.
	 * @param array<string,int|float> $confirmation Belastingdienst per-country confirmation.
	 *
	 * @return array{status: string, differences: array<string,float>}
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function reconcileDistribution(array $ossReturn, array $confirmation): array {
		$declared = [];
		foreach (($ossReturn['lineItems'] ?? []) as $line) {
			$country = (string)($line['countryCode'] ?? '');
			if ($country === '') {
				continue;
			}

			$declared[$country] = (($declared[$country] ?? 0) + $this->toCents(amount: ($line['vatAmount'] ?? 0)));
		}

		$differences = [];
		$countries = array_unique(array_merge(array_keys($declared), array_keys($confirmation)));
		$reconciled = true;
		foreach ($countries as $country) {
			$declaredCents = (int)($declared[(string)$country] ?? 0);
			$confirmedCents = $this->toCents(amount: ($confirmation[(string)$country] ?? 0));
			if ($declaredCents !== $confirmedCents) {
				$reconciled = false;
				$differences[(string)$country] = (float)(($confirmedCents - $declaredCents) / 100);
			}
		}

		$status = 'discrepancy';
		if ($reconciled === true) {
			$status = 'reconciled';
		}

		return [
			'status' => $status,
			'differences' => $differences,
		];

	}//end reconcileDistribution()

	/**
	 * Normalise an IBAN for comparison (strip spaces, upper-case).
	 *
	 * @param string $iban Raw IBAN.
	 *
	 * @return string Normalised IBAN.
	 */
	private function normaliseIban(string $iban): string {
		return strtoupper(str_replace(' ', '', trim($iban)));
	}//end normaliseIban()
}//end class
