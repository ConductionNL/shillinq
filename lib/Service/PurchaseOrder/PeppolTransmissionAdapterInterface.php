<?php

/**
 * Peppol Access Point Adapter Port
 *
 * Narrow port the PurchaseOrderService uses to transmit an approved PO as a UBL
 * 2.1 Order via the openconnector Peppol Access Point. The port is intentionally
 * minimal so the production binding (HTTP-backed against
 * /openconnector/api/peppol/...) can be swapped for the
 * {@see LogPeppolTransmissionAdapter} stub used in dev / CI without touching the
 * orchestration code (mirrors the OpenconnectorAdapterInterface pattern used by
 * BookingNotificationService).
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
 * Channel-adapter port for Peppol BIS Ordering 3.0 transmission.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 */
interface PeppolTransmissionAdapterInterface
{


    /**
     * Look up the Peppol participant identifier for a supplier.
     *
     * Returns the participant id (`scheme:identifier`, e.g. `0192:1234567890`)
     * when the supplier is registered in the Peppol directory, or `null`
     * otherwise. A `null` response is the signal for the orchestration layer
     * to fall back to PDF + email transmission (REQ-PO3W-002).
     *
     * @param string $administrationId Administration scope (server-resolved).
     * @param string $supplierId       The PurchaseOrder.supplierId to look up.
     *
     * @return string|null The Peppol participant id, or null when not registered.
     */
    public function lookupParticipant(string $administrationId, string $supplierId): ?string;


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
     * @param string $ublOrderXml   The UBL 2.1 Order document XML.
     *
     * @return string The Peppol message id (URN).
     *
     * @throws \RuntimeException When the access point refuses or fails.
     */
    public function submitOrder(string $participantId, string $ublOrderXml): string;


}//end interface
