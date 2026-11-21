<?php

/**
 * Unit tests for GirXmlDocument and GirXmlBuilder.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Cbcr;

use DOMDocument;
use DOMXPath;
use OCA\Shillinq\Service\Cbcr\CbcXsdSet;
use OCA\Shillinq\Service\Cbcr\GirRecordLookup;
use OCA\Shillinq\Service\Cbcr\GirXmlBuilder;
use OCA\Shillinq\Service\Cbcr\GirXmlDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The gaps a GIR file has, the TIN rule, the rendered blocks and validation against a set.
 *
 * The fixture schema stands in for the OECD GIR set, which the change bundles
 * by hand (task 2.1): it asks for a GLOBE_OECD root with a version, a
 * MessageSpec holding a MessageRefId and a GLOBEBody.
 */
#[CoversClass(GirXmlDocument::class)]
#[CoversClass(GirXmlBuilder::class)]
#[CoversClass(GirRecordLookup::class)]
#[UsesClass(CbcXsdSet::class)]
final class GirXmlDocumentTest extends TestCase {

	public const HOLDING = [
		'id' => 'ge-holding', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'taxResidency' => 'NL', 'tin' => '1234567', 'tinIssuedBy' => 'NL',
	];

	public const GMBH = [
		'id' => 'ge-gmbh', 'entityName' => 'Voorbeeld GmbH', 'jurisdiction' => 'DE', 'tin' => 'DE123', 'tinIssuedBy' => 'DE',
		'ultimateParentEntity' => 'ge-holding', 'parentEntity' => 'ge-holding', 'consolidationPercentage' => 80,
	];

	public const IRELAND = [
		'id' => 'ge-ie', 'entityName' => 'Voorbeeld Ireland Ltd', 'taxResidency' => 'IE', 'tin' => 'IE9', 'ultimateParentEntity' => 'ge-holding',
		'excludedEntityType' => 'GIR701',
	];

	/**
	 * The fixture folder.
	 *
	 * @var string
	 */
	private string $folder = '';

	/**
	 * A folder with a small schema under the GIR main schema's name.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->folder = sys_get_temp_dir() . '/gir-xsd-' . bin2hex(random_bytes(4));
		mkdir($this->folder);
		file_put_contents($this->folder . '/' . CbcXsdSet::GIR_MAIN_XSD, self::fixtureXsd());
	}//end setUp()

	/**
	 * Remove the fixture folder.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->folder . '/*') as $file) {
			unlink($file);
		}

		rmdir($this->folder);
	}//end tearDown()

	/**
	 * The fixture schema, in a namespace of its own so the document must read it from the set.
	 *
	 * @return string
	 */
	public static function fixtureXsd(): string {
		return <<<XSD
<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:test:gir:v1"
  xmlns:gir="urn:test:gir:v1" elementFormDefault="qualified">
  <xs:element name="GLOBE_OECD">
    <xs:complexType>
      <xs:sequence>
        <xs:element name="MessageSpec">
          <xs:complexType>
            <xs:sequence>
              <xs:any namespace="##targetNamespace" processContents="skip" minOccurs="0" maxOccurs="5"/>
              <xs:element name="MessageRefId" type="xs:string"/>
              <xs:any namespace="##targetNamespace" processContents="skip" minOccurs="0" maxOccurs="unbounded"/>
            </xs:sequence>
          </xs:complexType>
        </xs:element>
        <xs:element name="GLOBEBody">
          <xs:complexType>
            <xs:sequence>
              <xs:any namespace="##targetNamespace" processContents="skip" minOccurs="0" maxOccurs="unbounded"/>
            </xs:sequence>
          </xs:complexType>
        </xs:element>
      </xs:sequence>
      <xs:attribute name="version" type="xs:string" use="required"/>
    </xs:complexType>
  </xs:element>
</xs:schema>
XSD;
	}//end fixtureXsd()

