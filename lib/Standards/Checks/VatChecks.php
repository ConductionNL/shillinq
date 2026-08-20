<?php

/**
 * VAT Directive Check Provider
 *
 * Contributes executable checks for the `vat` rule domain (EU VAT Directive
 * 2006/112/EC) to the RuleEngine. The predicates map the directive's invoice-content
 * obligations onto the structured ARInvoice / APTransaction fields shillinq already
 * models — VAT identifiers, reverse-charge / exemption mentions, place-of-supply
 * flags, the tax point (chargeable event) date, the taxable amount, and the VAT
 * rate stated. VAT-return / recapitulative-statement / OSS-IOSS filing rules that
 * would require a VATReturn object shillinq does not model are intentionally not
 * registered here.
 *
 * Each predicate is `fn(array $object, array $context): bool` — true when the rule is
 * satisfied — and is side-effect free and self-contained (it inlines its logic via
 * the private static helpers on this class rather than reaching into RuleEngine).
 * Every ruleId is a real `machineCheckable` id from lib/Standards/rules/vat.json.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.Commenting.InlineComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Executable EU VAT Directive checks against ARInvoice / APTransaction content.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): this class enumerates every EU VAT Directive rule the
 *     catalogue defines; inherent complexity. Deferred to a follow-up.
 */
