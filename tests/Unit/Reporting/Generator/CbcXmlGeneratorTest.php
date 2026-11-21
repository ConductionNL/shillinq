<?php

/**
 * Unit tests for CbcXmlGenerator.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Reporting\Generator
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

namespace OCA\Shillinq\Tests\Unit\Reporting\Generator;

use DOMDocument;
use DOMXPath;
use OCA\Shillinq\Reporting\Generator\CbcXmlGenerator;
use OCA\Shillinq\Service\Cbcr\CbcrCheckRunner;
use OCA\Shillinq\Service\Cbcr\CbcXmlDocument;
use OCA\Shillinq\Service\Cbcr\CbcXsdSet;
use OCA\Shillinq\Tests\Unit\Service\Cbcr\CbcXsdSetTest;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The CbC file: refused on a blocking check, stored only when valid, corrections send the changes.
 */
#[CoversClass(CbcXmlGenerator::class)]
#[UsesClass(CbcXmlDocument::class)]
#[UsesClass(CbcXsdSet::class)]
#[UsesClass(ObjectIdentifier::class)]
final class CbcXmlGeneratorTest extends TestCase {

	private const HOLDING = [
		'id' => 'ge-holding', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'taxResidency' => 'NL', 'tin' => '001234567', 'tinIssuedBy' => 'NL',
		'address' => ['street' => 'Kade 1', 'postalCode' => '1011 AA', 'city' => 'Amsterdam', 'country' => 'NL'], 'bizActivities' => ['CBC512'],
		'administrationId' => 'adm-holding',
	];

	private const GMBH = [
		'id' => 'ge-gmbh', 'entityName' => 'Voorbeeld GmbH', 'jurisdiction' => 'DE', 'tin' => 'DE123', 'tinIssuedBy' => 'DE',
		'address' => ['city' => 'Berlin', 'country' => 'DE'], 'bizActivities' => ['CBC505'], 'ultimateParentEntity' => 'ge-holding',
	];

	private const SUMMARY_NL = ['id' => 'sum-nl', 'jurisdiction' => 'NL', 'unrelatedPartyRevenue' => 300000000, 'relatedPartyRevenue' => 40000000, 'totalRevenue' => 340000000, 'employeeMethodologyNote' => 'Full-time equivalents at year end.'];

	private const SUMMARY_DE = ['id' => 'sum-de', 'jurisdiction' => 'DE', 'unrelatedPartyRevenue' => 60000000, 'relatedPartyRevenue' => 0, 'totalRevenue' => 60000000];

