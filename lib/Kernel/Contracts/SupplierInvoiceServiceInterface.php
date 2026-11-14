<?php

/**
 * Contract of SupplierInvoiceService
 *
 * The public surface of SupplierInvoiceService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of SupplierInvoiceService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SupplierInvoiceServiceInterface {

	/**
	 * Initial lifecycle state of a newly ingested invoice (REQ-SI-002).
	 *
	 * @var string
	 */
	public const STATUS_RECEIVED = 'received';

	/**
	 * Ingest a Peppol-received UBL Invoice XML (REQ-PO3W-004 ingestion path).
	 *
	 * The caller supplies the UBL Invoice XML (as a string), the resolved
	 * tenant administration id, the originating Peppol message id (used as
	 * the `ublSourceUri` when no explicit URI is supplied) and an optional
	 * `ublSourceUri` + `peppolReceivedAt`. The method:
	 *
	 *  1. validates the administration scope (ADR-005);
	 *  2. parses the UBL via {@see parseUblInvoice()};
	 *  3. de-duplicates against an existing (invoiceNumber, supplierId,
	 *     administrationId) tuple — repeated deliveries on the same Peppol
	 *     message id MUST NOT create duplicate records;
	 *  4. persists a SupplierInvoice record with statusCode = received,
	 *     sourceFormat = ubl and the parsed line items as an embedded
	 *     `lines` array (the lines schema lives downstream in slice 06; at
	 *     this slice the lines travel embedded on the invoice document).
	 *
	 * @param string $administrationId Tenant scope (must be
	 *                                 accessible to the caller).
	 * @param string $ublXml UBL Invoice XML document.
	 * @param array<string,mixed> $context Optional context: ublSourceUri,
	 *                                     peppolReceivedAt (ISO-8601),
	 *                                     peppolMessageId.
	 *
	 * @return array<string,mixed> The persisted SupplierInvoice (id stamped by OR).
	 *
	 * @throws \RuntimeException When the administration is inaccessible, the
	 *                           UBL is malformed, or the document does not
	 *                           carry an InvoiceNumber.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#supplierinvoice
	 */
	public function ingestUBLInvoice(string $administrationId, string $ublXml, array $context = []): array;

	/**
	 * Parse a UBL Invoice XML document into the structured field shape used
	 * by both ingestion paths.
	 *
	 * The parser is deliberately minimal — Peppol BIS Invoice / UBL 2.1 is
	 * a large standard but our matching engine only needs the header
	 * identifiers, dates, monetary totals and the line items. Anything
	 * else (extensions, allowances, delivery party detail) is ignored at
	 * this slice; later slices may project additional fields into
	 * dedicated registers.
	 *
	 * Monetary fields in UBL are decimal amounts in the invoice currency;
	 * we convert each via {@see toCents()} (half-up rounding) so the
	 * SupplierInvoice schema's integer-cent invariant holds.
	 *
	 * @param string $ublXml UBL Invoice XML document.
	 *
	 * @return array<string,mixed> Parsed header + lines.
	 *
	 * @throws \RuntimeException When the document is not valid XML or the
	 *                           mandatory InvoiceNumber field is absent.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-1.1
	 */
	public function parseUblInvoice(string $ublXml): array;

	/**
	 * Turn the parsed supplier party into a payee reference.
	 *
	 * The party id stays as `supplierIdentifier`. `supplierId` is the payee's
	 * uuid when the party id is one, else the payee with the invoice's KvK
	 * number, else its VAT number; with no match it is left out, because the
	 * schema holds a uuid there (purchasing-supplier-invoice-intake REQ-PSII-001).
	 *
	 * @param string              $administrationId The administration.
	 * @param array<string,mixed> $parsed           The parsed invoice.
	 *
	 * @return array<string,mixed> The invoice without the parser's helper keys.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-1.1
	 */
	public function resolveSupplier(string $administrationId, array $parsed): array;
}//end interface
