<?php

/**
 * Unit tests for CbcXmlDocument.
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

use DOMDocument;
use DOMXPath;
use OCA\Shillinq\Service\Cbcr\CbcXmlDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Voorbeeld group's 2026 report rendered as a CbC v2.0 file.
 */
#[CoversClass(CbcXmlDocument::class)]
final class CbcXmlDocumentTest extends TestCase {

	/**
	 * The parts of the Voorbeeld 2026 file.
	 *
	 * @return array<string,mixed>
	 */
	private static function file(): array {
		$holding = [
			'id' => 'ge-holding', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'taxResidency' => 'NL', 'tin' => '001234567', 'tinIssuedBy' => 'NL',
			'address' => ['street' => 'Kade 1', 'postalCode' => '1011 AA', 'city' => 'Amsterdam', 'country' => 'NL'],
			'incorporationCountry' => 'NL', 'bizActivities' => ['CBC512'],
		];
		$productie = [
			'id' => 'ge-productie', 'entityName' => 'Voorbeeld Productie B.V.', 'taxResidency' => 'NL', 'tin' => '009876543', 'tinIssuedBy' => 'NL',
			'address' => ['street' => 'Fabrieksweg 3', 'postalCode' => '5600 AB', 'city' => 'Eindhoven', 'country' => 'NL'],
			'incorporationCountry' => 'NL', 'bizActivities' => ['CBC504', 'CBC504'], 'ultimateParentEntity' => 'ge-holding',
		];
		$gmbh = [
			'id' => 'ge-gmbh', 'entityName' => 'Voorbeeld GmbH', 'jurisdiction' => 'DE', 'tin' => '', 'address' => ['street' => 'Hauptstrasse 9 Berlin', 'country' => 'DE'],
			'bizActivities' => ['CBC505'], 'ultimateParentEntity' => 'ge-holding',
		];

		return [
			'return' => ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingRole' => 'CBC701'],
			'reportingEntity' => $holding,
			'upeId' => 'ge-holding',
			'groupName' => 'Voorbeeld Groep Holding B.V.',
			'entities' => [$gmbh, $productie, $holding],
			'summaries' => [
				['id' => 'sum-nl', 'jurisdiction' => 'NL', 'unrelatedPartyRevenue' => 300000000.4, 'relatedPartyRevenue' => 40000000, 'totalRevenue' => 340000000.4,
					'profitBeforeTax' => -1250.5, 'incomeTaxPaidCash' => 5000000, 'incomeTaxAccrued' => 5200000, 'statedCapital' => 18000, 'accumulatedEarnings' => 9000000,
					'numberOfEmployees' => 412, 'tangibleAssetsOtherThanCash' => 75000000],
				['id' => 'sum-de', 'jurisdiction' => 'DE', 'unrelatedPartyRevenue' => 60000000, 'relatedPartyRevenue' => 0, 'totalRevenue' => 60000000,
					'numberOfEmployees' => 38],
			],
			'messageRefId' => 'NL2026001234567-abc',
			'timestamp' => '2027-03-01T10:00:00',
			'docRefIds' => ['reporting-entity:cbc-2026' => 'NL2026001234567-abc-1', 'sum-nl' => 'NL2026001234567-abc-2', 'sum-de' => 'NL2026001234567-abc-3', 'additional-info:cbc-2026' => 'NL2026001234567-abc-4'],
			'language' => 'en',
			'currency' => 'EUR',
			'additionalInfo' => 'Employees are full-time equivalents at year end.',
		];
	}//end file()

	/**
	 * An XPath over a rendered file.
	 *
	 * @param string $xml The file.
	 *
	 * @return DOMXPath
	 */
	private static function xpath(string $xml): DOMXPath {
		$doc = new DOMDocument();
		self::assertTrue($doc->loadXML($xml));
		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('cbc', CbcXmlDocument::NS_CBC);
		$xpath->registerNamespace('stf', CbcXmlDocument::NS_STF);

		return $xpath;
	}//end xpath()

	/**
	 * The text of the first node a query finds.
	 *
	 * @param DOMXPath $xpath The XPath.
	 * @param string   $query The query.
	 *
	 * @return string
	 */
	private static function text(DOMXPath $xpath, string $query): string {
		$nodes = $xpath->query($query);
		self::assertNotFalse($nodes);
		self::assertGreaterThan(0, $nodes->length, $query . ' found nothing');

		return (string)$nodes->item(0)?->textContent;
	}//end text()

