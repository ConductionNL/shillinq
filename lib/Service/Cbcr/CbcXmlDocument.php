<?php

/**
 * Shillinq country-by-country XML document
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
 * Renders a country-by-country report in the OECD CbC XML Schema v2.0 layout.
 *
 * The document is built from records only: the return, its reporting entity,
 * the group's included entities and the jurisdiction summaries. It holds no
 * lookup of its own, so the same records always render the same XML for the
 * same message reference, document references and timestamp. Validation
 * against the bundled schema set is the caller's next step (CbcXsdSet).
 *
 * Element order follows CbcXML_v2.0.xsd: MessageSpec, then CbcBody with
 * ReportingEntity, one CbcReports per jurisdiction and AdditionalInfo.
 * Amounts are whole units with `currCode`, as MonAmnt_Type asks.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 */
final class CbcXmlDocument {

	/**
	 * The CbC message namespace.
	 */
	public const NS_CBC = 'urn:oecd:ties:cbc:v2';

	/**
	 * The OECD common types namespace of the DocSpec.
	 */
	public const NS_STF = 'urn:oecd:ties:cbcstf:v5';

	/**
	 * The schema version written on the root.
	 */
	public const VERSION = '2.0';

	/**
	 * The TIN value of an entity without a tax identification number.
	 */
	public const NO_TIN = 'NOTIN';

	/**
	 * Render the file.
	 *
	 * @param array<string,mixed> $file The parts:
	 *                                  `return` (CbcrReturn), `reportingEntity` (GroupEntityRegistry),
	 *                                  `upeId`, `groupName`, `entities` (included GroupEntityRegistry
	 *                                  rows), `summaries` (CbcrJurisdictionSummary rows),
	 *                                  `messageRefId`, `timestamp` (ISO 8601), `docRefIds`
	 *                                  (record id => DocRefId), `language`, `currency`,
	 *                                  optional `correction` (see CbcCorrectionPlan) and `additionalInfo`.
	 *
	 * @return string The XML.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function render(array $file): string {
		$doc = new DOMDocument('1.0', 'UTF-8');

		$doc->formatOutput = true;

		$root = $doc->createElementNS(self::NS_CBC, 'cbc:CBC_OECD');
		$root->setAttribute('version', self::VERSION);
		$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:stf', self::NS_STF);
		$doc->appendChild($root);

		$root->appendChild(self::messageSpec(doc: $doc, file: $file));

		$body = self::cbc(doc: $doc, name: 'CbcBody');
		$root->appendChild($body);
		$body->appendChild(self::reportingEntity(doc: $doc, file: $file));

		foreach (self::summariesInOrder(summaries: (array)($file['summaries'] ?? [])) as $summary) {
			if (self::sentInThisFile(file: $file, recordId: (string)($summary['id'] ?? '')) === false) {
				continue;
			}

			$body->appendChild(self::cbcReports(doc: $doc, file: $file, summary: $summary));
		}

		$note = trim((string)($file['additionalInfo'] ?? ''));
		if ($note !== '' && isset($file['correction']) === false) {
			$body->appendChild(self::additionalInfo(doc: $doc, file: $file, note: $note));
		}

		return (string)$doc->saveXML();
	}//end render()

	/**
	 * The record key of the reporting entity block in the return's docRefIds.
	 *
	 * @param string $returnId The CbcrReturn id.
	 *
	 * @return string The record key the return's docRefIds use for it.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function reportingEntityKey(string $returnId): string {
		return self::recordKey(prefix: 'reporting-entity:', returnId: $returnId);
	}//end reportingEntityKey()

	/**
	 * The additional information block's record key.
	 *
	 * @param string $returnId The CbcrReturn id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function additionalInfoKey(string $returnId): string {
		return self::recordKey(prefix: 'additional-info:', returnId: $returnId);
	}//end additionalInfoKey()

	/**
	 * The record key of a block that belongs to the return rather than to a record of its own.
	 *
	 * @param string $prefix   The block's prefix.
	 * @param string $returnId The CbcrReturn id.
	 *
	 * @return string
	 */
	private static function recordKey(string $prefix, string $returnId): string {
		return $prefix . $returnId;
	}//end recordKey()

