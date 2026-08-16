<?php

/**
 * Compliance Catalogue
 *
 * Versioned, read-only catalogue of digital-compliance / tax-data obligations
 * (Category B of the standards model — see docs/standards/digital-compliance.md).
 *
 * Unlike the StandardsPolicy (a per-administration *choice* of which basis of
 * accounting to follow, stored as an OpenRegister object and ranked by
 * precedence), compliance obligations are **facts about the world**: EN 16931,
 * ViDA's intra-EU mandate, "Poland KSeF mandatory from 2026", which countries
 * require SAF-T, etc. They are identical for every tenant and change only when
 * regulation changes — so they are NOT OpenRegister config; they ship as
 * versioned static code and update with releases.
 *
 * This catalogue is the machine-readable source business logic consults
 * (additively — meet every obligation that applies) and the basis for turning
 * compliance rules into specs/features. The only genuinely per-tenant input is
 * which jurisdictions an administration operates in, which is derived elsewhere
 * (administration / customer countries) and passed to applicableTo().
 *
 * Bump VERSION whenever an entry changes. Statuses are a snapshot as of
 * AS_OF — effectiveDate is the authoritative field; callers that care about a
 * specific date should compare against it rather than trust `status`.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md
 *
 * phpcs:disable Generic.Files.LineLength
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards;

/**
 * Read-only, versioned catalogue of digital-compliance obligations.
 */
final class ComplianceCatalogue {

	/**
	 * Catalogue version — bump on any change to the entries.
	 *
	 * @var string
	 */
	public const VERSION = '2026-06';

	/**
	 * The date the statuses below were last verified.
	 *
	 * @var string
	 */
	public const AS_OF = '2026-06-20';

	/**
	 * EU member states (ISO 3166-1 alpha-2) — used to decide whether an EU-wide
	 * obligation applies to a given jurisdiction.
	 *
	 * @var string[]
	 */
	public const EU_MEMBER_STATES = [
		'AT',
		'BE',
		'BG',
		'HR',
		'CY',
		'CZ',
		'DK',
		'EE',
		'FI',
		'FR',
		'DE',
		'GR',
		'HU',
		'IE',
		'IT',
		'LV',
		'LT',
		'LU',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SK',
		'SI',
		'ES',
		'SE',
	];

