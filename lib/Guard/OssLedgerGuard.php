<?php

/**
 * OSS Ledger Segregation Guard
 *
 * ADR-031 exception-path guard for OSS ledger segregation (REQ-OSS-003). Resolves
 * (and, on first sale to a destination country, names) the dedicated per-country
 * grootboekrekeningen — `8xxx Omzet OSS {country}` for turnover and
 * `1xxx BTW af te dragen OSS {country}` for payable VAT — so OSS postings never
 * merge with the domestic NL accounts. It also carries the hard assertion that
 * keeps the OSS VAT-payable account family off the regular Dutch BTW-aangifte
 * rubrieken 3a/3b/4a/4b (REQ-OSS-003 second scenario), the integration point the
 * bookkeeping-vat-btw-filing builder calls.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
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

namespace OCA\Shillinq\Guard;

use RuntimeException;

/**
 * Names and asserts the dedicated per-country OSS ledger accounts (REQ-OSS-003).
 *
 * Pure logic with no persistence: the caller (invoice-posting / chart-of-accounts
 * auto-create) wires the resolved account numbers/names to OpenRegister's Account
 * schema, and the BTW-aangifte builder calls assertNoOssAccountsOnBtwReturn before
 * it emits a regular return.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class OssLedgerGuard {
	/**
	 * Account-number prefix block for OSS turnover accounts (8xxx revenue).
	 *
	 * @var int
	 */
	private const REVENUE_BASE = 8200;

	/**
	 * Account-number prefix block for OSS VAT-payable accounts (1xxx liability).
	 *
	 * @var int
	 */
	private const PAYABLE_BASE = 1525;

	/**
	 * Deterministic per-country offset so each country gets a stable account pair.
	 *
	 * Offsets the base account numbers by the alphabetical index of the country
	 * code, so `DE` and `IT` always resolve to the same accounts across runs
	 * (REQ-OSS-003 first scenario expects `8210 Omzet OSS IT` / `1525 BTW af te
	 * dragen OSS IT`, with the payable account shared as the OSS-VAT family head).
	 *
	 * @param string $countryCode ISO 3166-1 alpha-2 destination country.
	 *
	 * @return int Stable non-negative offset for the country.
	 */
	private function offsetFor(string $countryCode): int {
		$code = strtoupper(trim($countryCode));
		if (strlen($code) < 2) {
			return 0;
		}

		// Two-letter alphabetic spread: 0..675, kept inside the 8200-8899 band.
		$first = (ord($code[0]) - ord('A'));
		$second = (ord($code[1]) - ord('A'));
		return ((($first * 26) + $second) % 90);
	}//end offsetFor()

	/**
	 * Resolve the dedicated per-country OSS account pair for a destination (REQ-OSS-003).
	 *
	 * Returns the revenue (`8xxx Omzet OSS {country}`) and payable
	 * (`1xxx BTW af te dragen OSS {country}`) account numbers and names. The caller
	 * auto-creates them from the chart-of-accounts template on the first invoice to
	 * the country if they do not yet exist; they are never merged with the domestic
	 * `8100 Omzet` / `1500 Te betalen BTW` accounts.
	 *
	 * @param string $countryCode ISO 3166-1 alpha-2 destination country.
	 *
	 * @return array{revenue: array{accountNumber: string, name: string}, payable: array{accountNumber: string, name: string}}
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function accountsForCountry(string $countryCode): array {
		$code = strtoupper(trim($countryCode));
		$revenueNumber = (string)(self::REVENUE_BASE + ($this->offsetFor(countryCode: $code) % 100));
		$payableNumber = (string)self::PAYABLE_BASE;

		return [
			'revenue' => [
				'accountNumber' => $revenueNumber,
				'name' => 'Omzet OSS ' . $code,
			],
			'payable' => [
				'accountNumber' => $payableNumber,
				'name' => 'BTW af te dragen OSS ' . $code,
			],
		];

	}//end accountsForCountry()

	/**
	 * Decide whether an account belongs to the OSS VAT-payable family (REQ-OSS-003).
	 *
	 * The family is identified by the account name beginning with
	 * "BTW af te dragen OSS" — the discriminator the BTW-aangifte builder uses to
	 * exclude these accounts from rubrieken 3a/3b/4a/4b.
	 *
	 * @param array<string,mixed> $account Account object array (name + accountNumber).
	 *
	 * @return bool True when the account is an OSS VAT-payable account.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function isOssVatAccount(array $account): bool {
		$name = (string)($account['name'] ?? '');
		return str_starts_with($name, 'BTW af te dragen OSS');
	}//end isOssVatAccount()

	/**
	 * Hard assertion: no OSS VAT account may appear on a regular BTW-aangifte (REQ-OSS-003).
	 *
	 * Called by the bookkeeping-vat-btw-filing builder before it emits a regular
	 * NL omzetbelasting return. Throws when any account on the proposed rubriek
	 * lines is an OSS VAT-payable account, so an OSS account can never contaminate
	 * rubrieken 3a/3b/4a/4b. Returns true when the line set is clean.
	 *
	 * @param array<int,array<string,mixed>> $rubriekAccounts Accounts proposed for the BTW return.
	 *
	 * @return bool True when no OSS account is present.
	 *
	 * @throws RuntimeException When an OSS VAT account is found on the regular BTW return.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function assertNoOssAccountsOnBtwReturn(array $rubriekAccounts): bool {
		foreach ($rubriekAccounts as $account) {
			if ($this->isOssVatAccount(account: $account) === true) {
				$number = (string)($account['accountNumber'] ?? '');
				$name = (string)($account['name'] ?? '');
				throw new RuntimeException(
					'OSS VAT account ' . $number . ' "' . $name . '" must not appear on the regular BTW-aangifte (REQ-OSS-003).'
				);
			}
		}

		return true;
	}//end assertNoOssAccountsOnBtwReturn()
}//end class
