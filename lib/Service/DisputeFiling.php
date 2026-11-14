<?php

/**
 * Dispute Filing
 *
 * The state of one {@see ExceptionResolutionService::fileDispute()} run: the
 * tenant, the loaded match and invoice, the trimmed reason and the
 * resolution stamp, carried between the preparation and submission steps.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One dispute filing on a ThreeWayMatch.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 */
class DisputeFiling {

	/**
	 * Tenant scope (already access-checked).
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * ThreeWayMatch id as requested.
	 *
	 * @var string
	 */
	public string $matchId = '';

	/**
	 * Dispute reason; trimmed once prepared.
	 *
	 * @var string
	 */
	public string $reason = '';

	/**
	 * The loaded ThreeWayMatch record.
	 *
	 * @var array<string,mixed>
	 */
	public array $match = [];

	/**
	 * The match's linked invoice id.
	 *
	 * @var string
	 */
	public string $invoiceId = '';

	/**
	 * The linked SupplierInvoice header.
	 *
	 * @var array<string,mixed>
	 */
	public array $invoice = [];

	/**
	 * The resolution fields to stamp onto the match.
	 *
	 * @var array{resolutionAction:string,resolutionNotes:string,resolvedBy:string,resolvedAt:string}
	 */
	public array $stamp = ['resolutionAction' => '', 'resolutionNotes' => '', 'resolvedBy' => '', 'resolvedAt' => ''];
}//end class
