<?php

/**
 * Unit tests for the staged import pipeline (pure computation guards).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use OCA\Shillinq\Lifecycle\ImportBatchGuard;
use OCA\Shillinq\Service\Import\AuditfileParser;
use OCA\Shillinq\Service\Import\ImportPipelineService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies the fully-real validation / dry-run / post / reverse guards (REQ-AIM-005..009).
 */
final class ImportPipelineServiceTest extends TestCase {

	/**
	 * Build a pipeline with mocked Container + Logger and real parser + guard.
	 *
	 * @return ImportPipelineService
	 */
	private function pipeline(): ImportPipelineService {
		/*
		 * The container is mocked to "have nothing" so cross-service writes
		 * degrade gracefully (logged warning finding) — the pure guards under
		 * test never touch it.
		 */
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		$container->method('get')->willThrowException(new \RuntimeException('not available in unit context'));

		$logger = $this->createMock(LoggerInterface::class);

		return new ImportPipelineService($container, $logger, new AuditfileParser(), new ImportBatchGuard());
	}//end pipeline()

	/**
	 * A balanced staged opening balance with matching control accounts validates.
	 *
	 * @return array<string,mixed> A valid batch for reuse.
	 */
	private function validBatch(): array {
		return [
			'administrationId' => 'adm-x',
			'migrationDate' => '2026-01-01',
			'periodOpen' => true,
			'mappings' => [
				['sourceCode' => '1300', 'targetAccount' => '1300', 'mappingSource' => 'rgs-auto', 'confirmed' => true],
			],
			'stagingPayload' => [
				'openingBalances' => [
					['accountCode' => '1300', 'debit' => 24200.00, 'credit' => 0.0],
					['accountCode' => '1600', 'debit' => 0.0, 'credit' => 24200.00],
				],
				'arOpenItems' => [['outstandingAmount' => 24200.00]],
				'apOpenItems' => [],
				'arControlOpeningAmount' => 24200.00,
				'apControlOpeningAmount' => 0.0,
			],
		];

	}//end validBatch()

	/**
	 * Balance guard: an unbalanced opening journal produces an error finding.
	 *
	 * @return void
	 */
	public function testUnbalancedOpeningJournalFailsValidation(): void {
		$batch = $this->validBatch();
		$batch['stagingPayload']['openingBalances'][1]['credit'] = 20000.00;

		$result = $this->pipeline()->validate($batch);

		self::assertFalse($result['valid']);
		$codes = array_column($result['findings'], 'code');
		self::assertContains('opening-journal-unbalanced', $codes);
	}//end testUnbalancedOpeningJournalFailsValidation()

	/**
	 * The happy-path batch validates with no error findings.
	 *
	 * @return void
	 */
	public function testValidBatchPassesValidation(): void {
		$result = $this->pipeline()->validate($this->validBatch());
		self::assertTrue($result['valid'], json_encode($result['findings']));
	}//end testValidBatchPassesValidation()

	/**
	 * Control-account double-count guard: open-item sum != control opening blocks.
	 *
	 * @return void
	 */
	public function testControlAccountMismatchFailsValidation(): void {
		$batch = $this->validBatch();
		// AR control says 24,200 but open items only sum 23,000.
		$batch['stagingPayload']['arOpenItems'] = [['outstandingAmount' => 23000.00]];

		$result = $this->pipeline()->validate($batch);

		self::assertFalse($result['valid']);
		$codes = array_column($result['findings'], 'code');
		self::assertContains('AR-control-mismatch', $codes);

		// The reported difference is exactly 1,200.
		foreach ($result['findings'] as $finding) {
			if ($finding['code'] === 'AR-control-mismatch') {
				self::assertSame(1200.00, $finding['context']['difference']);
			}
		}
	}//end testControlAccountMismatchFailsValidation()

	/**
	 * An unmapped account blocks the mapping → validated transition.
	 *
	 * @return void
	 */
	public function testUnmappedAccountBlocksValidation(): void {
		$batch = $this->validBatch();
		$batch['mappings'][] = ['sourceCode' => '9999', 'targetAccount' => null, 'mappingSource' => 'unmapped', 'confirmed' => false];

		$result = $this->pipeline()->validate($batch);

		self::assertFalse($result['valid']);
		$codes = array_column($result['findings'], 'code');
		self::assertContains('account-unmapped', $codes);
	}//end testUnmappedAccountBlocksValidation()

	/**
	 * A closed period blocks validation/posting.
	 *
	 * @return void
	 */
	public function testClosedPeriodFailsValidation(): void {
		$batch = $this->validBatch();
		$batch['periodOpen'] = false;

		$result = $this->pipeline()->validate($batch);

		self::assertFalse($result['valid']);
		$codes = array_column($result['findings'], 'code');
		self::assertContains('period-closed', $codes);
	}//end testClosedPeriodFailsValidation()

