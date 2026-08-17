<?php

/**
 * Hermetic integration test for the BADO SiSa-bijlage IIA linkage (Task 21 of
 * bookkeeping-bado-controleprotocol).
 *
 * Exercises the per-regeling SiSaAssurance roll-up + accountantsdossier
 * inclusion (REQ-008, REQ-010) with an in-memory ObjectService stub so the
 * suite stays hermetic — no Nextcloud bootstrap, no OR runtime, no SiSa-CBS
 * upload, no PKI.
 *
 * Walkthrough (mirrors Task 21 numbered steps):
 *  1. Seed a Controleprotocol + Materialiteit + ToleranceMatrix.
 *  2. Create SiSaAssurance entries (one per in-scope regeling).
 *  3. Link AuditFinding records to each SiSaAssurance (per-regeling findings).
 *  4. Build the dossier and assert the SiSa rows are present + counts match.
 *  5. Verify the IIA table fields exposed via summary HTML: regeling code,
 *     verantwoordingsplichtige, assurance level, finding summary.
 *  6. Verify the IIA table is part of the accountantsdossier bundle
 *     (attachments/sisa-assurance/).
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-21
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AccountantsdossierExportService;
use OCA\Shillinq\Service\BadoControleprotocolCalculator;
use OCA\Shillinq\Service\BadoControleprotocolService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ZipArchive;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * BADO SiSa-bijlage IIA integration test.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Shillinq\Service\BadoControleprotocolService
 * @covers \OCA\Shillinq\Service\AccountantsdossierExportService
 *
 * Seeding the Materialiteit + ToleranceMatrix (step 1) runs the REAL calculator
 * rather than a stub, so it is a collaborator this test uses but does not itself
 * claim coverage of. Without the declaration below the run is reported RISKY
 * under beStrictAboutCoverageMetadata. (Do not name the annotation with its
 * leading sigil in prose — PHPUnit parses docblock text as metadata and reports
 * the bare mention as "is invalid".)
 *
 * @uses \OCA\Shillinq\Service\BadoControleprotocolCalculator
 *
 * ObjectIdentifier is the same shape of collaborator, and it only became
 * REACHABLE with the ADR-084 rewiring in this branch: both subjects resolve a
 * record through the static helper (BadoControleprotocolService:633 and
 * AccountantsdossierExportService:892), and until the seeded store was
 * reconnected the lookup short-circuited before it. It is used, not covered.
 *
 * @uses \OCA\Shillinq\Util\ObjectIdentifier
 */
final class BadoSisaBijlageIIATest extends TestCase {

	/**
	 * In-memory ObjectService.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $os;

	/**
	 * Production BADO service.
	 *
	 * @var BadoControleprotocolService
	 */
	private BadoControleprotocolService $service;

	/**
	 * Production exporter.
	 *
	 * @var AccountantsdossierExportService
	 */
	private AccountantsdossierExportService $exporter;