	/**
	 * The records of a group whose NL and DE jurisdictions pass a safe harbour test.
	 *
	 * @return array<string,mixed>
	 */
	public static function safeHarbourRecords(): array {
		return [
			'upe' => self::HOLDING,
			'entities' => [self::HOLDING, self::GMBH],
			'safeHarbours' => [
				['jurisdiction' => 'NL', 'testApplied' => 'simplified-etr', 'testResult' => 'pass'],
				['jurisdiction' => 'DE', 'testApplied' => 'de-minimis', 'testResult' => 'fail'],
				['jurisdiction' => 'DE', 'testApplied' => 'simplified-etr', 'testResult' => 'pass'],
			],
			'computations' => [],
			'jurisdictions' => ['DE', 'NL'],
		];
	}//end safeHarbourRecords()

	/**
	 * The file parts over records.
	 *
	 * @param array<string,mixed> $records The records.
	 *
	 * @return array<string,mixed>
	 */
	private static function file(array $records): array {
		return $records + [
			'return' => ['id' => 'g-2026', 'period' => 'FY2026'],
			'messageRefId' => 'NL2026GIR001234567-abc',
			'timestamp' => '2027-03-01T10:00:00',
			'docRefIds' => ['filing-info:g-2026' => 'D-1', 'general-section:g-2026' => 'D-2', 'jurisdiction:NL' => 'D-3'],
			'language' => 'EN',
			'currency' => 'EUR',
		];
	}//end file()

	/**
	 * An XPath over a file in the fixture namespace.
	 *
	 * @param string $xml       The file.
	 * @param string $namespace The namespace the file is in.
	 *
	 * @return DOMXPath
	 */
	private static function xpath(string $xml, string $namespace = 'urn:test:gir:v1'): DOMXPath {
		$doc = new DOMDocument();
		self::assertTrue($doc->loadXML($xml));
		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('gir', $namespace);

		return $xpath;
	}//end xpath()

	/**
	 * A Dutch RSIN is padded to nine digits with issuer NL and type GIR3001; a foreign TIN is kept.
	 *
	 * @return void
	 */
	public function testTheDutchTinRule(): void {
		$document = new GirXmlDocument($this->folder);
		self::assertSame(['value' => '001234567', 'issuedBy' => 'NL', 'type' => 'GIR3001'], $document->tinOf(self::HOLDING));
		self::assertSame(['value' => '001234567', 'issuedBy' => 'NL', 'type' => 'GIR3001'], $document->tinOf(['tin' => '1234567', 'taxResidency' => 'nl']));
		self::assertSame(['value' => 'DE123', 'issuedBy' => 'DE', 'type' => ''], $document->tinOf(self::GMBH));
		self::assertSame(['value' => 'NOTIN', 'issuedBy' => 'IE', 'type' => ''], $document->tinOf(['taxResidency' => 'IE']));
		self::assertSame('NL1234567890', $document->tinOf(['tin' => 'NL1234567890', 'tinIssuedBy' => 'NL'])['value']);
	}//end testTheDutchTinRule()

	/**
	 * A group with every jurisdiction under safe harbour lacks nothing.
	 *
	 * @return void
	 */
	public function testEveryJurisdictionUnderSafeHarbourLacksNothing(): void {
		self::assertSame([], (new GirXmlDocument($this->folder))->missingElements(self::safeHarbourRecords()));
	}//end testEveryJurisdictionUnderSafeHarbourLacksNothing()

	/**
	 * A computed jurisdiction lists the per-entity elements of each resident entity.
	 *
	 * @return void
	 */
	public function testAComputedJurisdictionListsItsEntityElements(): void {
		$records                  = self::safeHarbourRecords();
		$records['entities'][]    = self::IRELAND;
		$records['computations']  = [['jurisdiction' => 'IE', 'state' => 'draft']];
		$records['jurisdictions'] = ['DE', 'IE', 'NL'];

		$missing = (new GirXmlDocument($this->folder))->missingElements($records);

		self::assertCount(3, $missing);
		self::assertSame(['IE'], array_values(array_unique(array_column($missing, 'jurisdiction'))));
		self::assertSame(['Voorbeeld Ireland Ltd'], array_values(array_unique(array_column($missing, 'entity'))));
		self::assertSame(GirXmlDocument::ENTITY_ELEMENTS, array_column($missing, 'element'));
	}//end testAComputedJurisdictionListsItsEntityElements()