	/**
	 * The message header is a first CbC file from the Netherlands for the year.
	 *
	 * @return void
	 */
	public function testTheMessageSpecIsAFirstFileForTheYear(): void {
		$xpath = self::xpath((new CbcXmlDocument())->render(self::file()));

		self::assertSame('2.0', self::text($xpath, '/cbc:CBC_OECD/@version'));
		$names = [];
		foreach ($xpath->query('/cbc:CBC_OECD/cbc:MessageSpec/*') ?: [] as $node) {
			$names[] = $node->localName;
		}

		self::assertSame(
			['SendingEntityIN', 'TransmittingCountry', 'ReceivingCountry', 'MessageType', 'Language', 'MessageRefId', 'MessageTypeIndic', 'ReportingPeriod', 'Timestamp'],
			$names
		);
		self::assertSame('001234567', self::text($xpath, '//cbc:MessageSpec/cbc:SendingEntityIN'));
		self::assertSame('CBC401', self::text($xpath, '//cbc:MessageSpec/cbc:MessageTypeIndic'));
		self::assertSame('2026-12-31', self::text($xpath, '//cbc:MessageSpec/cbc:ReportingPeriod'));
		self::assertSame('EN', self::text($xpath, '//cbc:MessageSpec/cbc:Language'));
	}//end testTheMessageSpecIsAFirstFileForTheYear()

	/**
	 * The reporting entity carries its TIN, address, role, period and DocSpec.
	 *
	 * @return void
	 */
	public function testTheReportingEntityBlock(): void {
		$xpath = self::xpath((new CbcXmlDocument())->render(self::file()));
		$base  = '/cbc:CBC_OECD/cbc:CbcBody/cbc:ReportingEntity';

		self::assertSame('NL', self::text($xpath, $base . '/cbc:Entity/cbc:TIN/@issuedBy'));
		self::assertSame('Amsterdam', self::text($xpath, $base . '/cbc:Entity/cbc:Address/cbc:AddressFix/cbc:City'));
		self::assertSame('Voorbeeld Groep Holding B.V.', self::text($xpath, $base . '/cbc:NameMNEGroup'));
		self::assertSame('CBC701', self::text($xpath, $base . '/cbc:ReportingRole'));
		self::assertSame('2026-01-01', self::text($xpath, $base . '/cbc:ReportingPeriod/cbc:StartDate'));
		self::assertSame('OECD1', self::text($xpath, $base . '/stf:DocSpec/stf:DocTypeIndic'));
		self::assertSame('NL2026001234567-abc-1', self::text($xpath, $base . '/stf:DocSpec/stf:DocRefId'));
	}//end testTheReportingEntityBlock()

	/**
	 * One CbcReports per jurisdiction in code order, amounts in whole euros, residents listed.
	 *
	 * @return void
	 */
	public function testOneReportPerJurisdictionWithItsResidents(): void {
		$xpath = self::xpath((new CbcXmlDocument())->render(self::file()));

		$codes = [];
		foreach ($xpath->query('//cbc:CbcReports/cbc:ResCountryCode') ?: [] as $node) {
			$codes[] = $node->textContent;
		}

		self::assertSame(['DE', 'NL'], $codes);
		$nl = '//cbc:CbcReports[cbc:ResCountryCode="NL"]';
		self::assertSame('300000000', self::text($xpath, $nl . '/cbc:Summary/cbc:Revenues/cbc:Unrelated'));
		self::assertSame('EUR', self::text($xpath, $nl . '/cbc:Summary/cbc:Revenues/cbc:Unrelated/@currCode'));
		self::assertSame('-1251', self::text($xpath, $nl . '/cbc:Summary/cbc:ProfitOrLoss'));
		self::assertSame('412', self::text($xpath, $nl . '/cbc:Summary/cbc:NbEmployees'));
		self::assertSame('NL2026001234567-abc-2', self::text($xpath, $nl . '/stf:DocSpec/stf:DocRefId'));
		self::assertSame(2, $xpath->query($nl . '/cbc:ConstEntities')->length);
		self::assertSame('Voorbeeld Groep Holding B.V.', self::text($xpath, $nl . '/cbc:ConstEntities[1]/cbc:ConstEntity/cbc:Name'));
		self::assertSame('CBC803', self::text($xpath, $nl . '/cbc:ConstEntities[1]/cbc:Role'));
		self::assertSame(0, $xpath->query($nl . '/cbc:ConstEntities[2]/cbc:Role')->length);
		self::assertSame(1, $xpath->query($nl . '/cbc:ConstEntities[2]/cbc:BizActivities')->length, 'a repeated activity is listed once');

		$de = '//cbc:CbcReports[cbc:ResCountryCode="DE"]';
		self::assertSame('0', self::text($xpath, $de . '/cbc:Summary/cbc:TaxPaid'));
		self::assertSame('NOTIN', self::text($xpath, $de . '/cbc:ConstEntities/cbc:ConstEntity/cbc:TIN'));
		self::assertSame(0, $xpath->query($de . '/cbc:ConstEntities/cbc:ConstEntity/cbc:TIN/@issuedBy')->length);
		self::assertSame('Hauptstrasse 9 Berlin', self::text($xpath, $de . '/cbc:ConstEntities/cbc:ConstEntity/cbc:Address/cbc:AddressFree'));
	}//end testOneReportPerJurisdictionWithItsResidents()

