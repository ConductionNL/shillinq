<?php

/**
 * Record store log
 *
 * Writes the record store's errors under the name of the service that owns
 * the store, so the log lines keep the "<Service>: <event>" form the services
 * wrote inline.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;

/**
 * A logger that prefixes every message with its source.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class RecordStoreLog {
	/**
	 * Log on behalf of a named source.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param string          $source The owning service name, e.g. GoodsReceiptNoteService.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $source,
	) {
	}//end __construct()

	/**
	 * Log an error as "<source>: <event>".
	 *
	 * @param string              $event   The event description.
	 * @param array<string,mixed> $context The log context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function error(string $event, array $context): void {
		$this->logger->error($this->source . ': ' . $event, $context);
	}//end error()
}//end class