	/**
	 * The fixture schema folder.
	 *
	 * @var string
	 */
	private string $folder = '';

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * A fixture schema folder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->folder = sys_get_temp_dir() . '/cbc-gen-' . bin2hex(random_bytes(4));
		mkdir($this->folder);
		file_put_contents($this->folder . '/' . CbcXsdSet::MAIN_XSD, CbcXsdSetTest::fixtureXsd());
	}//end setUp()

	/**
	 * Remove the fixture folder.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((glob($this->folder . '/*') ?: []) as $file) {
			unlink($file);
		}

		rmdir($this->folder);
	}//end tearDown()

	/**
	 * A generator over the given returns and summaries.
	 *
	 * @param array<int,array<string,mixed>> $returns   The CbcrReturn rows.
	 * @param array<int,array<string,mixed>> $summaries The summaries the records carry.
	 * @param array<int,array<string,mixed>> $checks    The check results.
	 * @param bool                           $bundled   Whether the schema set is there.
	 *
	 * @return CbcXmlGenerator
	 */
	private function generator(array $returns, array $summaries = [self::SUMMARY_NL, self::SUMMARY_DE], array $checks = [], bool $bundled = true): CbcXmlGenerator {
		$this->store = new InMemoryObjectServiceStub(['CbcrReturn' => $returns, 'GroupEntityRegistry' => [self::HOLDING, self::GMBH]]);
		$appConfig   = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$runner = $this->createMock(CbcrCheckRunner::class);
		$runner->method('runCbc')->willReturn($checks);
		$runner->method('cbcFingerprint')->willReturn('fp-now');
		$runner->method('cbcRecords')->willReturn(
			['reportingEntity' => self::HOLDING, 'upeId' => 'ge-holding', 'entities' => [self::HOLDING, self::GMBH], 'summaries' => $summaries]
		);

		$folder = $this->folder;
		if ($bundled === false) {
			$folder .= '/missing';
		}

		return new CbcXmlGenerator($this->store, $appConfig, $runner, new CbcXmlDocument(), new CbcXsdSet($folder), $l10n);
	}//end generator()

	/**
	 * The stored version of a return.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(string $id): array {
		$rows = $this->store->setRegister('shillinq')->setSchema('CbcrReturn')->findAll(['limit' => 100]);
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if (($record['id'] ?? null) === $id) {
				return $record;
			}
		}

		self::fail('No return ' . $id);
	}//end stored()

	/**
	 * An XPath over a file.
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
	 * The catalogue identity of the generator.
	 *
	 * @return void
	 */
	public function testItAnswersTheCbcReportInXml(): void {
		self::assertSame('cbc-report', CbcXmlGenerator::reportType());
		self::assertSame(['xml'], CbcXmlGenerator::supportedFormats());
	}//end testItAnswersTheCbcReportInXml()

	/**
	 * A valid file is returned, and the return records the validation, its fingerprint and the DocRefIds.
	 *
	 * @return void
	 */
	public function testAValidFileIsReturnedAndRecorded(): void {
		$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding', 'reportingRole' => 'CBC701', 'state' => 'reconciled'];
		$file   = $this->generator([$return])->generate(['returnId' => 'cbc-2026', 'period' => 'FY2026', 'administrationId' => 'adm-holding'], 'xml');

		self::assertSame('xml', $file->format);
		self::assertSame('application/xml', $file->mimeType);
		self::assertMatchesRegularExpression('/^cbc-report-2026-NL2026001234567-[0-9a-f]{12}\.xml$/', $file->fileName);
		$xpath = self::xpath($file->content);
		self::assertSame('CBC401', $xpath->query('//cbc:MessageTypeIndic')->item(0)?->textContent);
		self::assertSame(2, $xpath->query('//cbc:CbcReports')->length);
		self::assertSame('Voorbeeld Groep Holding B.V.', $xpath->query('//cbc:NameMNEGroup')->item(0)?->textContent);

		$stored     = $this->stored('cbc-2026');
		$validation = $stored['lastValidation'];
		self::assertTrue($validation['valid']);
		self::assertSame('fp-now', $validation['fingerprint']);
		self::assertSame('2.0', $validation['schemaVersion']);
		self::assertSame(64, strlen($validation['xsdChecksum']));
		self::assertSame(['reporting-entity:cbc-2026', 'sum-nl', 'sum-de'], array_keys($validation['recordFingerprints']));
		self::assertSame(['reporting-entity:cbc-2026', 'sum-nl', 'sum-de', 'additional-info:cbc-2026'], array_keys($stored['docRefIds']));
		$messageRefId = $validation['messageRefId'];
		self::assertSame($messageRefId . '-2', $stored['docRefIds']['sum-nl']);
		self::assertSame($messageRefId . '-2', $xpath->query('//cbc:CbcReports[cbc:ResCountryCode="NL"]/stf:DocSpec/stf:DocRefId')->item(0)?->textContent);
		self::assertSame('reconciled', $stored['state'], 'the state is the lifecycle\'s, not the file\'s');
	}//end testAValidFileIsReturnedAndRecorded()

	/**
	 * A failing blocking check refuses the file, naming it; a failing warning does not.
	 *
	 * @return void
	 */
	public function testABlockingCheckRefusesTheFileNamingIt(): void {
		$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding'];
		$checks = [
			['id' => 'cbc-entity-tin', 'severity' => 'mandatory', 'passed' => false, 'source' => '', 'statement' => 'Every constituent entity has a tax identification number.'],
			['id' => 'cbc-revenue-total', 'severity' => 'mandatory', 'passed' => true, 'source' => '', 'statement' => 'Totals add up.'],
			['id' => 'cbc-figures-from-books', 'severity' => 'warning', 'passed' => false, 'source' => '', 'statement' => 'Figures come from the books.'],
		];

		try {
			$this->generator([$return], checks: $checks)->generate(['returnId' => 'cbc-2026'], 'xml');
			self::fail('The file was made while a blocking check failed.');
		} catch (RuntimeException $refused) {
			self::assertStringContainsString('cbc-entity-tin: Every constituent entity has a tax identification number.', $refused->getMessage());
			self::assertStringNotContainsString('cbc-figures-from-books', $refused->getMessage());
			self::assertStringNotContainsString('cbc-revenue-total', $refused->getMessage());
		}

		self::assertSame([], $this->store->saved);
	}//end testABlockingCheckRefusesTheFileNamingIt()

	/**
	 * A warning alone does not stop the file.
	 *
	 * @return void
	 */
	public function testAWarningAloneDoesNotStopTheFile(): void {
		$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding'];
		$checks = [['id' => 'cbc-figures-from-books', 'severity' => 'warning', 'passed' => false, 'source' => '', 'statement' => 'Figures come from the books.']];

		$file = $this->generator([$return], checks: $checks)->generate(['returnId' => 'cbc-2026'], 'xml');

		self::assertNotSame('', $file->content);
	}//end testAWarningAloneDoesNotStopTheFile()

	/**
	 * Without the schema set the file is not returned, and the return says why.
	 *
	 * @return void
	 */
	public function testAFileThatCannotBeValidatedIsNotReturned(): void {
		$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding', 'cbcrXmlSubmission' => 'old-file'];

		try {
			$this->generator([$return], bundled: false)->generate(['returnId' => 'cbc-2026'], 'xml');
			self::fail('An unvalidated file was returned.');
		} catch (RuntimeException $refused) {
			self::assertStringContainsString('does not pass the OECD schema, so it was not stored', $refused->getMessage());
			self::assertStringContainsString('lib/Standards/Xsd/oecd-cbc-v2.0', $refused->getMessage());
		}

		$stored = $this->stored('cbc-2026');
		self::assertFalse($stored['lastValidation']['valid']);
		self::assertArrayNotHasKey('fingerprint', $stored['lastValidation']);
		self::assertArrayNotHasKey('docRefIds', $stored);
	}//end testAFileThatCannotBeValidatedIsNotReturned()

	/**
	 * A file that breaks the schema is not returned; its errors are kept with their line.
	 *
	 * @return void
	 */
	public function testAnInvalidFileKeepsItsErrors(): void {
		file_put_contents(
			$this->folder . '/' . CbcXsdSet::MAIN_XSD,
			str_replace('name="MessageRefId"', 'name="MessageRefIdentifier"', CbcXsdSetTest::fixtureXsd())
		);
		$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding'];

		try {
			$this->generator([$return])->generate(['returnId' => 'cbc-2026'], 'xml');
			self::fail('An invalid file was returned.');
		} catch (RuntimeException $refused) {
			self::assertStringContainsString('Line ', $refused->getMessage());
		}

		$errors = $this->stored('cbc-2026')['lastValidation']['errors'];
		self::assertNotSame([], $errors);
		self::assertGreaterThan(0, $errors[0]['line']);
	}//end testAnInvalidFileKeepsItsErrors()

	/**
	 * The file is made from a named return, in XML only.
	 *
	 * @return void
	 */
	public function testTheReturnMustBeNamedAndTheFormatXml(): void {
		$return    = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding'];
		$generator = $this->generator([$return]);

		foreach ([[[], 'xml', 'from its report page'], [['returnId' => 'nope'], 'xml', 'from its report page'], [['returnId' => 'cbc-2026'], 'pdf', 'XML only']] as [$context, $format, $reason]) {
			try {
				$generator->generate($context, $format);
				self::fail('Generated for ' . json_encode($context) . ' in ' . $format);
			} catch (RuntimeException $refused) {
				self::assertStringContainsString($reason, $refused->getMessage());
			}
		}

		self::assertSame([], $this->store->saved);
	}//end testTheReturnMustBeNamedAndTheFormatXml()

	/**
	 * A correction sends the jurisdiction that changed as OECD2 and leaves the unchanged one out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.2
	 */
	public function testACorrectionSendsOnlyWhatChanged(): void {
		$first = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding', 'reportingRole' => 'CBC701'];
		$this->generator([$first])->generate(['returnId' => 'cbc-2026'], 'xml');
		$filed          = $this->stored('cbc-2026');
		$filed['state'] = 'submitted';

		$changedDe  = ['totalRevenue' => 61000000, 'unrelatedPartyRevenue' => 61000000] + self::SUMMARY_DE;
		$correction = ['id' => 'cbc-2026-c1', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding', 'reportingRole' => 'CBC701', 'correctsReturn' => 'cbc-2026'];
		$file       = $this->generator([$filed, $correction], summaries: [self::SUMMARY_NL, $changedDe])->generate(['returnId' => 'cbc-2026-c1'], 'xml');

		$xpath = self::xpath($file->content);
		self::assertSame('CBC402', $xpath->query('//cbc:MessageTypeIndic')->item(0)?->textContent);
		self::assertSame('OECD0', $xpath->query('//cbc:ReportingEntity/stf:DocSpec/stf:DocTypeIndic')->item(0)?->textContent);
		self::assertSame($filed['docRefIds']['reporting-entity:cbc-2026'], $xpath->query('//cbc:ReportingEntity/stf:DocSpec/stf:CorrDocRefId')->item(0)?->textContent);
		self::assertSame(1, $xpath->query('//cbc:CbcReports')->length);
		self::assertSame('DE', $xpath->query('//cbc:CbcReports/cbc:ResCountryCode')->item(0)?->textContent);
		self::assertSame('OECD2', $xpath->query('//cbc:CbcReports/stf:DocSpec/stf:DocTypeIndic')->item(0)?->textContent);
		self::assertSame($filed['docRefIds']['sum-de'], $xpath->query('//cbc:CbcReports/stf:DocSpec/stf:CorrDocRefId')->item(0)?->textContent);
		self::assertSame(0, $xpath->query('//cbc:AdditionalInfo')->length);

		$kept = $this->stored('cbc-2026-c1')['docRefIds'];
		self::assertSame($filed['docRefIds']['sum-nl'], $kept['sum-nl'], 'the unchanged jurisdiction keeps its DocRefId');
		self::assertNotSame($filed['docRefIds']['sum-de'], $kept['sum-de']);
		self::assertSame($xpath->query('//cbc:CbcReports/stf:DocSpec/stf:DocRefId')->item(0)?->textContent, $kept['sum-de']);
		self::assertArrayHasKey('reporting-entity:cbc-2026-c1', $kept);
	}//end testACorrectionSendsOnlyWhatChanged()

	/**
	 * A correction with nothing changed, or of a report without a file, is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.2
	 */
	public function testACorrectionNeedsAFileAndAChange(): void {
		$first = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding', 'reportingRole' => 'CBC701'];
		$this->generator([$first])->generate(['returnId' => 'cbc-2026'], 'xml');
		$filed = $this->stored('cbc-2026');

		$correction = ['id' => 'cbc-2026-c1', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding', 'reportingRole' => 'CBC701', 'correctsReturn' => 'cbc-2026'];
		$cases      = [
			'nothing to correct' => [$filed, $correction],
			'no file to correct' => [['id' => 'cbc-2026', 'period' => 'FY2026', 'state' => 'submitted'], $correction],
		];
		foreach ($cases as $reason => $returns) {
			$generator = $this->generator($returns);
			try {
				$generator->generate(['returnId' => 'cbc-2026-c1'], 'xml');
				self::fail('A correction was made: ' . $reason);
			} catch (RuntimeException $refused) {
				self::assertStringContainsString($reason, $refused->getMessage());
			}

			self::assertSame([], $this->store->saved);
		}
	}//end testACorrectionNeedsAFileAndAChange()
}//end class
