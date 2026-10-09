<?php

/**
 * Log-backed dunning channel adapter.
 *
 * Default production binding: writes the dispatch attempt to the logger and
 * reports the result as PENDING with a synthetic provider message id. It
 * sends nothing, so it never reports DELIVERED: since executeStage() records
 * the adapter's outcome on the DunningRun (issue #1687), a DELIVERED here
 * would mark reminders as sent that no customer received. The real adapters
 * (mail, PostNL Track & Trace, incasso-bureau API) bind to this interface and
 * replace the log adapter via the DI container (see lib/AppInfo/Application.php).
 *
 * Until a real adapter is bound, this stub keeps the audit trail intact (logs
 * are forwarded to the Nextcloud audit-log infrastructure already used by
 * other Shillinq services).
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use Psr\Log\LoggerInterface;

/**
 * Default log-backed channel adapter.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 */
class LogDunningChannelAdapter implements DunningChannelAdapterInterface {
	/**
	 * Construct the log-backed channel adapter.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the dispatch attempt and report it PENDING: nothing was sent.
	 *
	 * @param string $channel One of EMAIL / EMAIL+POSTREGISTRATIE / AANGETEKENDE_POST / INCASSOBUREAU_API.
	 * @param array<string,mixed> $payload Channel-specific payload.
	 *
	 * @return DunningChannelSendResult The (synthetic) dispatch outcome.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
	 */
	public function send(string $channel, array $payload): DunningChannelSendResult {
		$sanitised = $payload;
		// Redact rendered body in log lines — keep the log focused on metadata.
		unset($sanitised['renderedBody']);
		unset($sanitised['body']);
		unset($sanitised['renderedPdfBytes']);

		$this->logger->info(
			'Shillinq dunning channel dispatch',
			[
				'channel' => $channel,
				'payload' => $sanitised,
			]
		);

		// No extras: a made-up PostNL barcode or dossier id would be stamped on
		// the DunningRun as evidence of a letter or handover that never happened.
		return new DunningChannelSendResult(
			channel: $channel,
			deliveryStatus: 'PENDING',
			providerMessageId: 'dunning-log-' . bin2hex(random_bytes(8)),
		);

	}//end send()
}//end class
