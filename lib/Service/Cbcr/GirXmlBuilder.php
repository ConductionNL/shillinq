<?php

/**
 * Shillinq GloBE information return XML element builder
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Cbcr
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

namespace OCA\Shillinq\Service\Cbcr;

use DOMDocument;
use DOMElement;

/**
 * Builds the blocks of a GloBE information return in one namespace (design D5).
 *
 * - MessageSpec: sender, NL to NL, message type GIR, GIR401, period and timestamp.
 * - FilingInfo: the filing constituent entity (the ultimate parent) with its TIN, the period, a DocSpec.
 * - GeneralSection/CorporateStructure: the ultimate parent and every constituent entity
 *   with its owner and ownership percentage, and the excluded-entity type where set.
 * - Summary per jurisdiction: the safe harbour claimed, or the ETR, SBIE and top-up tax.
 * - JurisdictionSection per jurisdiction: the safe harbour election only, or the
 *   jurisdiction's ETR computation and, for NL, the QDMTT amount.
 *
 * Amounts are whole units with `currCode`; rates have four decimals.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
 */
final class GirXmlBuilder {

	/**
	 * Constructor.
	 *
	 * @param DOMDocument     $doc       The document the elements belong to.
	 * @param string          $namespace The GIR namespace.
	 * @param GirRecordLookup $lookup    Reads the records.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function __construct(
		private readonly DOMDocument $doc,
		private readonly string $namespace,
		private readonly GirRecordLookup $lookup,
	) {
	}//end __construct()

	/**
	 * An element in the GIR namespace, with text when given.
	 *
	 * @param string      $name The local name.
	 * @param string|null $text The text, null for none.
	 *
	 * @return DOMElement
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function element(string $name, ?string $text = null): DOMElement {
		$element = $this->doc->createElementNS($this->namespace, 'gir:' . $name);
		if ($text !== null) {
			$element->appendChild($this->doc->createTextNode($text));
		}

		return $element;
	}//end element()

	/**
	 * The MessageSpec.
	 *
	 * @param array<string,mixed> $file The parts.
	 *
	 * @return DOMElement
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function messageSpec(array $file): DOMElement {
		$spec = $this->element(name: 'MessageSpec');
		$year = self::yearOf(return: (array)($file['return'] ?? []));
		$this->children(
			parent: $spec,
			children: [
				'SendingEntityIN' => $this->lookup->tinOf(entity: (array)($file['upe'] ?? []))['value'],
				'TransmittingCountry' => 'NL',
				'ReceivingCountry' => 'NL',
				'MessageType' => 'GIR',
				'Language' => (string)($file['language'] ?? 'EN'),
				'MessageRefId' => (string)($file['messageRefId'] ?? ''),
				'MessageTypeIndic' => 'GIR401',
				'ReportingPeriod' => $year . '-12-31',
				'Timestamp' => (string)($file['timestamp'] ?? ''),
			]
		);

		return $spec;
	}//end messageSpec()

	/**
	 * The FilingInfo: the filing constituent entity, the period and a DocSpec.
	 *
	 * @param array<string,mixed> $file The parts.
	 *
	 * @return DOMElement
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function filingInfo(array $file): DOMElement {
		$info = $this->element(name: 'FilingInfo');
		$info->appendChild($this->entity(name: 'FilingCE', entity: (array)($file['upe'] ?? [])));

		$year   = self::yearOf(return: (array)($file['return'] ?? []));
		$period = $this->element(name: 'Period');
		$this->children(parent: $period, children: ['Start' => $year . '-01-01', 'End' => $year . '-12-31']);
		$info->appendChild($period);
		$info->appendChild($this->docSpec(file: $file, key: 'filing-info:' . self::returnId(file: $file)));

		return $info;
	}//end filingInfo()

	/**
	 * The GeneralSection with the corporate structure.
	 *
	 * @param array<string,mixed> $file The parts.
	 *
	 * @return DOMElement
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function generalSection(array $file): DOMElement {
		$upe       = (array)($file['upe'] ?? []);
		$upeId     = (string)($upe['id'] ?? '');
		$byId      = [];
		$structure = $this->element(name: 'CorporateStructure');
		$structure->appendChild($this->entity(name: 'UPE', entity: $upe));

		foreach ((array)($file['entities'] ?? []) as $entity) {
			$byId[(string)(((array)$entity)['id'] ?? '')] = (array)$entity;
		}

		foreach ($byId as $id => $entity) {
			if ($id === $upeId) {
				continue;
			}

			$structure->appendChild($this->constituentEntity(entity: $entity, owner: ($byId[(string)($entity['parentEntity'] ?? '')] ?? $upe)));
		}

		$section = $this->element(name: 'GeneralSection');
		$section->appendChild($structure);
		$section->appendChild($this->docSpec(file: $file, key: 'general-section:' . self::returnId(file: $file)));

		return $section;
	}//end generalSection()

	/**
	 * The Summary of one jurisdiction.
	 *
	 * @param array<string,mixed> $file The parts.
	 * @param string              $code The jurisdiction.
	 *
	 * @return DOMElement
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function summary(array $file, string $code): DOMElement {
		$summary = $this->element(name: 'Summary');
		$summary->appendChild($this->element(name: 'Jurisdiction', text: $code));

		$test = $this->lookup->passedTest(records: $file, code: $code);
		if ($test !== null) {
			$summary->appendChild($this->element(name: 'SafeHarbour', text: $test));
			return $summary;
		}

		$computation = ($this->lookup->computationOf(records: $file, code: $code) ?? []);
		$summary->appendChild($this->element(name: 'ETRRate', text: self::rate(value: $computation['etrJurisdiction'] ?? 0)));
		$summary->appendChild($this->element(name: 'SBIE', text: self::flag(value: ((float)($computation['substanceBasedIncomeExclusion'] ?? 0) > 0))));
		$summary->appendChild($this->amount(name: 'QDMTTAmount', value: $computation['qdmttAmount'] ?? 0, file: $file));
		$summary->appendChild($this->amount(name: 'GLoBETopUpTax', value: $computation['topUpTaxAmount'] ?? 0, file: $file));

		return $summary;
	}//end summary()

	/**
	 * The JurisdictionSection of one jurisdiction.
	 *
	 * @param array<string,mixed> $file The parts.
	 * @param string              $code The jurisdiction.
	 *
	 * @return DOMElement
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function jurisdictionSection(array $file, string $code): DOMElement {
		$section = $this->element(name: 'JurisdictionSection');
		$section->appendChild($this->element(name: 'Jurisdiction', text: $code));

		$test = $this->lookup->passedTest(records: $file, code: $code);
		if ($test !== null) {
			$election = $this->element(name: 'SafeHarbourElection');
			$election->appendChild($this->element(name: 'SafeHarbour', text: $test));
			$section->appendChild($election);
		}

		if ($test === null) {
			$section->appendChild($this->globeTax(file: $file, code: $code));
		}

		$section->appendChild($this->docSpec(file: $file, key: 'jurisdiction:' . $code));

		return $section;
	}//end jurisdictionSection()

	/**
	 * The GLoBETax block of a computed jurisdiction, with the QDMTT for NL.
	 *
	 * @param array<string,mixed> $file The parts.
	 * @param string              $code The jurisdiction.
	 *
	 * @return DOMElement
	 */
	private function globeTax(array $file, string $code): DOMElement {
		$computation = ($this->lookup->computationOf(records: $file, code: $code) ?? []);

		$etr = $this->element(name: 'ETR');
		$etr->appendChild($this->element(name: 'ETRRate', text: self::rate(value: $computation['etrJurisdiction'] ?? 0)));
		$etr->appendChild($this->amount(name: 'GLoBEIncome', value: $computation['globeIncome'] ?? 0, file: $file));
		$etr->appendChild($this->amount(name: 'AdjustedCoveredTaxes', value: $computation['adjustedCoveredTaxes'] ?? 0, file: $file));
		$etr->appendChild($this->amount(name: 'SubstanceExclusion', value: $computation['substanceBasedIncomeExclusion'] ?? 0, file: $file));
		$etr->appendChild($this->amount(name: 'TopUpTax', value: $computation['topUpTaxAmount'] ?? 0, file: $file));

		$tax = $this->element(name: 'GLoBETax');
		$tax->appendChild($etr);

		if ($code === 'NL' && ($computation['qdmttApplicable'] ?? false) === true) {
			$qdmtt = $this->element(name: 'QDMTT');
			$qdmtt->appendChild($this->amount(name: 'Amount', value: $computation['qdmttAmount'] ?? 0, file: $file));
			$qdmtt->appendChild($this->element(name: 'Currency', text: self::currency(file: $file)));
			$tax->appendChild($qdmtt);
		}

		return $tax;
	}//end globeTax()

