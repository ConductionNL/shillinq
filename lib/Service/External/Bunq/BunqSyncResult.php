<?php

/**
 * Result value-object returned by a Bunq Bank Connector adapter call.
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

/**
 * Result of a Bunq pull-transactions / renew-consent attempt.
 *
 * `syncStatus` is one of `SYNCED`, `SCA_REQUIRED`, `SYNC_DEFERRED`,
 * `SYNC_ERROR`. `SYNCED` means a CAMT.053 document was attached
 * via docudesk and one or more `BankStatement` records were created;
 * `SCA_REQUIRED` means the connection has slipped into `expiring` /
 * `expired` and the operator needs to renew consent before the next
 * pull will succeed; `SYNC_DEFERRED` is the dormant default.
 *
 * @spec openspec/specs/bookkeeping-bank-connectors/spec.md
 */
final class BunqSyncResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $syncStatus SYNCED /
	 *                           SCA_REQUIRED /
	 *                           SYNC_DEFERRED /
	 *                           SYNC_ERROR.
	 * @param string $connectionReference Echoed input.
	 * @param int $transactionCount Number of
	 *                              transactions
	 *                              ingested in the
	 *                              pull (0 for
	 *                              deferred / SCA).
	 * @param bool $dormant TRUE when the
	 *                      adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific
	 *                                    extras —
	 *                                    camt053AttachmentUri,
	 *                                    scaUrl,
	 *                                    consentExpiresAt.
	 */
	public function __construct(
		public readonly string $syncStatus,
		public readonly string $connectionReference,
		public readonly int $transactionCount,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
