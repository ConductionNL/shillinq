<?php

/**
 * Unit tests for ENSIAVerklaringGenerator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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
 * @spec openspec/changes/bookkeeping-ensia-zelfevaluatie/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ENSIAVerklaringGenerator;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests REQ-ENSIA-006 college-verklaring DOCX generation.
 */
class ENSIAVerklaringGeneratorTest extends TestCase {

	private ENSIAVerklaringGenerator $generator;

	protected function setUp(): void {
		parent::setUp();
		$this->generator = new ENSIAVerklaringGenerator();

	}//end setUp()

	/**
	 * REQ-ENSIA-006: result is a valid OOXML ZIP archive carrying the four
	 * required parts.
	 *
	 * @return void
	 */
	public function testRenderProducesValidDocxArchive(): void {
		$docx = $this->generator->render(
			cyclus: [
				'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
				'year' => 2026,
			],
			vragen: [],
			bevindingen: []
		);

		$this->assertGreaterThan(500, strlen($docx));

		$tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
		file_put_contents($tmp, $docx);

		$zip = new ZipArchive();
		$opened = $zip->open($tmp);
		$this->assertTrue($opened === true);

		$parts = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$parts[] = $zip->getNameIndex($i);
		}

		$this->assertContains('[Content_Types].xml', $parts);
		$this->assertContains('_rels/.rels', $parts);
		$this->assertContains('word/_rels/document.xml.rels', $parts);
		$this->assertContains('word/document.xml', $parts);

		$zip->close();
		unlink($tmp);

	}//end testRenderProducesValidDocxArchive()

	/**
	 * REQ-ENSIA-006: rendered DOCX includes organisation name + KvK.
	 *
	 * @return void
	 */
	public function testRenderIncludesOrganisationData(): void {
		$docx = $this->generator->render(
			cyclus: [
				'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
				'year' => 2026,
			],
			vragen: [],
			bevindingen: []
		);

		$documentXml = $this->extractDocumentXml($docx);

		$this->assertStringContainsString('Gemeente Voorbeeld', $documentXml);
		$this->assertStringContainsString('12345678', $documentXml);
		$this->assertStringContainsString('2026', $documentXml);

	}//end testRenderIncludesOrganisationData()

	/**
	 * REQ-ENSIA-006: rendered DOCX includes per-domein summary lines.
	 *
	 * @return void
	 */
	public function testRenderIncludesPerDomeinSummary(): void {
		$docx = $this->generator->render(
			cyclus: [
				'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
				'year' => 2026,
			],
			vragen: [
				['domain' => 'BIO',   'maturityScore' => 4, 'normniveau' => 3],
				['domain' => 'BIO',   'maturityScore' => 2, 'normniveau' => 3],
				['domain' => 'DigiD', 'answer' => 'ja'],
			],
			bevindingen: []
		);

		$documentXml = $this->extractDocumentXml($docx);
		$this->assertStringContainsString('BIO', $documentXml);
		$this->assertStringContainsString('DigiD', $documentXml);

	}//end testRenderIncludesPerDomeinSummary()

	/**
	 * REQ-ENSIA-006: rendered DOCX lists top findings + mitigation plan.
	 *
	 * @return void
	 */
	public function testRenderListsTopFindings(): void {
		$docx = $this->generator->render(
			cyclus: [
				'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
				'year' => 2026,
			],
			vragen: [],
			bevindingen: [
				[
					'type' => 'tekortkoming',
					'description' => 'BIO-9.1.1 score 2 onder norm 3',
					'mitigationAction' => 'Implementeer access-review proces.',
				],
			]
		);

		$documentXml = $this->extractDocumentXml($docx);
		$this->assertStringContainsString('BIO-9.1.1', $documentXml);
		$this->assertStringContainsString('access-review', $documentXml);

	}//end testRenderListsTopFindings()

	/**
	 * REQ-ENSIA-006: rendered DOCX carries handtekeningvelden for ondertekenaars.
	 *
	 * @return void
	 */
	public function testRenderIncludesSignatureFields(): void {
		$docx = $this->generator->render(
			cyclus: [
				'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
				'year' => 2026,
			],
			vragen: [],
			bevindingen: []
		);

		$documentXml = $this->extractDocumentXml($docx);
		$this->assertStringContainsString('Burgemeester', $documentXml);
		$this->assertStringContainsString('Wethouder', $documentXml);
		$this->assertStringContainsString('Secretaris', $documentXml);
		$this->assertStringContainsString('Datum', $documentXml);

	}//end testRenderIncludesSignatureFields()

	/**
	 * Pull word/document.xml out of a DOCX byte string for assertion.
	 *
	 * @param string $docx Binary DOCX content.
	 *
	 * @return string XML.
	 */
	private function extractDocumentXml(string $docx): string {
		$tmp = tempnam(sys_get_temp_dir(), 'docx-extract-');
		file_put_contents($tmp, $docx);

		$zip = new ZipArchive();
		$zip->open($tmp);
		$xml = (string)$zip->getFromName('word/document.xml');
		$zip->close();
		unlink($tmp);

		return $xml;
	}//end extractDocumentXml()

}//end class
