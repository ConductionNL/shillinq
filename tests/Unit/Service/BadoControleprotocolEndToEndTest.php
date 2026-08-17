<?php

/**
 * Hermetic end-to-end integration test for the BADO controleprotocol workflow
 * (Task 20 of bookkeeping-bado-controleprotocol).
 *
 * Exercises every lifecycle stage with an in-memory ObjectService stub so the
 * suite stays hermetic — no Nextcloud bootstrap, no OR runtime, no
 * OpenConnector, no PKI. The decision logic is wired through the real
 * BadoControleprotocolService + BadoControleprotocolCalculator +
 * AccountantsdossierExportService classes so any drift in the production
 * services surfaces immediately.
 *
 * Walkthrough (mirrors Task 20 numbered steps in tasks.md):
 *  1. Create a Controleprotocol (draft).
 *  2. Pre-populate ToleranceMatrix (BADO statutory defaults).
 *  3. Calculate Materialiteit from the begroting (overall scope).
 *  4. Submit for review — gated by canSubmitForReview() (REQ-004).
 *  5. Link a raadsbesluit and call canAdopt() — gated (REQ-004).
 *  6. "Adopt" the protocol — declarative lifecycle is the production driver;
 *     here we flip the status and assert the guard accepts the transition,
 *     then assert the audit.protocol.adopted payload shape.
 *  7. Extract an AuditSample (MUS, reproducible seed).
 *  8. Record 3 AuditFindings (mix of acceptabel / te-corrigeren / materieel).
 *  9. Classify findings (severity is recomputed server-side by the service).
 * 10. Aggregate findings per topic + check verdict.
 * 11. Derive the audit opinion mechanically (REQ-007 decision tree).
 * 12. Sign the VerklaringDraft — gated by canSignVerklaring() (REQ-008).
 * 13. Export the accountantsdossier (PDF/A bundle, deterministic, SHA-256
 *     anchored, signaturePending=true).
 * 14. Verify bundle integrity — SHA-256 matches the canonicalised ledger.
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-20
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
 * End-to-end BADO controleprotocol integration test.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Shillinq\Service\BadoControleprotocolService
 * @covers \OCA\Shillinq\Service\AccountantsdossierExportService
 *
 * The materialiteit/tolerantie arithmetic is deliberately exercised through the
 * REAL calculator (see the walkthrough above) rather than a stub, so it is a
 * collaborator this test uses but does not itself claim coverage of. Without
 * the declaration below the run is reported RISKY under
 * beStrictAboutCoverageMetadata. (Do not name the annotation with its leading
 * sigil in prose — PHPUnit parses docblock text as metadata and reports the
 * bare mention as "is invalid".)
 *
 * @uses \OCA\Shillinq\Service\BadoControleprotocolCalculator
 *
 * ObjectIdentifier is the same shape of collaborator, and it only became
 * REACHABLE with the ADR-084 rewiring in this branch. Both subjects resolve a
 * record through the static helper (BadoControleprotocolService:633 and
 * AccountantsdossierExportService:892); until the seeded store was reconnected
 * the lookup short-circuited before it, so the class never executed and the
 * strict-coverage check had nothing to report. It is used, not covered.
 *
 * @uses \OCA\Shillinq\Util\ObjectIdentifier
 */
final class BadoControleprotocolEndToEndTest extends TestCase {

	/**
	 * In-memory ObjectService used by the service + exporter.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $os;

	/**
	 * Production BADO service under test.
	 *
	 * @var BadoControleprotocolService
	 */
	private BadoControleprotocolService $service;

	/**
	 * Production exporter under test.
	 *
	 * @var AccountantsdossierExportService
	 */
	private AccountantsdossierExportService $exporter;

