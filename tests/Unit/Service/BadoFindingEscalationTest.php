<?php

/**
 * Hermetic integration test for the BADO finding escalation workflow (Task 22
 * of bookkeeping-bado-controleprotocol).
 *
 * Exercises the open → disputed → resolved lifecycle with controller dispute,
 * auditor escalation, external audit-manager resolution and post-resolution
 * aggregation (REQ-006, Task 18) using the production
 * BadoControleprotocolService + BadoControleprotocolCalculator wired through
 * an in-memory ObjectService stub — no Nextcloud bootstrap, no OR runtime.
 *
 * Walkthrough (mirrors Task 22 numbered steps):
 *  1. Record an AuditFinding with controller disagreement (status=open).
 *  2. Controller submits response (controllerResponse field).
 *  3. Auditor reviews and marks finding as disputed (auditorConclusion =
 *     "escalation required"); guard hasControllerResponse() must accept the
 *     open → agreed transition AND must REFUSE the open → resolved short-cut
 *     while the four-eye axes are missing.
 *  4. Escalation task created (assigned to external audit manager).
 *  5. Audit manager resolves escalation — both axes recorded, both axes carry
 *     severity. isFourEyeComplete() must accept resolution.
 *  6. Finding status → resolved.
 *  7. Finding aggregates into opinion calculation (server-authoritative
 *     classifySeverity() reclassifies the finding against the topic's
 *     ToleranceMatrix + frozen materialiteit).
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BadoControleprotocolCalculator;
use OCA\Shillinq\Service\BadoControleprotocolService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * BADO finding escalation workflow integration test.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Shillinq\Service\BadoControleprotocolService
 * @covers \OCA\Shillinq\Service\BadoControleprotocolCalculator
 *
 * The subject resolves a record through the static ObjectIdentifier helper
 * (BadoControleprotocolService:633). That call only became REACHABLE with the
 * ADR-084 rewiring in this branch — until the seeded store was reconnected the
 * lookup short-circuited before it, so the class never executed and the
 * strict-coverage check had nothing to report. It is a collaborator this test
 * uses but does not claim coverage of; without the declaration below the run is
 * reported RISKY under beStrictAboutCoverageMetadata, and phpunit.xml sets
 * failOnRisky="true", so the whole cell exits 1 on an otherwise green suite.
 *
 * @uses \OCA\Shillinq\Util\ObjectIdentifier
 */
final class BadoFindingEscalationTest extends TestCase {

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
	 * Build the harness.
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