	/**
	 * The message header.
	 *
	 * @param DOMDocument         $doc  The document.
	 * @param array<string,mixed> $file The parts.
	 *
	 * @return DOMElement
	 */
	private static function messageSpec(DOMDocument $doc, array $file): DOMElement {
		$entity = (array)($file['reportingEntity'] ?? []);
		$spec   = self::cbc(doc: $doc, name: 'MessageSpec');
		$spec->appendChild(self::cbc(doc: $doc, name: 'SendingEntityIN', text: self::tinOf(entity: $entity)));
		$spec->appendChild(self::cbc(doc: $doc, name: 'TransmittingCountry', text: 'NL'));
		$spec->appendChild(self::cbc(doc: $doc, name: 'ReceivingCountry', text: 'NL'));
		$spec->appendChild(self::cbc(doc: $doc, name: 'MessageType', text: 'CBC'));
		$spec->appendChild(self::cbc(doc: $doc, name: 'Language', text: strtoupper((string)($file['language'] ?? 'EN'))));
		$spec->appendChild(self::cbc(doc: $doc, name: 'MessageRefId', text: (string)($file['messageRefId'] ?? '')));

		$indic = 'CBC401';
		if (isset($file['correction']) === true) {
			$indic = 'CBC402';
		}

		$spec->appendChild(self::cbc(doc: $doc, name: 'MessageTypeIndic', text: $indic));
		$spec->appendChild(self::cbc(doc: $doc, name: 'ReportingPeriod', text: self::yearEnd(file: $file)));
		$spec->appendChild(self::cbc(doc: $doc, name: 'Timestamp', text: (string)($file['timestamp'] ?? '')));

		return $spec;
	}//end messageSpec()

	/**
	 * The reporting entity block.
	 *
	 * @param DOMDocument         $doc  The document.
	 * @param array<string,mixed> $file The parts.
	 *
	 * @return DOMElement
	 */
	private static function reportingEntity(DOMDocument $doc, array $file): DOMElement {
		$return = (array)($file['return'] ?? []);
		$entity = (array)($file['reportingEntity'] ?? []);
		$block  = self::cbc(doc: $doc, name: 'ReportingEntity');
		$block->appendChild(self::organisation(doc: $doc, name: 'Entity', entity: $entity));

		$group = trim((string)($file['groupName'] ?? ''));
		if ($group !== '') {
			$block->appendChild(self::cbc(doc: $doc, name: 'NameMNEGroup', text: $group));
		}

		$block->appendChild(self::cbc(doc: $doc, name: 'ReportingRole', text: (string)($return['reportingRole'] ?? 'CBC701')));

		$period = self::cbc(doc: $doc, name: 'ReportingPeriod');
		$period->appendChild(self::cbc(doc: $doc, name: 'StartDate', text: self::year(file: $file) . '-01-01'));
		$period->appendChild(self::cbc(doc: $doc, name: 'EndDate', text: self::yearEnd(file: $file)));
		$block->appendChild($period);

		$block->appendChild(
			self::docSpec(doc: $doc, file: $file, recordId: self::recordKey(prefix: 'reporting-entity:', returnId: (string)($return['id'] ?? '')), resend: true)
		);

		return $block;
	}//end reportingEntity()

	/**
	 * One jurisdiction's report.
	 *
	 * @param DOMDocument         $doc     The document.
	 * @param array<string,mixed> $file    The parts.
	 * @param array<string,mixed> $summary The CbcrJurisdictionSummary.
	 *
	 * @return DOMElement
	 */
	private static function cbcReports(DOMDocument $doc, array $file, array $summary): DOMElement {
		$code     = strtoupper((string)($summary['jurisdiction'] ?? ''));
		$currency = strtoupper((string)($file['currency'] ?? 'EUR'));
		$reports  = self::cbc(doc: $doc, name: 'CbcReports');
		$reports->appendChild(self::docSpec(doc: $doc, file: $file, recordId: (string)($summary['id'] ?? ''), resend: false));
		$reports->appendChild(self::cbc(doc: $doc, name: 'ResCountryCode', text: $code));

		$figures  = self::cbc(doc: $doc, name: 'Summary');
		$revenues = self::cbc(doc: $doc, name: 'Revenues');
		$revenues->appendChild(self::amount(doc: $doc, name: 'Unrelated', value: $summary['unrelatedPartyRevenue'] ?? 0, currency: $currency));
		$revenues->appendChild(self::amount(doc: $doc, name: 'Related', value: $summary['relatedPartyRevenue'] ?? 0, currency: $currency));
		$revenues->appendChild(self::amount(doc: $doc, name: 'Total', value: $summary['totalRevenue'] ?? 0, currency: $currency));
		$figures->appendChild($revenues);

		$amounts = [
			'ProfitOrLoss' => 'profitBeforeTax',
			'TaxPaid' => 'incomeTaxPaidCash',
			'TaxAccrued' => 'incomeTaxAccrued',
			'Capital' => 'statedCapital',
			'Earnings' => 'accumulatedEarnings',
		];
		foreach ($amounts as $element => $field) {
			$figures->appendChild(self::amount(doc: $doc, name: $element, value: $summary[$field] ?? 0, currency: $currency));
		}

		$figures->appendChild(self::cbc(doc: $doc, name: 'NbEmployees', text: (string)(int)round((float)($summary['numberOfEmployees'] ?? 0))));
		$figures->appendChild(self::amount(doc: $doc, name: 'Assets', value: $summary['tangibleAssetsOtherThanCash'] ?? 0, currency: $currency));
		$reports->appendChild($figures);

		foreach (self::residentsOf(entities: (array)($file['entities'] ?? []), code: $code) as $entity) {
			$reports->appendChild(self::constEntities(doc: $doc, file: $file, entity: $entity));
		}

		return $reports;
	}//end cbcReports()