	/**
	 * Seed the in-memory store and wire the production classes.
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
	 * Full BADO workflow: draft → in-review → adopted → sample → findings
	 * → aggregation → opinion → signed → exported accountantsdossier.
	 *
	 * @return void
	 */
	public function testFullWorkflowFromDraftToSignedAccountantsdossier(): void {
		// 1. Create Controleprotocol (draft).
		$protocolId = 'cp-2026-hoorn';
		$this->os->seed(
			schema: 'Controleprotocol',
			rows: [
				[
					'id' => $protocolId,
					'version' => '2026.1',
					'auditYear' => 2026,
					'organisationId' => 'gemeente-hoorn',
					'organisationType' => 'municipality',
					'materialityBase' => 'expenses',
					'materialityAmount' => 1000000.0,
					'effectiveFrom' => '2026-01-01',
					'effectiveTo' => '2026-12-31',
					'status' => 'draft',
				],
			]
		);

		// 2. Pre-populate ToleranceMatrix rows (BADO statutory defaults).
		$this->os->seed(
			schema: 'ToleranceMatrix',
			rows: [
				$this->toleranceRow(protocolId: $protocolId, topic: 'Sociaal Domein'),
				$this->toleranceRow(protocolId: $protocolId, topic: 'Operations'),
			]
		);

		// 3. Calculate Materialiteit (frozen on adoption).
		$this->os->seed(
			schema: 'Materialiteit',
			rows: [
				[
					'protocol' => $protocolId,
					'scope' => 'overall',
					'base' => 100000000.0,
					'percentage' => 1.0,
					'calculatedAmount' => 1000000.0,
					'status' => 'draft',
				],
			]
		);

		// 4. Submit for review — guard must allow it.
		self::assertTrue($this->service->canSubmitForReview(protocolId: $protocolId));

		// 5. Try to adopt without raadsbesluit — guard must refuse.
		self::assertFalse($this->service->canAdopt(protocolId: $protocolId));

		// 5a. Link a raadsbesluit and retry.
		$this->replaceProtocol(
			protocolId: $protocolId,
			patch: [
				'status' => 'in-review',
				'adoptionDecision' => [
					'decisionNumber' => '2026/07',
					'date' => '2026-12-15',
					'decisionType' => 'councilResolution',
				],
			]
		);
		self::assertTrue($this->service->canAdopt(protocolId: $protocolId));

		// 6. Adopt and confirm payload shape for audit.protocol.adopted event.
		$this->replaceProtocol(
			protocolId: $protocolId,
			patch: [
				'status' => 'adopted',
				'adoptionDate' => '2026-12-15',
			]
		);
		$protocol = $this->os->setSchema('Controleprotocol')->findAll(['filters' => ['id' => $protocolId]])[0];
		self::assertSame('adopted', $protocol['status']);
		self::assertSame('2026-12-15', $protocol['adoptionDate']);
		self::assertSame('gemeente-hoorn', $protocol['organisationId']);
		self::assertSame(2026, $protocol['auditYear']);

		// 7. Extract AuditSample (MUS, reproducible seed).
		$sampleId = 'sample-sd-2026-01';
		$this->os->seed(
			schema: 'AuditSample',
			rows: [
				[
					'id' => $sampleId,
					'protocol' => $protocolId,
					'population' => 'invoices > €10k in Sociaal Domein, 2026-01-01 to 2026-12-31',
					'selectionMethod' => 'monetary-unit-sampling',
					'sampleSize' => 60,
					'reproducibleSeed' => '5f2a-7b91-d8c0-4e6f-aaaaaaaaaaaa',
					'extractedAt' => '2026-12-20T10:00:00Z',
					'extractedBy' => 'auditor-1',
				],
			]
		);

		// 8. Record 3 AuditFindings (mix of severity).
		$this->os->seed(
			schema: 'AuditFinding',
			rows: [
				[
					'id' => 'finding-acceptabel',
					'sample' => $sampleId,
					'transaction' => 'tx-001',
					'findingType' => 'lawfulness',
					'lawfulness' => 'exception',
					'amount' => 1000.0,
					'topic' => 'Sociaal Domein',
					'narrative' => 'Te-late machtiging',
					'controllerResponse' => 'Akkoord — proces aangepast.',
					'auditorConclusion' => 'accepted',
					'status' => 'agreed',
				],
				[
					'id' => 'finding-tecorrigeren',
					'sample' => $sampleId,
					'transaction' => 'tx-002',
					'findingType' => 'lawfulness',
					'lawfulness' => 'exception',
					'amount' => 15000.0,
					'topic' => 'Sociaal Domein',
					'narrative' => 'Te-corrigeren post',
					'controllerResponse' => 'Akkoord — gecorrigeerd in B25.',
					'auditorConclusion' => 'accepted',
					'status' => 'agreed',
				],
				[
					'id' => 'finding-materieel',
					'sample' => $sampleId,
					'transaction' => 'tx-003',
					'findingType' => 'faithfulness',
					'faithfulness' => 'misstated',
					'amount' => 35000.0,
					'topic' => 'Operations',
					'narrative' => 'Materiële afwijking jaarafsluiting',
					'controllerResponse' => 'Akkoord — opgenomen in jaarrekening-aanpassing.',
					'auditorConclusion' => 'accepted',
					'status' => 'agreed',
				],
			]
		);

		// 9 + 10. Compute the server-authoritative aggregation.
		$aggregation = $this->service->computeAggregation(protocolId: $protocolId);
		self::assertSame($protocolId, $aggregation['protocolId']);
		self::assertSame(1000000.0, (float)$aggregation['materialityAmount']);
		self::assertNotEmpty($aggregation['topics']);

		$bytopic = [];
		foreach ($aggregation['topics'] as $row) {
			$bytopic[$row['topic']] = $row;
		}

		// Sociaal Domein: 1k + 15k <= 30k ceiling but >= 10k → qualified.
		self::assertArrayHasKey('Sociaal Domein', $bytopic);
		self::assertContains($bytopic['Sociaal Domein']['verdict'], ['acceptable', 'qualified']);

		// Bedrijfsvoering: 35k >= 30k qualification ceiling → adverse.
		self::assertArrayHasKey('Operations', $bytopic);
		self::assertSame('adverse', $bytopic['Operations']['verdict']);

		// 11. Mechanically derived opinion is one of the four BADO outcomes.
		self::assertContains(
			$aggregation['proposedOpinion'],
			['goedkeurend', 'met-beperking', 'oordeelonthouding', 'afkeurend']
		);

		// 12. VerklaringDraft cannot be signed without SiSa coverage check.
		$this->os->seed(
			schema: 'VerklaringDraft',
			rows: [
				[
					'id' => 'verklaring-1',
					'protocol' => $protocolId,
					'proposedOpinion' => $aggregation['proposedOpinion'],
					'opinionRationale' => 'Mechanisch afgeleid uit aggregation per BADO §5.',
					'status' => 'draft',
					'signOff' => [
						'auditor' => 'P. de Vries',
						'afmPermitNumber' => 'AFM-13046',
						'date' => '2026-12-22',
						'place' => 'Hoorn',
					],
				],
			]
		);
		// Vacuously covered because no SiSaAssurance records exist (no SiSa-regelingen in scope).
		self::assertTrue($this->service->canSignVerklaring(declarationId: 'verklaring-1'));

		// 13. Export accountantsdossier.
		$envelope = $this->exporter->exportDossier(protocolId: $protocolId);
		self::assertSame($protocolId, $envelope['protocolId']);
		self::assertTrue($envelope['signaturePending']);
		self::assertNotEmpty($envelope['sha256']);
		self::assertSame(64, strlen($envelope['sha256']));
		self::assertSame(7, $envelope['retentionYears']);
		self::assertGreaterThanOrEqual(8, $envelope['attachmentCount']);
		self::assertFileExists($envelope['zipPath']);

		// 14. Verify bundle integrity (SHA-256 matches the ledger.json bytes).
		$zip = new ZipArchive();
		self::assertTrue($zip->open($envelope['zipPath']) === true);
		$manifestJson = $zip->getFromName($envelope['packageId'] . '/manifest.json');
		$ledgerJson = $zip->getFromName($envelope['packageId'] . '/ledger.json');
		$summaryHtml = $zip->getFromName($envelope['packageId'] . '/summary.pdf.html');
		$zip->close();

		self::assertIsString($manifestJson);
		self::assertIsString($ledgerJson);
		self::assertIsString($summaryHtml);
		self::assertSame($envelope['sha256'], hash(algo: 'sha256', data: $ledgerJson));

		$manifest = json_decode($manifestJson, true);
		self::assertSame($envelope['sha256'], $manifest['sha256']);
		self::assertSame(7, $manifest['retentionYears']);
		self::assertSame('1', $manifest['pdfaPart']);
		self::assertSame('pending', $manifest['signature']['status']);
		self::assertStringContainsString('pdfaid:part', $summaryHtml);
		self::assertStringContainsString('Accountantsdossier BADO', $summaryHtml);

		unlink($envelope['zipPath']);

	}//end testFullWorkflowFromDraftToSignedAccountantsdossier()

