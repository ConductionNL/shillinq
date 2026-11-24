<?php

/**
 * Shillinq Send Invoices Job
 *
 * Runs one queued send batch (REQ-SIS-003, design D4): 200 PDFs and mails do
 * not fit a web request, so POST /api/ar-invoices/send-batch records the
 * InvoiceBatch and queues this job, which sends every invoice of it through
 * InvoiceBatchSendService::run(). One argument set is one batch.
 *
 * @category Cron
 * @package  OCA\Shillinq\BackgroundJob
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

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\Service\InvoiceBatchSendService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the invoices of one queued batch.
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.1
 */
class SendInvoicesJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory            $time    The job runner's clock.
	 * @param InvoiceBatchSendService $batches Sends the batch.
	 * @param LoggerInterface         $logger  A batch that could not run.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly InvoiceBatchSendService $batches,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Send the batch named in the argument.
	 *
	 * @param mixed $argument `['batchId' => <InvoiceBatch id>]`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.1
	 */
	protected function run($argument): void {
		$batchId = '';
		if (is_array($argument) === true) {
			$batchId = trim((string)($argument['batchId'] ?? ''));
		}

		if ($batchId === '') {
			$this->logger->warning('SendInvoicesJob: no batch id, skipping');
			return;
		}

		try {
			if ($this->batches->run(batchId: $batchId) === []) {
				$this->logger->warning('SendInvoicesJob: batch not found', ['batchId' => $batchId]);
			}
		} catch (Throwable $e) {
			$this->logger->error('SendInvoicesJob: batch did not run', ['batchId' => $batchId, 'exception' => $e->getMessage()]);
		}
	}//end run()
}//end class
