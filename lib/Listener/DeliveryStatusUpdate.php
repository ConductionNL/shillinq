<?php

/**
 * One delivery-status update for an ARInvoice, read from the CloudEvent data.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

/**
 * The invoice's objectUri, the new delivery status, its detail and the
 * transmission id, each already trimmed where the listener trims it.
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
final class DeliveryStatusUpdate {

	/**
	 * Construct the update.
	 *
	 * @param string $objectUri The invoice's objectUri.
	 * @param string $status The new delivery status.
	 * @param string $detail The status detail.
	 * @param string $transmissionId The Peppol transmission id, or ''.
	 */
	public function __construct(
		public readonly string $objectUri,
		public readonly string $status,
		public readonly string $detail,
		public readonly string $transmissionId,
	) {

	}//end __construct()
}//end class
