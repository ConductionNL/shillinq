<?php

/**
 * Log-backed default PostNL adapter.
 *
 * Returns a synthetic DELIVERED result with a freshly-minted 3S-prefixed
 * Track & Trace barcode + tracking URL so the lifecycle stays observable
 * until an openconnector-backed binding is configured for the real PostNL
 * API. Mirrors the LogDunningChannelAdapter pattern.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-21
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use Psr\Log\LoggerInterface;

/**
 * Default log-backed PostNL adapter.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-21
 */
class LogPostNLAdapter implements PostNLAdapterInterface {
	/**
	 * Construct the log-backed PostNL adapter.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Synthesise a DELIVERED send + log-only dispatch.
	 *
	 * @param array<string,mixed> $payload Letter payload — recipientAdres + letterPdfRef.
	 *
	 * @return DunningChannelSendResult The dispatch outcome.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-21
	 */
	public function sendRegisteredLetter(array $payload): DunningChannelSendResult {
		$barcode = '3S' . str_pad((string)random_int(1, 9999999999999), 13, '0', STR_PAD_LEFT);

		$sanitised = $payload;
		unset($sanitised['letterPdfBytes']);
		$this->logger->info(
			'Shillinq PostNL registered-letter send deferred (no outbound connector bound)',
			[
				'barcode' => $barcode,
				'payload' => $sanitised,
			]
		);

		return new DunningChannelSendResult(
			channel: 'REGISTERED_POST',
			deliveryStatus: 'DELIVERED',
			providerMessageId: 'postnl-log-' . bin2hex(random_bytes(8)),
			extras: [
				'barcode' => $barcode,
				'trackingUrl' => 'https://postnl.nl/tracktrace/' . $barcode,
			],
		);

	}//end sendRegisteredLetter()
}//end class