	/**
	 * A constituent entity with its owner and ownership percentage.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param array<string,mixed> $owner  The entity that owns it.
	 *
	 * @return DOMElement
	 */
	private function constituentEntity(array $entity, array $owner): DOMElement {
		$element = $this->entity(name: 'CE', entity: $entity);

		$ownership = $this->element(name: 'Ownership');
		$ownership->appendChild($this->element(name: 'OwnerTIN', text: $this->lookup->tinOf(entity: $owner)['value']));
		$share = ((float)($entity['consolidationPercentage'] ?? 100)) / 100;
		$ownership->appendChild($this->element(name: 'OwnershipPercentage', text: self::rate(value: $share)));
		$element->appendChild($ownership);

		$excluded = trim((string)($entity['excludedEntityType'] ?? ''));
		if ($excluded !== '') {
			$element->appendChild($this->element(name: 'ExcludedEntity', text: $excluded));
		}

		return $element;
	}//end constituentEntity()

	/**
	 * An entity block: resident country, TIN with issuer (and type for an RSIN) and name.
	 *
	 * @param string              $name   The element name.
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return DOMElement
	 */
	private function entity(string $name, array $entity): DOMElement {
		$element = $this->element(name: $name);
		$element->appendChild($this->element(name: 'ResCountryCode', text: $this->lookup->jurisdictionOf(entity: $entity)));

		$tin        = $this->lookup->tinOf(entity: $entity);
		$tinElement = $this->element(name: 'TIN', text: $tin['value']);
		if ($tin['issuedBy'] !== '') {
			$tinElement->setAttribute('issuedBy', $tin['issuedBy']);
		}

		if ($tin['type'] !== '') {
			$tinElement->setAttribute('TypeOfTIN', $tin['type']);
		}

		$element->appendChild($tinElement);
		$element->appendChild($this->element(name: 'Name', text: $this->lookup->nameOf(entity: $entity)));

		return $element;
	}//end entity()

