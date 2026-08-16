<?php

/**
 * Unit tests for ENSIAXmlExporter.
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

use OCA\Shillinq\Service\ENSIAXmlExporter;
use PHPUnit\Framework\TestCase;

/**
 * Tests REQ-ENSIA-007 ENSIA-portal XML export.
 */
class ENSIAXmlExporterTest extends TestCase {

	private ENSIAXmlExporter $exporter;

	protected function setUp(): void {
		parent::setUp();
		$this->exporter = new ENSIAXmlExporter();

	}//end setUp()

	/**
	 * REQ-ENSIA-007: a cyclus not in college-akkoord status MUST NOT be
	 * exportable.
	 *
	 * @return void
	 */
	public function testCanExportRefusesNonApprovedCyclus(): void {
		$this->assertFalse(
			$this->exporter->canExport([
				'status' => 'in-uitvoering',
				'declarationFile' => 'docudesk://x',
			])
		);

	}//end testCanExportRefusesNonApprovedCyclus()

	/**
	 * REQ-ENSIA-007: a cyclus without verklaringFile MUST NOT be exportable.
	 *
	 * @return void
	 */
	public function testCanExportRefusesMissingVerklaringFile(): void {
		$this->assertFalse(
			$this->exporter->canExport([
				'status' => 'college-akkoord',
				'declarationFile' => '',
			])
		);

	}//end testCanExportRefusesMissingVerklaringFile()

	/**
	 * REQ-ENSIA-007: college-akkoord cyclus with verklaringFile — allowed.
	 *
	 * @return void
	 */
	public function testCanExportAllowsApprovedCyclusWithVerklaring(): void {
		$this->assertTrue(
			$this->exporter->canExport([
				'status' => 'college-akkoord',
				'declarationFile' => 'docudesk://files/12345',
			])
		);

	}//end testCanExportAllowsApprovedCyclusWithVerklaring()

	/**
	 * REQ-ENSIA-007: rendered XML contains organisation identification.
	 *
	 * @return void
	 */
	public function testRenderIncludesOrganisationIdentification(): void {
		$cyclus = [
			'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
			'year' => 2026,
			'status' => 'college-akkoord',
			'questionSetVersion' => 'BIO-1.04-2026',
			'declarationFile' => 'docudesk://files/12345',
		];

		$xml = $this->exporter->render(cyclus: $cyclus, vragen: [], submittedAt: '2026-04-30T12:00:00+00:00');

		$this->assertStringContainsString('12345678', $xml);
		$this->assertStringContainsString('Gemeente Voorbeeld', $xml);
		$this->assertStringContainsString('BIO-1.04-2026', $xml);

	}//end testRenderIncludesOrganisationIdentification()

	/**
	 * REQ-ENSIA-007: rendered XML contains per-question evidence SHA-256.
	 *
	 * @return void
	 */
	public function testRenderIncludesEvidenceShaHashes(): void {
		$cyclus = [
			'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
			'year' => 2026,
			'status' => 'college-akkoord',
			'questionSetVersion' => 'BIO-1.04-2026',
			'declarationFile' => 'docudesk://files/12345',
		];

		$vragen = [
			[
				'domain' => 'BIO',
				'questionCode' => 'BIO-9.1.1',
				'answerType' => 'volwassenheidsniveau-1-5',
				'answer' => '4',
				'maturityScore' => 4,
				'notes' => 'Toegangsbeveiliging is geregeld via formeel beleid.',
				'peerReviewStatus' => 'akkoord',
				'supportingDocuments' => [
					[
						'fileRef' => 'docudesk://files/98765',
						'description' => 'Access control policy effective 2025-01-01',
						'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
					],
				],
			],
		];

		$xml = $this->exporter->render($cyclus, $vragen);

		$this->assertStringContainsString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $xml);
		$this->assertStringContainsString('BIO-9.1.1', $xml);
		$this->assertStringContainsString('Access control policy effective 2025-01-01', $xml);

	}//end testRenderIncludesEvidenceShaHashes()

	/**
	 * REQ-ENSIA-007: questions are grouped per domein with a domein wrapper.
	 *
	 * @return void
	 */
	public function testRenderGroupsQuestionsPerDomein(): void {
		$cyclus = [
			'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
			'year' => 2026,
			'status' => 'college-akkoord',
			'questionSetVersion' => 'BIO-1.04-2026',
			'declarationFile' => 'docudesk://files/12345',
		];

		$vragen = [
			['domain' => 'BIO', 'questionCode' => 'BIO-9.1.1', 'answerType' => 'ja-nee-nvt', 'answer' => 'ja'],
			['domain' => 'DigiD', 'questionCode' => 'DigiD-1.1', 'answerType' => 'ja-nee-nvt', 'answer' => 'ja'],
			['domain' => 'BIO', 'questionCode' => 'BIO-12.1.1', 'answerType' => 'ja-nee-nvt', 'answer' => 'nee'],
		];

		$xml = $this->exporter->render($cyclus, $vragen);

		// Both domeinen present with their codes as attributes.
		$this->assertStringContainsString('code="BIO"', $xml);
		$this->assertStringContainsString('code="DigiD"', $xml);
		$this->assertStringContainsString('BIO-9.1.1', $xml);
		$this->assertStringContainsString('BIO-12.1.1', $xml);
		$this->assertStringContainsString('DigiD-1.1', $xml);

	}//end testRenderGroupsQuestionsPerDomein()

	/**
	 * REQ-ENSIA-007 second scenario: XML is regenerable after corrections;
	 * different submittedAt values yield different output.
	 *
	 * @return void
	 */
	public function testRenderIsRegenerableWithUpdatedTimestamp(): void {
		$cyclus = [
			'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
			'year' => 2026,
			'status' => 'college-akkoord',
			'questionSetVersion' => 'BIO-1.04-2026',
			'declarationFile' => 'docudesk://files/12345',
		];

		$first = $this->exporter->render($cyclus, [], '2026-04-30T12:00:00+00:00');
		$second = $this->exporter->render($cyclus, [], '2026-05-01T08:30:00+00:00');

		$this->assertStringContainsString('2026-04-30T12:00:00+00:00', $first);
		$this->assertStringContainsString('2026-05-01T08:30:00+00:00', $second);
		$this->assertNotSame($first, $second);

	}//end testRenderIsRegenerableWithUpdatedTimestamp()

	/**
	 * The rendered XML is valid parseable XML.
	 *
	 * @return void
	 */
	public function testRenderProducesValidXml(): void {
		$cyclus = [
			'organisation' => ['kvk' => '12345678', 'name' => 'Gemeente Voorbeeld'],
			'year' => 2026,
			'status' => 'college-akkoord',
			'questionSetVersion' => 'BIO-1.04-2026',
			'declarationFile' => 'docudesk://files/12345',
		];

		$xml = $this->exporter->render($cyclus, []);

		$previous = libxml_use_internal_errors(true);
		$doc = simplexml_load_string($xml);
		libxml_use_internal_errors($previous);

		$this->assertNotFalse($doc);

	}//end testRenderProducesValidXml()

}//end class
