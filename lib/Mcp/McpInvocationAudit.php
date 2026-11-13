<?php

/**
 * Shillinq MCP invocation audit
 *
 * One audit record per tool invocation, refused and failed ones included.
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

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * Writes the invocation to Nextcloud's audit log as a critical action: the
 * tool, the signed-in principal it ran as, the administration and the
 * outcome. Only those four values are recorded, never the arguments, so a
 * comment or an amount does not land in the audit log.
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
class McpInvocationAudit {

	/**
	 * The audit line; the four values fill it in order.
	 */
	public const MESSAGE = 'MCP tool "%s" invoked by "%s" on administration "%s": %s';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Hands the record to the audit log.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * Record one invocation.
	 *
	 * @param string               $toolId           The tool id.
	 * @param string               $userId           The principal, '' without a session.
	 * @param string               $administrationId The administration named, '' when none.
	 * @param array<string, mixed> $result           What the tool answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function record(string $toolId, string $userId, string $administrationId, array $result): void {
		$outcome = 'ok';
		if (McpResult::isError(result: $result) === true) {
			$outcome = (string)($result['error'] ?? 'failed');
		}

		$this->dispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				self::MESSAGE,
				['tool' => $toolId, 'user' => $userId, 'administrationId' => $administrationId, 'outcome' => $outcome]
			)
		);
	}//end record()
}//end class
