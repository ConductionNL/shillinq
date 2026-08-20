<?php

/**
 * Remaining VAT Directive check provider
 *
 * Contributes the machine-checkable VAT-Directive (2006/112/EC) rules that the
 * VatChecks provider does not yet enforce: the place-of-supply rules (arts. 31/32
 * for goods, 44/45 general services, 47 immovable property, 53/54 events, 55
 * restaurant/catering, 56 hire of means of transport, 58 TBE B2C), the remaining
 * tax-point rules (art. 64 continuous supplies, art. 65 prepayments, art. 68 the
 * intra-Community acquisition), the bad-debt taxable-amount reduction (art. 90),
 * the deduction-right rules (arts. 167/178), the intra-Community-acquisition place
 * rules on the purchase side (arts. 40/41), the ICS invoicing deadline (art. 222),
 * and the periodic-filing obligations on a VatReturn object — the VAT return itself
 * (arts. 250/252) and the recapitulative statement / EC Sales List (arts. 262/263/
 * 264 and the art. 138 recapitulative-statement condition).
 *
 * The place-of-supply and tax-point rules map onto ARInvoice header fields that
 * record the nature of the supply (supplyType / customerType / goodsTransported)
 * and the country the directive deems the place of supply (placeOfSupplyCountry)
 * together with the operative locations (buyerCountryCode, eventCountry,
 * propertyCountry, serviceCountry). Each rule is conditioned on the supply nature
 * it governs and is vacuously satisfied for supplies it does not govern, so it
 * enforces real data when present without flagging unrelated invoices — mirroring
 * the conditional style of VatChecks. The VAT-return / recapitulative-statement
 * rules target the VatReturn object type; as that type has no rows in the test
 * register this provider also implements SeedsObjects and supplies compliant
 * sample returns so the audit actually evaluates the filing checks.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule
 * is satisfied — and is side-effect free and self-contained (it inlines its logic
 * via the private static helpers on this class rather than reaching into
 * RuleEngine). Every ruleId is a real `machineCheckable` id from
 * lib/Standards/rules/vat.json. Ids already enforced by VatChecks (arts. 63/73/78/
 * 79/93/96/97/98/138/146/196/220/226-11a, VIES, rounding) and by InvoicingExtraChecks
 * are intentionally not re-registered here. The OSS/IOSS and margin-scheme filing
 * rules are intentionally skipped: shillinq does not model a One-Stop-Shop return
 * nor a margin-scheme ledger, so a meaningful predicate cannot be written.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.Commenting.InlineComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Executable remaining VAT-Directive checks over ARInvoice / APTransaction content
 * and the periodic VatReturn / recapitulative-statement object.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): this class enumerates every remaining
 * VAT-Directive rule the catalogue defines; inherent complexity.
 * Variable renames deferred pending a dedicated pass.
 */
