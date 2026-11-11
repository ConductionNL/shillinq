<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\ChartOfAccountsChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the ChartOfAccountsChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\ChartOfAccountsChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class ChartOfAccountsChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'Account' => [
			'rgs-coa-standard-codes' => 'FT',
			'rgs-code-structure' => 'FT',
			'pcg-common-coa' => 'FF',
			'pcg-class-1-capitaux' => 'TT',
			'pcg-class-2-immobilisations' => 'TT',
			'pcg-class-3-stocks' => 'TT',
			'pcg-class-4-tiers' => 'TT',
			'pcg-class-5-financiers' => 'TT',
			'pcg-class-6-charges' => 'TT',
			'pcg-class-7-produits' => 'TT',
			'skr04-class-mapping' => 'FF',
			'be-mar-pcmn-chart' => 'FF',
			'es-pgc-cuadro-cuentas' => 'FF',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (ChartOfAccountsChecks::checks() as $type => $rules) {
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
		$seed = [ChartOfAccountsChecks::seedSpec()];
		$this->assertSame('5e30dd1a107a392e42a7db5ec3f1db21', md5(serialize($seed)));

	}//end testSeedDataIsUnchanged()

	/**
	 * Accounts, the rule they are checked against and the expected verdict.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function accounts(): array {
		return [
			'SKR04 no number'             => ['skr04-class-mapping', ['accountType' => 'assets'], false],
			'SKR04 number not a digit'    => ['skr04-class-mapping', ['accountNumber' => 'A100', 'accountType' => 'assets'], false],
			'SKR04 class 0 equity'        => ['skr04-class-mapping', ['accountNumber' => '0800', 'accountType' => 'equity'], true],
			'SKR04 class 1 equity'        => ['skr04-class-mapping', ['accountNumber' => '1200', 'accountType' => 'equity'], false],
			'SKR04 class 7 expenses'      => ['skr04-class-mapping', ['accountNumber' => '7000', 'accountType' => 'expenses'], true],
			'SKR04 class 9 unlisted'      => ['skr04-class-mapping', ['accountNumber' => '9000', 'accountType' => 'anything'], true],
			'PCMN class 1 liabilities'    => ['be-mar-pcmn-chart', ['accountNumber' => '170', 'accountType' => 'liabilities'], true],
			'PCMN class 6 revenue'        => ['be-mar-pcmn-chart', ['accountNumber' => '600', 'accountType' => 'revenue'], false],
			'PCMN class 0 unlisted'       => ['be-mar-pcmn-chart', ['accountNumber' => '010', 'accountType' => 'x'], true],
			'PCMN no number'              => ['be-mar-pcmn-chart', ['accountNumber' => ' '], false],
			'PGC group 7 revenue'         => ['es-pgc-cuadro-cuentas', ['accountNumber' => '700', 'accountType' => 'revenue'], true],
			'PGC group 2 liabilities'     => ['es-pgc-cuadro-cuentas', ['accountNumber' => '210', 'accountType' => 'liabilities'], false],
			'PGC group 8 unlisted'        => ['es-pgc-cuadro-cuentas', ['accountNumber' => '800', 'accountType' => 'x'], true],
		];

	}//end accounts()

	/**
	 * The class-to-type mappings return the pinned verdict.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $account  The account checked.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('accounts')]
	public function testClassMapping(string $ruleId, array $account, bool $expected): void {
		$check = ChartOfAccountsChecks::checks()['Account'][$ruleId];
		$this->assertSame($expected, $check($account, ['jurisdiction' => 'NL']));

	}//end testClassMapping()

	/**
	 * The objects every check of a type runs on.
	 *
	 * @param string $type The object type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function samples(string $type): array {
		$samples = [[]];
		$defaults = (ChartOfAccountsChecks::seedSpec()[$type] ?? []);
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