	/**
	 * One constituent entity of a jurisdiction.
	 *
	 * @param DOMDocument         $doc    The document.
	 * @param array<string,mixed> $file   The parts.
	 * @param array<string,mixed> $entity The GroupEntityRegistry row.
	 *
	 * @return DOMElement
	 */
	private static function constEntities(DOMDocument $doc, array $file, array $entity): DOMElement {
		$block = self::cbc(doc: $doc, name: 'ConstEntities');
		$block->appendChild(self::organisation(doc: $doc, name: 'ConstEntity', entity: $entity));

		$role = self::roleOf(file: $file, entityId: (string)($entity['id'] ?? ''));
		if ($role !== '') {
			$block->appendChild(self::cbc(doc: $doc, name: 'Role', text: $role));
		}

		$incorporated = strtoupper(trim((string)($entity['incorporationCountry'] ?? '')));
		if (preg_match('/^[A-Z]{2}$/', $incorporated) === 1) {
			$block->appendChild(self::cbc(doc: $doc, name: 'IncorpCountryCode', text: $incorporated));
		}

		foreach (array_values(array_unique(array_map('strval', (array)($entity['bizActivities'] ?? [])))) as $activity) {
			$block->appendChild(self::cbc(doc: $doc, name: 'BizActivities', text: $activity));
		}

		return $block;
	}//end constEntities()

	/**
	 * The additional information block: the employee methodology and the source of the figures.
	 *
	 * @param DOMDocument         $doc  The document.
	 * @param array<string,mixed> $file The parts.
	 * @param string              $note The text.
	 *
	 * @return DOMElement
	 */
	private static function additionalInfo(DOMDocument $doc, array $file, string $note): DOMElement {
		$return = (array)($file['return'] ?? []);
		$block  = self::cbc(doc: $doc, name: 'AdditionalInfo');
		$block->appendChild(
			self::docSpec(doc: $doc, file: $file, recordId: self::recordKey(prefix: 'additional-info:', returnId: (string)($return['id'] ?? '')), resend: false)
		);
		$other = self::cbc(doc: $doc, name: 'OtherInfo', text: $note);
		$other->setAttribute('language', strtoupper((string)($file['language'] ?? 'EN')));
		$block->appendChild($other);

		return $block;
	}//end additionalInfo()

	/**
	 * An entity as OrganisationParty_Type: residence, TIN, name and address.
	 *
	 * @param DOMDocument         $doc    The document.
	 * @param string              $name   The element name.
	 * @param array<string,mixed> $entity The GroupEntityRegistry row.
	 *
	 * @return DOMElement
	 */
	private static function organisation(DOMDocument $doc, string $name, array $entity): DOMElement {
		$party = self::cbc(doc: $doc, name: $name);
		$party->appendChild(self::cbc(doc: $doc, name: 'ResCountryCode', text: self::residenceOf(entity: $entity)));

		$tin    = self::cbc(doc: $doc, name: 'TIN', text: self::tinOf(entity: $entity));
		$issuer = strtoupper(trim((string)($entity['tinIssuedBy'] ?? '')));
		if (preg_match('/^[A-Z]{2}$/', $issuer) === 1 && self::tinOf(entity: $entity) !== self::NO_TIN) {
			$tin->setAttribute('issuedBy', $issuer);
		}

		$party->appendChild($tin);
		$party->appendChild(self::cbc(doc: $doc, name: 'Name', text: (string)($entity['entityName'] ?? '')));
		$party->appendChild(self::address(doc: $doc, entity: $entity));

		return $party;
	}//end organisation()