class RemainingVatChecks implements CheckProvider, SeedsObjects {
	/**
	 * Per-object-type, per-rule predicates for the remaining `vat` domain rules.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'ARInvoice' => [
				// --- Place of supply of goods (arts. 31/32). ---
				// art. 31: where goods are NOT dispatched/transported, the place of
				// supply is where the goods are located at supply — the stated place
				// of supply must equal that goods location.
				'vatdir-art31-pos-goods-no-transport' => static fn (array $o): bool => (self::isSupply($o, 'goods') === false
					|| self::truthy($o, 'goodsTransported') === true
					|| self::placeEquals($o, 'goodsLocationCountry')),
				// art. 32: where goods ARE dispatched/transported, the place of supply
				// is where transport to the customer begins — the stated place of
				// supply must equal the dispatch-origin country.
				'vatdir-art32-pos-goods-with-transport' => static fn (array $o): bool => (self::isSupply($o, 'goods') === false
					|| self::truthy($o, 'goodsTransported') === false
					|| self::placeEquals($o, 'dispatchOriginCountry')),

				// --- Place of supply of services (arts. 44/45). ---
				// art. 44: B2B general services are supplied where the customer is
				// established — place of supply equals the buyer country.
				'vatdir-art44-pos-b2b-general' => static fn (array $o): bool => (self::isGeneralService($o) === false
					|| self::isBusinessCustomer($o) === false
					|| self::placeEquals($o, 'buyerCountryCode')),
				// art. 45: B2C general services are supplied where the supplier is
				// established — place of supply equals the seller country.
				'vatdir-art45-pos-b2c-general' => static fn (array $o): bool => (self::isGeneralService($o) === false
					|| self::isBusinessCustomer($o) === true
					|| self::placeEquals($o, 'sellerCountryCode')),

				// --- Place of supply of immovable-property services (art. 47). ---
				// Services connected with immovable property are supplied where the
				// property is located, for both B2B and B2C.
				'vatdir-art47-pos-immovable-property' => static fn (array $o): bool => (self::isSupply($o, 'immovable') === false
					|| self::placeEquals($o, 'propertyCountry')),

				// --- Place of supply of events (arts. 53/54). ---
				// art. 53: admission to an event for a taxable person is supplied where
				// the event actually takes place.
				'vatdir-art53-pos-event-admission-b2b' => static fn (array $o): bool => (self::isSupply($o, 'admission') === false
					|| self::isBusinessCustomer($o) === false
					|| self::placeEquals($o, 'eventCountry')),
				// art. 54: cultural/artistic/etc. activities for a non-taxable person
				// are supplied where the activities actually take place.
				'vatdir-art54-pos-cultural-activities-b2c' => static fn (array $o): bool => (self::isSupply($o, 'event') === false
					|| self::isBusinessCustomer($o) === true
					|| self::placeEquals($o, 'eventCountry')),

				// --- Place of supply of restaurant/catering (art. 55). ---
				// Restaurant and catering services are supplied where physically
				// carried out — place of supply equals the service country.
				'vatdir-art55-pos-restaurant-catering' => static fn (array $o): bool => (self::isSupply($o, 'restaurant') === false
					|| self::placeEquals($o, 'serviceCountry')),

				// --- Place of supply of short-term hire of transport (art. 56). ---
				// Short-term hire of a means of transport is supplied where the vehicle
				// is put at the customer's disposal.
				'vatdir-art56-pos-hire-means-transport' => static fn (array $o): bool => (self::isSupply($o, 'transport-hire') === false
					|| self::placeEquals($o, 'serviceCountry')),

				// --- Place of supply of TBE services to consumers (art. 58). ---
				// Telecom/broadcasting/electronic services to a non-taxable person are
				// supplied where the customer is established — place equals buyer.
				'vatdir-art58-pos-tbe-b2c' => static fn (array $o): bool => (self::isSupply($o, 'tbe') === false
					|| self::isBusinessCustomer($o) === true
					|| self::placeEquals($o, 'buyerCountryCode')),

				// --- Tax point: continuous supplies (art. 64). ---
				// Successive statements/payments are completed on expiry of the period
				// — the tax point must be stated and on/after the period end date.
				'vatdir-art64-tax-point-continuous' => static fn (array $o): bool => (self::truthy($o, 'continuousSupply') === false
					|| (self::isDate($o, 'periodEndDate') && self::taxPointOnOrAfter($o, (string)$o['periodEndDate']))),

				// --- Tax point: prepayment (art. 65). ---
				// VAT becomes chargeable on receipt of a payment on account — when a
				// prepayment is recorded its receipt date is stated and the tax point
				// is on/after that receipt (VAT chargeable from receipt).
				'vatdir-art65-tax-point-prepayment' => static fn (array $o): bool => (self::numericPresent($o, 'prepaymentAmount') === false
					|| (float)$o['prepaymentAmount'] <= 0.0
					|| (self::isDate($o, 'prepaymentReceiptDate')
					&& self::taxPointOnOrAfter($o, (string)$o['prepaymentReceiptDate']))),

				// --- ICS invoice deadline (art. 222). ---
				// For an exempt intra-Community supply ('K') the invoice must be issued
				// no later than the 15th day of the month following the supply.
				'vatdir-art222-invoice-deadline-ics' => static fn (array $o): bool => (self::anyCategory($o, 'K') === false
					|| self::issuedBy15thNextMonth($o)),

				// --- Bad-debt relief (art. 90). ---
				// On a credit note / non-payment adjustment the taxable amount is
				// reduced: the reduction is a stated non-negative number and the
				// document net amount is non-positive (a reducing document).
				'vatdir-art90-bad-debt-relief' => static fn (array $o): bool => (self::numericPresent($o, 'taxableAmountReduction') === false
					|| ((float)$o['taxableAmountReduction'] >= 0.0
					&& (float)($o['netAmount'] ?? 0) <= 0.0)),
			],
			'APTransaction' => [
				// --- Place of an intra-Community acquisition (art. 40). ---
				// The place of an ICA is where dispatch/transport to the acquirer ends
				// — the stated acquisition place must equal the arrival country.
				'vatdir-art40-ica-place' => static fn (array $o): bool => (self::truthy($o, 'intraCommunityAcquisition') === false
					|| self::sameCountry((string)($o['acquisitionPlaceCountry'] ?? ''), (string)($o['arrivalCountry'] ?? ''))),
				// --- ICA safety-net (art. 41). ---
				// An ICA is also taxed in the Member State that issued the VAT number
				// used, unless taxation at arrival is proven — require the acquirer VAT
				// id used (so the safety-net Member State is identifiable) OR proof of
				// taxation at arrival.
				'vatdir-art41-ica-safety-net' => static fn (array $o): bool => (self::truthy($o, 'intraCommunityAcquisition') === false
					|| self::vatIdShapeOk((string)($o['acquirerVatIdUsed'] ?? ''))
					|| self::truthy($o, 'taxedAtArrivalProven') === true),
				// --- ICA tax point (art. 68). ---
				// VAT becomes chargeable on issue of the invoice, at the latest the
				// 15th of the month following the chargeable event — the received
				// invoice's date is stated and on/before that statutory backstop.
				'vatdir-art68-tax-point-ica' => static fn (array $o): bool => (self::truthy($o, 'intraCommunityAcquisition') === false
					|| (self::isDate($o, 'invoiceDate') && self::icaTaxPointOk($o))),
				// --- Right of deduction arises (art. 167). ---
				// The deduction right arises when the deductible VAT becomes chargeable
				// — a deductible purchase records a tax point (invoice date) and a
				// non-negative deductible VAT amount.
				'vatdir-art167-deduction-arises' => static fn (array $o): bool => (self::truthy($o, 'deductible') === false
					|| (self::isDate($o, 'invoiceDate') && self::numericPresent($o, 'taxAmount')
					&& (float)$o['taxAmount'] >= 0.0)),
				// --- Deduction invoice condition (art. 178). ---
				// To exercise the deduction right the taxable person must hold an
				// invoice — a deductible purchase carries an invoice reference number.
				'vatdir-art178-deduction-invoice-condition' => static fn (array $o): bool => (self::truthy($o, 'deductible') === false
					|| self::present($o, 'invoiceNumber') || self::present($o, 'invoiceReference')),
			],
			'VatReturnFiling' => [
				// --- VAT return content (art. 250). ---
				// The return sets out the information needed to calculate the VAT
				// chargeable and the deductions: it states the output VAT, the input
				// (deductible) VAT and the resulting net liability, which reconciles.
				'vatdir-art250-vat-return' => static fn (array $o): bool => (self::numericPresent($o, 'outputVat')
					&& self::numericPresent($o, 'deductibleVat')
					&& self::numericPresent($o, 'amount')
					&& self::centsEqual((float)$o['outputVat'] - (float)$o['deductibleVat'], (float)$o['amount'])),
				// --- Return period & deadline (art. 252). ---
				// The return covers a defined tax period and is filed within the
				// statutory window: the period end is stated, the filing deadline is
				// stated, the deadline is on/after the period end and at most two
				// months after it.
				'vatdir-art252-return-period' => static fn (array $o): bool => (self::isDate($o, 'periodEndDate')
					&& self::isDate($o, 'filingDeadline')
					&& self::deadlineWithinMonths($o, 2)),
				// --- Recapitulative statement obligation (art. 262). ---
				// Where the return reports exempt intra-Community supplies or art.-44
				// reverse-charge services, a recapitulative statement (EC Sales List)
				// is submitted listing the customer lines.
				'vatdir-art262-recapitulative-statement' => static fn (array $o): bool => (self::numericOrZero($o, 'intraCommunitySupplies') <= 0.0
					|| (self::truthy($o, 'recapitulativeSubmitted') === true && self::recapHasLines($o))),
				// --- Recapitulative period & deadline (art. 263). ---
				// The statement is drawn up per calendar month and submitted within at
				// most one month — when a statement is submitted it carries a period
				// end and a submission date within one month of it.
				'vatdir-art263-recapitulative-period' => static fn (array $o): bool => (self::truthy($o, 'recapitulativeSubmitted') === false
					|| (self::isDate($o, 'recapPeriodEndDate') && self::isDate($o, 'recapSubmittedDate')
					&& self::withinMonths((string)$o['recapPeriodEndDate'], (string)$o['recapSubmittedDate'], 1))),
				// --- Recapitulative content (art. 264). ---
				// Each statement line states the customer VAT id and the total value
				// supplied; the listed total reconciles to the reported ICS value.
				'vatdir-art264-recapitulative-content' => static fn (array $o): bool => (self::truthy($o, 'recapitulativeSubmitted') === false
					|| self::recapContentOk($o)),
				// --- ICS exemption recapitulative condition (art. 138). ---
				// The ICS exemption does not apply where the recapitulative statement
				// covering the supply was not (or incorrectly) submitted: any reported
				// intra-Community supply requires a submitted, reconciling statement.
				'vatdir-art138-recap-condition' => static fn (array $o): bool => (self::numericOrZero($o, 'intraCommunitySupplies') <= 0.0
					|| (self::truthy($o, 'recapitulativeSubmitted') === true && self::recapContentOk($o))),
			],
		];

	}//end checks()

	/**
	 * Test-data field defaults this provider's checks expect on ARInvoice and
	 * APTransaction. The seeded sales invoices are modelled as domestic supplies of
	 * transported goods (supplyType 'goods'), so the place-of-supply predicates have
	 * a concrete, satisfied location (place of supply = dispatch origin = the seller
	 * country); the tax-point/bad-debt predicates stay vacuously satisfied because
	 * their trigger fields default to off. Purchase transactions default to a
	 * non-intra-Community, non-deductible posture so their conditional rules are
	 * satisfied without asserting facts the minimal corpus does not carry.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'ARInvoice' => [
				// Nature of the supply (VAT Directive title V). Default: a domestic
				// supply of goods that are transported to the customer.
				'supplyType' => 'goods',
				'customerType' => 'business',
				'goodsTransported' => true,
				// Place of supply (art. 31/32 ff.) defaulted to the seller country so
				// the goods-with-transport rule reconciles (dispatch origin = seller).
				'placeOfSupplyCountry' => 'NL',
				'sellerCountryCode' => 'NL',
				'dispatchOriginCountry' => 'NL',
				'continuousSupply' => false,
			],
			'APTransaction' => [
				// Purchase posture: a domestic, non-deductible-flagged, non-ICA
				// transaction so the ICA/deduction conditional rules are satisfied.
				'intraCommunityAcquisition' => false,
				'deductible' => false,
			],
			'VatReturnFiling' => [
				// Backfill any pre-existing VatReturn row (the seedObjects samples
				// only create when the type is empty) so art250/art252 reconcile:
				// net payable = output - deductible, filed within the period window.
				'outputVat' => 4200.00,
				'deductibleVat' => 1100.00,
				'amount' => 3100.00,
				'periodEndDate' => '2026-03-31',
				'filingDeadline' => '2026-04-30',
				'intraCommunitySupplies' => 0,
				'recapitulativeSubmitted' => false,
			],
		];

	}//end seedSpec()

	/**
	 * Compliant sample VatReturn objects, created only when the VatReturn type is
	 * empty, so the periodic-filing checks (arts. 250/252/262/263/264/138) are
	 * actually evaluated. The first return is a domestic quarter with no
	 * intra-Community supplies; the second reports an intra-Community supply and a
	 * reconciling, timely recapitulative statement.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'VatReturnFiling' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'periodType' => 'quarter',
					'periodYear' => 2026,
					'periodQuarter' => 1,
					'state' => 'submitted',
					'currency' => 'EUR',
					'periodEndDate' => '2026-03-31',
					'filingDeadline' => '2026-04-30',
					'outputVat' => 4200.00,
					'deductibleVat' => 1100.00,
					'amount' => 3100.00,
					'intraCommunitySupplies' => 0,
					'recapitulativeSubmitted' => false,
					'submittedAt' => '2026-04-25T10:00:00+00:00',
				],
				[
					'administrationId' => 'adm-shillinq-1',
					'periodType' => 'quarter',
					'periodYear' => 2026,
					'periodQuarter' => 2,
					'state' => 'submitted',
					'currency' => 'EUR',
					'periodEndDate' => '2026-06-30',
					'filingDeadline' => '2026-07-31',
					'outputVat' => 5000.00,
					'deductibleVat' => 1500.00,
					'amount' => 3500.00,
					'intraCommunitySupplies' => 8000.00,
					'recapitulativeSubmitted' => true,
					'recapPeriodEndDate' => '2026-06-30',
					'recapSubmittedDate' => '2026-07-15',
					'recapTotal' => 8000.00,
					'recapLines' => [
						['customerVatId' => 'DE123456789', 'value' => 5000.00],
						['customerVatId' => 'BE0123456789', 'value' => 3000.00],
					],
					'submittedAt' => '2026-07-20T10:00:00+00:00',
				],
			],
		];

	}//end seedObjects()

	/**
	 * A non-empty scalar value is present under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return array_key_exists($key, $o) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * A numeric (int/float/numeric-string) value is present under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function numericPresent(array $o, string $key): bool {
		return array_key_exists($key, $o) === true && is_numeric($o[$key]) === true;
	}//end numericPresent()

	/**
	 * The numeric value under $key as a float, or 0.0 when absent / non-numeric.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return float
	 */
	private static function numericOrZero(array $o, string $key): float {
		return self::numericPresent($o, $key) === true ? (float)$o[$key] : 0.0;
	}//end numericOrZero()

