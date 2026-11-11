<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\ComplianceTailChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the ComplianceTailChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\ComplianceTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class ComplianceTailChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'ARInvoice' => [
			'en16931-br-af-01' => 'TT',
			'en16931-br-af-05' => 'TT',
			'en16931-br-af-08' => 'TT',
			'en16931-br-af-10' => 'TT',
			'en16931-br-ag-01' => 'TT',
			'en16931-br-ag-10' => 'TT',
			'en16931-br-b-01' => 'TT',
			'en16931-br-b-02' => 'TT',
			'vatdir-art315-margin-taxable-amount' => 'TT',
			'vatdir-art322-margin-no-deduction' => 'TT',
		],
		'SaftFile' => [
			'saft-header-group' => 'FT',
			'saft-gl-accounts-group' => 'FT',
			'saft-customers-suppliers-group' => 'FT',
			'saft-taxtable-group' => 'FT',
			'saft-gl-entries-group' => 'FT',
			'saft-source-documents-group' => 'FT',
			'saft-xml-schema-validity' => 'FT',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (ComplianceTailChecks::checks() as $type => $rules) {
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
		$seed = [ComplianceTailChecks::seedSpec()];
		$seed[] = ComplianceTailChecks::seedObjects();
		$this->assertSame('a6405a25c8e9c3dab71c61752cf0452f', md5(serialize($seed)));

	}//end testSeedDataIsUnchanged()

	/**
	 * Objects, the rule they are checked against and the expected verdict.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
	 */
	public static function cases(): array {
		$invoice = [
			'vatBreakdown'  => [['category' => 'L', 'taxableAmount' => 95.0], ['category' => 'S', 'taxableAmount' => 7.0], 'x'],
			'invoiceLines'  => [['vatCategory' => 'L', 'netAmount' => 60.0], ['vatCategory' => 'L', 'netAmount' => 40.0], ['vatCategory' => 'S', 'netAmount' => 7.0], 'x'],
			'allowances'    => [['vatCategory' => 'L', 'amount' => 10.0], ['vatCategory' => 'S', 'amount' => 3.0]],
			'charges'       => [['vatCategory' => 'L', 'amount' => 5.0]],
		];
		$line = ['accountId' => '1000', 'postingDate' => '2026-01-01', 'debitAmount' => 10, 'creditAmount' => 0, 'documentReference' => 'D-1'];
		$gl = ['glEntries' => [['journalId' => 'J', 'transactionId' => 'T', 'lines' => [$line, $line]]]];
		return [
			'BR-AF-08 no L group'           => ['ARInvoice', 'en16931-br-af-08', ['vatBreakdown' => [['category' => 'S']]], true],
			'BR-AF-08 reconciles'           => ['ARInvoice', 'en16931-br-af-08', $invoice, true],
			'BR-AF-08 off by a charge'      => ['ARInvoice', 'en16931-br-af-08', ['charges' => []] + $invoice, false],
			'BR-AF-08 off by an allowance'  => ['ARInvoice', 'en16931-br-af-08', ['allowances' => 'none'] + $invoice, false],
			'SAF-T GL complete'             => ['SaftFile', 'saft-gl-entries-group', $gl, true],
			'SAF-T GL no entries'           => ['SaftFile', 'saft-gl-entries-group', ['glEntries' => []], false],
			'SAF-T GL no journal id'        => ['SaftFile', 'saft-gl-entries-group', ['glEntries' => [['transactionId' => 'T', 'lines' => [$line]]]], false],
			'SAF-T GL no transaction id'    => ['SaftFile', 'saft-gl-entries-group', ['glEntries' => [['journalId' => 'J', 'lines' => [$line]]]], false],
			'SAF-T GL no lines'             => ['SaftFile', 'saft-gl-entries-group', ['glEntries' => [['journalId' => 'J', 'transactionId' => 'T', 'lines' => []]]], false],
			'SAF-T GL line without account' => ['SaftFile', 'saft-gl-entries-group', ['glEntries' => [['journalId' => 'J', 'transactionId' => 'T', 'lines' => [$line, ['accountId' => ''] + $line]]]], false],
			'SAF-T GL line without debit'   => ['SaftFile', 'saft-gl-entries-group', ['glEntries' => [['journalId' => 'J', 'transactionId' => 'T', 'lines' => [['debitAmount' => 'x'] + $line]]]], false],
			'SAF-T GL line without credit'  => ['SaftFile', 'saft-gl-entries-group', ['glEntries' => [['journalId' => 'J', 'transactionId' => 'T', 'lines' => [['creditAmount' => null] + $line]]]], false],
			'SAF-T GL line without date'    => ['SaftFile', 'saft-gl-entries-group', ['glEntries' => [['journalId' => 'J', 'transactionId' => 'T', 'lines' => [['postingDate' => ' '] + $line]]]], false],
			'SAF-T GL line without doc ref' => ['SaftFile', 'saft-gl-entries-group', ['glEntries' => [['journalId' => 'J', 'transactionId' => 'T', 'lines' => [['documentReference' => ''] + $line]]]], false],
		];

	}//end cases()

	/**
	 * Each check returns the pinned verdict.
	 *
	 * @param string               $type     The object type.
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The object checked.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testVerdict(string $type, string $ruleId, array $object, bool $expected): void {
		$check = ComplianceTailChecks::checks()[$type][$ruleId];
		$this->assertSame($expected, $check($object, ['jurisdiction' => 'NL']));

	}//end testVerdict()

	/**
	 * The objects every check of a type runs on.
	 *
	 * @param string $type The object type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function samples(string $type): array {
		$samples = [[]];
		$defaults = (ComplianceTailChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
		}

		foreach ((ComplianceTailChecks::seedObjects()[$type] ?? []) as $object) {
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
