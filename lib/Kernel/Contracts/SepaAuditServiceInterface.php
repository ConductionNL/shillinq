<?php

/**
 * Contract of SepaAuditService
 *
 * The public surface of SepaAuditService that other components call, so they depend
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
 * Contract of SepaAuditService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SepaAuditServiceInterface {

	/**
	 * Build the audit dossier for one mandate, scoped to the CALLER's administrations.
	 *
	 * Returns null when the mandate does not exist or belongs to an
	 * administration the caller has no membership for (treated as not-found to
	 * avoid leaking existence; IDOR-safe per ADR-005 / REQ-MA-001).
	 *
	 * ⚠️ This used to read "outside the CONFIGURED administration", and that is
	 * exactly what it did: `resolveAdministration()` returned
	 * `appConfig->getValueString(APP_ID, 'administration_id', '')` — an
	 * instance-wide constant with no relation to the calling user — and the
	 * `$administration !== ''` short-circuit made the guard a complete no-op
	 * whenever that key was unset, which is its default.
	 *
	 * @param string $mandateId The SepaMandate UUID/slug.
	 *
	 * @return array{data:string,filename:string}|null The ZIP payload + filename, or null.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function buildMandateDossier(string $mandateId): ?array;
}//end interface