	/**
	 * The entity's address: AddressFix when a city is known, AddressFree otherwise.
	 *
	 * @param DOMDocument         $doc    The document.
	 * @param array<string,mixed> $entity The GroupEntityRegistry row.
	 *
	 * @return DOMElement
	 */
	private static function address(DOMDocument $doc, array $entity): DOMElement {
		$address = (array)($entity['address'] ?? []);
		$country = strtoupper(trim((string)($address['country'] ?? '')));
		$block   = self::cbc(doc: $doc, name: 'Address');
		$block->appendChild(self::cbc(doc: $doc, name: 'CountryCode', text: $country));

		$street = trim((string)($address['street'] ?? ''));
		$postal = trim((string)($address['postalCode'] ?? ''));
		$city   = trim((string)($address['city'] ?? ''));
		if ($city === '') {
			$block->appendChild(self::cbc(doc: $doc, name: 'AddressFree', text: trim($street . ' ' . $postal)));
			return $block;
		}

		$fix = self::cbc(doc: $doc, name: 'AddressFix');
		if ($street !== '') {
			$fix->appendChild(self::cbc(doc: $doc, name: 'Street', text: $street));
		}

		if ($postal !== '') {
			$fix->appendChild(self::cbc(doc: $doc, name: 'PostCode', text: $postal));
		}

		$fix->appendChild(self::cbc(doc: $doc, name: 'City', text: $city));
		$block->appendChild($fix);

		return $block;
	}//end address()

	/**
	 * A DocSpec: new data in a first file, corrected or resent data in a correction.
	 *
	 * @param DOMDocument         $doc      The document.
	 * @param array<string,mixed> $file     The parts.
	 * @param string              $recordId The record key.
	 * @param bool                $resend   Whether an unchanged record is resent (the reporting entity) rather than left out.
	 *
	 * @return DOMElement
	 */
	private static function docSpec(DOMDocument $doc, array $file, string $recordId, bool $resend): DOMElement {
		$spec       = $doc->createElementNS(self::NS_STF, 'stf:DocSpec');
		$correction = $file['correction'] ?? null;
		$indic      = 'OECD1';
		$corrects   = '';
		if (is_array($correction) === true) {
			$previous = (string)(($correction['previousDocRefIds'] ?? [])[$recordId] ?? '');
			$changed  = in_array($recordId, (array)($correction['changed'] ?? []), true);
			[$indic, $corrects] = self::correctionIndic(previous: $previous, changed: $changed, resend: $resend);
		}

		$spec->appendChild($doc->createElementNS(self::NS_STF, 'stf:DocTypeIndic', $indic));
		$spec->appendChild(self::stfText(doc: $doc, name: 'DocRefId', text: (string)(($file['docRefIds'] ?? [])[$recordId] ?? '')));
		if ($corrects !== '') {
			$spec->appendChild(self::stfText(doc: $doc, name: 'CorrDocRefId', text: $corrects));
		}

		return $spec;
	}//end docSpec()

	/**
	 * The DocTypeIndic and CorrDocRefId of a record in a correction.
	 *
	 * @param string $previous The record's DocRefId in the corrected file, '' when it was not in it.
	 * @param bool   $changed  Whether the record changed.
	 * @param bool   $resend   Whether the record is resent when unchanged.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function correctionIndic(string $previous, bool $changed, bool $resend): array {
		if ($previous === '') {
			return ['OECD1', ''];
		}

		if ($changed === true) {
			return ['OECD2', $previous];
		}

		if ($resend === true) {
			return ['OECD0', $previous];
		}

		return ['OECD1', ''];
	}//end correctionIndic()

	/**
	 * Whether a record goes in this file: always in a first file, only new or changed ones in a correction.
	 *
	 * @param array<string,mixed> $file     The parts.
	 * @param string              $recordId The record id.
	 *
	 * @return bool
	 */
	private static function sentInThisFile(array $file, string $recordId): bool {
		$correction = $file['correction'] ?? null;
		if (is_array($correction) === false) {
			return true;
		}

		$previous = (string)(($correction['previousDocRefIds'] ?? [])[$recordId] ?? '');

		return $previous === '' || in_array($recordId, (array)($correction['changed'] ?? []), true) === true;
	}//end sentInThisFile()

