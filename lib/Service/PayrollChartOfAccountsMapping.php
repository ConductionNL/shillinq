<?php

/**
 * Payroll Chart-of-Accounts Mapping
 *
 * Single source of truth for the GL account numbers used by the payroll
 * Loonjournaalpost (REQ-PAY-012). The mapping wires Shillinq's loonkosten /
 * schulden lines to the RGS 3.5 (Referentie Grootboekschema) account ranges
 * 4001–4099 (loonkosten) and 1610–1699 (schulden werknemers en publieke
 * instanties), so every downstream report (Trial Balance, Iv3, BBV) sees a
 * consistent loonpost-mapping.
 *
 * This class is intentionally const-driven and stateless — auditors and the
 * bookkeeping-chart-of-accounts app reference it as the canonical contract.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Canonical RGS 3.5 account-number mapping for payroll GL postings.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
final class PayrollChartOfAccountsMapping {

	/**
	 * Account: Brutolonen (gross wages, debit).
	 *
	 * @var string
	 */
	public const ACC_BRUTOLONEN = '4001';

	/**
	 * Account: Belastingvrije vergoedingen (tax-free allowances, debit).
	 *
	 * @var string
	 */
	public const ACC_BELASTINGVRIJE_VERGOEDINGEN = '4002';

	/**
	 * Account: Sociale lasten werkgever (employer SV premiums, debit).
	 *
	 * @var string
	 */
	public const ACC_SOCIALE_LASTEN_WG = '4010';

	/**
	 * Account: ZVW-bijdrage werkgever (employer ZVW, debit).
	 *
	 * @var string
	 */
	public const ACC_ZVW_WG = '4012';

	/**
	 * Account: Pensioenpremie werkgever (employer pension, debit).
	 *
	 * @var string
	 */
	public const ACC_PENSIOEN_WG = '4020';

	/**
	 * Account: Te betalen netto loon (net payable, credit).
	 *
	 * @var string
	 */
	public const ACC_TE_BETALEN_NETTO_LOON = '1610';

	/**
	 * Account: Af te dragen loonheffing (wage tax payable, credit).
	 *
	 * @var string
	 */
	public const ACC_AF_TE_DRAGEN_LH = '1620';

	/**
	 * Account: Af te dragen premies SV + ZVW (SV/ZVW payable, credit).
	 *
	 * @var string
	 */
	public const ACC_AF_TE_DRAGEN_PREMIES_SV_ZVW = '1630';

	/**
	 * Account: Af te dragen pensioenpremie (pension payable, credit).
	 *
	 * @var string
	 */
	public const ACC_AF_TE_DRAGEN_PENSIOEN = '1640';

	/**
	 * Account: Te betalen vakantiegeld (holiday allowance accrual, credit).
	 *
	 * @var string
	 */
	public const ACC_TE_BETALEN_VAKANTIEGELD = '1715';

	/**
	 * Return the full mapping as a {key => accountNumber} dictionary.
	 *
	 * Stable keys are used by tests + the chart-of-accounts app integration; new
	 * accounts MUST be added here (never inlined in services / controllers) so
	 * the contract stays single-source-of-truth.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		return [
			'brutolonen' => self::ACC_BRUTOLONEN,
			'belastingvrijeVergoedingen' => self::ACC_BELASTINGVRIJE_VERGOEDINGEN,
			'socialeLastenWg' => self::ACC_SOCIALE_LASTEN_WG,
			'zvwWg' => self::ACC_ZVW_WG,
			'pensioenWg' => self::ACC_PENSIOEN_WG,
			'teBetalenNettoLoon' => self::ACC_TE_BETALEN_NETTO_LOON,
			'afTeDragenLh' => self::ACC_AF_TE_DRAGEN_LH,
			'afTeDragenPremiesSvZvw' => self::ACC_AF_TE_DRAGEN_PREMIES_SV_ZVW,
			'afTeDragenPensioen' => self::ACC_AF_TE_DRAGEN_PENSIOEN,
			'teBetalenVakantiegeld' => self::ACC_TE_BETALEN_VAKANTIEGELD,
		];

	}//end all()

	/**
	 * Convenience: assert an account number is one of the payroll GL accounts.
	 *
	 * @param string $accountNumber Account number to test.
	 *
	 * @return bool True when known.
	 */
	public static function isKnown(string $accountNumber): bool {
		return in_array($accountNumber, self::all(), true);
	}//end isKnown()
}//end class
