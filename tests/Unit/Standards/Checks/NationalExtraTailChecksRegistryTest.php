<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\NationalExtraTailChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the NationalExtraTailChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\NationalExtraTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class NationalExtraTailChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'FinancialStatement' => [
			'it-cc-art2423-euro-rounding' => 'FF',
			'it-cc-art2424-balance-sheet-schema' => 'FF',
			'it-cc-art2425-income-statement-schema' => 'FF',
			'it-oic12-statement-structure' => 'FF',
			'it-cc-art2426-9-inventories-lower-of' => 'FT',
			'it-oic13-inventories' => 'FT',
			'it-cc-art2426-10-inventory-cost-formula' => 'FT',
			'it-cc-art2435bis-abbreviated' => 'TT',
			'it-oic34-effective-2024' => 'FT',
			'es-pgc-norma10-existencias' => 'FT',
			'es-pgc-norma14-icac-2021' => 'FT',
			'es-pgc-cuentas-anuales-normal' => 'FF',
			'es-pgc-cuentas-anuales-abreviado' => 'TT',
			'es-pgc-pymes' => 'TT',
			'be-car-annual-accounts-models' => 'FT',
			'be-nbb-filing' => 'FF',
			'it-cc-art2220-retention-10y' => 'FT',
			'es-ccom-art30-retention-6y' => 'FT',
			'be-cde-art3-86-retention-7y' => 'FT',
			'pt-retention-10y' => 'FT',
			'no-retention-5y' => 'FT',
			'at-retention-7y' => 'FT',
			'lu-retention-10y' => 'FT',
			'fr-retention-10y' => 'FT',
			'de-retention-10y' => 'FT',
			'nl-retention-7y' => 'FT',
		],
		'EInvoiceMandate' => [
			'it-einvoice-sdi-b2b' => 'FT',
			'es-verifactu-certified-software' => 'FT',
			'es-ticketbai-basque' => 'FT',
			'es-sii-real-time-vat' => 'FT',
			'be-einvoice-b2b-peppol' => 'FT',
			'pt-saft-pt-billing' => 'FT',
			'ro-efactura-b2b' => 'FT',
			'hu-rtir-online-szamla' => 'FT',
			'gr-mydata-ledgers' => 'FT',
			'lt-isaf-vat-invoices' => 'FT',
			'fr-einvoice-b2b-2026' => 'FT',
			'pl-ksef-b2b' => 'FT',
			'de-einvoice-b2b-2025' => 'FT',
		],
		'SaftExportProfile' => [
			'pt-saft-pt-accounting' => 'FT',
			'ro-saft-d406' => 'FT',
			'lt-isaft-accounting' => 'FT',
			'no-saft-financial' => 'FT',
			'at-saft-on-demand' => 'FT',
			'lu-saft-fec' => 'FT',
			'fr-fec-on-demand' => 'FT',
			'pl-jpk-saft' => 'FT',
			'dk-saft-on-demand' => 'FT',
			'bg-saft-2026' => 'FT',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (NationalExtraTailChecks::checks() as $type => $rules) {
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
		$seed = [NationalExtraTailChecks::seedSpec()];
		$seed[] = NationalExtraTailChecks::seedObjects();
		$this->assertSame('757c0db50101e928571c261f7c50b503', md5(serialize($seed)));

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
		$defaults = (NationalExtraTailChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
		}

		foreach ((NationalExtraTailChecks::seedObjects()[$type] ?? []) as $object) {
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
