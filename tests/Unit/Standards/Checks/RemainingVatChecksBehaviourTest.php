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
 * Pins the place-of-supply, tax-point, intra-Community acquisition,
 * deduction and return branches of the remaining VAT Directive checks.
 *
 * @covers \OCA\Shillinq\Standards\Checks\RemainingVatChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class RemainingVatChecksBehaviourTest extends TestCase {

	/**
	 * Objects, the rule they are checked against and the expected verdict.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
	 */
	public static function cases(): array {
		$ar = 'ARInvoice';
		$ap = 'APTransaction';
		$rf = 'VatReturnFiling';
		$goods = ['supplyType' => 'Goods', 'placeOfSupplyCountry' => 'NL'];
		$svc = ['supplyType' => 'service', 'placeOfSupplyCountry' => 'NL'];
		$ica = ['intraCommunityAcquisition' => 'yes'];
		$ded = ['deductible' => true];
		return [
			// art. 31 — goods not transported.
			'art31 not goods' => [$ar, 'vatdir-art31-pos-goods-no-transport', ['supplyType' => 'service'], true],
			'art31 transported goods skip' => [$ar, 'vatdir-art31-pos-goods-no-transport', $goods + ['goodsTransported' => true], true],
			'art31 location matches' => [$ar, 'vatdir-art31-pos-goods-no-transport', $goods + ['goodsLocationCountry' => 'nl-NH'], true],
			'art31 location differs' => [$ar, 'vatdir-art31-pos-goods-no-transport', $goods + ['goodsLocationCountry' => 'DE'], false],
			'art31 no stated place' => [$ar, 'vatdir-art31-pos-goods-no-transport', ['supplyType' => 'goods', 'goodsLocationCountry' => ''], false],
			// art. 32 — goods transported.
			'art32 not transported' => [$ar, 'vatdir-art32-pos-goods-with-transport', $goods + ['goodsTransported' => 'no'], true],
			'art32 origin matches' => [$ar, 'vatdir-art32-pos-goods-with-transport', $goods + ['goodsTransported' => '1', 'dispatchOriginCountry' => 'NL'], true],
			'art32 origin differs' => [$ar, 'vatdir-art32-pos-goods-with-transport', $goods + ['goodsTransported' => 'TRUE', 'dispatchOriginCountry' => 'BE'], false],
			// art. 44 / 45 — general services.
			'art44 not a service' => [$ar, 'vatdir-art44-pos-b2b-general', $goods, true],
			'art44 b2c skips' => [$ar, 'vatdir-art44-pos-b2b-general', $svc + ['customerType' => 'consumer'], true],
			'art44 b2b default, buyer matches' => [$ar, 'vatdir-art44-pos-b2b-general', $svc + ['buyerCountryCode' => 'NL'], true],
			'art44 b2b, buyer differs' => [$ar, 'vatdir-art44-pos-b2b-general', $svc + ['customerType' => ' Business ', 'buyerCountryCode' => 'FR'], false],
			'art45 b2b skips' => [$ar, 'vatdir-art45-pos-b2c-general', $svc, true],
			'art45 b2c, seller matches' => [$ar, 'vatdir-art45-pos-b2c-general', $svc + ['customerType' => 'consumer', 'sellerCountryCode' => 'NL'], true],
			'art45 b2c, seller differs' => [$ar, 'vatdir-art45-pos-b2c-general', $svc + ['customerType' => 'consumer', 'sellerCountryCode' => 'DE'], false],
			// art. 47 — immovable property.
			'art47 not immovable' => [$ar, 'vatdir-art47-pos-immovable-property', $svc, true],
			'art47 property matches' => [$ar, 'vatdir-art47-pos-immovable-property', ['supplyType' => 'immovable', 'placeOfSupplyCountry' => 'ES', 'propertyCountry' => 'ES'], true],
			'art47 property differs' => [$ar, 'vatdir-art47-pos-immovable-property', ['supplyType' => 'immovable', 'placeOfSupplyCountry' => 'NL', 'propertyCountry' => 'ES'], false],
			// art. 53 / 54 — events.
			'art53 not admission' => [$ar, 'vatdir-art53-pos-event-admission-b2b', $svc, true],
			'art53 b2c skips' => [$ar, 'vatdir-art53-pos-event-admission-b2b', ['supplyType' => 'admission', 'customerType' => 'consumer'], true],
			'art53 event matches' => [$ar, 'vatdir-art53-pos-event-admission-b2b', ['supplyType' => 'admission', 'placeOfSupplyCountry' => 'IT', 'eventCountry' => 'IT'], true],
			'art53 event differs' => [$ar, 'vatdir-art53-pos-event-admission-b2b', ['supplyType' => 'admission', 'placeOfSupplyCountry' => 'NL', 'eventCountry' => 'IT'], false],
			'art54 not event' => [$ar, 'vatdir-art54-pos-cultural-activities-b2c', $svc, true],
			'art54 b2b skips' => [$ar, 'vatdir-art54-pos-cultural-activities-b2c', ['supplyType' => 'event'], true],
			'art54 b2c event matches' => [$ar, 'vatdir-art54-pos-cultural-activities-b2c', ['supplyType' => 'event', 'customerType' => 'consumer', 'placeOfSupplyCountry' => 'AT', 'eventCountry' => 'AT'], true],
			'art54 b2c event differs' => [$ar, 'vatdir-art54-pos-cultural-activities-b2c', ['supplyType' => 'event', 'customerType' => 'consumer', 'placeOfSupplyCountry' => 'NL', 'eventCountry' => 'AT'], false],
			// art. 55 / 56 — physically performed services.
			'art55 not restaurant' => [$ar, 'vatdir-art55-pos-restaurant-catering', $svc, true],
			'art55 service country matches' => [$ar, 'vatdir-art55-pos-restaurant-catering', ['supplyType' => 'restaurant', 'placeOfSupplyCountry' => 'BE', 'serviceCountry' => 'BE'], true],
			'art55 service country differs' => [$ar, 'vatdir-art55-pos-restaurant-catering', ['supplyType' => 'restaurant', 'placeOfSupplyCountry' => 'BE', 'serviceCountry' => 'NL'], false],
			'art56 not hire' => [$ar, 'vatdir-art56-pos-hire-means-transport', $svc, true],
			'art56 service country matches' => [$ar, 'vatdir-art56-pos-hire-means-transport', ['supplyType' => 'transport-hire', 'placeOfSupplyCountry' => 'DE', 'serviceCountry' => 'DE'], true],
			'art56 service country differs' => [$ar, 'vatdir-art56-pos-hire-means-transport', ['supplyType' => 'transport-hire', 'placeOfSupplyCountry' => 'DE', 'serviceCountry' => 'PL'], false],
			// art. 58 — TBE to consumers.
			'art58 not tbe' => [$ar, 'vatdir-art58-pos-tbe-b2c', $svc, true],
			'art58 b2b skips' => [$ar, 'vatdir-art58-pos-tbe-b2c', ['supplyType' => 'tbe', 'buyerCountryCode' => 'FR'], true],
			'art58 b2c buyer matches' => [$ar, 'vatdir-art58-pos-tbe-b2c', ['supplyType' => 'tbe', 'customerType' => 'consumer', 'placeOfSupplyCountry' => 'FR', 'buyerCountryCode' => 'FR'], true],
			'art58 b2c buyer differs' => [$ar, 'vatdir-art58-pos-tbe-b2c', ['supplyType' => 'tbe', 'customerType' => 'consumer', 'placeOfSupplyCountry' => 'NL', 'buyerCountryCode' => 'FR'], false],
			// art. 64 — continuous supplies.
			'art64 not continuous' => [$ar, 'vatdir-art64-tax-point-continuous', [], true],
			'art64 tax point on period end' => [$ar, 'vatdir-art64-tax-point-continuous', ['continuousSupply' => true, 'periodEndDate' => '2026-03-31', 'taxPointDate' => '2026-03-31'], true],
			'art64 tax point before period end' => [$ar, 'vatdir-art64-tax-point-continuous', ['continuousSupply' => true, 'periodEndDate' => '2026-03-31', 'taxPointDate' => '2026-03-30'], false],
			'art64 no period end' => [$ar, 'vatdir-art64-tax-point-continuous', ['continuousSupply' => true, 'taxPointDate' => '2026-03-31'], false],
			'art64 no tax point at all' => [$ar, 'vatdir-art64-tax-point-continuous', ['continuousSupply' => true, 'periodEndDate' => '2026-03-31', 'taxPointDate' => ' '], false],
			'art64 unparsable tax point' => [$ar, 'vatdir-art64-tax-point-continuous', ['continuousSupply' => true, 'periodEndDate' => '2026-03-31', 'taxPointDate' => 'garbage'], false],
			'art64 supply date falls back' => [$ar, 'vatdir-art64-tax-point-continuous', ['continuousSupply' => true, 'periodEndDate' => '2026-03-31', 'supplyDate' => '2026-04-01'], true],
			// art. 65 — prepayments.
			'art65 no prepayment' => [$ar, 'vatdir-art65-tax-point-prepayment', ['prepaymentAmount' => 'n/a'], true],
			'art65 zero prepayment' => [$ar, 'vatdir-art65-tax-point-prepayment', ['prepaymentAmount' => 0], true],
			'art65 tax point after receipt' => [$ar, 'vatdir-art65-tax-point-prepayment', ['prepaymentAmount' => 100, 'prepaymentReceiptDate' => '2026-02-01', 'invoiceDate' => '2026-02-02'], true],
			'art65 tax point before receipt' => [$ar, 'vatdir-art65-tax-point-prepayment', ['prepaymentAmount' => 100, 'prepaymentReceiptDate' => '2026-02-01', 'invoiceDate' => '2026-01-31'], false],
			'art65 no receipt date' => [$ar, 'vatdir-art65-tax-point-prepayment', ['prepaymentAmount' => 100, 'invoiceDate' => '2026-01-31'], false],
			// art. 90 — reductions.
			'art90 no reduction' => [$ar, 'vatdir-art90-bad-debt-relief', [], true],
			'art90 reducing document' => [$ar, 'vatdir-art90-bad-debt-relief', ['taxableAmountReduction' => 50, 'netAmount' => -50], true],
			'art90 reduction without net amount' => [$ar, 'vatdir-art90-bad-debt-relief', ['taxableAmountReduction' => '0'], true],
			'art90 negative reduction' => [$ar, 'vatdir-art90-bad-debt-relief', ['taxableAmountReduction' => -1, 'netAmount' => -1], false],
			'art90 positive net amount' => [$ar, 'vatdir-art90-bad-debt-relief', ['taxableAmountReduction' => 50, 'netAmount' => 10], false],
			// art. 40 / 41 — ICA place and safety net.
			'art40 not an acquisition' => [$ap, 'vatdir-art40-ica-place', [], true],
			'art40 arrival matches' => [$ap, 'vatdir-art40-ica-place', $ica + ['acquisitionPlaceCountry' => 'NL', 'arrivalCountry' => 'nl'], true],
			'art40 arrival differs' => [$ap, 'vatdir-art40-ica-place', $ica + ['acquisitionPlaceCountry' => 'NL', 'arrivalCountry' => 'DE'], false],
			'art40 no countries' => [$ap, 'vatdir-art40-ica-place', $ica, false],
			'art41 not an acquisition' => [$ap, 'vatdir-art41-ica-safety-net', [], true],
			'art41 valid VAT id used' => [$ap, 'vatdir-art41-ica-safety-net', $ica + ['acquirerVatIdUsed' => 'nl 8188.82.763.B01'], true],
			'art41 taxation at arrival proven' => [$ap, 'vatdir-art41-ica-safety-net', $ica + ['acquirerVatIdUsed' => 'US123456', 'taxedAtArrivalProven' => true], true],
			'art41 non-EU prefix' => [$ap, 'vatdir-art41-ica-safety-net', $ica + ['acquirerVatIdUsed' => 'US123456'], false],
			'art41 too short' => [$ap, 'vatdir-art41-ica-safety-net', $ica + ['acquirerVatIdUsed' => 'NL1'], false],
			'art41 lowercase id is normalised' => [$ap, 'vatdir-art41-ica-safety-net', $ica + ['acquirerVatIdUsed' => 'de 12'], true],
			// art. 167 / 178 — deduction.
			'art167 not deductible' => [$ap, 'vatdir-art167-deduction-arises', [], true],
			'art167 dated with tax' => [$ap, 'vatdir-art167-deduction-arises', $ded + ['invoiceDate' => '2026-01-10', 'taxAmount' => '21.00'], true],
			'art167 zero tax allowed' => [$ap, 'vatdir-art167-deduction-arises', $ded + ['invoiceDate' => '2026-01-10', 'taxAmount' => 0], true],
			'art167 negative tax' => [$ap, 'vatdir-art167-deduction-arises', $ded + ['invoiceDate' => '2026-01-10', 'taxAmount' => -1], false],
			'art167 no tax amount' => [$ap, 'vatdir-art167-deduction-arises', $ded + ['invoiceDate' => '2026-01-10'], false],
			'art167 no invoice date' => [$ap, 'vatdir-art167-deduction-arises', $ded + ['taxAmount' => 5], false],
			'art178 not deductible' => [$ap, 'vatdir-art178-deduction-invoice-condition', [], true],
			'art178 invoice number' => [$ap, 'vatdir-art178-deduction-invoice-condition', $ded + ['invoiceNumber' => 'INV-1'], true],
			'art178 invoice reference' => [$ap, 'vatdir-art178-deduction-invoice-condition', $ded + ['invoiceNumber' => ' ', 'invoiceReference' => 'REF-9'], true],
			'art178 no invoice' => [$ap, 'vatdir-art178-deduction-invoice-condition', $ded, false],
			// art. 250 — return reconciles.
			'art250 reconciles' => [$rf, 'vatdir-art250-vat-return', ['outputVat' => 2100.004, 'deductibleVat' => 600, 'amount' => '1500.00'], true],
			'art250 off by a cent' => [$rf, 'vatdir-art250-vat-return', ['outputVat' => 2100, 'deductibleVat' => 600, 'amount' => 1500.01], false],
			'art250 no output VAT' => [$rf, 'vatdir-art250-vat-return', ['deductibleVat' => 600, 'amount' => 1500], false],
			'art250 no deductible VAT' => [$rf, 'vatdir-art250-vat-return', ['outputVat' => 2100, 'amount' => 1500], false],
			'art250 no amount' => [$rf, 'vatdir-art250-vat-return', ['outputVat' => 2100, 'deductibleVat' => 600], false],
			// art. 252 / 263 / 264 — remaining branches.
			'art252 no period end' => [$rf, 'vatdir-art252-return-period', ['filingDeadline' => '2026-05-31'], false],
			'art263 not submitted' => [$rf, 'vatdir-art263-recapitulative-period', [], true],
			'art263 submitted before period end' => [$rf, 'vatdir-art263-recapitulative-period', ['recapitulativeSubmitted' => true, 'recapPeriodEndDate' => '2026-06-30', 'recapSubmittedDate' => '2026-06-29'], false],
			'art263 no submitted date' => [$rf, 'vatdir-art263-recapitulative-period', ['recapitulativeSubmitted' => true, 'recapPeriodEndDate' => '2026-06-30'], false],
			'art264 not submitted' => [$rf, 'vatdir-art264-recapitulative-content', ['intraCommunitySupplies' => 5], true],
			'art138 no supplies' => [$rf, 'vatdir-art138-recap-condition', ['intraCommunitySupplies' => 0], true],
			'art138 supplies, not submitted' => [$rf, 'vatdir-art138-recap-condition', ['intraCommunitySupplies' => 10, 'recapLines' => [['customerVatId' => 'DE123456789', 'value' => 10]]], false],
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
}//end class