class VatChecks implements CheckProvider {
	/**
	 * ISO 3166-1 alpha-2 country prefixes accepted in an EU VAT identifier, plus the
	 * 'EL' alias Greece uses for VAT (instead of 'GR'). Used to validate VAT-id shape.
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

	/**
	 * Per-object-type, per-rule predicates for the `vat` domain.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'ARInvoice' => [
				// --- Tax point (chargeable event) — VAT Directive art. 63. ---
				// The supply date / value-added-tax point must be stated so the moment
				// VAT became chargeable is recorded on the invoice.
				'vatdir-art63-tax-point-supply' => static fn (array $o): bool => (self::present($o, 'supplyDate')
					|| self::present($o, 'taxPointDate') || self::present($o, 'invoiceDate')),

				// --- Obligation to invoice — VAT Directive art. 220. ---
				// An invoice exists for the supply: it carries a number and an issue date.
				'vatdir-art220-obligation-to-invoice' => static fn (array $o): bool => (self::present($o, 'invoiceNumber')
					&& self::present($o, 'invoiceDate')),

				// --- Taxable amount — VAT Directive art. 73. ---
				// The consideration (net taxable amount) is stated and non-negative, and
				// — when broken down per rate — reconciles to the sum of the breakdown.
				'vatdir-art73-taxable-amount' => static fn (array $o): bool => self::taxableAmountStated($o),
				// art. 78: the taxable base per category reconciles to the line net of
				// that category (i.e. it already includes the line consideration); the
				// sum of breakdown taxable amounts equals the document line net total.
				'vatdir-art78-taxable-amount-inclusions' => static fn (array $o): bool => self::breakdownTaxableReconciles($o),
				// art. 79: document-level early-payment/discount reductions are modelled
				// as allowances that REDUCE the base — the taxable base must equal line
				// net minus document allowances plus document charges.
				'vatdir-art79-taxable-amount-exclusions' => static fn (array $o): bool => self::baseNetOfAllowances($o),

				// --- VAT rate stated and within directive bounds. ---
				// art. 93: the applicable rate is stated on every rated breakdown group.
				'vatdir-art93-rate-applicable' => static fn (array $o): bool => self::ratedGroupsHaveRate($o),
				// art. 96: a single standard rate — at most one distinct positive standard
				// rate across the Standard-rated ('S') breakdown groups of the invoice.
				'vatdir-art96-standard-rate-single' => static fn (array $o): bool => self::singleStandardRate($o),
				// art. 97: the standard rate shall not be lower than 15 %.
				'vatdir-art97-standard-rate-minimum' => static fn (array $o): bool => self::standardRateAtLeast($o, 15.0),
				// art. 98: a reduced rate (a positive rate below the 15 % standard floor)
				// shall not be lower than 5 %.
				'vatdir-art98-reduced-rates' => static fn (array $o): bool => self::reducedRateAtLeast($o, 5.0),

				// --- Reverse charge / exemption mentions on the invoice (art. 226(11a) / 138). ---
				// art. 226(11a): where the customer is liable (a reverse-charge 'AE'
				// category appears) the invoice must carry the words 'Reverse charge'.
				'vatdir-art226-11a-reverse-charge-mention' => static fn (array $o): bool => (self::anyCategory($o, 'AE') === false
					|| self::noteContains($o, 'reverse charge')),
				// art. 138 exempt mention: where an intra-Community supply ('K') appears
				// the invoice must reference the applicable provision / that it is exempt.
				'vatdir-art138-exempt-mention' => static fn (array $o): bool => (self::anyCategory($o, 'K') === false
					|| self::noteContains($o, '138') || self::noteContains($o, 'intra-community')
					|| self::noteContains($o, 'intra-communautaire') || self::noteContains($o, 'exempt')),

				// --- Intra-Community supply exemption (arts. 138). ---
				// art. 138: an exempt intra-Community supply ('K') carries no VAT — the
				// 'K' breakdown groups must have a zero tax amount and a zero/absent rate.
				'vatdir-art138-ics-exemption' => static fn (array $o): bool => self::categoryUntaxed($o, 'K'),
				// art. 138 substantive condition: an ICS requires the supplier's own VAT
				// id AND a customer VAT id issued by a DIFFERENT Member State than departure.
				'vatdir-art138-vatid-substantive' => static fn (array $o): bool => (self::anyCategory($o, 'K') === false
					|| (self::present($o, 'sellerVatId')
					&& self::vatIdShapeOk((string)($o['buyerVatId'] ?? ''))
					&& self::differentMemberState($o))),

				// --- Export exemption (art. 146). ---
				// An export ('G') line is exempt with deduction: zero VAT on the 'G' group.
				'vatdir-art146-export-exemption' => static fn (array $o): bool => self::categoryUntaxed($o, 'G'),

				// --- Cross-border B2B reverse charge for services (art. 196). ---
				// A reverse-charge ('AE') supply must carry zero VAT and identify both the
				// supplier and the (liable) taxable customer by VAT id.
				'vatdir-art196-reverse-charge-b2b-services' => static fn (array $o): bool => (self::anyCategory($o, 'AE') === false
					|| (self::categoryUntaxed($o, 'AE') && self::present($o, 'sellerVatId')
					&& self::vatIdShapeOk((string)($o['buyerVatId'] ?? '')))),

				// --- VIES validation (recommended). ---
				// Where a customer VAT id is supplied for an exempt ICS ('K'), it must be a
				// well-formed VIES VAT identifier (country prefix + body).
				'vies-vatid-validation' => static fn (array $o): bool => (self::anyCategory($o, 'K') === false
					|| self::vatIdShapeOk((string)($o['buyerVatId'] ?? ''))),

				// --- Rounding (conditional). ---
				// The header VAT amount must reconcile to the sum of the per-category tax
				// amounts within the national one-cent rounding tolerance (art. 226-derived).
				'vat-rounding-line-or-total' => static fn (array $o): bool => self::vatRoundsToBreakdown($o),
			],
			'APTransaction' => [
				// --- Intra-Community acquisition is taxable (art. 2(1)(b)). ---
				// A purchase invoice records the consideration and the (reverse-charged)
				// acquisition VAT: both the total and the tax amount must be stated numbers.
				'vatdir-art2-1b-ica-taxable' => static fn (array $o): bool => (self::numericPresent($o, 'totalAmount')
					&& self::numericPresent($o, 'taxAmount')),
				// --- Tax point on the received invoice (art. 63). ---
				'vatdir-art63-tax-point-supply' => static fn (array $o): bool => self::present($o, 'invoiceDate'),
				// --- Taxable amount on the received invoice (art. 73). ---
				'vatdir-art73-taxable-amount' => static fn (array $o): bool => (self::numericPresent($o, 'totalAmount')
					&& (float)($o['totalAmount'] ?? 0) >= 0),
			],
		];

	}//end checks()

	/**
	 * Test-data field defaults this provider's checks expect on ARInvoice. Seeded
	 * sales invoices use only the Standard ('S') / Zero ('Z') categories, so the
	 * reverse-charge / intra-community / export predicates are vacuously satisfied;
	 * the `vatNote` default keeps them satisfied if a future seed adds those lines.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'ARInvoice' => [
				// Free-text legal note (VAT Directive art. 226(11a)/138) covering both the
				// reverse-charge wording and an intra-Community/exemption reference.
				'vatNote' => 'Reverse charge. Intra-Community supply exempt under art. 138 VAT Directive.',
			],
		];

	}//end seedSpec()

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
	 * The invoice's free-text VAT note contains $needle (case-insensitive).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $needle The phrase to look for.
	 *
	 * @return bool
	 */
	private static function noteContains(array $o, string $needle): bool {
		$note = strtolower((string)($o['vatNote'] ?? ''));
		return $note !== '' && str_contains($note, strtolower($needle));
	}//end noteContains()

