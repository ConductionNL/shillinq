<?php

/**
 * Dormant default Bunq Bank Connector adapter.
 *
 * Records the would-be Bunq pull / SCA-renewal intent to the
 * structured logger and returns a synthetic SYNC_DEFERRED result so
 * the surrounding lifecycle (Bank Connector polling
 * ScheduledWorkflow + consent-renewal action) stays observable
 * until an openconnector-backed binding to Bunq is wired in via
 * `Application::register()`. Mirrors the `LogCbsBestandenAdapter` /
 * `LogIb47Adapter` dormant-default pattern used across the
 * Shillinq external surface.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Bunq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bank-connectors/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Bunq;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Bunq Bank Connector adapter.
 *
 * @spec openspec/specs/bookkeeping-bank-connectors/spec.md
 */
class LogBunqBankConnectorAdapter implements BunqBankConnectorAdapterInterface {
	/**
	 * Construct the log-backed Bunq adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the pull intent + synthesise a SYNC_DEFERRED result.
	 *
	 * No PII is logged — `connectionReference` is a non-credential
	 * BankConnection id (per `bookkeeping-bank-connectors` D1) and
	 * `context` is operator-supplied.
	 *
	 * @param string $connectionReference Connection id.
	 * @param array<string,mixed> $context Pull context.
	 *
	 * @return BunqSyncResult The dispatch outcome.
	 */
	public function pullTransactions(string $connectionReference, array $context = []): BunqSyncResult {
		$this->logger->info(
			'Shillinq Bunq pullTransactions deferred (no outbound connector bound)',
			[
				'connectionReference' => $connectionReference,
				'context' => $context,
			]
		);

		return new BunqSyncResult(
			syncStatus: 'SYNC_DEFERRED',
			connectionReference: $connectionReference,
			transactionCount: 0,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `bunq-bank-connector` (Bunq API v1, per-tenant '
					. 'API key + installation token + device-server registration) and override '
					. 'BunqBankConnectorAdapterInterface in Application::register() to enable real sync.',
			],
		);
	}//end pullTransactions()

	/**
	 * Log the consent-renewal intent + synthesise a deferred result.
	 *
	 * @param string $connectionReference Connection id.
	 * @param array<string,mixed> $context Renewal context.
	 *
	 * @return BunqSyncResult The dispatch outcome.
	 */
	public function renewConsent(string $connectionReference, array $context = []): BunqSyncResult {
		$this->logger->info(
			'Shillinq Bunq renewConsent deferred (no outbound connector bound)',
			[
				'connectionReference' => $connectionReference,
				'context' => $context,
			]
		);

		return new BunqSyncResult(
			syncStatus: 'SYNC_DEFERRED',
			connectionReference: $connectionReference,
			transactionCount: 0,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector SCA endpoint for Bunq + override '
					. 'BunqBankConnectorAdapterInterface to enable real consent-renewal hand-off.',
				'scaUrl' => '',
			],
		);
	}//end renewConsent()

	/**
	 * Report whether this adapter is dormant.
	 *
	 * @return bool True when no outbound connector is bound.
	 *
	 * @inheritDoc
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
