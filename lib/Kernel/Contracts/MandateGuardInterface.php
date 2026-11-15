<?php

/**
 * Contract of MandateGuard
 *
 * The public surface of MandateGuard that other components call, so they depend
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
 * Contract of MandateGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MandateGuardInterface {

	/**
	 * True iff the mandate is dormant beyond the 36-month rulebook threshold.
	 *
	 * REQ-SDD-008: an active mandate whose last successful collection
	 * (lastUsedAt) — or, absent any collection, whose signedAt — is more
	 * than 36 months in the past MUST be expired by the daily job. Mandates
	 * never used and never signed cannot be evaluated and are left alone.
	 *
	 * @param string $mandateId The SepaMandate.id (call-signature parity; unused).
	 * @param array<string,mixed>|null $object The mandate object being transitioned.
	 *
	 * @return bool True when the mandate is dormant and may expire.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function canExpire(string $mandateId, ?array $object = null): bool;
}//end interface
