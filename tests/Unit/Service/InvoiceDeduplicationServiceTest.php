<?php

/**
 * Unit tests for InvoiceDeduplicationService.
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
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies double-invoice detection for time-entry + expense source ids
 * across BillableInvoice rows (Task 13 / #111 / design D9).
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 */
final class InvoiceDeduplicationServiceTest extends TestCase {

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default: appConfig returns "shillinq" register.
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the subject.
	 *
	 * @return InvoiceDeduplicationService
	 */
	private function svc(): InvoiceDeduplicationService {
		return new InvoiceDeduplicationService($this->container, $this->appConfig, $this->logger);
	}//end svc()

	/**
	 * Wire the OR ObjectService mock to return the given invoice rows.
	 *
	 * Uses a simple stand-in object that mimics the fluent
	 * setRegister()→setSchema()→findAll() chain.
	 *
	 * @param array<int,array<string,mixed>> $rows Pre-canned rows.
	 *
	 * @return void
	 */
	private function arrangeFindAll(array $rows): void {
		$svc = new class($rows) {
			/** @param array<int,array<string,mixed>> $rows */
			public function __construct(
				private array $rows,
			) {
			}
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			/** @return array<int,array<string,mixed>> */
			public function findAll(array $config = []): array {
				return $this->rows;
			}
		};

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($svc);

	}//end arrangeFindAll()

	/**
	 * Empty input → no conflicts and the ObjectService is never even queried.
	 *
	 * @return void
	 */
	public function testEmptyInputReturnsNoConflictsWithoutQuerying(): void {
		// Container::get must NOT be called when both lists are empty.
		$this->container->expects(self::never())->method('get');

		$result = $this->svc()->deduplicateSourceIds('admin-1', [], []);

		self::assertFalse($result['hasConflicts']);
		self::assertSame([], $result['conflicts']);

	}//end testEmptyInputReturnsNoConflictsWithoutQuerying()

	/**
	 * No existing invoices → no conflicts.
	 *
	 * @return void
	 */
	public function testNoExistingInvoicesYieldsNoConflicts(): void {
		$this->arrangeFindAll([]);

		$result = $this->svc()->deduplicateSourceIds('admin-1', ['t1'], ['e1']);

		self::assertFalse($result['hasConflicts']);
		self::assertSame([], $result['conflicts']);

	}//end testNoExistingInvoicesYieldsNoConflicts()

	/**
	 * Overlapping time-entry id on a draft invoice → reported.
	 *
	 * @return void
	 */
	public function testTimeEntryOverlapWithDraftInvoiceIsReported(): void {
		$this->arrangeFindAll([
			[
				'id' => 'inv-1',
				'invoiceNumber' => 'INV-2026-001',
				'status' => 'draft',
				'timeEntryIds' => ['t1', 't2'],
				'expenseIds' => [],
			],
		]);

		$result = $this->svc()->deduplicateSourceIds('admin-1', ['t2', 't3'], []);

		self::assertTrue($result['hasConflicts']);
		self::assertCount(1, $result['conflicts']);
		self::assertSame('inv-1', $result['conflicts'][0]['invoiceId']);
		self::assertSame('INV-2026-001', $result['conflicts'][0]['invoiceNumber']);
		self::assertSame('draft', $result['conflicts'][0]['status']);
		self::assertSame(['t2'], $result['conflicts'][0]['timeEntryIds']);
		self::assertSame([], $result['conflicts'][0]['expenseIds']);

	}//end testTimeEntryOverlapWithDraftInvoiceIsReported()

	/**
	 * Overlap with a `posted` invoice is also flagged.
	 *
	 * @return void
	 */
	public function testExpenseOverlapWithPostedInvoiceIsReported(): void {
		$this->arrangeFindAll([
			[
				'id' => 'inv-2',
				'invoiceNumber' => 'INV-2026-002',
				'status' => 'posted',
				'timeEntryIds' => [],
				'expenseIds' => ['e9'],
			],
		]);

		$result = $this->svc()->deduplicateSourceIds('admin-1', [], ['e9', 'e10']);

		self::assertTrue($result['hasConflicts']);
		self::assertSame(['e9'], $result['conflicts'][0]['expenseIds']);
		self::assertSame('posted', $result['conflicts'][0]['status']);

	}//end testExpenseOverlapWithPostedInvoiceIsReported()