	/**
	 * A DocSpec for a record key: a new record (OECD1) with its DocRefId.
	 *
	 * @param array<string,mixed> $file The parts.
	 * @param string              $key  The record key.
	 *
	 * @return DOMElement
	 */
	private function docSpec(array $file, string $key): DOMElement {
		$spec = $this->element(name: 'DocSpec');
		$this->children(parent: $spec, children: ['DocTypeIndic' => 'OECD1', 'DocRefId' => (string)(((array)($file['docRefIds'] ?? []))[$key] ?? $key)]);

		return $spec;
	}//end docSpec()

	/**
	 * An amount in whole units with its currency.
	 *
	 * @param string              $name  The element name.
	 * @param mixed               $value The amount.
	 * @param array<string,mixed> $file  The parts.
	 *
	 * @return DOMElement
	 */
	private function amount(string $name, mixed $value, array $file): DOMElement {
		$element = $this->element(name: $name, text: (string)(int)round((float)$value));
		$element->setAttribute('currCode', self::currency(file: $file));

		return $element;
	}//end amount()

	/**
	 * Append a text child per name.
	 *
	 * @param DOMElement           $parent   The parent.
	 * @param array<string,string> $children Name => text.
	 *
	 * @return void
	 */
	private function children(DOMElement $parent, array $children): void {
		foreach ($children as $name => $text) {
			$parent->appendChild($this->element(name: (string)$name, text: $text));
		}
	}//end children()

	/**
	 * The file's currency.
	 *
	 * @param array<string,mixed> $file The parts.
	 *
	 * @return string
	 */
	private static function currency(array $file): string {
		$currency = strtoupper(trim((string)($file['currency'] ?? '')));
		if ($currency === '') {
			$currency = 'EUR';
		}

		return $currency;
	}//end currency()

	/**
	 * A rate with four decimals.
	 *
	 * @param mixed $value The rate.
	 *
	 * @return string
	 */
	private static function rate(mixed $value): string {
		return number_format((float)$value, 4, '.', '');
	}//end rate()

	/**
	 * An XML boolean.
	 *
	 * @param bool $value The value.
	 *
	 * @return string
	 */
	private static function flag(bool $value): string {
		if ($value === true) {
			return 'true';
		}

		return 'false';
	}//end flag()

	/**
	 * The return's id.
	 *
	 * @param array<string,mixed> $file The parts.
	 *
	 * @return string
	 */
	private static function returnId(array $file): string {
		return (string)(((array)($file['return'] ?? []))['id'] ?? '');
	}//end returnId()

	/**
	 * The fiscal year of the return.
	 *
	 * @param array<string,mixed> $return The return.
	 *
	 * @return string
	 */
	private static function yearOf(array $return): string {
		if (preg_match('/(\d{4})/', (string)($return['period'] ?? ''), $match) === 1) {
			return $match[1];
		}

		return '0000';
	}//end yearOf()
}//end class