	/**
	 * The dry-run records a staged hash and posting refuses when it changes.
	 *
	 * @return void
	 */
	public function testStagedStateChangeForcesRevalidation(): void {
		$pipeline = $this->pipeline();
		$batch = $this->validBatch();

		// Dry-run records the staged hash on the batch.
		$report = $pipeline->dryRun($batch);
		$batch['dryRunReport'] = $report;
		self::assertNotSame('', $report['stagedHash']);

		// Now mutate the staged payload after the dry-run.
		$batch['stagingPayload']['openingBalances'][0]['debit'] = 99999.00;

		$result = $pipeline->post($batch);

		self::assertSame('posting_failed', $result['status']);
		$codes = array_column($result['findings'], 'code');
		self::assertContains('staged-state-changed', $codes);
	}//end testStagedStateChangeForcesRevalidation()

	/**
	 * Posting an unchanged dry-run batch proceeds (status posted) idempotently false.
	 *
	 * @return void
	 */
	public function testPostConsistentWithDryRunProceeds(): void {
		$pipeline = $this->pipeline();
		$batch = $this->validBatch();
		$batch['dryRunReport'] = $pipeline->dryRun($batch);

		$result = $pipeline->post($batch);

		// No ObjectService in unit context → writes degrade gracefully (warnings),
		// but the consistency + balance guards pass so status is posted.
		self::assertSame('posted', $result['status']);
		self::assertFalse($result['idempotent']);
	}//end testPostConsistentWithDryRunProceeds()

	/**
	 * Re-posting an already-posted batch is a no-op returning existing refs.
	 *
	 * @return void
	 */
	public function testRePostIsIdempotentNoOp(): void {
		$batch = $this->validBatch();
		$batch['status'] = 'posted';
		$batch['postingRefs'] = ['openingJournalId' => 'gl-1', 'arItemIds' => ['ar-1']];

		$result = $this->pipeline()->post($batch);

		self::assertTrue($result['idempotent']);
		self::assertSame('posted', $result['status']);
		self::assertSame('gl-1', $result['postingRefs']['openingJournalId']);
	}//end testRePostIsIdempotentNoOp()

	/**
	 * Reversal is blocked when the period is closed.
	 *
	 * @return void
	 */
	public function testReverseBlockedWhenPeriodClosed(): void {
		$batch = ['status' => 'posted', 'postingRefs' => ['openingJournalId' => 'gl-1']];

		$result = $this->pipeline()->reverse($batch, false);

		$codes = array_column($result['findings'], 'code');
		self::assertContains('reversal-blocked', $codes);
		self::assertNotSame('reversed', $result['status']);
	}//end testReverseBlockedWhenPeriodClosed()

	/**
	 * Reversal in an open period unwinds and reports contacts (never deletes them).
	 *
	 * @return void
	 */
	public function testReverseOpenPeriodReportsContacts(): void {
		$batch = [
			'status' => 'posted',
			'migrationDate' => '2026-01-01',
			'postingRefs' => [
				'openingJournalId' => 'gl-1',
				'arItemIds' => ['ar-1'],
				'apItemIds' => ['ap-1'],
				'masterIds' => ['m-1'],
				'contactIds' => ['kvk-12345678'],
			],
		];

		$result = $this->pipeline()->reverse($batch, true);

		self::assertSame('reversed', $result['status']);
		self::assertSame(['kvk-12345678'], $result['reportedContacts']);
	}//end testReverseOpenPeriodReportsContacts()

	/**
	 * Idempotency key is stable across calls and changes with scope.
	 *
	 * @return void
	 */
	public function testIdempotencyKeyStableAndScopeSensitive(): void {
		$pipeline = $this->pipeline();
		$batch = ['administrationId' => 'adm-x', 'sourceFiles' => [['path' => '/a.xaf']], 'scope' => ['openingBalance' => true]];

		$key1 = $pipeline->computeIdempotencyKey($batch);
		$key2 = $pipeline->computeIdempotencyKey($batch);
		self::assertSame($key1, $key2);

		$batch['scope']['openItems'] = true;
		self::assertNotSame($key1, $pipeline->computeIdempotencyKey($batch));
	}//end testIdempotencyKeyStableAndScopeSensitive()

	/**
	 * The due-date heuristic returns overdue for a past date, issued otherwise.
	 *
	 * @return void
	 */
	public function testOpenItemStateForDueDate(): void {
		$pipeline = $this->pipeline();
		self::assertSame('overdue', $pipeline->openItemStateForDueDate('2000-01-01'));
		self::assertSame('issued', $pipeline->openItemStateForDueDate('2099-01-01'));
		self::assertSame('issued', $pipeline->openItemStateForDueDate(''));
	}//end testOpenItemStateForDueDate()

}//end class
