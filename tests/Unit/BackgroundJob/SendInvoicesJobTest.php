<?php

/**
 * Unit tests for SendInvoicesJob.
 *
 * REQ-SIS-003: the queued job runs the batch named in its argument; a bad
 * argument or a failing batch is logged, never thrown into the job runner.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\SendInvoicesJob;
use OCA\Shillinq\Service\InvoiceBatchSendService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The job hands its batch id to the batch send.
 */
class SendInvoicesJobTest extends TestCase {

	/**
	 * Run the job's protected run() with an argument.
	 *
	 * @param SendInvoicesJob $job      The job.
	 * @param mixed           $argument The argument.
	 *
	 * @return void
	 */
	private static function runJob(SendInvoicesJob $job, mixed $argument): void {
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, $argument);
	}//end runJob()

	/**
	 * The batch id of the argument is run.
	 *
	 * @return void
	 */
	public function testTheJobRunsItsBatch(): void {
		$batches = $this->createMock(InvoiceBatchSendService::class);
		$batches->expects($this->once())->method('run')->with('batch-1')->willReturn(['id' => 'batch-1', 'state' => 'done']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		self::runJob(new SendInvoicesJob($this->createMock(ITimeFactory::class), $batches, $logger), ['batchId' => ' batch-1 ']);
	}//end testTheJobRunsItsBatch()

	/**
	 * No batch id runs nothing; a missing batch and a failing one are logged.
	 *
	 * @return void
	 */
	public function testABadArgumentOrAFailingBatchIsLogged(): void {
		$batches = $this->createMock(InvoiceBatchSendService::class);
		$batches->expects($this->exactly(2))->method('run')->willReturnOnConsecutiveCalls([], $this->throwException(new \RuntimeException('down')));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(3))->method('warning');
		$logger->expects($this->once())->method('error');
		$job = new SendInvoicesJob($this->createMock(ITimeFactory::class), $batches, $logger);

		self::runJob($job, 'batch-1');
		self::runJob($job, ['batchId' => '']);
		self::runJob($job, ['batchId' => 'batch-gone']);
		self::runJob($job, ['batchId' => 'batch-2']);
	}//end testABadArgumentOrAFailingBatchIsLogged()
}//end class