	/**
	 * Without a computation, a resident entity, a parent or a TIN, each gap is named.
	 *
	 * @return void
	 */
	public function testEveryOtherGapIsNamed(): void {
		$records = [
			'upe' => null,
			'entities' => [['id' => 'ge-x', 'taxResidency' => 'BE']],
			'safeHarbours' => [],
			'computations' => [],
			'jurisdictions' => ['FR'],
		];

		$missing = (new GirXmlDocument($this->folder))->missingElements($records);

		self::assertContains(['jurisdiction' => '', 'entity' => '', 'element' => 'FilingInfo/FilingCE'], $missing);
		self::assertContains(['jurisdiction' => 'BE', 'entity' => 'ge-x', 'element' => 'TIN'], $missing);
		self::assertContains(['jurisdiction' => 'FR', 'entity' => '', 'element' => 'JurisdictionSection/GLoBETax/ETR'], $missing);
		$noResident = ['jurisdiction' => 'FR', 'entity' => '', 'element' => 'JurisdictionSection/GLoBETax/ETR/ETRComputation/CEComputation'];
		self::assertContains($noResident, $missing);
		self::assertCount(4, $missing);
	}//end testEveryOtherGapIsNamed()

	/**
	 * The file claims the safe harbour for NL and DE, carries the structure and passes the set.
	 *
	 * @return void
	 */
	public function testASafeHarbourFileRendersAndValidates(): void {
		$document = new GirXmlDocument($this->folder);
		$xml      = $document->render(self::file(self::safeHarbourRecords()));
		$xpath    = self::xpath($xml);

		self::assertSame('1.0', $xpath->query('/gir:GLOBE_OECD/@version')->item(0)?->textContent);
		self::assertSame('GIR', $xpath->query('//gir:MessageSpec/gir:MessageType')->item(0)?->textContent);
		self::assertSame('GIR401', $xpath->query('//gir:MessageSpec/gir:MessageTypeIndic')->item(0)?->textContent);
		self::assertSame('2026-12-31', $xpath->query('//gir:MessageSpec/gir:ReportingPeriod')->item(0)?->textContent);
		self::assertSame('001234567', $xpath->query('//gir:MessageSpec/gir:SendingEntityIN')->item(0)?->textContent);

		$tin = $xpath->query('//gir:FilingInfo/gir:FilingCE/gir:TIN')->item(0);
		self::assertSame('001234567', $tin?->textContent);
		self::assertSame('NL', $tin?->attributes?->getNamedItem('issuedBy')?->nodeValue);
		self::assertSame('GIR3001', $tin?->attributes?->getNamedItem('TypeOfTIN')?->nodeValue);
		self::assertSame('2026-01-01', $xpath->query('//gir:FilingInfo/gir:Period/gir:Start')->item(0)?->textContent);
		self::assertSame('D-1', $xpath->query('//gir:FilingInfo/gir:DocSpec/gir:DocRefId')->item(0)?->textContent);

		self::assertSame('Voorbeeld Groep Holding B.V.', $xpath->query('//gir:CorporateStructure/gir:UPE/gir:Name')->item(0)?->textContent);
		self::assertSame(1, $xpath->query('//gir:CorporateStructure/gir:CE')->length);
		self::assertSame('001234567', $xpath->query('//gir:CE/gir:Ownership/gir:OwnerTIN')->item(0)?->textContent);
		self::assertSame('0.8000', $xpath->query('//gir:CE/gir:Ownership/gir:OwnershipPercentage')->item(0)?->textContent);

		self::assertSame(['DE', 'NL'], self::texts($xpath, '//gir:Summary/gir:Jurisdiction'));
		self::assertSame(['simplified-etr', 'simplified-etr'], self::texts($xpath, '//gir:Summary/gir:SafeHarbour'));
		self::assertSame(2, $xpath->query('//gir:JurisdictionSection/gir:SafeHarbourElection')->length);
		self::assertSame(0, $xpath->query('//gir:GLoBETax')->length);

		self::assertTrue($document->validate($xml)['valid']);
	}//end testASafeHarbourFileRendersAndValidates()