		$this->service = new BadoControleprotocolService(
			appConfig: $appConfig,
			calculator: new BadoControleprotocolCalculator(),
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($this->os),
		);

	}//end setUp()

	/**
	 * Full escalation workflow: open → disputed → resolved with both axes.
	 *
	 * @return void
	 */
	public function testEscalationWorkflowFromOpenToResolved(): void {
		$protocolId = 'cp-2026-escalation';
		$sampleId = 'sample-escalation';
		$findingId = 'finding-escalated';

		$this->os->seed(
			schema: 'Controleprotocol',
			rows: [
				[
					'id' => $protocolId,
					'version' => '2026.1',
					'auditYear' => 2026,
					'organisationId' => 'gemeente-rotterdam',
					'organisationType' => 'municipality',
					'materialityBase' => 'expenses',
					'materialityAmount' => 2000000.0,
					'effectiveFrom' => '2026-01-01',
					'effectiveTo' => '2026-12-31',
					'status' => 'adopted',
				],
			]
		);
		$this->os->seed(
			schema: 'Materialiteit',
			rows: [
				[
					'protocol' => $protocolId,
					'scope' => 'overall',
					'base' => 200000000.0,
					'percentage' => 1.0,
					'calculatedAmount' => 2000000.0,
					'status' => 'frozen',
				],
			]
		);
		$this->os->seed(
			schema: 'ToleranceMatrix',
			rows: [
				[
					'protocol' => $protocolId,
					'topic' => 'Subsidies & Bijdragen',
					'faithfulnessApprovalCeiling' => 1.0,
					'faithfulnessQualificationCeiling' => 3.0,
					'lawfulnessApprovalCeiling' => 1.0,
					'lawfulnessQualificationCeiling' => 3.0,
					'uncertaintyCeiling' => 3.0,
				],
			]
		);
		$this->os->seed(
			schema: 'AuditSample',
			rows: [
				[
					'id' => $sampleId,
					'protocol' => $protocolId,
					'population' => 'Subsidies > €100k boekjaar 2026',
					'selectionMethod' => 'risk-based',
					'sampleSize' => 15,
					'reproducibleSeed' => 'esc-seed-2026',
					'extractedAt' => '2026-12-19T08:30:00Z',
					'extractedBy' => 'auditor-2',
				],
			]
		);

		// 1. AuditFinding with controller disagreement (status=open).
		$this->os->seed(
			schema: 'AuditFinding',
			rows: [
				[
					'id' => $findingId,
					'sample' => $sampleId,
					'transaction' => 'sub-tx-001',
					'findingType' => 'lawfulness',
					'topic' => 'Subsidies & Bijdragen',
					'amount' => 25000.0,
					'narrative' => 'Mogelijk onbevoegd toegekend',
					'status' => 'open',
				],
			]
		);

		// Sanity: at this point no controllerResponse → cannot move open → agreed.
		self::assertFalse($this->service->hasControllerResponse(findingId: $findingId));

		// 2. Controller submits response.
		$this->updateFinding(
			findingId: $findingId,
			patch: [
				'controllerResponse' => 'Niet eens — toekenning conform delegatiebesluit B-2026/07.',
			]
		);
		self::assertTrue($this->service->hasControllerResponse(findingId: $findingId));

		// 3. Auditor reviews and concludes escalation required.
		$this->updateFinding(
			findingId: $findingId,
			patch: [
				'auditorConclusion' => 'escalation required',
				'status' => 'disputed',
			]
		);

		// Mid-escalation: only one axis carries severity, the other is still
		// blank → four-eye guard MUST refuse resolution.
		$this->updateFinding(
			findingId: $findingId,
			patch: [
				'lawfulness' => 'exception',
				'rechtmatigheidSeverity' => 'te-corrigeren',
			]
		);
		self::assertFalse($this->service->isFourEyeComplete(findingId: $findingId));

		// 4. Escalation task assigned to external audit manager — modelled as
		// a state-only transition in the in-memory store (no task schema in
		// this fragment; the audit manager is the actor on resolution).
		// 5. Audit manager resolves: both axes documented + severities recorded.
		$this->updateFinding(
			findingId: $findingId,
			patch: [
				'lawfulness' => 'exception',
				'rechtmatigheidSeverity' => 'te-corrigeren',
				'faithfulness' => 'compliant',
				'getrouwheidSeverity' => 'acceptabel',
				'controllerResponse' => 'Niet eens; audit manager bevestigt rechtmatigheid-uitzondering.',
				'auditorConclusion' => 'accepted; escalation resolved; rechtmatigheid-uitzondering te-corrigeren, geen getrouwheid-impact',
			]
		);

		// 6. After both axes are present + responses recorded, four-eye guard
		// must accept resolution.
		self::assertTrue($this->service->isFourEyeComplete(findingId: $findingId));

		// 7. Once status → resolved, the finding aggregates into the protocol
		// opinion calculation.
		$this->updateFinding(
			findingId: $findingId,
			patch: ['status' => 'resolved']
		);

		$aggregation = $this->service->computeAggregation(protocolId: $protocolId);
		self::assertNotEmpty($aggregation['topics']);

		$matched = null;
		foreach ($aggregation['topics'] as $row) {
			if ($row['topic'] === 'Subsidies & Bijdragen') {
				$matched = $row;
				break;
			}
		}

		self::assertNotNull(
			$matched,
			'resolved finding must aggregate into its topic verdict'
		);

		// €25k on a €2M materialiteit (1% = €20k approval, 3% = €60k qualification)
		// → te-corrigeren → 'qualified' verdict for the topic.
		self::assertSame('qualified', $matched['verdict']);
		self::assertGreaterThanOrEqual(1, ($matched['teCorrigerenCount'] ?? 0));

	}//end testEscalationWorkflowFromOpenToResolved()

	/**
	 * Helper: merge-update an AuditFinding in the in-memory store.
	 *
	 * @param string $findingId The AuditFinding.id.
	 * @param array<string,mixed> $patch Fields to merge.
	 *
	 * @return void
	 */
	private function updateFinding(string $findingId, array $patch): void {
		$reflection = new \ReflectionClass(InMemoryObjectService::class);
		$records = $reflection->getProperty('records');
		$records->setAccessible(true);

		$all = $records->getValue($this->os);
		foreach (($all['AuditFinding'] ?? []) as $index => $row) {
			if ((string)($row['id'] ?? '') === $findingId) {
				$all['AuditFinding'][$index] = array_merge($row, $patch);
			}
		}

		$records->setValue($this->os, $all);

	}//end updateFinding()
}//end class
