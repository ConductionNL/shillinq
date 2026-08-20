<?php

/**
 * Purchase Order Mailer Port (PDF + email fallback)
 *
 * Narrow port for the PDF + email fallback transmission path (REQ-PO3W-002).
 * When a supplier is not registered in the Peppol directory, the
 * PurchaseOrderService delegates to this port so the PO still reaches the
 * supplier and `peppolFallbackReason` can be recorded. Mirrors the
 * Peppol adapter port so production deployments can swap the log default
 * for a real SMTP / openconnector-mailer binding without touching the
 * orchestration code.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PurchaseOrder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

/**
 * PDF + email fallback transmission port.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 */
interface PurchaseOrderMailerInterface {
	/**
	 * Send a PO to a supplier as PDF + email.
	 *
	 * Implementations are expected to render the PO into a PDF representation
	 * (or attach a UBL document as a fallback) and dispatch it to the supplier
	 * contact resolved server-side. The method MUST throw on failure — the
	 * orchestration layer treats a successful return as proof of dispatch and
	 * records the lifecycle transition.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $purchaseOrder The persisted PurchaseOrder record.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the mailer cannot dispatch.
	 */
	public function sendPurchaseOrderEmail(string $administrationId, array $purchaseOrder): void;
}//end interface