	/**
	 * The modeled VAT breakdown groups (BG-23) as a list of arrays.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function breakdown(array $o): array {
		$rows = ($o['vatBreakdown'] ?? null);
		if (is_array($rows) === false) {
			return [];
		}

		return array_values(array_filter($rows, static fn ($r): bool => is_array($r) === true));
	}//end breakdown()

	/**
	 * The modeled invoice lines (BG-25) as a list of arrays.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function lines(array $o): array {
		$rows = ($o['invoiceLines'] ?? null);
		if (is_array($rows) === false) {
			return [];
		}

		return array_values(array_filter($rows, static fn ($r): bool => is_array($r) === true));
	}//end lines()

	/**
	 * Whether any breakdown group OR invoice line carries VAT category $category.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $category UNTDID 5305 category code (e.g. 'AE', 'K', 'G').
	 *
	 * @return bool
	 */
	private static function anyCategory(array $o, string $category): bool {
		foreach (self::breakdown($o) as $b) {
			if ((string)($b['category'] ?? '') === $category) {
				return true;
			}
		}

		foreach (self::lines($o) as $l) {
			if ((string)($l['vatCategory'] ?? '') === $category) {
				return true;
			}
		}

		return false;
	}//end anyCategory()

	/**
	 * Every breakdown group of $category is untaxed: zero tax amount and a zero or
	 * absent rate (exemption / zero-rated families carry no positive VAT).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $category The category code.
	 *
	 * @return bool
	 */
	private static function categoryUntaxed(array $o, string $category): bool {
		foreach (self::breakdown($o) as $b) {
			if ((string)($b['category'] ?? '') !== $category) {
				continue;
			}

			if (abs((float)($b['taxAmount'] ?? 0)) >= 0.005) {
				return false;
			}

			if (array_key_exists('rate', $b) === true && abs((float)$b['rate']) >= 0.0005) {
				return false;
			}
		}

		return true;
	}//end categoryUntaxed()

