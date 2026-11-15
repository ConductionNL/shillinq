<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\InvoiceMentionsTailChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the InvoiceMentionsTailChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\InvoiceMentionsTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class InvoiceMentionsTailChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'ARInvoice' => [
			'vatdir-art226-7a' => 'T',
			'vatdir-art226-10a' => 'T',
			'vatdir-art226-11' => 'T',
			'vatdir-art226-11a' => 'T',
			'vatdir-art226-13' => 'T',
			'vatdir-art226-14' => 'T',
			'vatdir-art226-15' => 'T',
			'vatdir-art226b-simplified' => 'T',
			'vatdir-art230-currency' => 'T',
			'en16931-br-cl-10' => 'T',
			'en16931-br-cl-25' => 'T',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (InvoiceMentionsTailChecks::checks() as $type => $rules) {
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
		$seed = [InvoiceMentionsTailChecks::seedSpec()];
		$this->assertSame('73980c64749f168015761cd752caacb5', md5(serialize($seed)));

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
		$defaults = (InvoiceMentionsTailChecks::seedSpec()[$type] ?? []);
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
