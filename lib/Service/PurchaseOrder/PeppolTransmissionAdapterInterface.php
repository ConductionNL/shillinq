<?php

/**
 * Peppol Access Point Adapter Port
 *
 * Narrow port the PurchaseOrderService uses to transmit an approved PO as a UBL
 * 2.1 Order via the openconnector Peppol Access Point. The port is intentionally
 * minimal so the production binding (HTTP-backed against
 * /openconnector/api/peppol/...) can be swapped for a
 * {@see \OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter} stub used in
 * dev / CI without touching the orchestration code (mirrors the
 * OpenconnectorAdapterInterface pattern used by BookingNotificationService).
 *
 * REQ-EINV-004 (add-invoice-pdf-export-with-ubl-peppol-support) generalised the
 * transmission port into a shared, document-type-agnostic
 * {@see \OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface} so both PO
 * (Order) and AR (Invoice, e-invoicing) documents transmit through the same
 * `lookupParticipant()` + `submit()` contract. This interface is now retained
 * as a THIN ALIAS/EXTENSION of that shared port (pure refactor, no
 * behavioural regression — design.md Risk 2): it adds only the PO-specific
 * `submitOrder()` method, which still accepts the raw UBL Order XML directly
 * (unlike the shared port's `submit()`, which takes a stored document's
 * `payloadFileUri`) so the existing PurchaseOrderService::sendToPeppol() call
 * site is untouched.
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
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;

/**
 * Channel-adapter port for Peppol BIS Ordering 3.0 transmission — a thin
 * alias/extension of the shared {@see PeppolTransmissionPortInterface}.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
interface PeppolTransmissionAdapterInterface extends PeppolTransmissionPortInterface {
	/**
	 * Submit a UBL 2.1 Order to the Peppol Access Point.
	 *
	 * On success the adapter returns the Peppol message id (URN form,
	 * `urn:uuid:...`) so the orchestration layer can persist it on the
	 * PurchaseOrder record. On failure it MUST throw — the orchestration
	 * layer maps the throw into a `peppol_send_failed` fallback reason.
	 *
	 * @param string $participantId Peppol participant id resolved earlier
	 *                              (`scheme:identifier`).
	 * @param string $ublOrderXml The UBL 2.1 Order document XML.
	 *
	 * @return string The Peppol message id (URN).
	 *
	 * @throws \RuntimeException When the access point refuses or fails.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
	 */
	public function submitOrder(string $participantId, string $ublOrderXml): string;
}//end interface
