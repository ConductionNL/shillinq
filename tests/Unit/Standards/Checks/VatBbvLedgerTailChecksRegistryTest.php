<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\VatBbvLedgerTailChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the VatBbvLedgerTailChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\VatBbvLedgerTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class VatBbvLedgerTailChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'ARInvoice' => [
			'vatdir-art141-triangulation-simplification' => 'TT',
			'vatdir-art197-reverse-charge-triangulation' => 'TT',
			'vatdir-art282-smes-exemption' => 'TT',
		],
		'APTransaction' => [
			'vatdir-art173-pro-rata-deduction' => 'TT',
			'vatdir-art184-adjustment-deductions' => 'TT',
		],
		'GLTransaction' => [
			'fr-fec-format' => 'TT',
			'fr-nf525-secured-archive' => 'TT',
			'pt-certified-invoicing-software' => 'TT',
		],
		'BbvStatement' => [
			'bbv-art7-begroting-structure' => 'TTT',
			'bbv-art8-programmaplan' => 'TTT',
			'bbv-art9-mandatory-paragraphs' => 'FTT',
			'bbv-art24-jaarstukken' => 'TTF',
			'bbv-art59-activeren' => 'FTT',
			'bbv-art62-verkrijgingsprijs' => 'FTT',
			'bbv-taakvelden-overview' => 'FTT',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (VatBbvLedgerTailChecks::checks() as $type => $rules) {
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
		$seed = [VatBbvLedgerTailChecks::seedSpec()];
		$seed[] = VatBbvLedgerTailChecks::seedObjects();
		$this->assertSame('2784faaaadcd43e876efc1257f556247', md5(serialize($seed)));

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
		$defaults = (VatBbvLedgerTailChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
		}

		foreach ((VatBbvLedgerTailChecks::seedObjects()[$type] ?? []) as $object) {
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