	/**
	 * The summaries sorted by jurisdiction code, so the file is stable.
	 *
	 * @param array<int,mixed> $summaries The summaries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function summariesInOrder(array $summaries): array {
		$rows = array_values(array_filter($summaries, 'is_array'));
		usort($rows, static fn (array $one, array $two): int => strcmp((string)($one['jurisdiction'] ?? ''), (string)($two['jurisdiction'] ?? '')));

		return $rows;
	}//end summariesInOrder()

	/**
	 * The entities resident in a jurisdiction, by name.
	 *
	 * @param array<int,mixed> $entities The included entities.
	 * @param string           $code     The jurisdiction.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function residentsOf(array $entities, string $code): array {
		$rows = [];
		foreach ($entities as $entity) {
			if (is_array($entity) === true && self::residenceOf(entity: $entity) === $code) {
				$rows[] = $entity;
			}
		}

		usort($rows, static fn (array $one, array $two): int => strcmp((string)($one['entityName'] ?? ''), (string)($two['entityName'] ?? '')));

		return $rows;
	}//end residentsOf()

	/**
	 * The CbC role of a constituent entity: ultimate parent, reporting entity, or both.
	 *
	 * @param array<string,mixed> $file     The parts.
	 * @param string              $entityId The entity id.
	 *
	 * @return string CBC801, CBC802, CBC803 or ''.
	 */
	private static function roleOf(array $file, string $entityId): string {
		$isUpe       = ($entityId !== '' && $entityId === (string)($file['upeId'] ?? ''));
		$isReporting = ($entityId !== '' && $entityId === (string)(($file['reportingEntity'] ?? [])['id'] ?? ''));

		return match (true) {
			$isUpe && $isReporting => 'CBC803',
			$isUpe => 'CBC801',
			$isReporting => 'CBC802',
			default => '',
		};
	}//end roleOf()

	/**
	 * The entity's tax residence: tax residency, else jurisdiction.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return string
	 */
	private static function residenceOf(array $entity): string {
		foreach (['taxResidency', 'jurisdiction'] as $field) {
			$code = strtoupper(trim((string)($entity[$field] ?? '')));
			if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
				return $code;
			}
		}

		return '';
	}//end residenceOf()

	/**
	 * The entity's TIN, NOTIN when it has none.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return string
	 */
	private static function tinOf(array $entity): string {
		$tin = trim((string)($entity['tin'] ?? ''));
		if ($tin === '') {
			return self::NO_TIN;
		}

		return $tin;
	}//end tinOf()

	/**
	 * The fiscal year of the return.
	 *
	 * @param array<string,mixed> $file The parts.
	 *
	 * @return string
	 */
	private static function year(array $file): string {
		if (preg_match('/(\d{4})/', (string)(($file['return'] ?? [])['period'] ?? ''), $match) === 1) {
			return $match[1];
		}

		return '0000';
	}//end year()

	/**
	 * The last day of the fiscal year.
	 *
	 * @param array<string,mixed> $file The parts.
	 *
	 * @return string
	 */
	private static function yearEnd(array $file): string {
		return self::year(file: $file) . '-12-31';
	}//end yearEnd()

	/**
	 * A monetary element in whole units with its currency.
	 *
	 * @param DOMDocument $doc      The document.
	 * @param string      $name     The element name.
	 * @param mixed       $value    The amount.
	 * @param string      $currency The ISO 4217 code.
	 *
	 * @return DOMElement
	 */
	private static function amount(DOMDocument $doc, string $name, mixed $value, string $currency): DOMElement {
		$whole = 0;
		if (is_numeric($value) === true) {
			$whole = (int)round((float)$value);
		}

		$element = self::cbc(doc: $doc, name: $name, text: (string)$whole);
		$element->setAttribute('currCode', $currency);

		return $element;
	}//end amount()

	/**
	 * An element in the CbC namespace, with text when given.
	 *
	 * @param DOMDocument $doc  The document.
	 * @param string      $name The local name.
	 * @param string|null $text The text.
	 *
	 * @return DOMElement
	 */
	private static function cbc(DOMDocument $doc, string $name, ?string $text = null): DOMElement {
		$element = $doc->createElementNS(self::NS_CBC, 'cbc:' . $name);
		if ($text !== null) {
			$element->appendChild($doc->createTextNode($text));
		}

		return $element;
	}//end cbc()

	/**
	 * An element in the stf namespace with escaped text.
	 *
	 * @param DOMDocument $doc  The document.
	 * @param string      $name The local name.
	 * @param string      $text The text.
	 *
	 * @return DOMElement
	 */
	private static function stfText(DOMDocument $doc, string $name, string $text): DOMElement {
		$element = $doc->createElementNS(self::NS_STF, 'stf:' . $name);
		$element->appendChild($doc->createTextNode($text));

		return $element;
	}//end stfText()
}//end class
