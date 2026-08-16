<?php

/**
 * Log-backed dunning channel adapter.
 *
 * Default production binding: writes the dispatch attempt to the logger and
 * reports the result as DELIVERED with a synthetic provider message id. The
 * real HTTP adapters (PostNL Track & Trace, incasso-bureau API, SMTP) bind
 * to this interface and replace the log adapter via the DI container
 * (see lib/AppInfo/Application.php).
 *
 * Until the openconnector outbound mappings are configured, this stub keeps
 * the lifecycle moving and the audit trail intact (logs are forwarded to the
 * Nextcloud audit-log infrastructure already used by other Shillinq services).
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
	 * Synthesise a DELIVERED send result + log the dispatch attempt.
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
		unset($sanitised['renderedPdfBytes']);

		$this->logger->info(
			'Shillinq dunning channel dispatch',
			[
				'channel' => $channel,
				'payload' => $sanitised,
			]
		);

		$messageId = 'dunning-log-' . bin2hex(random_bytes(8));
		$extras = [];
		if ($channel === 'REGISTERED_POST') {
			// Synthetic PostNL Track & Trace barcode (3S + 13 digits) for evidence-trail.
			$extras['barcode'] = '3S' . str_pad((string)random_int(1, 9999999999999), 13, '0', STR_PAD_LEFT);
			$extras['trackingUrl'] = 'https://postnl.nl/tracktrace/' . $extras['barcode'];
		}

		if ($channel === 'COLLECTION_AGENCY_API') {
			$extras['dossierId'] = 'dossier-stub-' . bin2hex(random_bytes(6));
		}

		return new DunningChannelSendResult(
			channel: $channel,
			deliveryStatus: 'DELIVERED',
			providerMessageId: $messageId,
			extras: $extras,
		);

	}//end send()
}//end class
