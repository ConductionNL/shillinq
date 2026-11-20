<?php

/**
 * Contract of RecurringInvoiceGenerator
 *
 * The public surface of RecurringInvoiceGenerator that other components call, so they depend
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
 * Contract of RecurringInvoiceGenerator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecurringInvoiceGeneratorInterface {

	/**
	 * Generate the due period's ordinary ARInvoice for a single profile and
	 * advance the profile's schedule (REQ-RIN-002/004).
	 *
	 * The method is idempotent on the (profileId, billingPeriod) key: if a
	 * non-cancelled ARInvoice already carries this profile id + billing period
	 * the existing record is returned unchanged and the profile is NOT
	 * re-advanced. A cancelled invoice for the same key unblocks regeneration.
	 *
	 * @param array<string,mixed> $profile The RecurringInvoiceProfile object.
	 *
	 * @return array<string,mixed> Result: ['invoice' => array|null,
	 *                             'profile' => array, 'created' => bool,
	 *                             'billingPeriod' => string].
	 *
	 * @throws \RuntimeException When the profile is malformed.
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 * @spec openspec/changes/billing-inherited-defects/specs/recurring-invoicing/spec.md (REQ-RIN-010)
	 */
	public function generateForProfile(array $profile): array;
}//end interface
