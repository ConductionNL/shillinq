<?php

/**
 * Dunning stage dispatcher.
 *
 * Sends one dunning stage through the bound DunningChannelAdapterInterface and
 * stamps the adapter's outcome on the DunningRun before it is saved. Before
 * this class existed nothing called the adapter: DunningRunService::executeStage()
 * saved every run as PENDING and no customer was contacted (issue #1687).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches a DunningRun through the channel adapter and records the truth.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.3
 */
class DunningStageDispatcher {
	/**
	 * Construct the dispatcher.
	 *
	 * @param DunningChannelAdapterInterface $adapter The bound channel adapter (Application.php).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly DunningChannelAdapterInterface $adapter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send a run through the adapter and return it with the adapter's outcome.
	 *
	 * The payload follows DunningChannelAdapterInterface::send(): for EMAIL the
	 * subject, body and recipientEmail, plus the run context an adapter needs to
	 * address or file the dispatch. The returned run carries the adapter's
	 * deliveryStatus, whatever the caller put there, and its postage evidence
	 * when it has any. An adapter that throws gives a FAILED run, never one that
	 * reads as sent.
	 *
	 * @param array<string,mixed> $record  The DunningRun about to be saved.
	 * @param array<string,mixed> $invoice The invoice the mail attaches; not part of the run.
	 *
	 * @return array<string,mixed> The run with deliveryStatus, deliveryNote (and postageStatus) from the adapter.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.3
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.2
	 */
	public function dispatch(array $record, array $invoice = []): array {
		$channel = (string)($record['channel'] ?? 'EMAIL');

		try {
			$result = $this->adapter->send(
				channel: $channel,
				payload: [
					'administrationId' => ($record['administrationId'] ?? null),
					'invoiceId' => ($record['invoiceId'] ?? null),
					'ladderId' => ($record['ladderId'] ?? null),
					'stageNr' => ($record['stageNr'] ?? null),
					'templateId' => ($record['templateId'] ?? null),
					'recipientEmail' => ($record['recipientEmail'] ?? null),
					'recipientName' => ($record['recipientName'] ?? null),
					'recipientAddress' => ($record['recipientAddress'] ?? null),
					'subject' => ($record['renderedSubject'] ?? null),
					'body' => ($record['renderedBody'] ?? null),
					'renderedPdfHash' => ($record['renderedPdfHash'] ?? null),
					'invoice' => $invoice,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq: dunning stage dispatch failed, the run is recorded as FAILED',
				['invoiceId' => ($record['invoiceId'] ?? null), 'channel' => $channel, 'exception' => $e->getMessage()]
			);
			$result = new DunningChannelSendResult(channel: $channel, deliveryStatus: 'FAILED', errorMessage: $e->getMessage());
		}

		$record['deliveryStatus'] = $result->deliveryStatus;
		// Why a stage failed or waits for a person (REQ-RAD-003, REQ-RAD-006).
		$record['deliveryNote'] = $result->errorMessage;
		$postage = $result->postageStatus();
		if ($postage !== null) {
			$record['postageStatus'] = array_merge((array)($record['postageStatus'] ?? []), $postage);
		}

		return $record;
	}//end dispatch()
}//end class