	/**
	 * Cancelled / paid / void invoices are NOT considered conflicting.
	 *
	 * Only draft + posted block.
	 *
	 * @return void
	 */
	public function testCancelledAndPaidInvoicesAreIgnored(): void {
		$this->arrangeFindAll([
			[
				'id' => 'inv-3',
				'status' => 'cancelled',
				'timeEntryIds' => ['t1'],
				'expenseIds' => [],
			],
			[
				'id' => 'inv-4',
				'status' => 'paid',
				'timeEntryIds' => ['t1'],
				'expenseIds' => [],
			],
			[
				'id' => 'inv-5',
				'status' => 'void',
				'timeEntryIds' => ['t1'],
				'expenseIds' => [],
			],
		]);

		$result = $this->svc()->deduplicateSourceIds('admin-1', ['t1'], []);

		self::assertFalse($result['hasConflicts']);
		self::assertSame([], $result['conflicts']);

	}//end testCancelledAndPaidInvoicesAreIgnored()

	/**
	 * `excludeInvoiceId` skips that invoice's own ids (re-validating a draft).
	 *
	 * @return void
	 */
	public function testExcludeInvoiceIdSkipsSelfOverlap(): void {
		$this->arrangeFindAll([
			[
				'id' => 'inv-draft-self',
				'status' => 'draft',
				'timeEntryIds' => ['t1'],
				'expenseIds' => [],
			],
			[
				'id' => 'inv-other',
				'status' => 'draft',
				'timeEntryIds' => ['t1'],
				'expenseIds' => [],
			],
		]);

		$result = $this->svc()->deduplicateSourceIds('admin-1', ['t1'], [], 'inv-draft-self');

		self::assertTrue($result['hasConflicts']);
		self::assertCount(1, $result['conflicts']);
		self::assertSame('inv-other', $result['conflicts'][0]['invoiceId']);

	}//end testExcludeInvoiceIdSkipsSelfOverlap()

	/**
	 * Invoice without an `id` (and without an @self.id) is skipped.
	 *
	 * @return void
	 */
	public function testInvoiceWithoutIdIsSkipped(): void {
		$this->arrangeFindAll([
			[
				// no id, no @self.id
				'status' => 'draft',
				'timeEntryIds' => ['t1'],
				'expenseIds' => [],
			],
		]);

		$result = $this->svc()->deduplicateSourceIds('admin-1', ['t1'], []);

		self::assertFalse($result['hasConflicts']);

	}//end testInvoiceWithoutIdIsSkipped()

	/**
	 * `@self.id` is honoured when top-level `id` is absent.
	 *
	 * @return void
	 */
	public function testAtSelfIdIsUsedAsFallbackInvoiceId(): void {
		$this->arrangeFindAll([
			[
				'@self' => ['id' => 'self-id-7'],
				'status' => 'draft',
				'timeEntryIds' => ['t1'],
				'expenseIds' => [],
			],
		]);

		$result = $this->svc()->deduplicateSourceIds('admin-1', ['t1'], []);

		self::assertTrue($result['hasConflicts']);
		self::assertSame('self-id-7', $result['conflicts'][0]['invoiceId']);

	}//end testAtSelfIdIsUsedAsFallbackInvoiceId()

	/**
	 * ObjectService throwing → logged, deduped to "no conflicts" (fail open
	 * for read; the live writer enforces conflicts on commit).
	 *
	 * @return void
	 */
	public function testObjectServiceFailureLogsAndReturnsNoConflicts(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OR down'));

		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains('InvoiceDeduplicationService findAll failed'));

		$result = $this->svc()->deduplicateSourceIds('admin-1', ['t1'], []);

		self::assertFalse($result['hasConflicts']);

	}//end testObjectServiceFailureLogsAndReturnsNoConflicts()

	/**
	 * Multiple overlapping invoices each yield one conflict row.
	 *
	 * @return void
	 */
	public function testMultipleConflictingInvoicesAreAllReported(): void {
		$this->arrangeFindAll([
			[
				'id' => 'inv-a',
				'status' => 'draft',
				'timeEntryIds' => ['t1'],
				'expenseIds' => [],
			],
			[
				'id' => 'inv-b',
				'status' => 'posted',
				'timeEntryIds' => [],
				'expenseIds' => ['e5'],
			],
		]);

		$result = $this->svc()->deduplicateSourceIds('admin-1', ['t1'], ['e5']);

		self::assertTrue($result['hasConflicts']);
		self::assertCount(2, $result['conflicts']);

	}//end testMultipleConflictingInvoicesAreAllReported()

}//end class
