<?php

/**
 * Contract of AdministrationContextService
 *
 * The public surface of AdministrationContextService that other components call, so they depend
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
 * Contract of AdministrationContextService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdministrationContextServiceInterface {

	/**
	 * List the administration ids the authenticated user may access.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	public function accessibleAdministrationIds(): array;

	/**
	 * Build the session administration context for the authenticated user (REQ-MA-003).
	 *
	 * Returns the list of accessible administrations (id + code + name + the
	 * user's role and posting/closing rights), and the default active
	 * administration (the first accessible one). An anonymous user, or a user
	 * with no memberships, gets an empty list and a null active administration.
	 *
	 * @return array{userId:?string,administrations:array<int,array<string,mixed>>,activeAdministrationId:?string}
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
	 */
	public function buildContext(): array;

	/**
	 * Whether the authenticated user may access a given administration (REQ-MA-001).
	 *
	 * Used as the IDOR guard before any single-administration read/write: if this
	 * returns false the caller MUST mask the resource as a 404, never a 403.
	 *
	 * @param string $administrationId The administration id to check.
	 *
	 * @return bool True when the user has a valid membership for the administration.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	public function canAccess(string $administrationId): bool;

	/**
	 * Whether the authenticated user may post journal entries in the administration (REQ-MA-003).
	 *
	 * Returns false when the user has no membership for the administration or when
	 * the membership's role/flag does not grant posting rights.
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-21
	 */
	public function canPostJournalEntry(string $administrationId): bool;

	/**
	 * Whether a named user may access an administration, without a session.
	 *
	 * A background job runs as nobody; a scheduled report runs on behalf of the
	 * schedule's owner and must never reach further than that owner could by hand.
	 *
	 * @param string $userId           The user.
	 * @param string $administrationId The administration.
	 *
	 * @return bool True when the user holds a currently valid membership.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function canUserAccess(string $userId, string $administrationId): bool;

	/**
	 * Resolve the uid of the authenticated user, or null if anonymous.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
	 */
	public function currentUserId(): ?string;

	/**
	 * The administration a named user works in by default: their first valid membership.
	 *
	 * A dashboard widget is asked for a user id without a shillinq session, so
	 * it cannot read the session's active administration.
	 *
	 * @param string $userId The user.
	 *
	 * @return string|null The administration id, or null when the user has none.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function defaultAdministrationIdForUser(string $userId): ?string;

	/**
	 * Validate a requested active administration and return the id to switch to (REQ-MA-003).
	 *
	 * Returns the requested id when the user has a valid membership for it; returns
	 * null otherwise (the caller masks this as a 404, never confirming the
	 * administration exists for a non-member).
	 *
	 * @param string $targetId The administration the user wants to switch to.
	 *
	 * @return string|null The id to make active, or null when access is denied.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
	 */
	public function resolveSwitchTarget(string $targetId): ?string;
}//end interface
