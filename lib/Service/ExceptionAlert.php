<?php

/**
 * Exception Alert
 *
 * One ThreeWayMatch alert to a membership role: who, about what, with which invoice header.
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
 * A role-addressed ThreeWayMatch alert.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 */
class ExceptionAlert {

	/**
	 * Tenant scope.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * Membership role to notify.
	 *
	 * @var string
	 */
	public string $role = '';

	/**
	 * Nextcloud notification subject.
	 *
	 * @var string
	 */
	public string $subject = '';

	/**
	 * ThreeWayMatch id.
	 *
	 * @var string
	 */
	public string $matchId = '';

	/**
	 * Linked SupplierInvoice header, when known.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $invoice = null;
}//end class