	/**
	 * The full catalogue. Each entry:
	 *  id            stable slug
	 *  jurisdiction  ISO alpha-2, or 'EU' (EU-wide), or 'US'
	 *  type          e-invoicing | digital-reporting | saf-t | vat | sales-tax
	 *  standard      en-16931 | peppol | vida | country-mandate | saf-t |
	 *                vat-2006-112 | oss-ioss | dbnalliance | us-state-sales-tax
	 *  name          human label
	 *  status        live | upcoming | voluntary  (snapshot as of AS_OF)
	 *  effectiveDate ISO date the obligation becomes/became mandatory, or null
	 *  notes         short detail
	 *  source        authoritative URL
	 *
	 * @return array<int, array<string, string|null>>
	 */
	public static function all(): array {
		return [
			// — EU-wide framework standards —
			['id' => 'eu-en-16931', 'jurisdiction' => 'EU', 'type' => 'e-invoicing', 'standard' => 'en-16931', 'name' => 'EN 16931 — European e-invoice standard', 'status' => 'live', 'effectiveDate' => '2019-04-18', 'notes' => 'Semantic data model underpinning every EU e-invoicing mandate; B2B-extended revision 2025/26.', 'source' => 'https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/467108974/Registry+of+supporting+artefacts+to+implement+EN16931'],
			['id' => 'eu-peppol', 'jurisdiction' => 'EU', 'type' => 'e-invoicing', 'standard' => 'peppol', 'name' => 'Peppol BIS Billing 3.0', 'status' => 'live', 'effectiveDate' => null, 'notes' => 'De-facto EU delivery network + CIUS of EN 16931.', 'source' => 'https://docs.peppol.eu/poacc/billing/3.0/bis/'],
			['id' => 'eu-vida', 'jurisdiction' => 'EU', 'type' => 'digital-reporting', 'standard' => 'vida', 'name' => 'ViDA — VAT in the Digital Age', 'status' => 'upcoming', 'effectiveDate' => '2030-07-01', 'notes' => 'Adopted 11 Mar 2025; mandatory intra-EU e-invoicing + real-time reporting 1 Jul 2030; domestic regimes align by 2035.', 'source' => 'https://sovos.com/blog/vat/vida-timeline/'],
			['id' => 'eu-vat-2006-112', 'jurisdiction' => 'EU', 'type' => 'vat', 'standard' => 'vat-2006-112', 'name' => 'EU VAT Directive 2006/112/EC', 'status' => 'live', 'effectiveDate' => null, 'notes' => 'Core VAT framework: rates, place-of-supply, invoicing content, returns.', 'source' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32006L0112'],
			['id' => 'eu-oss-ioss', 'jurisdiction' => 'EU', 'type' => 'vat', 'standard' => 'oss-ioss', 'name' => 'VAT One-Stop-Shop / Import OSS', 'status' => 'live', 'effectiveDate' => '2021-07-01', 'notes' => 'Single-registration schemes for cross-border B2C / e-commerce; expanded under ViDA.', 'source' => 'https://vat-one-stop-shop.ec.europa.eu/'],

			// — Per-country e-invoicing mandates —
			['id' => 'it-sdi', 'jurisdiction' => 'IT', 'type' => 'e-invoicing', 'standard' => 'country-mandate', 'name' => 'Italy SdI / FatturaPA', 'status' => 'live', 'effectiveDate' => '2019-01-01', 'notes' => 'Clearance model live since 2019; EU derogation to 31 Dec 2027.', 'source' => 'https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/467108890/eInvoicing+in+Italy'],
			['id' => 'pl-ksef', 'jurisdiction' => 'PL', 'type' => 'e-invoicing', 'standard' => 'country-mandate', 'name' => 'Poland KSeF', 'status' => 'live', 'effectiveDate' => '2026-02-01', 'notes' => 'Large taxpayers 1 Feb 2026, most others 1 Apr 2026, micro Jan 2027.', 'source' => 'https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026'],
			['id' => 'es-verifactu', 'jurisdiction' => 'ES', 'type' => 'e-invoicing', 'standard' => 'country-mandate', 'name' => 'Spain Verifactu', 'status' => 'upcoming', 'effectiveDate' => '2026-07-01', 'notes' => 'Certified-software invoicing mandatory 1 Jul 2026; B2B (Crea y Crece) pending.', 'source' => 'https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026'],
			['id' => 'fr-einvoice', 'jurisdiction' => 'FR', 'type' => 'e-invoicing', 'standard' => 'country-mandate', 'name' => 'France e-invoicing reform', 'status' => 'upcoming', 'effectiveDate' => '2026-09-01', 'notes' => 'Receive + large/mid issue 1 Sep 2026; SMEs 1 Sep 2027.', 'source' => 'https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026'],
			['id' => 'de-einvoice', 'jurisdiction' => 'DE', 'type' => 'e-invoicing', 'standard' => 'country-mandate', 'name' => 'Germany B2B e-invoicing', 'status' => 'upcoming', 'effectiveDate' => '2027-01-01', 'notes' => 'Receive capability since Jan 2025; issue mandatory Jan 2027 (>EUR 800k) / Jan 2028 (rest).', 'source' => 'https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026'],
			['id' => 'be-einvoice', 'jurisdiction' => 'BE', 'type' => 'e-invoicing', 'standard' => 'country-mandate', 'name' => 'Belgium B2B via Peppol', 'status' => 'live', 'effectiveDate' => '2026-01-01', 'notes' => 'Structured B2B e-invoicing via Peppol from Jan 2026.', 'source' => 'https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026'],

			// — SAF-T (tax-audit ledger export) —
			['id' => 'pt-saft', 'jurisdiction' => 'PT', 'type' => 'saf-t', 'standard' => 'saf-t', 'name' => 'Portugal SAF-T (PT)', 'status' => 'live', 'effectiveDate' => null, 'notes' => 'Long-standing SAF-T; annual accounting SAF-T from FY2025 (first filings 2026).', 'source' => 'https://www.vatupdate.com/2025/10/15/updated-overview-of-countries-implementing-oecds-saf-t/'],
			['id' => 'pl-saft', 'jurisdiction' => 'PL', 'type' => 'saf-t', 'standard' => 'saf-t', 'name' => 'Poland JPK / SAF-T', 'status' => 'live', 'effectiveDate' => null, 'notes' => 'JPK_KR_PD / JPK_ST_KR CIT structures; first filings due 31 Mar 2026.', 'source' => 'https://www.vatupdate.com/2025/10/15/updated-overview-of-countries-implementing-oecds-saf-t/'],
			['id' => 'no-saft', 'jurisdiction' => 'NO', 'type' => 'saf-t', 'standard' => 'saf-t', 'name' => 'Norway SAF-T', 'status' => 'live', 'effectiveDate' => '2020-01-01', 'notes' => 'On-demand SAF-T financial export.', 'source' => 'https://www.vatupdate.com/2025/10/15/updated-overview-of-countries-implementing-oecds-saf-t/'],
			['id' => 'lu-saft', 'jurisdiction' => 'LU', 'type' => 'saf-t', 'standard' => 'saf-t', 'name' => 'Luxembourg FAIA (SAF-T)', 'status' => 'live', 'effectiveDate' => null, 'notes' => 'FAIA audit-file export on request.', 'source' => 'https://www.vatupdate.com/2025/10/15/updated-overview-of-countries-implementing-oecds-saf-t/'],

			// — United States —
			['id' => 'us-dbnalliance', 'jurisdiction' => 'US', 'type' => 'e-invoicing', 'standard' => 'dbnalliance', 'name' => 'US DBNAlliance exchange', 'status' => 'voluntary', 'effectiveDate' => null, 'notes' => 'Peppol-style 4-corner network; no US federal mandate — voluntary.', 'source' => 'https://edicomgroup.com/blog/the-united-states-begins-an-electronic-invoicing-pilot-project'],
			['id' => 'us-sales-tax', 'jurisdiction' => 'US', 'type' => 'sales-tax', 'standard' => 'us-state-sales-tax', 'name' => 'US state & local sales tax', 'status' => 'live', 'effectiveDate' => null, 'notes' => 'Per-state economic-nexus rules (post-Wayfair); no national VAT.', 'source' => 'https://www.salestaxinstitute.com/resources/economic-nexus-state-guide'],
		];

	}//end all()

	/**
	 * Obligations applying to a jurisdiction: its own entries plus EU-wide
	 * entries when the jurisdiction is an EU member state.
	 *
	 * @param string $jurisdiction ISO 3166-1 alpha-2 country code.
	 *
	 * @return array<int, array<string, string|null>>
	 */
	public static function applicableTo(string $jurisdiction): array {
		$code = strtoupper($jurisdiction);
		$isMember = in_array($code, self::EU_MEMBER_STATES, true);

		return array_values(
			array_filter(
				self::all(),
				static function (array $entry) use ($code, $isMember): bool {
					if ($entry['jurisdiction'] === $code) {
						return true;
					}

					return $entry['jurisdiction'] === 'EU' && $isMember === true;
				}
			)
		);

	}//end applicableTo()

	/**
	 * All obligations of a given type (e-invoicing, saf-t, vat, ...).
	 *
	 * @param string $type The obligation type.
	 *
	 * @return array<int, array<string, string|null>>
	 */
	public static function byType(string $type): array {
		return array_values(
			array_filter(
				self::all(),
				static function (array $entry) use ($type): bool {
					return $entry['type'] === $type;
				}
			)
		);

	}//end byType()

	/**
	 * The catalogue version string.
	 *
	 * @return string
	 */
	public static function version(): string {
		return self::VERSION;
	}//end version()
}//end class
