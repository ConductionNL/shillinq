<?php

/**
 * Audit Package Run
 *
 * One generation of a 3-way-match audit package: the administration and
 * invoice it is for, and what the steps have produced so far (invoice,
 * lifecycle bundle, ledger, stamp, manifest, summary, ZIP path).
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Progress of one AuditExportService::generateAuditPackage() call; never
 * leaves the service.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 */
final class AuditPackageRun {

	/**
	 * The lifecycle bundle: invoice, purchase orders, goods receipts, matches.
	 *
	 * @var array<string,mixed>
	 */
	public array $bundle = [];

	/**
	 * The ledger payload.
	 *
	 * @var array<string,mixed>
	 */
	public array $ledger = [];

	/**
	 * The canonical ledger JSON the SHA-256 is taken over.
	 *
	 * @var string
	 */
	public string $ledgerJson = '';

	/**
	 * Generation timestamp (ISO 8601 UTC).
	 *
	 * @var string
	 */
	public string $generatedAt = '';

	/**
	 * The user (or 'system') generating the package.
	 *
	 * @var string
	 */
	public string $generatedBy = '';

	/**
	 * The invoice number (the invoice id when it has none).
	 *
	 * @var string
	 */
	public string $invoiceNumber = '';

	/**
	 * The package id.
	 *
	 * @var string
	 */
	public string $packageId = '';

	/**
	 * SHA-256 over the ledger JSON.
	 *
	 * @var string
	 */
	public string $sha256 = '';

	/**
	 * The manifest.
	 *
	 * @var array<string,mixed>
	 */
	public array $manifest = [];

	/**
	 * The HTML summary.
	 *
	 * @var string
	 */
	public string $summaryHtml = '';

	/**
	 * Absolute path of the written ZIP.
	 *
	 * @var string
	 */
	public string $zipPath = '';

	/**
	 * Hold the administration and the invoice.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId        SupplierInvoice id.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $invoiceId,
	) {
	}//end __construct()
}//end class
