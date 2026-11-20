<?php

/**
 * One inbound document integriq reported, as SelfBilledInvoiceIntake works it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\EInvoice;

/**
 * Who sent it, where the received UBL is, and when it was taken in.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.2
 */
final class InboundDocument {
	/**
	 * Constructor.
	 *
	 * @param string $senderPeppolId   Peppol participant id of the sender.
	 * @param string $payloadReference Files path of the received UBL document.
	 * @param string $receivedAt       When it was taken in (ISO 8601).
	 */
	public function __construct(
		public readonly string $senderPeppolId,
		public readonly string $payloadReference,
		public readonly string $receivedAt,
	) {
	}//end __construct()
}//end class
