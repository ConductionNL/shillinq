<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\AmlBankingChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the AmlBankingChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\AmlBankingChecks
 * @uses \OCA\Shillinq\Standards\Checks\CheckFields
 * @uses \OCA\Shillinq\Standards\Checks\GlTransactionLines
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class AmlBankingChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'GLTransaction' => [
			'ledger-double-entry-balance' => 'FF',
			'ledger-electronic-payment-records' => 'TT',
			'ledger-no-post-to-closed-period' => 'TT',
		],
		'Account' => [
			'nl-derdengelden-segregation' => 'TT',
			'us-iolta-escrow-segregation' => 'TT',
		],
		'CashTransaction' => [
			'eu-cash-limit-10000' => 'TT',
			'eu-cash-limit-member-state-lower' => 'TT',
			'eu-cash-limit-private-exemption' => 'TT',
			'eu-cash-large-occasional-cdd' => 'TT',
			'de-cash-dealer-cdd-10000' => 'TT',
			'de-precious-metals-cash-2000' => 'TT',
			'fr-cash-limit-1000' => 'TT',
			'it-cash-limit-5000' => 'TT',
			'es-cash-limit-1000' => 'TT',
			'us-form8300-cash' => 'TT',
			'us-form8300-related-transactions' => 'TT',
		],
		'AmlReport' => [
			'us-ctr-10000' => 'TTTTTTT',
			'us-ctr-15-day-efile' => 'FTTTTTT',
			'us-ctr-aggregate-same-day' => 'TTTTTTT',
			'us-sar-30-day-filing' => 'TTTTTTT',
			'us-form8300-15-day-filing' => 'TTTTTTT',
			'us-form8300-payer-statement' => 'TTTTTTT',
			'us-cmir-10000' => 'TTTTTTT',
			'us-fbar-foreign-accounts' => 'TTTTTTT',
			'nl-cash-no-general-limit' => 'TTTTTTT',
			'nl-wwft-report-retention-5yr' => 'FTTTTTT',
		],
		'SanctionsScreening' => [
			'eu-amlr-targeted-sanctions-screening' => 'FT',
			'nl-wwft-sanctions-sanctiewet' => 'FT',
			'us-ofac-sanctions-screening' => 'FT',
		],
		'CddRecord' => [
			'eu-amlr-cdd-occasional-15000' => 'TT',
			'eu-amlr-ubo-25-percent' => 'FT',
			'eu-amlr-record-keeping-5yr' => 'FT',
			'nl-wwft-cdd-retention-5yr' => 'FT',
			'de-gwg-record-retention-5yr' => 'FT',
			'fatf-rec11-record-keeping' => 'FT',
		],
		'FundsTransfer' => [
			'fatf-rec16-travel-rule' => 'FT',
			'eu-tfr-travel-rule' => 'FT',
		],
		'TrustLedger' => [
			'ledger-escrow-no-overdraw' => 'FT',
		],
		'CashCount' => [
			'ledger-cash-count-kasadministratie' => 'FT',
			'ledger-cash-balance-non-negative' => 'FT',
			'ledger-daily-z-report' => 'FT',
		],
		'BankReconciliation' => [
			'ledger-bank-reconciliation' => 'F',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (AmlBankingChecks::checks() as $type => $rules) {
			foreach ($rules as $ruleId => $check) {
				$actual[$type][$ruleId] = self::verdicts($check, self::samples($type));
			}
		}

		$this->assertSame(self::VERDICTS, $actual);

	}//end testRegistryMatchesThePinnedVerdicts()

	/**
	 * The seed data is unchanged.
	 *
	 * @return void
	 */
	public function testSeedDataIsUnchanged(): void {
		$seed = [AmlBankingChecks::seedSpec()];
		$seed[] = AmlBankingChecks::seedObjects();
		$this->assertSame('eeb672f931dc655312488c56ab616a61', md5(serialize($seed)));

	}//end testSeedDataIsUnchanged()

	/**
	 * The objects every check of a type runs on.
	 *
	 * @param string $type The object type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function samples(string $type): array {
		$samples = [[]];
		$defaults = (AmlBankingChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
		}

		foreach ((AmlBankingChecks::seedObjects()[$type] ?? []) as $object) {
			$samples[] = $object;
		}

		return $samples;

	}//end samples()

	/**
	 * One character per sample: T (true), F (false) or E (it threw).
	 *
	 * @param callable                         $check   The check.
	 * @param array<int, array<string, mixed>> $samples The objects.
	 *
	 * @return string
	 */
	private static function verdicts(callable $check, array $samples): string {
		$out = '';
		foreach ($samples as $object) {
			try {
				$out .= $check($object, ['jurisdiction' => 'NL']) === true ? 'T' : 'F';
			} catch (\Throwable $e) {
				$out .= 'E';
			}
		}

		return $out;

	}//end verdicts()
}//end class
