<?php

/**
 * Which sub-ledger invoices make up one party's open balance.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * The invoice schema, the party it is filtered on, the administration and
 * the lifecycle states that do not count as open.
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-006
 */
final class OpenBalanceQuery {

	/**
	 * Invoices read per page.
	 *
	 * @var integer
	 */
	public const PAGE_SIZE = 500;

	/**
	 * Construct the query.
	 *
	 * @param string $schema 'APInvoice' or 'ARInvoice'.
	 * @param string $partyField 'vendorId' or 'customerId'.
	 * @param string $partyId The party identifier.
	 * @param string $adminId The administration identifier.
	 * @param array<string> $excluded lifecycleState values to exclude.
	 */
	public function __construct(
		public readonly string $schema,
		public readonly string $partyField,
		public readonly string $partyId,
		public readonly string $adminId,
		public readonly array $excluded,
	) {

	}//end __construct()

	/**
	 * The findAll arguments for one page.
	 *
	 * @param integer $page The 1-based page number.
	 *
	 * @return array<string,mixed> The filters, limit and offset.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-006
	 */
	public function pageArgs(int $page): array {
		return [
			'filters' => [
				$this->partyField => $this->partyId,
				'administrationId' => $this->adminId,
			],
			'limit' => self::PAGE_SIZE,
			'offset' => (($page - 1) * self::PAGE_SIZE),
		];

	}//end pageArgs()
}//end class
