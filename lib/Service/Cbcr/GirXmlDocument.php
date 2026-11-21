<?php

/**
 * Shillinq GloBE information return XML document
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

/**
 * Renders a GloBE information return in the layout of the OECD GIR XML Schema (January 2025).
 *
 * The document is built from records only: the return, the ultimate parent,
 * the group's entities in the minimum tax, the safe harbour records and the
 * jurisdiction computations (design D5). Before rendering, missingElements()
 * lists every mandatory element those records cannot fill: the records hold
 * a computed jurisdiction's GloBE income and covered taxes per jurisdiction,
 * while the schema asks for them per constituent entity. The generator renders
 * nothing while that list is not empty.
 *
 * The namespace is the one the bundled main schema declares; until task 2.1
 * bundles the set, the file is refused by validation anyway (CbcXsdSet), so the
 * fallback namespace never reaches a stored file.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
 */
final class GirXmlDocument {

	/**
	 * The GIR message namespace used while the set does not name one.
	 */
	public const NS_GIR = 'urn:oecd:ties:globe:v1';

	/**
	 * The schema version written on the root.
	 */
	public const VERSION = '1.0';

	/**
	 * The TIN value of an entity without a tax identification number.
	 */
	public const NO_TIN = 'NOTIN';

	/**
	 * The TIN type of a Dutch RSIN (FAQ GIR).
	 */
	public const DUTCH_TIN_TYPE = 'GIR3001';

	/**
	 * The per-entity elements of a computed jurisdiction the records do not hold.
	 *
	 * @var array<int,string>
	 */
	public const ENTITY_ELEMENTS = [
		'JurisdictionSection/GLoBETax/ETR/ETRComputation/CEComputation/GLoBEIncome',
		'JurisdictionSection/GLoBETax/ETR/ETRComputation/CEComputation/GLoBEIncomeAdjustments',
		'JurisdictionSection/GLoBETax/ETR/ETRComputation/CEComputation/AdjustedCoveredTaxes',
	];

	/**
	 * The schema set the file is validated against.
	 *
	 * @var CbcXsdSet
	 */
	private readonly CbcXsdSet $xsd;

