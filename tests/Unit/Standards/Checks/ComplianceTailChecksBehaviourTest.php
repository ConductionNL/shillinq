<?php

/**
 * Behaviour of the compliance tail checks on real invoices and SAF-T files.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\ComplianceTailChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A complete invoice (Canary Islands IGIC, Ceuta/Melilla IPSI, Italian split
 * payment, margin scheme) and a complete SAF-T file pass every rule; each
 * defect fails exactly the rules that name it.
 *
 * @covers \OCA\Shillinq\Standards\Checks\ComplianceTailChecks
 * @uses   \OCA\Shillinq\Standards\Checks\InvoiceParts
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class ComplianceTailChecksBehaviourTest extends TestCase {

	/**
	 * A Canary Islands (L) invoice with one IPSI (M) line.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(): array {
		return [
			'sellerCountryCode' => 'ES',
			'buyerCountryCode'  => 'ES',
			'invoiceLines'      => [
				['vatCategory' => 'L', 'vatRate' => 7, 'netAmount' => 60.00],
				['vatCategory' => 'L', 'vatRate' => '0', 'netAmount' => 40.00],
				['vatCategory' => 'M', 'vatRate' => 4, 'netAmount' => 20.00],
			],
			'allowances'        => [['vatCategory' => 'L', 'amount' => 10.00]],
			'charges'           => [['vatCategory' => 'L', 'amount' => 5.004]],
			'vatBreakdown'      => [
				['category' => 'L', 'taxableAmount' => 50.00],
				['category' => 'L', 'taxableAmount' => 45.00],
				['category' => 'M', 'taxableAmount' => 20.00, 'exemptionReasonText' => ' '],
			],
		];

	}//end invoice()

	/**
	 * An Italian domestic split-payment (B) invoice.
	 *
	 * @return array<string, mixed>
	 */
	private static function splitPayment(): array {
		return [
			'sellerCountryCode' => 'IT',
			'buyerCountryCode'  => 'it-01234567890',
			'invoiceLines'      => [['vatCategory' => 'B', 'vatRate' => 22, 'netAmount' => 100.00]],
			'vatBreakdown'      => [['category' => 'B', 'taxableAmount' => 100.00]],
		];

	}//end splitPayment()

	/**
	 * A margin-scheme invoice: bought at 100, sold at 150.
	 *
	 * @return array<string, mixed>
	 */
	private static function margin(): array {
		return [
			'marginScheme'        => 'yes',
			'marginPurchasePrice' => 100,
			'marginSellingPrice'  => '150.00',
			'marginTaxableAmount' => 50.004,
			'taxAmount'           => 0,
			'invoiceLines'        => [['vatCategory' => 'E', 'vatRate' => 0], ['vatCategory' => 'E']],
			'vatBreakdown'        => [['category' => 'E', 'taxAmount' => '0.00']],
		];

	}//end margin()

	/**
	 * A complete SAF-T file.
	 *
	 * @return array<string, mixed>
	 */
	private static function saft(): array {
		$party = ['name' => 'Partij B.V.', 'vatNumber' => 'NL123456789B01', 'address' => 'Markt 1', 'openingBalance' => 0, 'closingBalance' => '12.50'];
		return [
			'header'                => [
				'companyName'     => 'Shillinq B.V.',
				'companyId'       => '12345678',
				'currencyCode'    => 'EUR',
				'startDate'       => '2026-01-01',
				'endDate'         => '2026-12-31',
				'taxonomyVersion' => '2.00',
				'softwareId'      => 'shillinq',
			],
			'generalLedgerAccounts' => [['accountId' => '1000', 'description' => 'Kas', 'accountType' => 'Asset', 'openingBalance' => 0, 'closingBalance' => 10]],
			'customers'             => [['customerId' => 'C1'] + $party],
			'suppliers'             => [['supplierId' => 'S1'] + $party],
			'taxTable'              => [['taxCode' => 'H', 'taxPercentage' => 21, 'description' => 'Hoog']],
			'glEntries'             => [
				[
					'journalId'     => 'MEM',
					'transactionId' => 'T1',
					'lines'         => [['accountId' => '1000', 'postingDate' => '2026-02-01', 'debitAmount' => 10, 'creditAmount' => 0, 'documentReference' => 'F1']],
				],
			],
			'sourceDocuments'       => [['documentType' => 'invoice', 'documentReference' => 'F1', 'glTransactionId' => 'T1']],
			'xsdValid'              => true,
		];

	}//end saft()

	/**
	 * The rule ids of a type that refuse the object, in registry order.
	 *
	 * @param string               $type The object type.
	 * @param array<string, mixed> $o    The object.
	 *
	 * @return array<int, string>
	 */
	private static function failing(string $type, array $o): array {
		$failing = [];
		foreach (ComplianceTailChecks::checks()[$type] as $ruleId => $check) {
			if ($check($o, ['jurisdiction' => 'NL']) !== true) {
				$failing[] = $ruleId;
			}
		}

		return $failing;

	}//end failing()

	/**
	 * Complete objects pass every rule of their type.
	 *
	 * @return void
	 */
	public function testCompleteObjectsPassEveryRule(): void {
		$this->assertSame([], self::failing('ARInvoice', self::invoice()));
		$this->assertSame([], self::failing('ARInvoice', self::splitPayment()));
		$this->assertSame([], self::failing('ARInvoice', self::margin()));
		$this->assertSame([], self::failing('ARInvoice', array_diff_key(self::margin(), ['marginTaxableAmount' => 1])));
		$this->assertSame([], self::failing('ARInvoice', ['marginScheme' => 'no', 'taxAmount' => 21]));
		$this->assertSame([], self::failing('SaftFile', self::saft()));
		$this->assertSame([], self::failing('SaftFile', ['xsdValid' => ' TRUE '] + self::saft()));

	}//end testCompleteObjectsPassEveryRule()

	/**
	 * Each invoice defect fails exactly the rules that name it.
	 *
	 * @param \Closure           $defect   Builds the defective invoice.
	 * @param array<int, string> $expected The rules that must refuse it.
	 *
	 * @return void
	 */
	#[DataProvider('invoiceDefects')]
	public function testAnInvoiceDefectFailsExactlyItsRules(\Closure $defect, array $expected): void {
		$this->assertSame($expected, self::failing('ARInvoice', $defect()));

	}//end testAnInvoiceDefectFailsExactlyItsRules()

	/**
	 * Invoice defects and the rules they break.
	 *
	 * @return array<string, array{0: \Closure, 1: array<int, string>}>
	 */
	public static function invoiceDefects(): array {
		return [
			'IGIC line without IGIC breakdown'     => [static function (): array {
				$o = self::invoice();
				$o['vatBreakdown'] = [$o['vatBreakdown'][2]];
				return $o;
			}, ['en16931-br-af-01'],
			],
			'IGIC charge without IGIC breakdown'   => [static function (): array {
				$o = self::invoice();
				foreach (array_keys($o['invoiceLines']) as $i) {
					$o['invoiceLines'][$i]['vatCategory'] = 'M';
				}

				$o['allowances']   = [];
				$o['vatBreakdown'] = [['category' => 'M', 'taxableAmount' => 120.00]];
				return $o;
			}, ['en16931-br-af-01'],
			],
			'negative IGIC rate'                   => [static function (): array {
				$o = self::invoice();
				$o['invoiceLines'][0]['vatRate'] = -7;
				return $o;
			}, ['en16931-br-af-05'],
			],
			'IGIC line without a rate'             => [static function (): array {
				$o = self::invoice();
				unset($o['invoiceLines'][1]['vatRate']);
				return $o;
			}, ['en16931-br-af-05'],
			],
			'IGIC taxable does not reconcile'      => [static function (): array {
				$o = self::invoice();
				$o['vatBreakdown'][1]['taxableAmount'] = 45.02;
				return $o;
			}, ['en16931-br-af-08'],
			],
			'IGIC breakdown with exemption reason' => [static function (): array {
				$o = self::invoice();
				$o['vatBreakdown'][0]['exemptionReasonCode'] = 'VATEX-EU-79-C';
				return $o;
			}, ['en16931-br-af-10'],
			],
			'IPSI line without IPSI breakdown'     => [static function (): array {
				$o = self::invoice();
				array_pop($o['vatBreakdown']);
				return $o;
			}, ['en16931-br-ag-01'],
			],
			'IPSI breakdown with exemption reason' => [static function (): array {
				$o = self::invoice();
				$o['vatBreakdown'][2]['exemptionReasonText'] = 'Exempt';
				return $o;
			}, ['en16931-br-ag-10'],
			],
			'split payment by a foreign seller'    => [static fn (): array => ['sellerCountryCode' => 'NL'] + self::splitPayment(), ['en16931-br-b-01']],
			'split payment to an unknown buyer'    => [static fn (): array => array_diff_key(self::splitPayment(), ['buyerCountryCode' => 1]), ['en16931-br-b-01']],
			'split payment beside standard rate'   => [static function (): array {
				$o = self::splitPayment();
				$o['invoiceLines'][] = ['vatCategory' => 'S', 'vatRate' => 22];
				return $o;
			}, ['en16931-br-b-02'],
			],
			'split payment charge beside standard' => [static fn (): array => ['charges' => [['vatCategory' => 'B']], 'invoiceLines' => [['vatCategory' => 'S']]] + self::splitPayment(), ['en16931-br-b-02']],
			'margin without purchase price'        => [static fn (): array => array_diff_key(self::margin(), ['marginPurchasePrice' => 1]), ['vatdir-art315-margin-taxable-amount']],
			'margin with non-numeric selling price' => [static fn (): array => ['marginSellingPrice' => 'n/a'] + self::margin(), ['vatdir-art315-margin-taxable-amount']],
			'margin sold at a loss'                => [static fn (): array => ['marginSellingPrice' => 90, 'marginTaxableAmount' => -10] + self::margin(), ['vatdir-art315-margin-taxable-amount']],
			'margin taxable amount mismatch'       => [static fn (): array => ['marginTaxableAmount' => 49.99] + self::margin(), ['vatdir-art315-margin-taxable-amount']],
			'margin with header VAT'               => [static fn (): array => ['taxAmount' => '0.01'] + self::margin(), ['vatdir-art322-margin-no-deduction']],
			'margin with a VAT-rated line'         => [static function (): array {
				$o = self::margin();
				$o['invoiceLines'][1]['vatRate'] = 21;
				return $o;
			}, ['vatdir-art322-margin-no-deduction'],
			],
			'margin with breakdown VAT'            => [static function (): array {
				$o = self::margin();
				$o['vatBreakdown'][0]['taxAmount'] = 3;
				return $o;
			}, ['vatdir-art322-margin-no-deduction'],
			],
		];

	}//end invoiceDefects()

	/**
	 * Each SAF-T defect fails exactly the rules that name it.
	 *
	 * @param \Closure           $defect   Turns the complete file into a defective one.
	 * @param array<int, string> $expected The rules that must refuse it.
	 *
	 * @return void
	 */
	#[DataProvider('saftDefects')]
	public function testASaftDefectFailsExactlyItsRules(\Closure $defect, array $expected): void {
		$this->assertSame($expected, self::failing('SaftFile', $defect(self::saft())));

	}//end testASaftDefectFailsExactlyItsRules()

	/**
	 * SAF-T defects and the rules they break.
	 *
	 * @return array<string, array{0: \Closure, 1: array<int, string>}>
	 */
	public static function saftDefects(): array {
		return [
			'no header'                     => [static fn (array $o): array => ['header' => 'Shillinq'] + $o, ['saft-header-group']],
			'header without software id'    => [static function (array $o): array {
				$o['header']['softwareId'] = ' ';
				return $o;
			}, ['saft-header-group'],
			],
			'no accounts'                   => [static fn (array $o): array => ['generalLedgerAccounts' => []] + $o, ['saft-gl-accounts-group']],
			'account without closing'       => [static function (array $o): array {
				$o['generalLedgerAccounts'][] = ['accountId' => '2000', 'description' => 'Bank', 'accountType' => 'Asset', 'openingBalance' => 0, 'closingBalance' => ''];
				return $o;
			}, ['saft-gl-accounts-group'],
			],
			'account without type'          => [static function (array $o): array {
				unset($o['generalLedgerAccounts'][0]['accountType']);
				return $o;
			}, ['saft-gl-accounts-group'],
			],
			'no customers'                  => [static fn (array $o): array => array_diff_key($o, ['customers' => 1]), ['saft-customers-suppliers-group']],
			'supplier without VAT number'   => [static function (array $o): array {
				$o['suppliers'][0]['vatNumber'] = '';
				return $o;
			}, ['saft-customers-suppliers-group'],
			],
			'customer under the wrong id'   => [static function (array $o): array {
				$o['customers'][0]['supplierId'] = $o['customers'][0]['customerId'];
				unset($o['customers'][0]['customerId']);
				return $o;
			}, ['saft-customers-suppliers-group'],
			],
			'no tax table'                  => [static fn (array $o): array => ['taxTable' => null] + $o, ['saft-taxtable-group']],
			'tax without percentage'        => [static function (array $o): array {
				$o['taxTable'][0]['taxPercentage'] = 'high';
				return $o;
			}, ['saft-taxtable-group'],
			],
			'no entries'                    => [static fn (array $o): array => ['glEntries' => []] + $o, ['saft-gl-entries-group']],
			'entry without journal'         => [static function (array $o): array {
				unset($o['glEntries'][0]['journalId']);
				return $o;
			}, ['saft-gl-entries-group'],
			],
			'entry without lines'           => [static function (array $o): array {
				$o['glEntries'][0]['lines'] = [];
				return $o;
			}, ['saft-gl-entries-group'],
			],
			'entry line without debit'      => [static function (array $o): array {
				$o['glEntries'][0]['lines'][] = ['accountId' => '1000', 'postingDate' => '2026-02-01', 'creditAmount' => 10, 'documentReference' => 'F1'];
				return $o;
			}, ['saft-gl-entries-group'],
			],
			'second entry incomplete'       => [static function (array $o): array {
				$o['glEntries'][] = ['journalId' => 'MEM', 'lines' => $o['glEntries'][0]['lines']];
				return $o;
			}, ['saft-gl-entries-group'],
			],
			'no source documents'           => [static fn (array $o): array => ['sourceDocuments' => []] + $o, ['saft-source-documents-group']],
			'document without transaction'  => [static function (array $o): array {
				$o['sourceDocuments'][0]['glTransactionId'] = '';
				return $o;
			}, ['saft-source-documents-group'],
			],
			'schema validation failed'      => [static fn (array $o): array => ['xsdValid' => false] + $o, ['saft-xml-schema-validity']],
			'schema validation unknown'     => [static fn (array $o): array => array_diff_key($o, ['xsdValid' => 1]), ['saft-xml-schema-validity']],
			'schema validation as text no'  => [static fn (array $o): array => ['xsdValid' => 'no'] + $o, ['saft-xml-schema-validity']],
		];

	}//end saftDefects()
}//end class
