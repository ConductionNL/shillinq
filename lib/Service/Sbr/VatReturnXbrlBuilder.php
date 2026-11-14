<?php

/**
 * Shillinq VAT return XBRL builder
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sbr
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

namespace OCA\Shillinq\Service\Sbr;

use RuntimeException;

/**
 * Builds the XBRL instance that files a prepared VAT return (BtwAangifte) on
 * the Belastingdienst OB entry point.
 *
 * The facts are the return's declarations summed per return box, in whole
 * euros, plus the owed (5a), input (5b) and payable (5g) totals; the entity
 * is the administration's VAT number. The taxonomy record effective for the
 * period names the version, the entry point schema (`schemaRefs`), the
 * concept namespaces (`namespaces`) and may rename any concept
 * (`conceptMap`); without one the instance is still built, and validation
 * refuses it naming the missing taxonomy.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-vat-btw-filing/spec.md#requirement-a-vat-return-is-filed-through-its-xbrl-instance-req-tdf-001
 */
class VatReturnXbrlBuilder {

	/**
	 * The entry point a VAT return is filed on.
	 */
	public const ENTRY_POINT = 'belastingdienst-ob';

	/**
	 * The filing type of the instance.
	 */
	public const FILING_TYPE = 'vat-return';

	/**
	 * The identifier scheme of a VAT number.
	 */
	public const VAT_SCHEME = 'www.belastingdienst.nl/omzetbelastingnummer';

	/**
	 * The namespace of the Belastingdienst concepts when the taxonomy names none.
	 */
	public const DEFAULT_NAMESPACES = ['bd-i' => 'http://www.nltaxonomie.nl/nt/bd/dictionary/bd-data'];

	/**
	 * The concept of each box amount, keyed `<box>.base` and `<box>.vat`, in form order.
	 * A taxonomy record's `conceptMap.belastingdienst-ob` overrides any of them.
	 */
	public const CONCEPTS = [
		'1a.base' => 'bd-i:TurnoverSuppliesServicesGeneralTariff',
		'1a.vat'  => 'bd-i:ValueAddedTaxSuppliesServicesGeneralTariff',
		'1b.base' => 'bd-i:TurnoverSuppliesServicesReducedTariff',
		'1b.vat'  => 'bd-i:ValueAddedTaxSuppliesServicesReducedTariff',
		'1c.base' => 'bd-i:TurnoverSuppliesServicesOtherRates',
		'1c.vat'  => 'bd-i:ValueAddedTaxSuppliesServicesOtherRates',
		'1d.base' => 'bd-i:TurnoverPrivateUse',
		'1d.vat'  => 'bd-i:ValueAddedTaxPrivateUse',
		'1e.base' => 'bd-i:TurnoverSuppliesServicesZeroOrNotTaxed',
		'2a.base' => 'bd-i:TurnoverSuppliesServicesByWhichVATTaxationIsTransferred',
		'2a.vat'  => 'bd-i:ValueAddedTaxSuppliesServicesByWhichVATTaxationIsTransferred',
		'3a.base' => 'bd-i:TurnoverSuppliesToCountriesOutsideTheEC',
		'3b.base' => 'bd-i:TurnoverSuppliesToCountriesWithinTheEC',
		'3c.base' => 'bd-i:TurnoverInstallationDistanceSalesWithinTheEC',
		'4a.base' => 'bd-i:TurnoverFromTaxedSuppliesFromCountriesOutsideTheEC',
		'4a.vat'  => 'bd-i:ValueAddedTaxOnSuppliesFromCountriesOutsideTheEC',
		'4b.base' => 'bd-i:TurnoverFromTaxedSuppliesFromCountriesWithinTheEC',
		'4b.vat'  => 'bd-i:ValueAddedTaxOnSuppliesFromCountriesWithinTheEC',
		'5a.vat'  => 'bd-i:ValueAddedTaxOwed',
		'5b.vat'  => 'bd-i:ValueAddedTaxOnInput',
		'5g.vat'  => 'bd-i:ValueAddedTaxOwedToBePaidBack',
	];