	/**
	 * Build the harness and wire production classes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->os = new InMemoryObjectService();

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($this->os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$userSession = $this->createStub(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->service = new BadoControleprotocolService(
			appConfig: $appConfig,
			calculator: new BadoControleprotocolCalculator(),
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($this->os),
		);

		$this->exporter = new AccountantsdossierExportService(
			appConfig: $appConfig,
			userSession: $userSession,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($this->os),
		);

	}//end setUp()

	/**
	 * SiSa-bijlage IIA per-regeling roll-up + dossier inclusion.
	 *
	 * @return void
	 */
	public function testSisaBijlageRollupAndDossierInclusion(): void {
		$protocolId = 'cp-2026-sisa-utrecht';
		$this->os->seed(
			schema: 'Controleprotocol',
			rows: [
				[
					'id' => $protocolId,
					'version' => '2026.1',
					'auditYear' => 2026,
					'organisationId' => 'gemeente-utrecht',
					'organisationType' => 'municipality',
					'materialityBase' => 'expenses',
					'materialityAmount' => 5000000.0,
					'effectiveFrom' => '2026-01-01',
					'effectiveTo' => '2026-12-31',
					'status' => 'adopted',
					'adoptionDate' => '2026-12-10',
					'adoptionDecision' => [
						'decisionNumber' => '2026/12',
						'date' => '2026-12-10',
						'decisionType' => 'councilResolution',
					],
				],
			]
		);
		$this->os->seed(
			schema: 'Materialiteit',
			rows: [
				[
					'protocol' => $protocolId,
					'scope' => 'overall',
					'base' => 500000000.0,
					'percentage' => 1.0,
					'calculatedAmount' => 5000000.0,
					'status' => 'frozen',
				],
			]
		);
		$this->os->seed(
			schema: 'ToleranceMatrix',
			rows: [
				$this->statutoryRow(protocolId: $protocolId, topic: 'SiSa-G2 Participatiewet'),
				$this->statutoryRow(protocolId: $protocolId, topic: 'SiSa-G3 Schulden'),
			]
		);

		// Seed AuditSample + per-regeling AuditFinding.
		$g2Sample = 'sample-sisa-g2';
		$g3Sample = 'sample-sisa-g3';
		$this->os->seed(
			schema: 'AuditSample',
			rows: [
				[
					'id' => $g2Sample,
					'protocol' => $protocolId,
					'population' => 'SiSa G2 Participatiewet uitkeringen 2026',
					'selectionMethod' => 'monetary-unit-sampling',
					'sampleSize' => 30,
					'reproducibleSeed' => 'g2-seed-2026',
					'extractedAt' => '2026-12-18T09:00:00Z',
					'extractedBy' => 'auditor-1',
				],
				[
					'id' => $g3Sample,
					'protocol' => $protocolId,
					'population' => 'SiSa G3 Schuldhulp dossiers 2026',
					'selectionMethod' => 'random',
					'sampleSize' => 20,
					'reproducibleSeed' => 'g3-seed-2026',
					'extractedAt' => '2026-12-18T11:00:00Z',
					'extractedBy' => 'auditor-1',
				],
			]
		);

		$g2Finding1 = 'finding-g2-1';
		$g2Finding2 = 'finding-g2-2';
		$g3Finding1 = 'finding-g3-1';
		$this->os->seed(
			schema: 'AuditFinding',
			rows: [
				[
					'id' => $g2Finding1,
					'sample' => $g2Sample,
					'transaction' => 'pw-tx-001',
					'findingType' => 'lawfulness',
					'lawfulness' => 'exception',
					'amount' => 8000.0,
					'topic' => 'SiSa-G2 Participatiewet',
					'narrative' => 'Onterechte uitkering',
					'controllerResponse' => 'Akkoord — terugvordering ingezet.',
					'auditorConclusion' => 'accepted',
					'status' => 'agreed',
				],
				[
					'id' => $g2Finding2,
					'sample' => $g2Sample,
					'transaction' => 'pw-tx-002',
					'findingType' => 'lawfulness',
					'lawfulness' => 'compliant',
					'amount' => 0.0,
					'topic' => 'SiSa-G2 Participatiewet',
					'narrative' => 'Compliant',
					'controllerResponse' => 'Akkoord',
					'auditorConclusion' => 'accepted',
					'status' => 'agreed',
				],
				[
					'id' => $g3Finding1,
					'sample' => $g3Sample,
					'transaction' => 'sh-tx-001',
					'findingType' => 'faithfulness',
					'faithfulness' => 'misstated',
					'amount' => 2500.0,
					'topic' => 'SiSa-G3 Schulden',
					'narrative' => 'Boekingsfout opname schuldhulp',
					'controllerResponse' => 'Akkoord — geboekt op juiste taakveld.',
					'auditorConclusion' => 'accepted',
					'status' => 'agreed',
				],
			]
		);

		// SiSaAssurance: one row per regeling.
		$this->os->seed(
			schema: 'SiSaAssurance',
			rows: [
				[
					'protocol' => $protocolId,
					'schemeCode' => 'G2',
					'accountableParty' => 'municipality',
					'specificBenefit' => 'Participatiewet',
					'assuranceLevel' => 'sisa-specific',
					'findings' => [$g2Finding1, $g2Finding2],
				],
				[
					'protocol' => $protocolId,
					'schemeCode' => 'G3',
					'accountableParty' => 'municipality',
					'specificBenefit' => 'Schuldhulpverlening',
					'assuranceLevel' => 'sisa-specific',
					'findings' => [$g3Finding1],
				],
			]
		);

		// VerklaringDraft.
		$this->os->seed(
			schema: 'VerklaringDraft',
			rows: [
				[
					'id' => 'verklaring-sisa',
					'protocol' => $protocolId,
					'proposedOpinion' => 'goedkeurend',
					'opinionRationale' => 'Geen materiële afwijkingen; SiSa-coverage compleet.',
					'status' => 'draft',
					'signOff' => [
						'auditor' => 'J. Bakker',
						'afmPermitNumber' => 'AFM-1235',
						'date' => '2026-12-22',
						'place' => 'Utrecht',
					],
				],
			]
		);

		// Per Task 21 step 1+2: dossier must include both SiSaAssurance rows.
		$envelope = $this->exporter->buildDossier(protocolId: $protocolId);
		$ledger = $envelope['ledger'];

		self::assertCount(2, $ledger['sisaAssurance']);
		$bycode = [];
		foreach ($ledger['sisaAssurance'] as $row) {
			$bycode[$row['schemeCode']] = $row;
		}

		self::assertArrayHasKey('G2', $bycode);
		self::assertArrayHasKey('G3', $bycode);

		// Step 4: per-regeling roll-up — count findings linked to each regeling.
		self::assertCount(2, $bycode['G2']['findings']);
		self::assertCount(1, $bycode['G3']['findings']);

		// Step 4: each row carries verantwoordingsplichtige, assurance level,
		// specifiekeUitkering — the IIA-table column set.
		foreach (['G2', 'G3'] as $code) {
			self::assertSame('municipality', $bycode[$code]['accountableParty']);
			self::assertSame('sisa-specific', $bycode[$code]['assuranceLevel']);
			self::assertNotEmpty($bycode[$code]['specificBenefit']);
		}

		// Step 4: HTML summary contains the IIA-style row for each regeling.
		self::assertStringContainsString('G2', $envelope['summaryHtml']);
		self::assertStringContainsString('G3', $envelope['summaryHtml']);
		self::assertStringContainsString('Participatiewet', $envelope['summaryHtml']);
		self::assertStringContainsString('Schuldhulpverlening', $envelope['summaryHtml']);

		// Step 5: IIA inclusion is reflected in the bundle's attachment manifest.
		$attachmentPaths = $envelope['manifest']['attachments'];
		$sisaAttachments = array_filter(
			$attachmentPaths,
			static fn (string $path): bool => str_starts_with($path, 'attachments/sisa-assurance/')
		);
		self::assertCount(2, $sisaAttachments);

		// Step 6: the ZIP physically carries the SiSa rows.
		$written = $this->exporter->exportDossier(protocolId: $protocolId);
		$zip = new ZipArchive();
		self::assertTrue($zip->open($written['zipPath']) === true);

		$g2Json = $zip->getFromName($written['packageId'] . '/attachments/sisa-assurance/row-0001.json');
		$g3Json = $zip->getFromName($written['packageId'] . '/attachments/sisa-assurance/row-0002.json');
		$zip->close();

		self::assertIsString($g2Json);
		self::assertIsString($g3Json);
		self::assertStringContainsString('"schemeCode": "G2"', $g2Json);
		self::assertStringContainsString('"schemeCode": "G3"', $g3Json);

		unlink($written['zipPath']);

		// Sanity: signature delegation still pending (no signer configured).
		self::assertTrue($this->service->canSignVerklaring(declarationId: 'verklaring-sisa'));

	}//end testSisaBijlageRollupAndDossierInclusion()

	/**
	 * Build a statutory-default ToleranceMatrix row.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 * @param string $topic Topic.
	 *
	 * @return array<string,mixed>
	 */
	private function statutoryRow(string $protocolId, string $topic): array {
		return [
			'protocol' => $protocolId,
			'topic' => $topic,
			'faithfulnessApprovalCeiling' => 1.0,
			'faithfulnessQualificationCeiling' => 3.0,
			'lawfulnessApprovalCeiling' => 1.0,
			'lawfulnessQualificationCeiling' => 3.0,
			'uncertaintyCeiling' => 3.0,
		];
	}//end statutoryRow()
}//end class