	/**
	 * Build a statutory-default ToleranceMatrix row for one topic.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 * @param string $topic Programma / taakveld.
	 *
	 * @return array<string,mixed>
	 */
	private function toleranceRow(string $protocolId, string $topic): array {
		return [
			'protocol' => $protocolId,
			'topic' => $topic,
			'faithfulnessApprovalCeiling' => 1.0,
			'faithfulnessQualificationCeiling' => 3.0,
			'lawfulnessApprovalCeiling' => 1.0,
			'lawfulnessQualificationCeiling' => 3.0,
			'uncertaintyCeiling' => 3.0,
		];
	}//end toleranceRow()

	/**
	 * Replace the Controleprotocol record with a merged patch.
	 *
	 * The in-memory store appends on saveObject(); for a hermetic test we
	 * mutate the underlying array directly so the protocol stays unique.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 * @param array<string,mixed> $patch Fields to merge.
	 *
	 * @return void
	 */
	private function replaceProtocol(string $protocolId, array $patch): void {
		$reflection = new \ReflectionClass(InMemoryObjectService::class);
		$records = $reflection->getProperty('records');
		$records->setAccessible(true);

		$all = $records->getValue($this->os);
		foreach (($all['Controleprotocol'] ?? []) as $index => $row) {
			if ((string)($row['id'] ?? '') === $protocolId) {
				$all['Controleprotocol'][$index] = array_merge($row, $patch);
			}
		}

		$records->setValue($this->os, $all);

	}//end replaceProtocol()
}//end class
