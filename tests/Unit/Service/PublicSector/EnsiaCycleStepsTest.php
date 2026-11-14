<?php

/**
 * Unit tests for EnsiaCycleSteps.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use DomainException;
use OCA\Shillinq\Service\ENSIABevindingGenerator;
use OCA\Shillinq\Service\ENSIAVerklaringGenerator;
use OCA\Shillinq\Service\ENSIAXmlExporter;
use OCA\Shillinq\Service\PublicSector\EnsiaCycleSteps;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * REQ-ENSIA-011 / REQ-ENSIA-012: the findings, the statement and the XML of a
 * cycle, through the real generators over records-shaped questions.
 *
 * @covers \OCA\Shillinq\Service\PublicSector\EnsiaCycleSteps
 * @uses   \OCA\Shillinq\Service\ENSIABevindingGenerator
 * @uses   \OCA\Shillinq\Service\ENSIAVerklaringGenerator
 * @uses   \OCA\Shillinq\Service\ENSIAXmlExporter
 * @uses   \OCA\Shillinq\PaymentRun\RenderedFile
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class EnsiaCycleStepsTest extends TestCase {

	/**
	 * The rows per schema the records mock answers.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The objects created, as [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $created = [];

	/**
	 * The filters each schema was read with.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * A 2026 cycle of administration "gem" with two questions answered no, one
	 * answered yes and one scored at its norm; plus a question of another cycle.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'Evaluatievraag' => [
				self::question(id: 'q1', code: 'BIO-9.1.1', answer: 'nee'),
				self::question(id: 'q2', code: 'BIO-9.2.1', answer: 'nee'),
				self::question(id: 'q3', code: 'BIO-9.4.1', answer: 'ja'),
				['id' => 'q4', 'cyclusId' => 'c1', 'administrationId' => 'gem', 'domain' => 'BIO', 'questionCode' => 'BIO-12.1.1',
					'answerType' => 'volwassenheidsniveau-1-5', 'answer' => '3', 'maturityScore' => 3, 'normniveau' => 3],
				['id' => 'q9', 'cyclusId' => 'other', 'administrationId' => 'gem', 'answerType' => 'ja-nee-nvt', 'answer' => 'nee', 'questionCode' => 'X'],
			],
			'Bevinding' => [],
		];
	}//end setUp()

	/**
	 * One ja-nee-nvt question of cycle c1.
	 *
	 * @param string $id     The id.
	 * @param string $code   The question code.
	 * @param string $answer The answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function question(string $id, string $code, string $answer): array {
		return [
			'id' => $id,
			'cyclusId' => 'c1',
			'administrationId' => 'gem',
			'domain' => 'BIO',
			'questionCode' => $code,
			'questionText' => 'Beleid ' . $code,
			'answerType' => 'ja-nee-nvt',
			'answer' => $answer,
			'peerReviewStatus' => 'nog-niet-beoordeeld',
		];
	}//end question()

	/**
	 * The cycle as the transition hands it over.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function cycle(array $overrides=[]): array {
		return array_merge(
			[
				'@self' => ['id' => 'c1'],
				'administrationId' => 'gem',
				'year' => 2026,
				'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
				'status' => 'peer-review',
				'questionSetVersion' => '2026.1',
			],
			$overrides
		);
	}//end cycle()

	/**
	 * The steps over the rows; create() records the object and answers an id.
	 *
	 * @return EnsiaCycleSteps
	 */
	private function steps(): EnsiaCycleSteps {
		$records = $this->createMock(PublicSectorRecords::class);
		$records->method('records')->willReturnCallback(
			function (string $schema, array $filters): array {
				$this->asked[$schema] = $filters;
				return ($this->rows[$schema] ?? []);
			}
		);
		$records->method('create')->willReturnCallback(
			function (string $schema, array $object): string {
				$this->created[] = [$schema, $object];
				$id = 'b' . count($this->created);
				$this->rows[$schema][] = ['id' => $id] + $object;
				return $id;
			}
		);

		return new EnsiaCycleSteps(
			records: $records,
			findings: new ENSIABevindingGenerator(),
			statement: new ENSIAVerklaringGenerator(),
			exporter: new ENSIAXmlExporter()
		);
	}//end steps()

	/**
	 * Two questions answered no give two findings of the cycle, read within its
	 * administration, and a valid Bevinding payload each.
	 *
	 * @return void
	 */
	public function testTwoQuestionsAnsweredNoGiveTwoFindings(): void {
		$ids = $this->steps()->createFindings(cycle: self::cycle());

		$this->assertSame(['b1', 'b2'], $ids);
		$this->assertSame(['q1', 'q2'], array_map(static fn (array $c): string => $c[1]['questionId'], $this->created));
		$this->assertSame(['Bevinding', 'Bevinding'], array_column($this->created, 0));
		$this->assertSame('c1', $this->created[0][1]['cyclusId']);
		$this->assertSame('tekortkoming', $this->created[0][1]['type']);
		$this->assertSame('open', $this->created[0][1]['status']);
		$this->assertSame('BIO-9.1.1 — Beleid BIO-9.1.1: beantwoord met nee.', $this->created[0][1]['description']);
		$this->assertSame(['cyclusId' => 'c1', 'administrationId' => 'gem'], $this->asked['Evaluatievraag']);
		$this->assertSame([], RegisterSchema::errors(slug: 'Bevinding', object: $this->created[0][1]));
	}//end testTwoQuestionsAnsweredNoGiveTwoFindings()

	/**
	 * Moving the cycle again creates no second finding for a question.
	 *
	 * @return void
	 */
	public function testASecondMoveCreatesNoDuplicates(): void {
		$steps = $this->steps();
		$steps->createFindings(cycle: self::cycle());

		$this->assertSame([], $steps->createFindings(cycle: self::cycle()));
		$this->assertCount(2, $this->created);
	}//end testASecondMoveCreatesNoDuplicates()

	/**
	 * A cycle without an id reads nothing and creates nothing.
	 *
	 * @return void
	 */
	public function testACycleWithoutAnIdCreatesNothing(): void {
		$cycle = self::cycle();
		unset($cycle['@self']);

		$this->assertSame([], $this->steps()->createFindings(cycle: $cycle));
		$this->assertSame([], $this->asked);
	}//end testACycleWithoutAnIdCreatesNothing()

	/**
	 * Moving to college approval stores the statement DOCX, names the file and
	 * lists only the outstanding findings.
	 *
	 * @return void
	 */
	public function testTheStatementIsStoredOnTheCycle(): void {
		$this->rows['Bevinding'] = [
			['id' => 'b1', 'cyclusId' => 'c1', 'administrationId' => 'gem', 'type' => 'tekortkoming', 'description' => 'Open gap', 'status' => 'open'],
			['id' => 'b2', 'cyclusId' => 'c1', 'administrationId' => 'gem', 'type' => 'tekortkoming', 'description' => 'Closed gap', 'status' => 'gerealiseerd'],
		];

		$cycle = $this->steps()->withStatement(cycle: self::cycle(['status' => 'college-akkoord']));

		$this->assertSame('college-verklaring-ensia-gem-2026.docx', $cycle['declarationFile']);
		$document = self::documentXml(docx: (string)base64_decode($cycle['declarationDocx'], true));
		$this->assertStringContainsString('College-verklaring ENSIA 2026', $document);
		$this->assertStringContainsString('Open gap', $document);
		$this->assertStringNotContainsString('Closed gap', $document);
		$this->assertSame('college-akkoord', $cycle['status']);
	}//end testTheStatementIsStoredOnTheCycle()

	/**
	 * A file the process owner already named is kept.
	 *
	 * @return void
	 */
	public function testANamedDeclarationFileIsKept(): void {
		$cycle = $this->steps()->withStatement(cycle: self::cycle(['declarationFile' => 'docudesk://files/12345']));

		$this->assertSame('docudesk://files/12345', $cycle['declarationFile']);
		$this->assertNotSame('', $cycle['declarationDocx']);
	}//end testANamedDeclarationFileIsKept()

	/**
	 * Submitting an approved cycle with its statement stores the XML with the
	 * cycle's questions and its submission moment.
	 *
	 * @return void
	 */
	public function testSubmittingStoresTheXml(): void {
		$cycle = $this->steps()->withSubmission(
			cycle: self::cycle(['status' => 'submitted', 'declarationFile' => 'college-verklaring.docx', 'submittedAt' => '2026-11-14T10:00:00+00:00'])
		);

		$this->assertStringContainsString('<submittedAt>2026-11-14T10:00:00+00:00</submittedAt>', $cycle['submissionXml']);
		$this->assertStringContainsString('code="BIO-9.1.1"', $cycle['submissionXml']);
		$this->assertStringNotContainsString('code="X"', $cycle['submissionXml']);
		$this->assertStringContainsString('<kvk>12345678</kvk>', $cycle['submissionXml']);
	}//end testSubmittingStoresTheXml()

	/**
	 * A cycle without its statement is refused, and so is one not yet approved.
	 *
	 * @return void
	 */
	public function testACycleWithoutItsStatementIsRefused(): void {
		$steps = $this->steps();
		foreach ([self::cycle(['status' => 'submitted']), self::cycle(['status' => 'peer-review', 'declarationFile' => 'x.docx'])] as $cycle) {
			try {
				$steps->withSubmission(cycle: $cycle);
				$this->fail('The cycle was submitted.');
			} catch (DomainException $e) {
				$this->assertSame('Only a cycle approved by the college with its statement can be submitted.', $e->getMessage());
			}
		}
	}//end testACycleWithoutItsStatementIsRefused()

	/**
	 * The stored files come back as downloads; without them there is none.
	 *
	 * @return void
	 */
	public function testTheStoredFilesAreDownloads(): void {
		$steps = $this->steps();
		$cycle = self::cycle(['declarationDocx' => base64_encode('PKdocx'), 'submissionXml' => '<ensia/>']);

		$statement = $steps->statementFile(cycle: $cycle);
		$this->assertNotNull($statement);
		$this->assertSame('PKdocx', $statement->content);
		$this->assertSame('college-verklaring-ensia-gem-2026.docx', $statement->fileName);
		$this->assertSame(EnsiaCycleSteps::DOCX_MIME, $statement->mimeType);

		$xml = $steps->submissionFile(cycle: $cycle);
		$this->assertNotNull($xml);
		$this->assertSame('<ensia/>', $xml->content);
		$this->assertSame('ensia-gem-2026.xml', $xml->fileName);
		$this->assertSame('text/xml', $xml->mimeType);

		$this->assertNull($steps->statementFile(cycle: self::cycle(['declarationDocx' => '%%not base64%%'])));
		$this->assertNull($steps->submissionFile(cycle: self::cycle(['submissionXml' => '  '])));
	}//end testTheStoredFilesAreDownloads()

	/**
	 * The cycle schema accepts the stored statement and XML.
	 *
	 * @return void
	 */
	public function testTheStoredFieldsValidateAgainstTheRegister(): void {
		$cycle = $this->steps()->withStatement(cycle: self::cycle(['status' => 'college-akkoord']));
		$cycle['submissionXml'] = '<ensia/>';
		unset($cycle['@self']);

		$this->assertSame([], RegisterSchema::errors(slug: 'ENSIAJaarcyclus', object: $cycle));
	}//end testTheStoredFieldsValidateAgainstTheRegister()

	/**
	 * The word/document.xml of a DOCX.
	 *
	 * @param string $docx The DOCX bytes.
	 *
	 * @return string
	 */
	private static function documentXml(string $docx): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'ensia-test-');
		file_put_contents($path, $docx);
		$zip = new ZipArchive();
		$zip->open($path);
		$xml = (string)$zip->getFromName('word/document.xml');
		$zip->close();
		unlink($path);

		return $xml;
	}//end documentXml()
}//end class
