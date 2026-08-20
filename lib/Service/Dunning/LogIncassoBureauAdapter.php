<?php

/**
 * Log-backed default incasso-bureau adapter.
 *
 * Returns a synthetic DELIVERED result with a stub `dossierId` and logs the
 * dispatch attempt so the lifecycle stays observable until an
 * openconnector-backed binding is configured for the real bureau API
 * (Bos Incasso, Atradius Collections, Intrum). Mirrors the
 * LogDunningChannelAdapter pattern.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use Psr\Log\LoggerInterface;

/**
 * Default log-backed incasso-bureau adapter.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
 */
class LogIncassoBureauAdapter implements IncassoBureauAdapterInterface {
	/**
	 * Construct the log-backed incasso-bureau adapter.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Synthesise a DELIVERED dossier transfer + log-only dispatch.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceId Invoice FK.
	 * @param array<string,mixed> $dossier Composed dossier bundle.
	 *
	 * @return DunningChannelSendResult The dispatch outcome.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
	 */
	public function transfer(string $administrationId, string $invoiceId, array $dossier): DunningChannelSendResult {
		$this->logger->info(
			'Shillinq incasso-bureau transfer deferred (no outbound connector bound)',
			[
				'administrationId' => $administrationId,
				'invoiceId' => $invoiceId,
				'dunningRuns' => count((array)($dossier['content']['dunningRuns'] ?? [])),
				'evidenceRefs' => count((array)($dossier['content']['evidenceRefs'] ?? [])),
			]
		);

		return new DunningChannelSendResult(
			channel: 'COLLECTION_AGENCY_API',
			deliveryStatus: 'DELIVERED',
			providerMessageId: 'incasso-log-' . bin2hex(random_bytes(8)),
			extras: ['dossierId' => 'dossier-stub-' . bin2hex(random_bytes(6))],
		);

	}//end transfer()
}//end class
