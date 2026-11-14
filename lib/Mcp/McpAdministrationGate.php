<?php

/**
 * Shillinq MCP administration gate
 *
 * The authorisation ladder every administration-scoped tool runs before its
 * service: signed in, then the administration is one the caller may access.
 *
 * @category Mcp
 * @package  OCA\Shillinq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Mcp;

use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IUserSession;

/**
 * Refuses a tool call exactly where the REST twin refuses the request: no
 * session is `unauthorised`, an administration the caller may not access is
 * the same masked `not_found` the controller answers with 404, so a model
 * cannot tell a foreign administration from a missing one.
 *
 * The helpers return bool and are never wrapped in a catch: a check that
 * throws fails the call instead of letting it through.
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
class McpAdministrationGate {

	/**
	 * Constructor.
	 *
	 * @param IUserSession                 $userSession           The caller's session.
	 * @param AdministrationContextService $administrationContext The administrations the caller may access.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly AdministrationContextService $administrationContext,
	) {
	}//end __construct()

	/**
	 * The refusal for a call on an administration, or null when it may run.
	 *
	 * @param string $administrationId The administration the call acts on.
	 * @param string $notFound         The masked message the REST twin answers.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function refusal(string $administrationId, string $notFound): ?array {
		if ($this->isSignedIn() === false) {
			return McpResult::error(code: 'unauthorised', message: 'Not logged in');
		}

		if ($this->mayAccess(administrationId: $administrationId) === false) {
			return McpResult::error(code: 'not_found', message: $notFound);
		}

		return null;
	}//end refusal()

	/**
	 * The signed-in user's id, or '' without a session.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function userId(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end userId()

	/**
	 * Whether a user is signed in.
	 *
	 * @return bool
	 */
	private function isSignedIn(): bool {
		return $this->userSession->getUser() !== null;
	}//end isSignedIn()

	/**
	 * Whether the caller may access the administration.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return bool
	 */
	private function mayAccess(string $administrationId): bool {
		return $administrationId !== '' && $this->administrationContext->canAccess(administrationId: $administrationId) === true;
	}//end mayAccess()
}//end class