	/**
	 * The box input VAT is declared in.
	 */
	private const INPUT_VAT_BOX = '5b';

	/**
	 * Constructor.
	 *
	 * @param SbrRecords         $records    Reads the return and writes the instance.
	 * @param XbrlTaxonomyLookup $taxonomies Finds the taxonomy effective for the period.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public function __construct(
		private readonly SbrRecords $records,
		private readonly XbrlTaxonomyLookup $taxonomies,
	) {
	}//end __construct()

	/**
	 * Build (or rebuild, while it is a draft) the instance of a return and link the return to it.
	 *
	 * An instance that left draft is returned as it is: what was validated or
	 * filed is never rewritten.
	 *
	 * @param array<string,mixed> $vatReturn The BtwAangifte.
	 *
	 * @return array<string,mixed> The saved XbrlInstance.
	 *
	 * @throws RuntimeException When the return has no id or no period.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public function build(array $vatReturn): array {
		$returnId = trim((string) ($vatReturn['id'] ?? ($vatReturn['@self']['id'] ?? '')));
		if ($returnId === '') {
			throw new RuntimeException('The VAT return has no id; save it before filing it.');
		}

		$existing = $this->records->find(schema: 'XbrlInstance', id: (string) ($vatReturn['xbrlInstanceId'] ?? ''));
		if ($existing !== null && in_array((string) ($existing['state'] ?? 'draft'), ['draft', ''], true) === false) {
			return $existing;
		}

		$start          = substr(trim((string) ($vatReturn['startDate'] ?? '')), 0, 10);
		$end            = substr(trim((string) ($vatReturn['endDate'] ?? '')), 0, 10);
		$administration = $this->records->find(
			schema: 'Administration',
			id: (string) ($vatReturn['administrationId'] ?? ''),
			fallback: 'administrationCode'
		);
		$instance       = $this->instance(
			vatReturn: $vatReturn,
			declarations: $this->records->findAll(schema: 'VATDeclaration', filters: ['returnId' => $returnId]),
			administration: ($administration ?? []),
			taxonomy: $this->taxonomies->effectiveFor(entryPoint: self::ENTRY_POINT, start: $start, end: $end)
		);
		if ($existing !== null) {
			$instance = array_merge($existing, $instance);
		}

		$saved = $this->records->save(schema: 'XbrlInstance', record: $instance);
		if ((string) ($vatReturn['xbrlInstanceId'] ?? '') !== $saved['id']) {
			$vatReturn['id']             = $returnId;
			$vatReturn['xbrlInstanceId'] = $saved['id'];
			$this->records->save(schema: 'BtwAangifte', record: $vatReturn);
		}

		return $saved;
	}//end build()

	/**
	 * The draft XbrlInstance of a return.
	 *
	 * @param array<string,mixed>       $vatReturn      The BtwAangifte.
	 * @param list<array<string,mixed>> $declarations   Its VATDeclarations.
	 * @param array<string,mixed>       $administration Its administration.
	 * @param array<string,mixed>|null  $taxonomy       The taxonomy effective for the period, or null.
	 *
	 * @return array<string,mixed> The instance record.
	 *
	 * @throws RuntimeException When the return has no period.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public function instance(array $vatReturn, array $declarations, array $administration, ?array $taxonomy): array {
		$start = substr(trim((string) ($vatReturn['startDate'] ?? '')), 0, 10);
		$end   = substr(trim((string) ($vatReturn['endDate'] ?? '')), 0, 10);
		if ($start === '' || $end === '') {
			throw new RuntimeException('The VAT return has no period; it cannot be filed.');
		}

		$concepts = self::concepts(taxonomy: $taxonomy);
		$facts    = [];
		foreach (self::amounts(declarations: $declarations) as $key => $amount) {
			if (isset($concepts[$key]) === true) {
				$facts[] = ['concept' => $concepts[$key], 'value' => $amount, 'context' => XbrlInstanceWriter::DURATION];
			}
		}

		$vatNumber = strtoupper((string) preg_replace('/[\s.]+/', '', (string) ($administration['vatNumber'] ?? '')));
		$xml       = XbrlInstanceWriter::write(
			entity: ['scheme' => self::VAT_SCHEME, 'identifier' => $vatNumber],
			start: $start,
			end: $end,
			namespaces: XbrlInstanceWriter::namespaces(taxonomy: $taxonomy, defaults: self::DEFAULT_NAMESPACES),
			facts: $facts,
			schemaRef: XbrlInstanceWriter::schemaRef(taxonomy: $taxonomy, entryPoint: self::ENTRY_POINT)
		);

		return [
			'administrationId'     => (string) ($vatReturn['administrationId'] ?? ''),
			'instanceNumber'       => 'OB-' . (string) ($vatReturn['returnNumber'] ?? $start),
			'entryPoint'           => self::ENTRY_POINT,
			'filingType'           => self::FILING_TYPE,
			'sourceReturnId'       => (string) ($vatReturn['id'] ?? ($vatReturn['@self']['id'] ?? '')),
			'taxonomyVersion'      => (string) ($taxonomy['taxonomyVersion'] ?? ''),
			'reportingPeriodStart' => $start,
			'reportingPeriodEnd'   => $end,
			'instanceXml'          => $xml,
			'instanceHash'         => hash('sha256', $xml),
			'state'                => 'draft',
			'digipoortSourceSlug'  => 'digipoort-sbr',
		];
	}//end instance()

	/**
	 * The amounts of the return in whole euros, keyed as CONCEPTS is, in form order.
	 *
	 * Each box sums its declarations' taxable base and VAT, rounded per box;
	 * 5a is the VAT of every box but 5b, and 5g is 5a less 5b, so the totals
	 * add up to exactly what is reported.
	 *
	 * @param list<array<string,mixed>> $declarations The VATDeclarations.
	 *
	 * @return array<string,int> The amounts.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public static function amounts(array $declarations): array {
		$sums = [];
		foreach ($declarations as $declaration) {
			$box = strtolower(trim((string) ($declaration['returnBox'] ?? '')));
			if ($box === '') {
				continue;
			}

			$sums[$box . '.base'] = (($sums[$box . '.base'] ?? 0.0) + (float) ($declaration['totalTaxableAmount'] ?? 0));
			$sums[$box . '.vat']  = (($sums[$box . '.vat'] ?? 0.0) + (float) ($declaration['totalVATAmount'] ?? 0));
		}

		$amounts = [];
		$owed    = 0;
		foreach (array_keys(self::CONCEPTS) as $key) {
			$box = strstr($key, '.', true);
			if (in_array($box, ['5a', self::INPUT_VAT_BOX, '5g'], true) === true) {
				continue;
			}

			$amounts[$key] = (int) round($sums[$key] ?? 0.0);
			if (str_ends_with($key, '.vat') === true) {
				$owed += $amounts[$key];
			}
		}

		$input            = (int) round($sums[self::INPUT_VAT_BOX . '.vat'] ?? 0.0);
		$amounts['5a.vat'] = $owed;
		$amounts['5b.vat'] = $input;
		$amounts['5g.vat'] = ($owed - $input);

		return $amounts;
	}//end amounts()

	/**
	 * The concept of each amount: the defaults, overridden by the taxonomy's conceptMap.
	 *
	 * @param array<string,mixed>|null $taxonomy The taxonomy record.
	 *
	 * @return array<string,string> The concepts.
	 */
	private static function concepts(?array $taxonomy): array {
		$concepts = self::CONCEPTS;
		$override = (($taxonomy['conceptMap'] ?? [])[self::ENTRY_POINT] ?? []);
		if (is_array($override) === true) {
			foreach ($override as $key => $concept) {
				if (isset($concepts[$key]) === true && is_string($concept) === true && str_contains($concept, ':') === true) {
					$concepts[$key] = trim($concept);
				}
			}
		}

		return $concepts;
	}//end concepts()
}//end class
