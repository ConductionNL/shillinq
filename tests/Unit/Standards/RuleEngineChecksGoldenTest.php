<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards;

use OCA\Shillinq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden record of the RuleEngine predicate registry.
 *
 * Pins, per object type, every registered rule id in order, and the verdict
 * of every predicate on a fixed list of sample objects, one character per
 * sample: T (true), F (false) or E (it threw). The record is
 * tests/Unit/Standards/fixtures/rule-engine-checks.golden.json;
 * SHILLINQ_GOLDEN_RECORD=1 rewrites it.
 *
 * @covers \OCA\Shillinq\Standards\RuleEngine
 *
 * @uses \OCA\Shillinq\Standards\Checks\AmlBankingChecks
 * @uses \OCA\Shillinq\Standards\Checks\ChartOfAccountsChecks
 * @uses \OCA\Shillinq\Standards\Checks\ComplianceTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\FinalTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\FinancialStatementsChecks
 * @uses \OCA\Shillinq\Standards\Checks\IfrsUsGaapChecks
 * @uses \OCA\Shillinq\Standards\Checks\InvoiceMentionsTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\InvoicingExtraChecks
 * @uses \OCA\Shillinq\Standards\Checks\InvoicingTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\LedgerIntegrityChecks
 * @uses \OCA\Shillinq\Standards\Checks\NationalExtraTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\NationalReportingTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\OssIossChecks
 * @uses \OCA\Shillinq\Standards\Checks\PublicSectorIpsasGasbChecks
 * @uses \OCA\Shillinq\Standards\Checks\RemainingVatChecks
 * @uses \OCA\Shillinq\Standards\Checks\SustainabilityChecks
 * @uses \OCA\Shillinq\Standards\Checks\VatBbvLedgerTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\VatChecks
 * @uses \OCA\Shillinq\Standards\Checks\CheckFields
 * @uses \OCA\Shillinq\Standards\Checks\GlTransactionLines
 * @uses \OCA\Shillinq\Standards\Checks\InvoiceParts
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
class RuleEngineChecksGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/rule-engine-checks.golden.json';

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		RuleEngine::reset();

	}//end setUp()

	/**
	 * The registry and every verdict match the record.
	 *
	 * @return void
	 */
	public function testRegistryMatchesTheGoldenRecord(): void {
		$method = new ReflectionMethod(RuleEngine::class, 'checks');
		$checks = $method->invoke(null);

		$actual = [];
		foreach ($checks as $objectType => $ruleChecks) {
			foreach ($ruleChecks as $ruleId => $predicate) {
				$verdicts = '';
				foreach (self::samples() as $object) {
					$verdicts .= self::verdict($predicate, $object);
				}

				$actual[$objectType][(string) $ruleId] = $verdicts;
			}
		}

		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $objectType => $ruleChecks) {
			$this->assertSame(array_keys($ruleChecks), array_keys($actual[$objectType]), $objectType . ' rule order');
			$this->assertSame($ruleChecks, $actual[$objectType], $objectType . ' verdicts');
		}

	}//end testRegistryMatchesTheGoldenRecord()

	/**
	 * Run one predicate: T (true), F (false) or E (it threw).
	 *
	 * @param callable             $predicate The registered check.
	 * @param array<string, mixed> $object    The sample object.
	 *
	 * @return string
	 */
	private static function verdict(callable $predicate, array $object): string {
		try {
			return $predicate($object, ['jurisdiction' => 'NL']) === true ? 'T' : 'F';
		} catch (\Throwable $e) {
			return 'E';
		}

	}//end verdict()

	/**
	 * The sample objects every predicate is run on.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function samples(): array {
		$line = static fn (string $category, float $rate, float $net): array => [
			'lineId'          => 'L-' . $category,
			'quantity'        => 2,
			'unitCode'        => 'C62',
			'netAmount'       => $net,
			'itemName'        => 'Item ' . $category,
			'netPrice'        => ($net / 2),
			'vatCategory'     => $category,
			'vatRate'         => $rate,
			'lineAllowances'  => [['amount' => 1.0, 'reason' => 'Promo', 'baseAmount' => 10.0]],
			'lineCharges'     => [['amount' => 2.0, 'reasonCode' => 'FC', 'baseAmount' => 20.0]],
		];
		$full = [
			'specificationId'   => 'urn:cen.eu:en16931:2017',
			'invoiceNumber'     => 'INV-1',
			'invoiceDate'       => '2026-01-01',
			'invoiceTypeCode'   => '380',
			'supplyDate'        => '2026-01-01',
			'dueDate'           => '2026-02-01',
			'currency'          => 'EUR',
			'customerId'        => 'C-1',
			'sellerName'        => 'Seller',
			'sellerAddress'     => 'Street 1',
			'sellerCountryCode' => 'NL',
			'sellerVatId'       => 'NL123456789B01',
			'buyerAddress'      => 'Road 2',
			'buyerVatId'        => 'DE123456789',
			'netAmount'         => 100.0,
			'vatAmount'         => 21.0,
			'grossAmount'       => 121.0,
			'lineNetTotal'      => 100.0,
			'allowancesTotal'   => 5.0,
			'chargesTotal'      => 0.0,
			'allowances'        => [['amount' => 5.0, 'vatCategory' => 'S', 'reason' => 'Discount', 'baseAmount' => 50.0]],
			'charges'           => [],
			'invoiceLines'      => [$line('S', 21.0, 100.0)],
			'vatBreakdown'      => [['category' => 'S', 'taxableAmount' => 100.0, 'taxAmount' => 21.0, 'rate' => 21.0]],
			'vendorId'          => 'V-1',
			'totalAmount'       => 121.0,
			'taxAmount'         => 21.0,
			'sourceDocumentUri' => 'https://example.org/doc',
			'transactionNumber' => 'GL-1',
			'postingDate'       => '2026-01-01',
			'sourceReference'   => 'REF-1',
			'lines'             => [
				['account' => '1000', 'debit' => 121.0, 'credit' => 0.0],
				['account' => '8000', 'debit' => 0.0, 'credit' => 121.0],
			],
		];

		$mixed = $full;
		$mixed['invoiceLines'] = [
			$line('Z', 0.0, 10.0),
			$line('E', 0.0, 10.0),
			$line('AE', 0.0, 10.0),
			$line('O', 0.0, 10.0),
			$line('G', 0.0, 10.0),
			$line('K', 0.0, 10.0),
			$line('S', 0.0, 10.0),
		];
		$mixed['vatBreakdown'] = [
			['category' => 'Z', 'taxableAmount' => 10.0, 'taxAmount' => 0.0, 'rate' => 0.0, 'exemptionReason' => 'Zero'],
			['category' => 'E', 'taxableAmount' => 10.0, 'taxAmount' => 0.0, 'rate' => 0.0, 'exemptionReason' => 'Exempt'],
			['category' => 'AE', 'taxableAmount' => 9.0, 'taxAmount' => 1.0, 'rate' => 0.0],
			['category' => 'O', 'taxableAmount' => 10.0, 'taxAmount' => 0.0, 'exemptionReasonCode' => 'VATEX-EU-O'],
			['category' => 'K', 'taxableAmount' => 10.0, 'taxAmount' => 0.0, 'rate' => 0.0, 'exemptionReason' => 'IC'],
		];
		$mixed['sellerVatId']     = '123';
		$mixed['buyerVatId']      = '';
		$mixed['taxPointDate']    = '2026-01-01';
		$mixed['taxPointDateCode'] = '3';
		$mixed['netAmount']       = 100.123;
		$mixed['currency']        = 'XXQ';
		$mixed['lines']           = [
			['account' => '1000', 'debit' => 100.0, 'credit' => 0.0],
			['account' => '', 'debit' => 0.0, 'credit' => 99.0],
		];

		$sparse = [
			'grossAmount'      => 50.0,
			'netAmount'        => 'abc',
			'vatAmount'        => null,
			'currency'         => 'eur',
			'invoiceLines'     => [['vatCategory' => 'S'], 'not-a-line', ['vatCategory' => 'O', 'vatRate' => 0]],
			'vatBreakdown'     => [['category' => 'S', 'taxableAmount' => 'x']],
			'allowances'       => [['amount' => 'x']],
			'charges'          => [['amount' => 1.005, 'reasonCode' => 'X']],
			'sellerIdentifier' => 'S-ID',
			'paymentTerms'     => 'Net 30',
			'lines'            => [['account' => '1000', 'debit' => 10.0]],
		];

		$samples = [
			'empty'  => [],
			'full'   => $full,
			'mixed'  => $mixed,
			'sparse' => $sparse,
			'zero'   => ['grossAmount' => 0, 'netAmount' => 0, 'vatAmount' => 0, 'invoiceLines' => [], 'vatBreakdown' => [], 'lines' => []],
		];
		foreach (['full' => $full, 'mixed' => $mixed] as $base => $object) {
			foreach (array_keys($object) as $field) {
				$without = $object;
				unset($without[$field]);
				$samples[$base . '-' . $field] = $without;
				$blank         = $object;
				$blank[$field] = '';
				$samples[$base . '=' . $field] = $blank;
			}
		}

		return $samples;

	}//end samples()
}//end class