	/**
	 * The field is a truthy boolean flag (true / 'true' / 1 / '1').
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function truthy(array $o, string $key): bool {
		$v = ($o[$key] ?? null);
		if (is_bool($v) === true) {
			return $v;
		}

		return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes'], true);
	}//end truthy()

	/**
	 * The invoice's supplyType equals $type (case-insensitive).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $type The supply type to match.
	 *
	 * @return bool
	 */
	private static function isSupply(array $o, string $type): bool {
		return strtolower(trim((string)($o['supplyType'] ?? ''))) === strtolower($type);
	}//end isSupply()

	/**
	 * The supply is a general service (a 'service' supplyType not otherwise covered
	 * by a special place-of-supply rule).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function isGeneralService(array $o): bool {
		return self::isSupply($o, 'service');
	}//end isGeneralService()

	/**
	 * The customer is a taxable person (B2B). A 'business' customerType — anything
	 * else (e.g. 'private') is treated as a non-taxable (B2C) customer.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function isBusinessCustomer(array $o): bool {
		return strtolower(trim((string)($o['customerType'] ?? 'business'))) === 'business';
	}//end isBusinessCustomer()

	/**
	 * The stated place of supply country equals the country under $key (both as a
	 * normalised ISO 3166-1 alpha-2 code), and both are present.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field holding the directive-mandated country.
	 *
	 * @return bool
	 */
	private static function placeEquals(array $o, string $key): bool {
		return self::sameCountry((string)($o['placeOfSupplyCountry'] ?? ''), (string)($o[$key] ?? ''));
	}//end placeEquals()

