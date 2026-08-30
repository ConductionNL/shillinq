<?php

/**
 * Peppol Access Point Transmission Port (generalised, document-type-agnostic)
 *
 * Generalises the PO-only {@see \OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface}
 * into a shared port usable by both Order (PO) and Invoice (AR e-invoicing,
 * REQ-EINV-004) documents. `lookupParticipant()` resolves a party's Peppol
 * participant identity from master data (Vendor for suppliers, CustomerMaster
 * for debtors); `submit()` transmits an already-materialised, already-stored
 * document (referenced by its `payloadFileUri` — never inline XML on this
 * port, unlike the PO-only interface's `submitOrder()` which still carries
 * the raw XML for backward compatibility).
 *
 * {@see \OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface}
 * extends this port as a thin alias so the existing 3-way-match transmission
 * path continues to compile and behave identically (design.md Risk 2 — pure
 * refactor, no behavioural regression).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Peppol
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

namespace OCA\Shillinq\Service\Peppol;

/**
 * Document-type-agnostic Peppol transmission port shared by PO and AR.
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
interface PeppolTransmissionPortInterface {
	/**
	 * Look up the Peppol participant identifier for a party (supplier or debtor).
	 *
	 * Returns the participant id (`scheme:identifier`, e.g. `0192:1234567890`)
	 * when the party is registered with a known Peppol identity, or `null`
	 * otherwise. A `null` response is the signal for the orchestration layer
	 * to fall back to PDF + email transmission.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $partyId The party identifier to look up (PurchaseOrder.supplierId
	 *                        or ARInvoice.customerId).
	 *
	 * @return string|null The Peppol participant id, or null when not registered.
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function lookupParticipant(string $administrationId, string $partyId): ?string;

	/**
	 * Submit an already-stored document to the Peppol Access Point.
	 *
	 * On success the adapter returns the Peppol message id (URN form,
	 * `urn:uuid:...`) so the orchestration layer can persist it. On failure it
	 * MUST throw — the orchestration layer maps the throw into a fallback
	 * reason (mirrors the PO 3-way-match `peppol_send_failed` contract).
	 *
	 * @param string $participantId Peppol participant id resolved earlier (`scheme:identifier`).
	 * @param string $documentType Peppol document-type identifier (e.g. `ubl-invoice-2.1`,
	 *                             `ubl-order-2.1`).
	 * @param string $payloadFileUri Docudesk/Files FK URI of the stored document (UBL XML or
	 *                               hybrid PDF artefact) to transmit. Never inline XML.
	 *
	 * @return string The Peppol message id (URN).
	 *
	 * @throws \RuntimeException When the access point refuses or fails.
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function submit(string $participantId, string $documentType, string $payloadFileUri): string;
}//end interface