	/**
	 * The taxable (net) amount is stated as a non-negative number.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function taxableAmountStated(array $o): bool {
		return self::numericPresent($o, 'netAmount') && (float)($o['netAmount'] ?? 0) >= 0;
	}//end taxableAmountStated()

	/**
	 * The sum of breakdown taxable amounts equals the document line net total within a
	 * one-cent tolerance (art. 78 — the base includes the full line consideration).
	 * Vacuously true when no breakdown is modeled.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function breakdownTaxableReconciles(array $o): bool {
		$breakdown = self::breakdown($o);
		if ($breakdown === []) {
			return true;
		}

		$sum = 0.0;
		foreach ($breakdown as $b) {
			$sum += (float)($b['taxableAmount'] ?? 0);
		}

		$target = array_key_exists('lineNetTotal', $o) === true ? (float)$o['lineNetTotal'] : (float)($o['netAmount'] ?? 0);

		return self::centsEqual($sum, $target);
	}//end breakdownTaxableReconciles()

	/**
	 * The net (taxable) amount equals the line net total minus document allowances plus
	 * document charges (art. 79 — early-payment/discount reductions excluded from base).
	 * Vacuously true when no line net total is modeled.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function baseNetOfAllowances(array $o): bool {
		if (array_key_exists('lineNetTotal', $o) === false) {
			return true;
		}

		$expected = (float)$o['lineNetTotal'] - (float)($o['allowancesTotal'] ?? 0) + (float)($o['chargesTotal'] ?? 0);

		return self::centsEqual($expected, (float)($o['netAmount'] ?? 0));
	}//end baseNetOfAllowances()

	/**
	 * Every rated breakdown group (Standard 'S' or Group 'G' export carries 0, but any
	 * non-exempt group) states a numeric rate. Specifically: each 'S' group must carry a
	 * numeric rate (art. 93 — the applicable rate is stated).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ratedGroupsHaveRate(array $o): bool {
		foreach (self::breakdown($o) as $b) {
			if ((string)($b['category'] ?? '') !== 'S') {
				continue;
			}

			if (array_key_exists('rate', $b) === false || is_numeric($b['rate']) === false) {
				return false;
			}
		}

		return true;
	}//end ratedGroupsHaveRate()

	/**
	 * The distinct positive Standard-rated ('S') rates across the breakdown number at
	 * most one (art. 96 — a single standard rate per supply on the invoice).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function singleStandardRate(array $o): bool {
		$rates = [];
		foreach (self::breakdown($o) as $b) {
			if ((string)($b['category'] ?? '') !== 'S') {
				continue;
			}

			$rate = (float)($b['rate'] ?? 0);
			if ($rate > 0) {
				$rates[(string)round($rate, 2)] = true;
			}
		}

		return count($rates) <= 1;
	}//end singleStandardRate()

	/**
	 * Every positive Standard-rated ('S') rate is at least $min percent
	 * (art. 97 — the standard rate shall not be lower than 15 %).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param float $min Minimum standard rate in percent.
	 *
	 * @return bool
	 */
	private static function standardRateAtLeast(array $o, float $min): bool {
		foreach (self::breakdown($o) as $b) {
			if ((string)($b['category'] ?? '') !== 'S') {
				continue;
			}

			$rate = (float)($b['rate'] ?? 0);
			if ($rate > 0 && ($rate + 0.0005) < $min) {
				return false;
			}
		}

		return true;
	}//end standardRateAtLeast()

	/**
	 * Every positive rate that is below the 15 % standard floor (i.e. a reduced rate) is
	 * at least $min percent (art. 98 — reduced rates not lower than 5 %).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param float $min Minimum reduced rate in percent.
	 *
	 * @return bool
	 */
	private static function reducedRateAtLeast(array $o, float $min): bool {
		foreach (self::breakdown($o) as $b) {
			$rate = (float)($b['rate'] ?? 0);
			if ($rate > 0 && ($rate + 0.0005) < 15.0 && ($rate + 0.0005) < $min) {
				return false;
			}
		}

		return true;
	}//end reducedRateAtLeast()

	/**
	 * The header VAT amount reconciles to the sum of the per-category breakdown tax
	 * amounts within a one-cent rounding tolerance. Vacuously true with no breakdown.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function vatRoundsToBreakdown(array $o): bool {
		$breakdown = self::breakdown($o);
		if ($breakdown === []) {
			return true;
		}

		$sum = 0.0;
		foreach ($breakdown as $b) {
			$sum += (float)($b['taxAmount'] ?? 0);
		}

		return self::centsEqual($sum, (float)($o['vatAmount'] ?? 0));
	}//end vatRoundsToBreakdown()

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
	 * The buyer VAT id's country prefix differs from the seller's country / VAT-id
	 * prefix (art. 138 — customer identified in a different Member State than departure).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function differentMemberState(array $o): bool {
		$buyer = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($o['buyerVatId'] ?? '')) ?? '', 0, 2));
		if ($buyer === '') {
			return false;
		}

		$sellerCountry = strtoupper((string)($o['sellerCountryCode'] ?? ''));
		if ($sellerCountry === '') {
			$sellerCountry = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($o['sellerVatId'] ?? '')) ?? '', 0, 2));
		}

		return $sellerCountry !== '' && $buyer !== $sellerCountry;
	}//end differentMemberState()

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
}//end class