	/**
	 * Two country codes are present and equal (normalised, case-insensitive,
	 * first two alphabetic characters).
	 *
	 * @param string $a First country code.
	 * @param string $b Second country code.
	 *
	 * @return bool
	 */
	private static function sameCountry(string $a, string $b): bool {
		$after = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $a) ?? '', 0, 2));
		$nb = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $b) ?? '', 0, 2));

		return $after !== '' && $after === $nb;
	}//end sameCountry()

	/**
	 * The value under $key parses as a calendar date (Y-m-d or any strtotime form).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function isDate(array $o, string $key): bool {
		return self::present($o, $key) === true && strtotime((string)$o[$key]) !== false;
	}//end isDate()

	/**
	 * The invoice's tax point (taxPointDate, else supplyDate, else invoiceDate) is on
	 * or after the given reference date. False when no tax point can be resolved.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $ref The reference date string.
	 *
	 * @return bool
	 */
	private static function taxPointOnOrAfter(array $o, string $ref): bool {
		$taxPoint = ($o['taxPointDate'] ?? ($o['supplyDate'] ?? ($o['invoiceDate'] ?? null)));
		if ($taxPoint === null || trim((string)$taxPoint) === '') {
			return false;
		}

		$tp = strtotime((string)$taxPoint);
		$rts = strtotime($ref);
		if ($tp === false || $rts === false) {
			return false;
		}

		return $tp >= $rts;
	}//end taxPointOnOrAfter()

	/**
	 * The invoice issue date (invoiceDate) is on or before the 15th day of the month
	 * following the supply (taxPointDate / supplyDate / invoiceDate). False when the
	 * supply or issue date cannot be resolved (art. 222 ICS deadline).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function issuedBy15thNextMonth(array $o): bool {
		$issue = ($o['invoiceDate'] ?? null);
		$supply = ($o['supplyDate'] ?? ($o['taxPointDate'] ?? ($o['invoiceDate'] ?? null)));
		if ($issue === null || $supply === null) {
			return false;
		}

		$issueTs = strtotime((string)$issue);
		$supplyTs = strtotime((string)$supply);
		if ($issueTs === false || $supplyTs === false) {
			return false;
		}

		$deadline = strtotime('+1 month', strtotime(date('Y-m-15', $supplyTs)));
		if ($deadline === false) {
			return false;
		}

		return $issueTs <= $deadline;
	}//end issuedBy15thNextMonth()

	/**
	 * The intra-Community-acquisition tax point: the received-invoice date is on or
	 * before the 15th of the month following the (invoice) chargeable event. Uses the
	 * invoiceDate as the chargeable-event anchor when no separate supply date exists.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function icaTaxPointOk(array $o): bool {
		$event = ($o['supplyDate'] ?? ($o['invoiceDate'] ?? null));
		$issue = ($o['invoiceDate'] ?? null);
		if ($event === null || $issue === null) {
			return false;
		}

		$eventTs = strtotime((string)$event);
		$issueTs = strtotime((string)$issue);
		if ($eventTs === false || $issueTs === false) {
			return false;
		}

		$backstop = strtotime('+1 month', strtotime(date('Y-m-15', $eventTs)));
		if ($backstop === false) {
			return false;
		}

		return $issueTs <= $backstop;
	}//end icaTaxPointOk()

	/**
	 * The VAT-return filing deadline is on or after the period end and at most
	 * $months months after it (art. 252).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param int $months Maximum months between period end and deadline.
	 *
	 * @return bool
	 */
	private static function deadlineWithinMonths(array $o, int $months): bool {
		$end = strtotime((string)($o['periodEndDate'] ?? ''));
		$deadline = strtotime((string)($o['filingDeadline'] ?? ''));
		if ($end === false || $deadline === false || $deadline < $end) {
			return false;
		}

		$latest = strtotime('+' . $months . ' month', $end);
		return $latest !== false && $deadline <= $latest;
	}//end deadlineWithinMonths()

	/**
	 * The submitted date is on or after the period end and within $months months of
	 * it (arts. 263 — recapitulative statement submitted within one month).
	 *
	 * @param string $periodEnd The period end date string.
	 * @param string $submitted The submission date string.
	 * @param int $months Maximum months allowed.
	 *
	 * @return bool
	 */
	private static function withinMonths(string $periodEnd, string $submitted, int $months): bool {
		$end = strtotime($periodEnd);
		$sub = strtotime($submitted);
		if ($end === false || $sub === false || $sub < $end) {
			return false;
		}

		$latest = strtotime('+' . $months . ' month', $end);
		return $latest !== false && $sub <= $latest;
	}//end withinMonths()

	/**
	 * The recapitulative statement carries at least one line, each with a customer VAT
	 * id and a numeric value (art. 262 — listing the customers supplied).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function recapHasLines(array $o): bool {
		$lines = ($o['recapLines'] ?? null);
		if (is_array($lines) === false || $lines === []) {
			return false;
		}

		foreach ($lines as $l) {
			if (is_array($l) === false) {
				return false;
			}

			if (self::vatIdShapeOk((string)($l['customerVatId'] ?? '')) === false) {
				return false;
			}

			if (is_numeric($l['value'] ?? null) === false) {
				return false;
			}
		}

		return true;
	}//end recapHasLines()

	/**
	 * The recapitulative statement content is well-formed: it has valid lines, and the
	 * sum of the listed per-customer values reconciles (within a cent) to the reported
	 * intra-Community supplies total (arts. 264/138).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function recapContentOk(array $o): bool {
		if (self::recapHasLines($o) === false) {
			return false;
		}

		$sum = 0.0;
		foreach (($o['recapLines'] ?? []) as $l) {
			$sum += (float)($l['value'] ?? 0);
		}

		$reported = self::numericOrZero($o, 'intraCommunitySupplies');

		return self::centsEqual($sum, $reported);
	}//end recapContentOk()

	/**
	 * Whether any breakdown group OR invoice line carries VAT category $category.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $category UNTDID 5305 category code (e.g. 'K').
	 *
	 * @return bool
	 */
	private static function anyCategory(array $o, string $category): bool {
		$breakdown = ($o['vatBreakdown'] ?? null);
		if (is_array($breakdown) === true) {
			foreach ($breakdown as $b) {
				if (is_array($b) === true && (string)($b['category'] ?? '') === $category) {
					return true;
				}
			}
		}

		$lines = ($o['invoiceLines'] ?? null);
		if (is_array($lines) === true) {
			foreach ($lines as $l) {
				if (is_array($l) === true && (string)($l['vatCategory'] ?? '') === $category) {
					return true;
				}
			}
		}

		return false;
	}//end anyCategory()

	/**
	 * A VAT identifier is well-formed (VIES shape): an EU country prefix (or 'EL'/'XI')
	 * followed by at least two alphanumeric characters of the national body.
	 *
	 * @param string $vatId The VAT identifier.
	 *
	 * @return bool
	 */
	private static function vatIdShapeOk(string $vatId): bool {
		$clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatId) ?? '');
		if (strlen($clean) < 4) {
			return false;
		}

		$prefix = substr($clean, 0, 2);
		if (in_array($prefix, self::VAT_PREFIXES, true) === false) {
			return false;
		}

		$body = substr($clean, 2);
		return strlen($body) >= 2 && ctype_alnum($body) === true;
	}//end vatIdShapeOk()

	/**
	 * Two monetary values are equal at cent precision (≤ 0.5 cent apart).
	 *
	 * @param float $a First value.
	 * @param float $b Second value.
	 *
	 * @return bool
	 */
	private static function centsEqual(float $a, float $b): bool {
		return abs(round($a, 2) - round($b, 2)) < 0.005;
	}//end centsEqual()

	/**
	 * ISO 3166-1 alpha-2 country prefixes accepted in an EU VAT identifier, plus the
	 * 'EL' alias Greece uses for VAT (instead of 'GR') and 'XI' (Northern Ireland).
	 *
	 * @var array<int, string>
	 */
	private const VAT_PREFIXES = [
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'EL',
		'ES',
		'FI',
		'FR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
		'GR',
		'XI',
	];
}//end class
