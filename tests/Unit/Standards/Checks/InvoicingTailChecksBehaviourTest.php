<?php

/**
 * Behaviour of the EN 16931 / VAT Directive tail checks on real invoices.
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

use OCA\Shillinq\Standards\Checks\InvoicingTailChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A complete standard-rated invoice passes every rule; each defect fails
 * exactly the rules that name it, and nothing else. The same holds for an
 * invoice in each zero-rate or exempt VAT category.
 *
 * @covers \OCA\Shillinq\Standards\Checks\InvoicingTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class InvoicingTailChecksBehaviourTest extends TestCase {

	/**
	 * A complete standard-rated (21%) Dutch invoice: two lines, one allowance, one charge.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(): array {
		return [
			'specificationId'   => 'urn:cen.eu:en16931:2017',
			'invoiceNumber'     => 'F-2026-001',
			'invoiceDate'       => '2026-05-16',
			'invoiceTypeCode'   => '380',
			'currency'          => 'EUR',
			'sellerName'        => 'Leverancier B.V.',
			'buyerName'         => 'Afnemer B.V.',
			'sellerAddress'     => 'Markt 1, Utrecht',
			'sellerCountryCode' => 'NL',
			'buyerAddress'      => 'Dorpsstraat 2, Zeist',
			'sellerVatId'       => 'NL123456789B01',
			'buyerVatId'        => 'NL987654321B01',
			'lineNetTotal'      => 100.00,
			'allowancesTotal'   => 10.00,
			'chargesTotal'      => 5.00,
			'netAmount'         => 95.00,
			'vatAmount'         => 19.95,
			'grossAmount'       => 114.95,
			'amountDue'         => 114.95,
			'dueDate'           => '2026-06-15',
			'invoiceLines'      => [
				['lineId' => '1', 'quantity' => 2, 'unitCode' => 'C62', 'netAmount' => 60.00, 'itemName' => 'Stoel', 'netPrice' => 30.00, 'vatCategory' => 'S', 'vatRate' => 21],
				['lineId' => '2', 'quantity' => 1, 'unitCode' => 'C62', 'netAmount' => 40.00, 'itemName' => 'Tafel', 'netPrice' => 40.00, 'vatCategory' => 'S', 'vatRate' => 21],
			],
			'allowances'        => [['amount' => 10.00, 'baseAmount' => 100.00, 'vatCategory' => 'S', 'vatRate' => 21]],
			'charges'           => [['amount' => 5.00, 'baseAmount' => 100.00, 'vatCategory' => 'S', 'vatRate' => 21]],
			'vatBreakdown'      => [['category' => 'S', 'rate' => 21, 'taxableAmount' => 95.00, 'taxAmount' => 19.95]],
		];
	}//end invoice()

	/**
	 * The invoice moved wholly into one untaxed VAT category.
	 *
	 * @param string  $category The VAT category code.
	 * @param boolean $reason   Whether the breakdown states an exemption reason.
	 *
	 * @return array<string, mixed>
	 */
	private static function untaxed(string $category, bool $reason=true): array {
		$o    = self::invoice();
		$rate = $category === 'O' ? null : 0;
		foreach (['invoiceLines', 'allowances', 'charges'] as $key) {
			foreach ($o[$key] as $i => $item) {
				$o[$key][$i]['vatCategory'] = $category;
				unset($o[$key][$i]['vatRate']);
				if ($rate !== null) {
					$o[$key][$i]['vatRate'] = $rate;
				}
			}
		}

		$group = ['category' => $category, 'taxableAmount' => 95.00, 'taxAmount' => 0];
		if ($rate !== null) {
			$group['rate'] = $rate;
		}

		if ($reason === true) {
			$group['exemptionReasonText'] = 'Vrijgesteld';
		}

		$o['vatBreakdown'] = [$group];
		$o['vatAmount']    = 0;
		$o['grossAmount']  = 95.00;
		$o['amountDue']    = 95.00;
		if ($category === 'O') {
			unset($o['sellerVatId'], $o['buyerVatId']);
			$o['sellerIdentifier'] = 'KVK12345678';
		}

		return $o;
	}//end untaxed()

	/**
	 * The ids of the rules an invoice fails, in registry order.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return list<string>
	 */
	private static function failing(array $invoice): array {
		$failing = [];
		foreach (InvoicingTailChecks::checks()['ARInvoice'] as $ruleId => $check) {
			if ($check($invoice, ['jurisdiction' => 'NL']) !== true) {
				$failing[] = $ruleId;
			}
		}

		return $failing;
	}//end failing()

	/**
	 * The complete invoice passes every rule.
	 *
	 * @return void
	 */
	public function testACompleteInvoicePassesEveryRule(): void {
		$this->assertSame([], self::failing(self::invoice()));
		$this->assertGreaterThan(100, count(InvoicingTailChecks::checks()['ARInvoice']));
	}//end testACompleteInvoicePassesEveryRule()

	/**
	 * An invoice in each untaxed category, with its reason, passes every rule.
	 *
	 * @return void
	 */
	public function testEachUntaxedCategoryPasses(): void {
		foreach (['E', 'AE', 'K', 'G'] as $category) {
			$this->assertSame([], self::failing(self::untaxed($category)), $category);
		}

		$this->assertSame([], self::failing(self::untaxed('Z', reason: false)));
	}//end testEachUntaxedCategoryPasses()

	/**
	 * A not-subject invoice satisfies the O rules but cannot also carry the
	 * supplier VAT id art. 226(3) asks for: BR-O-02 forbids it. Pinned as observed.
	 *
	 * @return void
	 */
	public function testANotSubjectInvoiceOnlyFailsTheSupplierVatIdRule(): void {
		$this->assertSame(['vatdir-art226-3'], self::failing(self::untaxed('O')));
	}//end testANotSubjectInvoiceOnlyFailsTheSupplierVatIdRule()

	/**
	 * Each defect and the exact rules it fails.
	 *
	 * @return array<string, array{\Closure, list<string>}>
	 */
	public static function defects(): array {
		$line = static function (int $i, array $fields): \Closure {
			return static function (array $o) use ($i, $fields): array {
				$o['invoiceLines'][$i] = array_merge($o['invoiceLines'][$i], $fields);
				return $o;
			};
		};
		$with    = static fn (array $fields): \Closure => static fn (array $o): array => array_merge($o, $fields);
		$without = static fn (string $key): \Closure => static function (array $o) use ($key): array {
			unset($o[$key]);
			return $o;
		};

		return [
			'no invoice number'              => [$without('invoiceNumber'), ['en16931-br-02', 'vatdir-art226-2']],
			'blank buyer name'               => [$with(['buyerName' => '  ']), ['en16931-br-07', 'vatdir-art226-5']],
			'non-numeric gross amount'       => [$with(['grossAmount' => 'n/a']), ['en16931-br-14']],
			'no lines'                       => [$with(['invoiceLines' => []]), ['en16931-br-16', 'en16931-br-21', 'en16931-br-22', 'en16931-br-23', 'en16931-br-24', 'en16931-br-25', 'en16931-br-26', 'en16931-br-co-04', 'en16931-br-co-10', 'en16931-br-s-08', 'vatdir-art226-8']],
			'a line without unit'            => [$line(1, ['unitCode' => '']), ['en16931-br-23']],
			'line amounts off the total'     => [$line(0, ['netAmount' => 61.00]), ['en16931-br-co-10', 'en16931-br-s-08']],
			'allowance total off'            => [$with(['allowancesTotal' => 11.00, 'netAmount' => 94.00]), ['en16931-br-co-11']],
			'VAT total off'                  => [$with(['vatAmount' => 20.00]), ['en16931-br-co-14']],
			'no VAT breakdown'               => [$with(['vatBreakdown' => []]), ['en16931-br-45', 'en16931-br-46', 'en16931-br-47', 'en16931-br-48', 'en16931-br-co-14', 'en16931-br-co-17', 'en16931-br-co-18', 'vatdir-art226-8', 'vatdir-art226-9']],
			'tax point date and code'        => [$with(['taxPointDate' => '2026-05-16', 'taxPointDateCode' => '3']), ['en16931-br-co-03']],
			'buyer VAT id without EU prefix' => [$with(['buyerVatId' => 'US123']), ['en16931-br-co-09']],
			'amount due without terms'       => [$without('dueDate'), ['en16931-br-co-25']],
			'seller without any identifier'  => [$without('sellerVatId'), ['en16931-br-co-26', 'en16931-br-s-02', 'en16931-br-s-03', 'en16931-br-s-04', 'vatdir-art226-3']],
			'three decimals on net amount'   => [$with(['netAmount' => 95.001]), ['en16931-br-dec-12']],
			'full card number'               => [$with(['cardPan' => '4111111111111111']), ['en16931-br-51']],
			'item id without scheme'         => [$line(0, ['itemStandardId' => '8712345678906']), ['en16931-br-64']],
			'classification without scheme' => [$line(1, ['itemClassificationId' => '43211503']), ['en16931-br-65']],
			'classification with scheme'     => [$line(1, ['itemClassificationId' => '43211503', 'itemClassificationIdScheme' => 'STI']), []],
			'currency not written as listed' => [$with(['currency' => 'eur']), ['en16931-br-cl-03']],
			'standard line at zero rate'     => [$line(0, ['vatRate' => 0]), ['en16931-br-s-05']],
			'line allowance three decimals'  => [$line(0, ['lineAllowances' => [['amount' => 1.005, 'baseAmount' => 10]]]), ['en16931-br-dec-24']],
			'standard charge without rate'   => [
				static function (array $o): array {
					unset($o['charges'][0]['vatRate']);
					return $o;
				},
				['en16931-br-s-07'],
			],
			'breakdown tax not rate x base'  => [
				static function (array $o): array {
					$o['vatBreakdown'][0]['taxAmount'] = 19.00;
					$o['vatAmount'] = 19.00;
					return $o;
				},
				['en16931-br-co-17', 'en16931-br-s-09'],
			],
			'standard group with a reason'   => [
				static function (array $o): array {
					$o['vatBreakdown'][0]['exemptionReasonCode'] = 'VATEX-EU-79-C';
					return $o;
				},
				['en16931-br-s-10'],
			],
		];
	}//end defects()

	/**
	 * A defect fails exactly the rules that name it.
	 *
	 * @param \Closure     $defect   Turns the complete invoice into the defective one.
	 * @param list<string> $expected The rules it fails.
	 *
	 * @return void
	 */
	#[DataProvider('defects')]
	public function testADefectFailsExactlyItsRules(\Closure $defect, array $expected): void {
		$this->assertSame($expected, self::failing($defect(self::invoice())));
	}//end testADefectFailsExactlyItsRules()

	/**
	 * Defects in the untaxed categories fail exactly their category's rules.
	 *
	 * @return void
	 */
	public function testUntaxedCategoryDefects(): void {
		$z = self::untaxed('Z', reason: true);
		$this->assertSame(['en16931-br-z-10'], self::failing($z));

		$e = self::untaxed('E', reason: false);
		$this->assertSame(['en16931-br-e-10'], self::failing($e));

		$ae = self::untaxed('AE');
		unset($ae['buyerVatId']);
		$this->assertSame(['en16931-br-ae-02'], self::failing($ae));

		$k = self::untaxed('K');
		unset($k['buyerVatId']);
		$this->assertSame(['en16931-br-ic-02'], self::failing($k));

		$k = self::untaxed('K');
		$k['vatBreakdown'][0]['taxAmount'] = 1;
		$k['vatAmount'] = 1;
		$this->assertSame(['en16931-br-co-17', 'en16931-br-ic-09'], self::failing($k));

		$g = self::untaxed('G');
		$g['invoiceLines'][0]['vatRate'] = 21;
		$this->assertSame(['en16931-br-g-05'], self::failing($g));

		$e = self::untaxed('E');
		$e['allowances'][0]['vatRate'] = 9;
		$this->assertSame(['en16931-br-e-06'], self::failing($e));

		$o = self::untaxed('O');
		$o['invoiceLines'][0]['vatRate'] = 0;
		$this->assertSame(['en16931-br-o-05', 'vatdir-art226-3'], self::failing($o));

		$o = self::untaxed('O');
		$o['sellerVatId'] = 'NL123456789B01';
		$this->assertSame(['en16931-br-o-02', 'en16931-br-o-03', 'en16931-br-o-04'], self::failing($o));
	}//end testUntaxedCategoryDefects()
}//end class