	/**
	 * The employee methodology goes in AdditionalInfo with its own DocSpec.
	 *
	 * @return void
	 */
	public function testTheNoteIsAdditionalInfo(): void {
		$xpath = self::xpath((new CbcXmlDocument())->render(self::file()));

		self::assertSame('Employees are full-time equivalents at year end.', self::text($xpath, '//cbc:AdditionalInfo/cbc:OtherInfo'));
		self::assertSame('EN', self::text($xpath, '//cbc:AdditionalInfo/cbc:OtherInfo/@language'));
		self::assertSame('NL2026001234567-abc-4', self::text($xpath, '//cbc:AdditionalInfo/stf:DocSpec/stf:DocRefId'));
	}//end testTheNoteIsAdditionalInfo()

	/**
	 * A correction is CBC402: a changed jurisdiction is OECD2 pointing at its old DocRefId,
	 * an unchanged one is left out, the unchanged reporting entity is resent as OECD0.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.2
	 */
	public function testACorrectionSendsTheChangedJurisdictionOnly(): void {
		$file = self::file();
		$file['correction'] = [
			'previousDocRefIds' => ['reporting-entity:cbc-2026' => 'OLD-1', 'sum-nl' => 'OLD-2', 'sum-de' => 'OLD-3'],
			'changed' => ['sum-de'],
		];
		$xpath = self::xpath((new CbcXmlDocument())->render($file));

		self::assertSame('CBC402', self::text($xpath, '//cbc:MessageSpec/cbc:MessageTypeIndic'));
		self::assertSame('OECD0', self::text($xpath, '//cbc:ReportingEntity/stf:DocSpec/stf:DocTypeIndic'));
		self::assertSame('OLD-1', self::text($xpath, '//cbc:ReportingEntity/stf:DocSpec/stf:CorrDocRefId'));
		self::assertSame(1, $xpath->query('//cbc:CbcReports')->length);
		self::assertSame('DE', self::text($xpath, '//cbc:CbcReports/cbc:ResCountryCode'));
		self::assertSame('OECD2', self::text($xpath, '//cbc:CbcReports/stf:DocSpec/stf:DocTypeIndic'));
		self::assertSame('OLD-3', self::text($xpath, '//cbc:CbcReports/stf:DocSpec/stf:CorrDocRefId'));
		self::assertSame('NL2026001234567-abc-3', self::text($xpath, '//cbc:CbcReports/stf:DocSpec/stf:DocRefId'));
		self::assertSame(0, $xpath->query('//cbc:AdditionalInfo')->length);
	}//end testACorrectionSendsTheChangedJurisdictionOnly()

	/**
	 * A jurisdiction new since the corrected file is sent as new data; a changed reporting entity as OECD2.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.2
	 */
	public function testANewJurisdictionInACorrectionIsNewData(): void {
		$file = self::file();
		$file['correction'] = [
			'previousDocRefIds' => ['reporting-entity:cbc-2026' => 'OLD-1', 'sum-nl' => 'OLD-2'],
			'changed' => ['reporting-entity:cbc-2026'],
		];
		$xpath = self::xpath((new CbcXmlDocument())->render($file));

		self::assertSame('OECD2', self::text($xpath, '//cbc:ReportingEntity/stf:DocSpec/stf:DocTypeIndic'));
		self::assertSame(1, $xpath->query('//cbc:CbcReports')->length);
		self::assertSame('OECD1', self::text($xpath, '//cbc:CbcReports/stf:DocSpec/stf:DocTypeIndic'));
		self::assertSame(0, $xpath->query('//cbc:CbcReports/stf:DocSpec/stf:CorrDocRefId')->length);
	}//end testANewJurisdictionInACorrectionIsNewData()

	/**
	 * Text is escaped, not taken as markup.
	 *
	 * @return void
	 */
	public function testNamesAreEscaped(): void {
		$file = self::file();
		$file['reportingEntity']['entityName'] = 'Smit & Zonen <B.V.>';
		$xpath = self::xpath((new CbcXmlDocument())->render($file));

		self::assertSame('Smit & Zonen <B.V.>', self::text($xpath, '//cbc:ReportingEntity/cbc:Entity/cbc:Name'));
	}//end testNamesAreEscaped()

	/**
	 * The record keys of the return's own blocks.
	 *
	 * @return void
	 */
	public function testTheRecordKeys(): void {
		$document = new CbcXmlDocument();

		self::assertSame('reporting-entity:r-1', $document->reportingEntityKey('r-1'));
		self::assertSame('additional-info:r-1', $document->additionalInfoKey('r-1'));
	}//end testTheRecordKeys()
}//end class
