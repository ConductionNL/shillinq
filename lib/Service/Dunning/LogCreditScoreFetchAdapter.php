<?php

/**
 * Log-backed default credit-score fetch adapter.
 *
 * Returns null on every call (no fresh fetch available) and logs the
 * dispatch attempt so the lifecycle is observable until the openconnector
 * outbound mapping for Graydon / Creditsafe / Atradius Insights is configured.
 * Mirrors the LogDunningChannelAdapter shape so the production swap is a
 * single DI-binding change in lib/AppInfo/Application.php.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use Psr\Log\LoggerInterface;

/**
 * Default log-backed fetch adapter.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 */
class LogCreditScoreFetchAdapter implements CreditScoreFetchAdapterInterface {
	/**
	 * Construct the log-backed credit-score fetch adapter.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return null (no live fetch) + log the deferred dispatch.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $customerId Customer FK.
	 * @param string $provider One of GRAYDON / CREDITSAFE / ATRADIUS_INSIGHTS.
	 *
	 * @return array<string,mixed>|null Always null on the log binding (caller falls back to cache).
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
	 */
	public function fetch(string $administrationId, string $customerId, string $provider): ?array {
		$this->logger->info(
			'Shillinq credit-score fetch deferred (no outbound connector bound)',
			[
				'administrationId' => $administrationId,
				'customerId' => $customerId,
				'provider' => $provider,
			]
		);
		return null;
	}//end fetch()
}//end class
