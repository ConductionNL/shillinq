<?php

/**
 * Unit tests for CbcXsdSet.
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Cbcr;

use OCA\Shillinq\Service\Cbcr\CbcXmlDocument;
use OCA\Shillinq\Service\Cbcr\CbcXsdSet;
use OCA\Shillinq\Service\Cbcr\GirXmlDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validation against a schema set in a folder, and the refusal while none is there.
 *
 * The fixture schema here stands in for the OECD set, which the change bundles
 * by hand (task 2.1): it asks for a CBC_OECD root with a version and a
 * MessageSpec holding a MessageRefId.
 */
#[CoversClass(CbcXsdSet::class)]
#[UsesClass(CbcXmlDocument::class)]
#[UsesClass(GirXmlDocument::class)]
final class CbcXsdSetTest extends TestCase {

	/**
	 * The fixture folder.
	 *
	 * @var string
	 */
	private string $folder = '';

	/**
	 * A folder with a small schema under the main schema's name.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->folder = sys_get_temp_dir() . '/cbc-xsd-' . bin2hex(random_bytes(4));
		mkdir($this->folder);
		file_put_contents($this->folder . '/' . CbcXsdSet::MAIN_XSD, self::fixtureXsd());
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
	 * The fixture schema.
	 *
	 * @return string
	 */
	public static function fixtureXsd(): string {
		return <<<XSD
<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:oecd:ties:cbc:v2"
  xmlns:cbc="urn:oecd:ties:cbc:v2" elementFormDefault="qualified">
  <xs:element name="CBC_OECD">
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
        <xs:any namespace="##any" processContents="skip" minOccurs="0" maxOccurs="unbounded"/>
      </xs:sequence>
      <xs:attribute name="version" type="xs:string" use="required"/>
    </xs:complexType>
  </xs:element>
</xs:schema>
XSD;
	}//end fixtureXsd()

	/**
	 * Without the set the file is not valid, and the answer says why.
	 *
	 * @return void
	 */
	public function testWithoutTheSetNothingIsValid(): void {
		$set = new CbcXsdSet($this->folder . '/missing');

		self::assertFalse($set->isBundled());
		self::assertSame('', $set->checksum());
		$outcome = $set->validate('<cbc:CBC_OECD xmlns:cbc="urn:oecd:ties:cbc:v2" version="2.0"/>');
		self::assertFalse($outcome['valid']);
		self::assertStringContainsString('lib/Standards/Xsd/oecd-cbc-v2.0', $outcome['errors'][0]['message']);
		self::assertSame('2.0', $outcome['schemaVersion']);
	}//end testWithoutTheSetNothingIsValid()

	/**
	 * The default folder is the bundled one, which the change has not filled yet.
	 *
	 * @return void
	 */
	public function testTheDefaultFolderIsTheBundledOne(): void {
		$expected = is_file(__DIR__ . '/../../../../lib/Standards/Xsd/oecd-cbc-v2.0/' . CbcXsdSet::MAIN_XSD);

		self::assertSame($expected, (new CbcXsdSet())->isBundled());
	}//end testTheDefaultFolderIsTheBundledOne()

	/**
	 * A file that matches the schema is valid and carries the set's checksum.
	 *
	 * @return void
	 */
	public function testAMatchingFileIsValid(): void {
		$set     = new CbcXsdSet($this->folder);
		$outcome = $set->validate(
			'<cbc:CBC_OECD xmlns:cbc="urn:oecd:ties:cbc:v2" version="2.0">'
			. '<cbc:MessageSpec><cbc:MessageRefId>M-1</cbc:MessageRefId></cbc:MessageSpec></cbc:CBC_OECD>'
		);

		self::assertTrue($outcome['valid']);
		self::assertSame([], $outcome['errors']);
		self::assertSame(64, strlen($outcome['xsdChecksum']));
		self::assertSame($set->checksum(), $outcome['xsdChecksum']);
	}//end testAMatchingFileIsValid()

	/**
	 * A file that breaks the schema lists each error with its line and element.
	 *
	 * @return void
	 */
	public function testABreachIsListedByLineAndElement(): void {
		$outcome = (new CbcXsdSet($this->folder))->validate(
			"<cbc:CBC_OECD xmlns:cbc=\"urn:oecd:ties:cbc:v2\">\n<cbc:MessageSpec><cbc:Other/></cbc:MessageSpec></cbc:CBC_OECD>"
		);

		self::assertFalse($outcome['valid']);
		self::assertGreaterThanOrEqual(2, count($outcome['errors']));
		$elements = array_column($outcome['errors'], 'element');
		self::assertContains('CBC_OECD', $elements);
		self::assertContains('MessageSpec', $elements);
		self::assertSame(2, $outcome['errors'][array_search('MessageSpec', $elements, true)]['line']);
	}//end testABreachIsListedByLineAndElement()

	/**
	 * A file that is not XML is not valid.
	 *
	 * @return void
	 */
	public function testNotXmlIsNotValid(): void {
		$outcome = (new CbcXsdSet($this->folder))->validate('not xml');

		self::assertFalse($outcome['valid']);
		self::assertNotSame([], $outcome['errors']);
	}//end testNotXmlIsNotValid()

	/**
	 * The checksum follows the files: it changes when a schema file changes or is added.
	 *
	 * @return void
	 */
	public function testTheChecksumFollowsTheFiles(): void {
		$set    = new CbcXsdSet($this->folder);
		$before = $set->checksum();
		self::assertSame($before, $set->checksum());

		file_put_contents($this->folder . '/oecdcbctypes_v5.0.xsd', '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>');
		self::assertNotSame($before, $set->checksum());
	}//end testTheChecksumFollowsTheFiles()

	/**
	 * The GIR set has its own folder, main schema and version, and reads the namespace its main schema declares.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 *
	 * @return void
	 */
	public function testTheGirSetIsItsOwnFolder(): void {
		$missing = CbcXsdSet::forGir($this->folder . '/missing');
		self::assertFalse($missing->isBundled());
		self::assertSame('', $missing->targetNamespace());
		$outcome = $missing->validate('<x/>');
		self::assertStringContainsString('lib/Standards/Xsd/oecd-gir-2025-01', $outcome['errors'][0]['message']);
		self::assertSame('1.0', $outcome['schemaVersion']);

		self::assertFalse(CbcXsdSet::forGir($this->folder)->isBundled(), 'The CbC main schema is not the GIR one.');
		file_put_contents($this->folder . '/' . CbcXsdSet::GIR_MAIN_XSD, GirXmlDocumentTest::fixtureXsd());
		$gir = CbcXsdSet::forGir($this->folder);
		self::assertTrue($gir->isBundled());
		self::assertSame('urn:test:gir:v1', $gir->targetNamespace());
		self::assertSame('urn:oecd:ties:cbc:v2', (new CbcXsdSet($this->folder))->targetNamespace());

		$expected = is_file(__DIR__ . '/../../../../lib/Standards/Xsd/oecd-gir-2025-01/' . CbcXsdSet::GIR_MAIN_XSD);
		self::assertSame($expected, CbcXsdSet::forGir()->isBundled());
	}//end testTheGirSetIsItsOwnFolder()
}//end class
