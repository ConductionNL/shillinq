<?php

/**
 * Unit tests for GirXmlGenerator.
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Reporting\Generator;

use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Reporting\Generator\GirXmlGenerator;
use OCA\Shillinq\Service\Cbcr\CbcrCheckRunner;
use OCA\Shillinq\Service\Cbcr\CbcXsdSet;
use OCA\Shillinq\Service\Cbcr\GirXmlBuilder;
use OCA\Shillinq\Service\Cbcr\GirXmlDocument;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCA\Shillinq\Tests\Unit\Service\Cbcr\GirXmlDocumentTest;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The GIR file: gaps written and refused, blocking checks named, stored only when valid.
 */
#[CoversClass(GirXmlGenerator::class)]
#[UsesClass(GirXmlDocument::class)]
#[UsesClass(GirXmlBuilder::class)]
#[UsesClass(CbcXsdSet::class)]
#[UsesClass(ObjectIdentifier::class)]
#[UsesClass(SbrRecords::class)]
final class GirXmlGeneratorTest extends TestCase {

	private const RETURN = ['id' => 'gir-2026', 'period' => 'FY2026', 'ultimateParent' => 'ge-holding', 'state' => 'draft'];

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
	 * The return the checks were run on.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $checked = null;

	/**
	 * A fixture schema folder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->folder = sys_get_temp_dir() . '/gir-gen-' . bin2hex(random_bytes(4));
		mkdir($this->folder);
		file_put_contents($this->folder . '/' . CbcXsdSet::GIR_MAIN_XSD, GirXmlDocumentTest::fixtureXsd());
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
	 * A generator over the given records and check results.
	 *
	 * @param array<string,mixed>            $records The records girRecords() answers.
	 * @param array<int,array<string,mixed>> $checks  The check results.
	 * @param bool                           $bundled Whether the schema set is there.
	 *
	 * @return GirXmlGenerator
	 */
	private function generator(array $records, array $checks = [], bool $bundled = true): GirXmlGenerator {
		$this->store = new InMemoryObjectServiceStub(['GlobeInformationReturn' => [self::RETURN]]);
		$location    = $this->createMock(RegisterLocationInterface::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$runner = $this->createMock(CbcrCheckRunner::class);
		$runner->method('girRecords')->willReturn($records);
		$runner->method('girFingerprint')->willReturn('fp-gir');
		$runner->method('runGir')->willReturnCallback(
			function (array $return) use ($checks): array {
				$this->checked = $return;
				return $checks;
			}
		);

		$folder = $this->folder;
		if ($bundled === false) {
			$folder .= '/missing';
		}

		return new GirXmlGenerator(new SbrRecords($this->store, $location), $runner, new GirXmlDocument($folder), $l10n);
	}//end generator()

	/**
	 * The stored return.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(): array {
		foreach ($this->store->setRegister('shillinq')->setSchema('GlobeInformationReturn')->findAll(['limit' => 10]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if (($record['id'] ?? null) === 'gir-2026') {
				return $record;
			}
		}

		self::fail('No return gir-2026');
	}//end stored()

	/**
	 * The context Generate XML passes.
	 *
	 * @return array<string,string>
	 */
	private static function context(): array {
		return ['returnId' => 'gir-2026', 'period' => 'FY2026', 'administrationId' => 'adm-holding'];
	}//end context()

	/**
	 * The catalogue identity of the generator.
	 *
	 * @return void
	 */
	public function testItAnswersTheGirInXml(): void {
		self::assertSame('globe-information-return', GirXmlGenerator::reportType());
		self::assertSame(['xml'], GirXmlGenerator::supportedFormats());
	}//end testItAnswersTheGirInXml()

	/**
	 * Every jurisdiction under safe harbour: a valid file, its validation, fingerprint and DocRefIds recorded.
	 *
	 * @return void
	 */
	public function testEveryJurisdictionUnderSafeHarbourGivesAStoredFile(): void {
		$file = $this->generator(GirXmlDocumentTest::safeHarbourRecords())->generate(self::context(), 'xml');

		self::assertSame('application/xml', $file->mimeType);
		self::assertMatchesRegularExpression('/^globe-information-return-2026-NL2026GIR001234567-[0-9a-f]{12}\.xml$/', $file->fileName);
		self::assertStringContainsString('<gir:SafeHarbour>simplified-etr</gir:SafeHarbour>', $file->content);

		$stored = $this->stored();
		self::assertSame([], $stored['missingElements']);
		self::assertTrue($stored['lastValidation']['valid']);
		self::assertSame('fp-gir', $stored['lastValidation']['fingerprint']);
		self::assertSame(
			['filing-info:gir-2026', 'general-section:gir-2026', 'jurisdiction:DE', 'jurisdiction:NL'],
			array_keys($stored['docRefIds'])
		);
		self::assertStringEndsWith('-4', $stored['docRefIds']['jurisdiction:NL']);
		self::assertSame([], $this->checked['missingElements']);
	}//end testEveryJurisdictionUnderSafeHarbourGivesAStoredFile()

	/**
	 * A computed jurisdiction: the per-entity gaps are written on the return and no file is made.
	 *
	 * @return void
	 */
	public function testAComputedJurisdictionWritesItsGapsAndStoresNothing(): void {
		$records                  = GirXmlDocumentTest::safeHarbourRecords();
		$records['entities'][]    = GirXmlDocumentTest::IRELAND;
		$records['computations']  = [['jurisdiction' => 'IE', 'state' => 'approved']];
		$records['jurisdictions'] = ['DE', 'IE', 'NL'];

		try {
			$this->generator($records)->generate(self::context(), 'xml');
			self::fail('A GIR with missing elements was made.');
		} catch (RuntimeException $refused) {
			self::assertStringContainsString('3 elements the schema requires are not in the records', $refused->getMessage());
			self::assertStringContainsString('IE Voorbeeld Ireland Ltd: JurisdictionSection/GLoBETax/ETR/ETRComputation/CEComputation/GLoBEIncome', $refused->getMessage());
		}

		$stored = $this->stored();
		self::assertCount(3, $stored['missingElements']);
		self::assertSame('IE', $stored['missingElements'][0]['jurisdiction']);
		self::assertArrayNotHasKey('lastValidation', $stored);
		self::assertCount(3, $this->checked['missingElements'], 'the checks judge the gaps just written');
	}//end testAComputedJurisdictionWritesItsGapsAndStoresNothing()

	/**
	 * A failing blocking check is named; a warning does not stop the file.
	 *
	 * @return void
	 */
	public function testABlockingCheckIsNamed(): void {
		$checks = [
			['id' => 'gir-jurisdiction-covered', 'severity' => 'mandatory', 'passed' => false, 'statement' => 'Every jurisdiction is covered.'],
			['id' => 'gir-note', 'severity' => 'warning', 'passed' => false, 'statement' => 'A warning.'],
		];

		try {
			$this->generator(GirXmlDocumentTest::safeHarbourRecords(), $checks)->generate(self::context(), 'xml');
			self::fail('A GIR was made while a blocking check fails.');
		} catch (RuntimeException $refused) {
			self::assertStringContainsString('gir-jurisdiction-covered: Every jurisdiction is covered.', $refused->getMessage());
			self::assertStringNotContainsString('gir-note', $refused->getMessage());
		}

		$file = $this->generator(GirXmlDocumentTest::safeHarbourRecords(), [$checks[1]])->generate(self::context(), 'xml');
		self::assertNotSame('', $file->content);
	}//end testABlockingCheckIsNamed()

	/**
	 * Without the schema set the file is refused and the validation errors are on the return.
	 *
	 * @return void
	 */
	public function testWithoutTheSetNothingIsStored(): void {
		try {
			$this->generator(GirXmlDocumentTest::safeHarbourRecords(), [], false)->generate(self::context(), 'xml');
			self::fail('An unvalidated GIR was returned.');
		} catch (RuntimeException $refused) {
			self::assertStringContainsString('does not pass the OECD schema', $refused->getMessage());
			self::assertStringContainsString('oecd-gir-2025-01', $refused->getMessage());
		}

		$stored = $this->stored();
		self::assertFalse($stored['lastValidation']['valid']);
		self::assertArrayNotHasKey('fingerprint', $stored['lastValidation']);
		self::assertArrayNotHasKey('docRefIds', $stored);
	}//end testWithoutTheSetNothingIsStored()

	/**
	 * Another format, or no return, is refused.
	 *
	 * @return void
	 */
	public function testAnotherFormatOrNoReturnIsRefused(): void {
		$generator = $this->generator(GirXmlDocumentTest::safeHarbourRecords());
		foreach ([[self::context(), 'pdf'], [['returnId' => 'gir-missing'], 'xml']] as [$context, $format]) {
			try {
				$generator->generate($context, $format);
				self::fail('Refusal expected for ' . $format);
			} catch (RuntimeException $refused) {
				self::assertStringContainsString('GloBE information return', $refused->getMessage());
			}
		}

		self::assertSame([], $this->store->saved);
	}//end testAnotherFormatOrNoReturnIsRefused()
}//end class
