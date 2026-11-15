<?php

/**
 * ImportPipelineService at its edges: the dry run's duplicate warnings and
 * keyless contacts, a post refused for an unbalanced opening balance after
 * the dry run, and an open item whose due date cannot be read.
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
 * @spec openspec/changes/administration-import-migration/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use OCA\Shillinq\Lifecycle\ImportBatchGuard;
use OCA\Shillinq\Service\Import\AuditfileParser;
use OCA\Shillinq\Service\Import\ImportPipelineService;
use OCA\Shillinq\Service\Import\ImportPosting;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The dry run's warnings, the post's balance re-check and the open item state.
 *
 * @covers \OCA\Shillinq\Service\Import\ImportPipelineService
 * @uses   \OCA\Shillinq\Service\Import\AuditfileParser
 */
final class ImportPipelineEdgesTest extends TestCase {

	/**
	 * The posting double; a post that reaches it is a test failure.
	 *
	 * @var ImportPosting&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ImportPosting $posting;

	/**
	 * A pipeline without a register and with a posting that must not be reached.
	 *
	 * @return ImportPipelineService
	 */
	private function pipeline(): ImportPipelineService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		$container->method('get')->willThrowException(new RuntimeException('not available in unit context'));
		$this->posting = $this->createMock(ImportPosting::class);

		$logger = $this->createMock(LoggerInterface::class);

		return new ImportPipelineService($container, $logger, new AuditfileParser(), new ImportBatchGuard(), $this->posting);

	}//end pipeline()

	/**
	 * A batch with the given opening balances and relations, all accounts mapped.
	 *
	 * @param list<array<string,mixed>> $balances  The opening balances.
	 * @param list<array<string,mixed>> $relations The relations.
	 *
	 * @return array<string,mixed>
	 */
	private static function batch(array $balances, array $relations = []): array {
		return [
			'administrationId' => 'adm-x',
			'migrationDate'    => '2026-01-01',
			'status'           => 'dry_run_completed',
			'mappings'         => [
				['sourceCode' => '1300', 'targetAccount' => '1300', 'confirmed' => true],
				['sourceCode' => '1600', 'targetAccount' => '1600', 'confirmed' => true],
			],
			'stagingPayload'   => ['openingBalances' => $balances, 'relations' => $relations, 'arOpenItems' => [], 'apOpenItems' => []],
		];

	}//end batch()

	/**
	 * The dry run warns once per later relation that repeats an earlier key,
	 * naming the first key that matched, and marks a relation without kvk,
	 * vat or email as created without a key.
	 *
	 * @return void
	 */
	public function testTheDryRunWarnsOfDuplicatesAndKeylessContacts(): void {
		$batch = self::batch(
			[['accountCode' => '1300', 'debit' => 100.0, 'credit' => 0.0], ['accountCode' => '1600', 'debit' => 0.0, 'credit' => 100.0]],
			[
				['name' => 'Alpha BV', 'kvk' => '12345678', 'email' => 'a@example.org'],
				['name' => 'Alpha Holding', 'kvk' => ' 12345678 ', 'email' => 'other@example.org'],
				['name' => 'Beta', 'email' => 'A@example.org'],
				['name' => 'Gamma'],
				['name' => 'gamma '],
			]
		);

		$report = $this->pipeline()->dryRun($batch);

		$warnings = $report['warnings'];
		$this->assertSame(['possible-duplicate-relation', 'possible-duplicate-relation', 'possible-duplicate-relation'], array_column($warnings, 'code'));
		$this->assertSame('warning', $warnings[0]['severity']);
		$this->assertSame(['matchKey' => 'kvk', 'value' => '12345678', 'name' => 'Alpha Holding'], $warnings[0]['context']);
		$this->assertSame(['matchKey' => 'email', 'value' => 'a@example.org', 'name' => 'Beta'], $warnings[1]['context']);
		$this->assertSame(['matchKey' => 'name', 'value' => 'gamma', 'name' => 'gamma '], $warnings[2]['context']);

		$outcomes = array_column($report['contacts'], 'wouldOutcome');
		$this->assertSame(['create-or-link', 'create-or-link', 'create-or-link', 'create-no-key', 'create-no-key'], $outcomes);
		$this->assertSame(['kvk', 'kvk', 'email', '', ''], array_column($report['contacts'], 'dedupeKey'));

	}//end testTheDryRunWarnsOfDuplicatesAndKeylessContacts()

	/**
	 * A batch whose opening balance does not balance is refused at post even
	 * when its dry run still matches, and nothing reaches the posting.
	 *
	 * @return void
	 */
	public function testAnUnbalancedOpeningBalanceIsRefusedAtPost(): void {
		$pipeline = $this->pipeline();
		$batch    = self::batch(
			[['accountCode' => '1300', 'debit' => 100.0, 'credit' => 0.0], ['accountCode' => '1600', 'debit' => 0.0, 'credit' => 99.5]]
		);
		$batch['dryRunReport'] = $pipeline->dryRun($batch);
		$this->posting->expects($this->never())->method('post');

		$result = $pipeline->post($batch);

		$this->assertSame('posting_failed', $result['status']);
		$this->assertNull($result['postingRefs']);
		$this->assertFalse($result['idempotent']);
		$this->assertSame('opening-journal-unbalanced', $result['findings'][0]['code']);
		$this->assertSame('error', $result['findings'][0]['severity']);
		$this->assertSame(['difference' => 0.5], $result['findings'][0]['context']);

	}//end testAnUnbalancedOpeningBalanceIsRefusedAtPost()

	/**
	 * An open item whose due date cannot be read stays issued, as one without a due date does.
	 *
	 * @return void
	 */
	public function testAnUnreadableDueDateLeavesTheItemIssued(): void {
		$pipeline = $this->pipeline();

		$this->assertSame('issued', $pipeline->openItemStateForDueDate('not a date'));
		$this->assertSame('issued', $pipeline->openItemStateForDueDate(''));

	}//end testAnUnreadableDueDateLeavesTheItemIssued()
}//end class
