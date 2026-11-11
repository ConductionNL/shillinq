<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\LedgerIntegrityChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the LedgerIntegrityChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\LedgerIntegrityChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class LedgerIntegrityChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'GLTransaction' => [
			'gl-double-entry-balanced' => 'FF',
			'gl-trial-balance-reconciles' => 'FF',
			'gl-no-deletion-only-reversal' => 'TT',
			'gl-period-locking' => 'FT',
			'gl-change-audit-trail' => 'FT',
			'de-hgb239-2-timely-orderly' => 'FF',
			'de-ao146-1-timely-complete' => 'FF',
			'de-gobd-vollstaendigkeit' => 'FF',
			'de-gobd-zeitgerechte-buchungen' => 'FF',
			'de-hgb239-3-no-undetectable-change' => 'FT',
			'de-ao146-4-no-unrecorded-change' => 'FT',
			'de-gobd-unveraenderbarkeit' => 'FT',
			'de-gobd-belegfunktion' => 'FF',
			'de-gobd-datenzugriff-z3' => 'FT',
			'fr-fec-mandatory-export' => 'FT',
			'fr-fec-validated-entries' => 'FF',
			'fr-fec-18-fields' => 'FF',
			'fr-pcg-chronological-irreversible' => 'FF',
			'fr-nf525-inalterability' => 'TT',
			'fr-comm-l123-22-no-blank-no-gap' => 'FF',
			'be-wer-no-blank-no-alteration' => 'FF',
			'es-ccom-29-no-blank-no-gap' => 'FF',
			'it-cc-2219-orderly' => 'FF',
			'it-cad-digital-conservazione' => 'FT',
			'eu-vat-2006112-art233-integrity' => 'FT',
			'eu-vat-2006112-art244-storage' => 'FF',
			'eu-vat-2006112-art248a-access' => 'FT',
			'eu-saft-oecd-audit-file' => 'FT',
			'pt-saft-pt-mandatory' => 'FT',
			'pl-jpk-vat-mandatory' => 'FT',
			'ro-d406-saft-mandatory' => 'FT',
			'us-irs-electronic-records' => 'FT',
			'nl-awr52-general-7y' => 'FF',
			'nl-awr52-realestate-10y' => 'FF',
			'nl-bw2-10-retain-7y' => 'FF',
			'de-ao147-3-books-8y' => 'FF',
			'de-ao147-belege-8y-beg4' => 'FF',
			'de-ao147-commercial-letters-6y' => 'FF',
			'de-hgb257-4-books-8y' => 'FF',
			'de-hgb257-letters-6y' => 'FF',
			'de-ustg14b-invoice-8y' => 'FF',
			'fr-comm-l123-22-10y' => 'FF',
			'fr-lpf-l102b-6y' => 'FF',
			'be-wvv-3-65-10y' => 'FF',
			'be-vat-invoice-10y' => 'FF',
			'es-ccom-30-6y' => 'FF',
			'es-lgt-66-tax-4y' => 'FF',
			'it-cc-2220-10y' => 'FF',
			'it-dpr600-tax-records' => 'FF',
			'us-irs-general-3y' => 'FF',
			'us-irs-substantial-omission-6y' => 'FF',
			'us-irs-worthless-security-7y' => 'FF',
			'us-irs-employment-tax-4y' => 'FF',
			'us-sox-103-auditor-workpapers-7y' => 'FF',
			'us-sox-802-records-5y' => 'FF',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (LedgerIntegrityChecks::checks() as $type => $rules) {
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
		$seed = [LedgerIntegrityChecks::seedSpec()];
		$this->assertSame('845de9c3c1dfb25034d469cea186072a', md5(serialize($seed)));

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
		$defaults = (LedgerIntegrityChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
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
