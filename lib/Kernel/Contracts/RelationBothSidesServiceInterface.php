<?php

/**
 * Contract of RelationBothSidesService
 *
 * The public surface of RelationBothSidesService that other components call, so they depend
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

use DomainException;

/**
 * Contract of RelationBothSidesService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RelationBothSidesServiceInterface {

	/**
	 * The customer linked to a supplier, for the supplier's page.
	 *
	 * @param string $administrationId The administration.
	 * @param string $payeeId          The supplier.
	 *
	 * @return string|null The customer id, or null when the supplier is not linked.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function customerOfPayee(string $administrationId, string $payeeId): ?string;

	/**
	 * Every linked relation of an administration with its totals.
	 *
	 * @param string                            $administrationId The administration.
	 * @param string                            $from             First invoice date.
	 * @param string                            $to               Last invoice date.
	 * @param array{sent: bool, received: bool} $sides            The sides the caller may read.
	 *
	 * @return array<string, mixed> relations (one row each), restricted sides.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function forAdministration(string $administrationId, string $from, string $to, array $sides): array;

	/**
	 * Both sides of one customer's relation.
	 *
	 * @param string                                   $administrationId The administration.
	 * @param string                                   $customerId       The customer.
	 * @param string                                   $from             First invoice date, YYYY-MM-DD.
	 * @param string                                   $to               Last invoice date, YYYY-MM-DD.
	 * @param array{sent: bool, received: bool}        $sides            The sides the caller may read.
	 *
	 * @return array<string, mixed> customer, payee, link, sent, received, totals.
	 *
	 * @throws DomainException When the customer is not in the administration.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function forCustomer(string $administrationId, string $customerId, string $from, string $to, array $sides): array;

	/**
	 * The report as CSV: one row per relation with the five amounts.
	 *
	 * @param array<int, array<string, mixed>> $relations forAdministration()['relations'].
	 *
	 * @return string The CSV text.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function toCsv(array $relations): string;
}//end interface
