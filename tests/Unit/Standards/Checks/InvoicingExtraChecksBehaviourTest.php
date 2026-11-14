<?php

/**
 * Behaviour of the remaining EN 16931 / VAT Directive invoicing checks on real invoices.
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

use OCA\Shillinq\Standards\Checks\InvoicingExtraChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A complete invoice that uses every optional group passes every rule; each
 * defect fails exactly the rules that name it, and nothing else.
 *
 * @covers \OCA\Shillinq\Standards\Checks\InvoicingExtraChecks
 * @uses   \OCA\Shillinq\Standards\Checks\InvoiceParts
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class InvoicingExtraChecksBehaviourTest extends TestCase {

	/**
	 * A complete standard-rated invoice with payment, delivery, periods,
	 * references, a tax representative and a separate payee.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(): array {
		return [
			'invoiceTypeCode'               => '380',
			'currency'                      => 'EUR',
			'taxCurrencyCode'               => 'USD',
			'taxAmountAccountingCurrency'   => 21.5,
			'sellerName'                    => 'Leverancier B.V.',
			'sellerCountryCode'             => 'NL',
			'buyerCountryCode'              => 'NL',
			'buyerVatId'                    => 'NL987654321B01',
			'grossAmount'                   => 114.95,
			'paidAmount'                    => 14.95,
			'roundingAmount'                => 0.01,
			'amountDue'                     => 100.01,
			'invoicingPeriodStart'          => '2026-05-01',
			'invoicingPeriodEnd'            => '2026-05-31',
			'paymentMeansTypeCode'          => '30',
			'paymentAccountId'              => 'NL91ABNA0417164300',
			'sellerElectronicAddress'       => '0106:12345678',
			'sellerElectronicAddressScheme' => '0106',
			'buyerElectronicAddress'        => '0106:87654321',
			'buyerElectronicAddressScheme'  => '0106',
			'sellerTaxRepName'              => 'Fiscaal Vertegenwoordiger B.V.',
			'sellerTaxRepAddress'           => 'Kade 3, Rotterdam',
			'sellerTaxRepCountryCode'       => 'NL',
			'sellerTaxRepVatId'             => 'NL111222333B01',
			'payeeName'                     => 'Factoring B.V.',
			'deliverToAddress'              => 'Haven 4, Antwerpen',
			'deliverToCountryCode'          => 'BE',
			'supportingDocuments'           => [['reference' => 'TS-1']],
			'precedingInvoiceReferences'    => [['reference' => 'F-2026-000']],
			'invoiceLines'                  => [
				[
					'unitCode'        => 'C62',
					'vatCategory'     => 'S',
					'netPrice'        => 30.00,
					'grossPrice'      => 32.00,
					'periodStart'     => '2026-05-01',
					'periodEnd'       => '2026-05-15',
					'lineAllowances'  => [['reasonCode' => '95']],
					'lineCharges'     => [['reasonText' => 'Packing']],
					'itemAttributes'  => [['name' => 'Colour', 'value' => 'Blue']],
				],
				['unitCode' => 'HUR', 'vatCategory' => 'S', 'netPrice' => '', 'grossPrice' => null],
			],
			'allowances'                    => [['reasonText' => 'Discount', 'vatCategory' => 'S']],
			'charges'                       => [['reasonCode' => 'ABL', 'vatCategory' => 'S']],
			'vatBreakdown'                  => [['category' => 'S']],
		];

	}//end invoice()

	/**
	 * The rule ids that refuse the object, in registry order.
	 *
	 * @param array<string, mixed> $o The invoice.
	 *
	 * @return array<int, string>
	 */
	private static function failing(array $o): array {
		$failing = [];
		foreach (InvoicingExtraChecks::checks()['ARInvoice'] as $ruleId => $check) {
			if ($check($o, ['jurisdiction' => 'NL']) !== true) {
				$failing[] = $ruleId;
			}
		}

		return $failing;

	}//end failing()

	/**
	 * A complete invoice passes every rule.
	 *
	 * @return void
	 */
	public function testACompleteInvoicePassesEveryRule(): void {
		$this->assertSame([], self::failing(self::invoice()));

	}//end testACompleteInvoicePassesEveryRule()

	/**
	 * An invoice wholly outside the scope of VAT, or wholly intra-community
	 * with a delivery date and country, passes every rule.
	 *
	 * @return void
	 */
	public function testAWhollyUntaxedInvoicePasses(): void {
		foreach (['O', 'K'] as $category) {
			$o = self::invoice();
			foreach (['invoiceLines', 'allowances', 'charges'] as $key) {
				foreach (array_keys($o[$key]) as $i) {
					$o[$key][$i]['vatCategory'] = $category;
				}
			}

			$o['vatBreakdown']       = [['category' => $category]];
			$o['actualDeliveryDate'] = '2026-05-20';
			unset($o['invoicingPeriodStart'], $o['invoicingPeriodEnd']);
			$this->assertSame([], self::failing($o), $category);
		}

	}//end testAWhollyUntaxedInvoicePasses()

	/**
	 * Each defect fails exactly the rules that name it.
	 *
	 * @param \Closure           $defect   Turns the complete invoice into a defective one.
	 * @param array<int, string> $expected The rules that must refuse it.
	 *
	 * @return void
	 */
	#[DataProvider('defects')]
	public function testADefectFailsExactlyItsRules(\Closure $defect, array $expected): void {
		$this->assertSame($expected, self::failing($defect(self::invoice())));

	}//end testADefectFailsExactlyItsRules()

	/**
	 * Defects and the rules they break.
	 *
	 * @return array<string, array{0: \Closure, 1: array<int, string>}>
	 */
	public static function defects(): array {
		return [
			'no buyer country'                    => [static fn (array $o): array => array_diff_key($o, ['buyerCountryCode' => 1]), ['en16931-br-11']],
			'lower-case buyer country'            => [static fn (array $o): array => ['buyerCountryCode' => 'nl'] + $o, ['en16931-br-cl-14']],
			'spelled-out delivery country'        => [static fn (array $o): array => ['deliverToCountryCode' => 'Belgium'] + $o, ['en16931-br-cl-14']],
			'lower-case currency'                 => [static fn (array $o): array => ['currency' => 'eur'] + $o, ['en16931-br-cl-04']],
			'two-letter tax currency'             => [static fn (array $o): array => ['taxCurrencyCode' => 'US'] + $o, ['en16931-br-cl-05']],
			'unknown type code'                   => [static fn (array $o): array => ['invoiceTypeCode' => '999'] + $o, ['en16931-br-cl-01']],
			'unknown line VAT category'           => [static function (array $o): array {
				$o['invoiceLines'][1]['vatCategory'] = 'X';
				return $o;
			}, ['en16931-br-cl-17'],
			],
			'unknown breakdown category'          => [static function (array $o): array {
				$o['vatBreakdown'][] = ['category' => 'Q'];
				return $o;
			}, ['en16931-br-cl-17'],
			],
			'no lines'                            => [static fn (array $o): array => ['invoiceLines' => []] + $o, ['en16931-br-cl-17', 'en16931-br-cl-23']],
			'line without unit code'              => [static function (array $o): array {
				$o['invoiceLines'][1]['unitCode'] = ' ';
				return $o;
			}, ['en16931-br-cl-23'],
			],
			'negative net price'                  => [static function (array $o): array {
				$o['invoiceLines'][0]['netPrice'] = -1;
				return $o;
			}, ['en16931-br-27'],
			],
			'non-numeric gross price'             => [static function (array $o): array {
				$o['invoiceLines'][0]['grossPrice'] = 'free';
				return $o;
			}, ['en16931-br-28'],
			],
			'period ends before it starts'        => [static fn (array $o): array => ['invoicingPeriodEnd' => '2026-04-30'] + $o, ['en16931-br-29']],
			'line period ends before it starts'   => [static function (array $o): array {
				$o['invoiceLines'][0]['periodEnd'] = '2026-04-01';
				return $o;
			}, ['en16931-br-30'],
			],
			'allowance without reason'            => [static function (array $o): array {
				$o['allowances'][0]['reasonText'] = ' ';
				return $o;
			}, ['en16931-br-co-21'],
			],
			'charge without reason'               => [static function (array $o): array {
				unset($o['charges'][0]['reasonCode']);
				return $o;
			}, ['en16931-br-co-22'],
			],
			'line allowance without reason'       => [static function (array $o): array {
				$o['invoiceLines'][0]['lineAllowances'][] = ['amount' => 1];
				return $o;
			}, ['en16931-br-co-23'],
			],
			'line charge without reason'          => [static function (array $o): array {
				$o['invoiceLines'][0]['lineCharges'][] = ['amount' => 1];
				return $o;
			}, ['en16931-br-co-24'],
			],
			'non-numeric amount due'              => [static fn (array $o): array => ['amountDue' => 'n/a'] + $o, ['en16931-br-15', 'en16931-br-co-16']],
			'amount due with three decimals'      => [static fn (array $o): array => ['amountDue' => 100.014, 'grossAmount' => 114.954] + $o, ['en16931-br-dec-18']],
			'paid amount with three decimals'     => [static fn (array $o): array => ['paidAmount' => 14.954] + $o, ['en16931-br-dec-16']],
			'rounding with three decimals'        => [static fn (array $o): array => ['roundingAmount' => 0.011] + $o, ['en16931-br-dec-17']],
			'accounting VAT with three decimals'  => [static fn (array $o): array => ['taxAmountAccountingCurrency' => 21.555] + $o, ['en16931-br-dec-15']],
			'amount due does not add up'          => [static fn (array $o): array => ['amountDue' => 114.96] + $o, ['en16931-br-co-16']],
			'tax currency without its VAT total'  => [static fn (array $o): array => array_diff_key($o, ['taxAmountAccountingCurrency' => 1]), ['en16931-br-53']],
			'account without payment means'       => [static fn (array $o): array => array_diff_key($o, ['paymentMeansTypeCode' => 1]), ['en16931-br-49']],
			'unknown payment means'               => [static fn (array $o): array => ['paymentMeansTypeCode' => '99'] + $o, ['en16931-br-cl-16']],
			'credit transfer without account'     => [static fn (array $o): array => array_diff_key(['paymentMeansTypeCode' => '58'] + $o, ['paymentAccountId' => 1]), ['en16931-br-50', 'en16931-br-61']],
			'seller address without scheme'       => [static fn (array $o): array => array_diff_key($o, ['sellerElectronicAddressScheme' => 1]), ['en16931-br-62']],
			'buyer address without scheme'        => [static fn (array $o): array => ['buyerElectronicAddressScheme' => ''] + $o, ['en16931-br-63']],
			'supporting document without ref'     => [static function (array $o): array {
				$o['supportingDocuments'][] = ['description' => 'Timesheet'];
				return $o;
			}, ['en16931-br-52'],
			],
			'preceding invoice without ref'       => [static function (array $o): array {
				$o['precedingInvoiceReferences'][0]['reference'] = '';
				return $o;
			}, ['en16931-br-55'],
			],
			'item attribute without value'        => [static function (array $o): array {
				$o['invoiceLines'][0]['itemAttributes'][] = ['name' => 'Size'];
				return $o;
			}, ['en16931-br-54'],
			],
			'item attribute without name'         => [static function (array $o): array {
				$o['invoiceLines'][1]['itemAttributes'] = [['value' => 'XL']];
				return $o;
			}, ['en16931-br-54'],
			],
			'tax representative named only'       => [
				static fn (array $o): array => array_diff_key($o, ['sellerTaxRepAddress' => 1, 'sellerTaxRepCountryCode' => 1, 'sellerTaxRepVatId' => 1]),
				['en16931-br-19', 'en16931-br-20', 'en16931-br-56'],
			],
			'tax representative without name'     => [static fn (array $o): array => array_diff_key($o, ['sellerTaxRepName' => 1]), ['en16931-br-18']],
			'payee identified but not named'      => [static fn (array $o): array => ['payeeIdentifier' => 'KVK-1'] + array_diff_key($o, ['payeeName' => 1]), ['en16931-br-17']],
			'delivery address without country'    => [static fn (array $o): array => array_diff_key($o, ['deliverToCountryCode' => 1]), ['en16931-br-57']],
			'zero-rated line not in breakdown'    => [static function (array $o): array {
				$o['invoiceLines'][1]['vatCategory'] = 'Z';
				return $o;
			}, ['en16931-br-z-01'],
			],
			'exempt allowance not in breakdown'   => [static function (array $o): array {
				$o['allowances'][0]['vatCategory'] = 'E';
				return $o;
			}, ['en16931-br-e-01'],
			],
			'reverse-charge charge not in breakdown' => [static function (array $o): array {
				$o['charges'][0]['vatCategory'] = 'AE';
				return $o;
			}, ['en16931-br-ae-01'],
			],
			'intra-community line not in breakdown'  => [static function (array $o): array {
				$o['invoiceLines'][0]['vatCategory'] = 'K';
				return $o;
			}, ['en16931-br-ic-01'],
			],
			'export line not in breakdown'        => [static function (array $o): array {
				$o['invoiceLines'][0]['vatCategory'] = 'G';
				return $o;
			}, ['en16931-br-g-01'],
			],
			'not-subject line not in breakdown'   => [static function (array $o): array {
				$o['invoiceLines'][0]['vatCategory'] = 'O';
				return $o;
			}, ['en16931-br-o-01'],
			],
			'not-subject breakdown mixed with S'  => [static function (array $o): array {
				$o['vatBreakdown'][] = ['category' => 'O'];
				return $o;
			}, ['en16931-br-o-11', 'en16931-br-o-12', 'en16931-br-o-13', 'en16931-br-o-14'],
			],
			'intra-community without delivery'    => [static function (array $o): array {
				$o['vatBreakdown'][] = ['category' => 'K'];
				return array_diff_key($o, ['invoicingPeriodStart' => 1, 'invoicingPeriodEnd' => 1, 'deliverToCountryCode' => 1, 'deliverToAddress' => 1]);
			}, ['en16931-br-ic-11', 'en16931-br-ic-12'],
			],
			'buyer VAT id without country prefix' => [static fn (array $o): array => ['buyerVatId' => ' 987654321B01'] + $o, ['vatdir-art226-4']],
		];

	}//end defects()

	/**
	 * Optional values are vacuously satisfied; unparsable period dates do not refuse.
	 *
	 * @return void
	 */
	public function testAbsentOptionalGroupsDoNotRefuse(): void {
		$o = self::invoice();
		foreach (['taxCurrencyCode', 'taxAmountAccountingCurrency', 'paymentMeansTypeCode', 'paymentAccountId', 'sellerElectronicAddress', 'sellerElectronicAddressScheme', 'sellerTaxRepName', 'sellerTaxRepAddress', 'sellerTaxRepCountryCode', 'sellerTaxRepVatId', 'payeeName', 'deliverToAddress', 'deliverToCountryCode', 'amountDue', 'buyerVatId'] as $key) {
			unset($o[$key]);
		}

		$o['invoicingPeriodEnd'] = 'not a date';
		$o['supportingDocuments'] = 'not a list';
		$this->assertSame([], self::failing($o));

		$sameAsSeller = ['payeeName' => 'Leverancier B.V.'] + self::invoice();
		$this->assertSame([], self::failing($sameAsSeller));

	}//end testAbsentOptionalGroupsDoNotRefuse()
}//end class