	/**
	 * A computed NL jurisdiction carries its ETR computation and the QDMTT amount; an excluded entity its type.
	 *
	 * @return void
	 */
	public function testAComputedDutchJurisdictionCarriesTheQdmtt(): void {
		$records                  = self::safeHarbourRecords();
		$records['entities'][]    = self::IRELAND;
		$records['safeHarbours']  = [];
		$records['computations']  = [
			[
				'jurisdiction' => 'NL', 'etrJurisdiction' => 0.12, 'globeIncome' => 5500000.4, 'adjustedCoveredTaxes' => 660000,
				'substanceBasedIncomeExclusion' => 0, 'topUpTaxAmount' => 165000, 'qdmttApplicable' => true, 'qdmttAmount' => 165000,
			],
		];
		$records['jurisdictions'] = ['NL'];

		$xpath = self::xpath((new GirXmlDocument($this->folder))->render(self::file($records)));

		self::assertSame('0.1200', $xpath->query('//gir:Summary/gir:ETRRate')->item(0)?->textContent);
		self::assertSame('false', $xpath->query('//gir:Summary/gir:SBIE')->item(0)?->textContent);
		self::assertSame('5500000', $xpath->query('//gir:GLoBETax/gir:ETR/gir:GLoBEIncome')->item(0)?->textContent);
		self::assertSame('EUR', $xpath->query('//gir:GLoBETax/gir:ETR/gir:GLoBEIncome/@currCode')->item(0)?->textContent);
		self::assertSame('165000', $xpath->query('//gir:GLoBETax/gir:QDMTT/gir:Amount')->item(0)?->textContent);
		self::assertSame('EUR', $xpath->query('//gir:GLoBETax/gir:QDMTT/gir:Currency')->item(0)?->textContent);
		self::assertSame('GIR701', $xpath->query('//gir:CE/gir:ExcludedEntity')->item(0)?->textContent);
		self::assertSame(0, $xpath->query('//gir:SafeHarbourElection')->length);
	}//end testAComputedDutchJurisdictionCarriesTheQdmtt()

	/**
	 * Without a bundled set the fallback namespace is used and nothing is valid.
	 *
	 * @return void
	 */
	public function testWithoutTheSetTheFileIsNotValid(): void {
		$document = new GirXmlDocument($this->folder . '/missing');
		$xml      = $document->render(self::file(self::safeHarbourRecords()));

		self::assertSame(1, self::xpath($xml, GirXmlDocument::NS_GIR)->query('/gir:GLOBE_OECD')->length);
		$outcome = $document->validate($xml);
		self::assertFalse($outcome['valid']);
		self::assertStringContainsString('lib/Standards/Xsd/oecd-gir-2025-01', $outcome['errors'][0]['message']);
		self::assertSame('1.0', $outcome['schemaVersion']);
	}//end testWithoutTheSetTheFileIsNotValid()

	/**
	 * The DocSpec keys: the filing info, the general section and each jurisdiction.
	 *
	 * @return void
	 */
	public function testTheDocRefKeys(): void {
		self::assertSame(
			['filing-info:g-1', 'general-section:g-1', 'jurisdiction:DE', 'jurisdiction:NL'],
			(new GirXmlDocument($this->folder))->docRefKeys('g-1', ['de', 'NL'])
		);
	}//end testTheDocRefKeys()

	/**
	 * The texts of the nodes a query finds.
	 *
	 * @param DOMXPath $xpath The XPath.
	 * @param string   $query The query.
	 *
	 * @return array<int,string>
	 */
	private static function texts(DOMXPath $xpath, string $query): array {
		$texts = [];
		foreach ($xpath->query($query) as $node) {
			$texts[] = (string)$node->textContent;
		}

		return $texts;
	}//end texts()
}//end class
