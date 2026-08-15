<?php

/**
 * Dunning channel send result.
 *
 * Outcome of one DunningChannelAdapterInterface::send() call. Used by the
 * DunningRunService to populate DunningRun.deliveryStatus + postageStatus +
 * openTracking on the executed run record.
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

/**
 * Immutable result value object.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 */
final class DunningChannelSendResult {
	/**
	 * Build an immutable result value object.
	 *
	 * @param string $channel One of the four kanaal enum values.
	 * @param string $deliveryStatus DELIVERED / BOUNCED / FAILED / PENDING.
	 * @param string|null $providerMessageId Channel/provider message id, if any.
	 * @param array<string,mixed> $extras Channel-specific extras (barcode, trackingUrl, dossierId, etc.).
	 * @param string|null $errorMessage Error detail when deliveryStatus is BOUNCED / FAILED.
	 */
	public function __construct(
		public readonly string $channel,
		public readonly string $deliveryStatus,
		public readonly ?string $providerMessageId = null,
		public readonly array $extras = [],
		public readonly ?string $errorMessage = null,
	) {
	}//end __construct()

	/**
	 * Convenience for callers building a DunningRun postageStatus object.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-21
	 */
	public function postageStatus(): ?array {
		$barcode = (string)($this->extras['barcode'] ?? '');
		$trackingUrl = (string)($this->extras['trackingUrl'] ?? '');
		if ($barcode === '' && $trackingUrl === '') {
			return null;
		}

		$status = [];
		if ($barcode !== '') {
			$status['barcode'] = $barcode;
		}

		if ($trackingUrl !== '') {
			$status['trackingUrl'] = $trackingUrl;
		}

		if (isset($this->extras['deliveredAt']) === true) {
			$status['deliveredAt'] = (string)$this->extras['deliveredAt'];
		}

		return $status;
	}//end postageStatus()
}//end class
