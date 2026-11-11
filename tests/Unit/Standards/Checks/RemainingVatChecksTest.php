<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\RemainingVatChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the deadline, category and recapitulative-statement branches of the
 * remaining VAT Directive checks.
 *
 * @covers \OCA\Shillinq\Standards\Checks\RemainingVatChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class RemainingVatChecksTest extends TestCase {

	/**
	 * Objects, the rule they are checked against and the expected verdict.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
	 */
	public static function cases(): array {
		$ics = ['vatBreakdown' => [['category' => 'K']]];
		$icsLine = ['invoiceLines' => ['x', ['vatCategory' => 'S'], ['vatCategory' => 'K']]];
		$recap = [
			'intraCommunitySupplies' => 8000.0,
			'recapitulativeSubmitted' => true,
			'recapLines' => [
				['customerVatId' => 'DE123456789', 'value' => 5000.0],
				['customerVatId' => 'BE0123456789', 'value' => 3000.0],
			],
		];
		$ica = ['intraCommunityAcquisition' => true];
		return [
			'art222 no K category' => ['ARInvoice', 'vatdir-art222-invoice-deadline-ics', ['vatBreakdown' => [['category' => 'S'], 'x'], 'invoiceLines' => 'none'], true],
			'art222 K in breakdown, issued in time' => ['ARInvoice', 'vatdir-art222-invoice-deadline-ics', $ics + ['supplyDate' => '2026-01-20', 'invoiceDate' => '2026-02-15'], true],
			'art222 K in breakdown, issued late' => ['ARInvoice', 'vatdir-art222-invoice-deadline-ics', $ics + ['supplyDate' => '2026-01-20', 'invoiceDate' => '2026-02-16'], false],
			'art222 K on a line, tax point fallback' => ['ARInvoice', 'vatdir-art222-invoice-deadline-ics', $icsLine + ['taxPointDate' => '2026-03-01', 'invoiceDate' => '2026-04-15'], true],
			'art222 K, invoice date anchors itself' => ['ARInvoice', 'vatdir-art222-invoice-deadline-ics', $ics + ['invoiceDate' => '2026-03-31'], true],
			'art222 K, no issue date' => ['ARInvoice', 'vatdir-art222-invoice-deadline-ics', $ics + ['supplyDate' => '2026-01-20'], false],
			'art222 K, unparsable issue date' => ['ARInvoice', 'vatdir-art222-invoice-deadline-ics', $ics + ['supplyDate' => '2026-01-20', 'invoiceDate' => 'not a date'], false],
			'art222 K, unparsable supply date' => ['ARInvoice', 'vatdir-art222-invoice-deadline-ics', $ics + ['supplyDate' => 'never', 'invoiceDate' => '2026-01-20'], false],
			'art68 not an acquisition' => ['APTransaction', 'vatdir-art68-tax-point-ica', [], true],
			'art68 invoiced in time' => ['APTransaction', 'vatdir-art68-tax-point-ica', $ica + ['supplyDate' => '2026-05-31', 'invoiceDate' => '2026-06-15'], true],
			'art68 invoiced late' => ['APTransaction', 'vatdir-art68-tax-point-ica', $ica + ['supplyDate' => '2026-05-31', 'invoiceDate' => '2026-06-16'], false],
			'art68 invoice date anchors itself' => ['APTransaction', 'vatdir-art68-tax-point-ica', $ica + ['invoiceDate' => '2026-06-16'], true],
			'art68 no invoice date' => ['APTransaction', 'vatdir-art68-tax-point-ica', $ica + ['supplyDate' => '2026-05-31'], false],
			'art68 unparsable supply date' => ['APTransaction', 'vatdir-art68-tax-point-ica', $ica + ['supplyDate' => 'soon', 'invoiceDate' => '2026-06-01'], false],
			'art262 no supplies' => ['VatReturnFiling', 'vatdir-art262-recapitulative-statement', [], true],
			'art262 compliant statement' => ['VatReturnFiling', 'vatdir-art262-recapitulative-statement', $recap, true],
			'art262 no lines' => ['VatReturnFiling', 'vatdir-art262-recapitulative-statement', ['recapLines' => []] + $recap, false],
			'art262 lines not a list' => ['VatReturnFiling', 'vatdir-art262-recapitulative-statement', ['recapLines' => 'x'] + $recap, false],
			'art262 line not an array' => ['VatReturnFiling', 'vatdir-art262-recapitulative-statement', ['recapLines' => ['x']] + $recap, false],
			'art262 line with a bad VAT id' => ['VatReturnFiling', 'vatdir-art262-recapitulative-statement', ['recapLines' => [['customerVatId' => '12', 'value' => 1.0]]] + $recap, false],
			'art262 line without a value' => ['VatReturnFiling', 'vatdir-art262-recapitulative-statement', ['recapLines' => [['customerVatId' => 'DE123456789', 'value' => 'n/a']]] + $recap, false],
			'art262 not submitted' => ['VatReturnFiling', 'vatdir-art262-recapitulative-statement', ['recapitulativeSubmitted' => false] + $recap, false],
			'art264 reconciling statement' => ['VatReturnFiling', 'vatdir-art264-recapitulative-content', $recap, true],
			'art264 statement off by more than a cent' => ['VatReturnFiling', 'vatdir-art264-recapitulative-content', ['intraCommunitySupplies' => 8000.5] + $recap, false],
			'art264 statement with a bad line' => ['VatReturnFiling', 'vatdir-art264-recapitulative-content', ['recapLines' => [['customerVatId' => 'DE123456789']]] + $recap, false],
			'art138 reconciling statement' => ['VatReturnFiling', 'vatdir-art138-recap-condition', $recap, true],
			'art252 deadline within two months' => ['VatReturnFiling', 'vatdir-art252-return-period', ['periodEndDate' => '2026-03-31', 'filingDeadline' => '2026-05-31'], true],
			'art252 deadline too late' => ['VatReturnFiling', 'vatdir-art252-return-period', ['periodEndDate' => '2026-03-31', 'filingDeadline' => '2026-06-01'], false],
			'art252 deadline before period end' => ['VatReturnFiling', 'vatdir-art252-return-period', ['periodEndDate' => '2026-03-31', 'filingDeadline' => '2026-03-30'], false],
			'art263 submitted within a month' => ['VatReturnFiling', 'vatdir-art263-recapitulative-period', ['recapitulativeSubmitted' => true, 'recapPeriodEndDate' => '2026-06-30', 'recapSubmittedDate' => '2026-07-30'], true],
			'art263 submitted late' => ['VatReturnFiling', 'vatdir-art263-recapitulative-period', ['recapitulativeSubmitted' => true, 'recapPeriodEndDate' => '2026-06-30', 'recapSubmittedDate' => '2026-08-01'], false],
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
		$check = RemainingVatChecks::checks()[$type][$ruleId];
		$this->assertSame($expected, $check($object, ['jurisdiction' => 'NL']));

	}//end testVerdict()

	/**
	 * The registry keeps its object types and rule order.
	 *
	 * @return void
	 */
	public function testRegistryOrder(): void {
		$checks = RemainingVatChecks::checks();
		$this->assertSame(['ARInvoice', 'APTransaction', 'VatReturnFiling'], array_keys($checks));
		$this->assertSame('vatdir-art31-pos-goods-no-transport', array_key_first($checks['ARInvoice']));
		$this->assertSame('vatdir-art90-bad-debt-relief', array_key_last($checks['ARInvoice']));
		$this->assertCount(14, $checks['ARInvoice']);
		$this->assertCount(5, $checks['APTransaction']);
		$this->assertCount(6, $checks['VatReturnFiling']);

	}//end testRegistryOrder()

	/**
	 * The seed data is unchanged.
	 *
	 * @return void
	 */
	public function testSeedData(): void {
		$this->assertSame('5db55b30', substr(md5(serialize([RemainingVatChecks::seedSpec(), RemainingVatChecks::seedObjects()])), 0, 8));

	}//end testSeedData()
}//end class