	/**
	 * Constructor.
	 *
	 * @param string|null $xsdFolder The folder holding the GIR set; null for the bundled one.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function __construct(?string $xsdFolder = null) {
		$this->xsd = CbcXsdSet::forGir(folder: $xsdFolder);
	}//end __construct()

	/**
	 * Validate a rendered file against the GIR schema set.
	 *
	 * @param string $xml The file.
	 *
	 * @return array{valid:bool,errors:array<int,array{line:int,element:string,message:string}>,schemaVersion:string,xsdChecksum:string}
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function validate(string $xml): array {
		return $this->xsd->validate(xml: $xml);
	}//end validate()

	/**
	 * Every mandatory element the records cannot fill, per jurisdiction and entity.
	 *
	 * A jurisdiction under a passed safe harbour test needs its election only.
	 * A computed jurisdiction needs its computation and, per constituent entity
	 * resident there, the entity's GloBE income, adjustments and covered taxes.
	 * Every entity needs a tax identification number.
	 *
	 * @param array<string,mixed> $records The records of CbcrCheckRunner::girRecords().
	 *
	 * @return array<int,array{jurisdiction:string,entity:string,element:string}>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function missingElements(array $records): array {
		$missing = [];
		if (($records['upe'] ?? null) === null) {
			$missing[] = ['jurisdiction' => '', 'entity' => '', 'element' => 'FilingInfo/FilingCE'];
		}

		$entities = (array)($records['entities'] ?? []);
		foreach ($entities as $entity) {
			if (self::tinOf(entity: (array)$entity)['value'] === self::NO_TIN) {
				$missing[] = ['jurisdiction' => self::jurisdictionOf(entity: (array)$entity), 'entity' => self::nameOf(entity: (array)$entity), 'element' => 'TIN'];
			}
		}

		foreach ((array)($records['jurisdictions'] ?? []) as $code) {
			if (self::passedTest(records: $records, code: (string)$code) !== null) {
				continue;
			}

			$missing = array_merge($missing, self::computedJurisdictionGaps(records: $records, code: (string)$code));
		}

		return $missing;
	}//end missingElements()

	/**
	 * Render the file.
	 *
	 * @param array<string,mixed> $file The parts: `return` (GlobeInformationReturn), `upe`, `entities`,
	 *                                  `safeHarbours`, `computations`, `jurisdictions` (see
	 *                                  CbcrCheckRunner::girRecords()), `messageRefId`, `timestamp`
	 *                                  (ISO 8601), `docRefIds` (record key => DocRefId), `language`
	 *                                  and `currency`.
	 *
	 * @return string The XML.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function render(array $file): string {
		$namespace = $this->xsd->targetNamespace();
		if ($namespace === '') {
			$namespace = self::NS_GIR;
		}

		$doc = new DOMDocument('1.0', 'UTF-8');

		$doc->formatOutput = true;

		$root = $doc->createElementNS($namespace, 'gir:GLOBE_OECD');
		$root->setAttribute('version', self::VERSION);
		$doc->appendChild($root);

		$builder = new GirXmlBuilder(doc: $doc, namespace: $namespace);
		$root->appendChild($builder->messageSpec(file: $file));

		$body = $builder->element(name: 'GLOBEBody');
		$root->appendChild($body);
		$body->appendChild($builder->filingInfo(file: $file));
		$body->appendChild($builder->generalSection(file: $file));

		foreach ((array)($file['jurisdictions'] ?? []) as $code) {
			$body->appendChild($builder->summary(file: $file, code: (string)$code));
		}

		foreach ((array)($file['jurisdictions'] ?? []) as $code) {
			$body->appendChild($builder->jurisdictionSection(file: $file, code: (string)$code));
		}

		return (string)$doc->saveXML();
	}//end render()

	/**
	 * The record keys a DocSpec is written for: the filing info, the general section and each jurisdiction.
	 *
	 * @param string            $returnId      The return.
	 * @param array<int,string> $jurisdictions The jurisdictions.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function docRefKeys(string $returnId, array $jurisdictions): array {
		$keys = ['filing-info:' . $returnId, 'general-section:' . $returnId];
		foreach ($jurisdictions as $code) {
			$keys[] = 'jurisdiction:' . strtoupper((string)$code);
		}

		return $keys;
	}//end docRefKeys()

	/**
	 * An entity's TIN as the GIR asks it: a Dutch RSIN padded to nine digits with issuer NL and type GIR3001.
	 *
	 * @param array<string,mixed> $entity The GroupEntityRegistry row.
	 *
	 * @return array{value:string,issuedBy:string,type:string} `type` is '' for a foreign TIN.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function tinOf(array $entity): array {
		$tin      = trim((string)($entity['tin'] ?? ''));
		$issuedBy = strtoupper(trim((string)($entity['tinIssuedBy'] ?? '')));
		if ($issuedBy === '') {
			$issuedBy = self::jurisdictionOf(entity: $entity);
		}

		if ($tin === '') {
			return ['value' => self::NO_TIN, 'issuedBy' => $issuedBy, 'type' => ''];
		}

		if ($issuedBy === 'NL') {
			$digits = (string)preg_replace('/\D/', '', $tin);
			if ($digits !== '' && strlen($digits) <= 9) {
				return ['value' => str_pad($digits, 9, '0', STR_PAD_LEFT), 'issuedBy' => 'NL', 'type' => self::DUTCH_TIN_TYPE];
			}
		}

		return ['value' => $tin, 'issuedBy' => $issuedBy, 'type' => ''];
	}//end tinOf()

	/**
	 * The jurisdiction an entity is resident in: its tax residency, else its jurisdiction.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function jurisdictionOf(array $entity): string {
		$code = trim((string)($entity['taxResidency'] ?? ''));
		if ($code === '') {
			$code = trim((string)($entity['jurisdiction'] ?? ''));
		}

		return strtoupper($code);
	}//end jurisdictionOf()

	/**
	 * The test a jurisdiction passed, null when it passed none.
	 *
	 * @param array<string,mixed> $records The records.
	 * @param string              $code    The jurisdiction.
	 *
	 * @return string|null The test applied.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function passedTest(array $records, string $code): ?string {
		foreach ((array)($records['safeHarbours'] ?? []) as $test) {
			$test = (array)$test;
			if (strtoupper((string)($test['jurisdiction'] ?? '')) === $code && (string)($test['testResult'] ?? '') === 'pass') {
				return (string)($test['testApplied'] ?? '');
			}
		}

		return null;
	}//end passedTest()

	/**
	 * A jurisdiction's computation, null when there is none.
	 *
	 * @param array<string,mixed> $records The records.
	 * @param string              $code    The jurisdiction.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function computationOf(array $records, string $code): ?array {
		foreach ((array)($records['computations'] ?? []) as $computation) {
			if (strtoupper((string)(((array)$computation)['jurisdiction'] ?? '')) === $code) {
				return (array)$computation;
			}
		}

		return null;
	}//end computationOf()

	/**
	 * The entities resident in a jurisdiction.
	 *
	 * @param array<int,mixed> $entities The entities.
	 * @param string           $code     The jurisdiction.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function residentsOf(array $entities, string $code): array {
		$residents = [];
		foreach ($entities as $entity) {
			if (self::jurisdictionOf(entity: (array)$entity) === $code) {
				$residents[] = (array)$entity;
			}
		}

		return $residents;
	}//end residentsOf()

	/**
	 * An entity's name, its id when it has none.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function nameOf(array $entity): string {
		$name = trim((string)($entity['entityName'] ?? ''));
		if ($name === '') {
			$name = (string)($entity['id'] ?? '');
		}

		return $name;
	}//end nameOf()

	/**
	 * What a jurisdiction without a passed test lacks: its computation, its entities, their per-entity figures.
	 *
	 * @param array<string,mixed> $records The records.
	 * @param string              $code    The jurisdiction.
	 *
	 * @return array<int,array{jurisdiction:string,entity:string,element:string}>
	 */
	private static function computedJurisdictionGaps(array $records, string $code): array {
		$gaps = [];
		if (self::computationOf(records: $records, code: $code) === null) {
			$gaps[] = ['jurisdiction' => $code, 'entity' => '', 'element' => 'JurisdictionSection/GLoBETax/ETR'];
		}

		$residents = self::residentsOf(entities: (array)($records['entities'] ?? []), code: $code);
		if ($residents === []) {
			$gaps[] = ['jurisdiction' => $code, 'entity' => '', 'element' => 'JurisdictionSection/GLoBETax/ETR/ETRComputation/CEComputation'];
		}

		foreach ($residents as $entity) {
			foreach (self::ENTITY_ELEMENTS as $element) {
				$gaps[] = ['jurisdiction' => $code, 'entity' => self::nameOf(entity: $entity), 'element' => $element];
			}
		}

		return $gaps;
	}//end computedJurisdictionGaps()
}//end class
