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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the invocation to Nextcloud's audit log as a critical action: the
 * tool, the signed-in principal it ran as, the administration and the
 * outcome. Only those four values are recorded, never the arguments, so a
 * comment or an amount does not land in the audit log.
 *
 * Where OpenRegister publishes its tool-invocation recorder, the same call is
 * also written to OpenRegister's hash-chained audit trail, as its own tool
 * providers write theirs (an argument digest, never the arguments). The
 * recorder is looked up by name, so shillinq runs without OpenRegister.
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
class McpInvocationAudit {

	/**
	 * The audit line; the four values fill it in order.
	 */
	public const MESSAGE = 'MCP tool "%s" invoked by "%s" on administration "%s": %s';

	/**
	 * OpenRegister's published tool-invocation recorder, by name.
	 */
	public const RECORDER = 'OCA\\OpenRegister\\Contract\\ToolInvocationRecorderInterface';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher        $dispatcher Hands the record to the audit log.
	 * @param ContainerInterface|null $container  Resolves OpenRegister's recorder, when installed.
	 * @param LoggerInterface|null    $logger     Logs a recorder that cannot be reached.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly ?ContainerInterface $container = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Record one invocation.
	 *
	 * @param string               $toolId           The tool id.
	 * @param string               $userId           The principal, '' without a session.
	 * @param string               $administrationId The administration named, '' when none.
	 * @param array<string, mixed> $result           What the tool answered.
	 * @param array<string, mixed> $arguments        The arguments, digested by OpenRegister's recorder.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function record(string $toolId, string $userId, string $administrationId, array $result, array $arguments = []): void {
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
		$this->recordInOpenRegister(toolId: $toolId, arguments: $arguments, result: $result);
	}//end record()

	/**
	 * Write the call to OpenRegister's hash-chained audit trail, when its recorder is published.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The arguments.
	 * @param array<string, mixed> $result    What the tool answered.
	 *
	 * @return void
	 */
	private function recordInOpenRegister(string $toolId, array $arguments, array $result): void {
		if ($this->container === null || $this->container->has(self::RECORDER) === false) {
			return;
		}

		try {
			$this->container->get(self::RECORDER)->record($toolId, $arguments, $result);
		} catch (Throwable $e) {
			$this->logger?->error('Shillinq MCP: OpenRegister tool-invocation recorder failed', ['toolId' => $toolId, 'exception' => $e->getMessage()]);
		}
	}//end recordInOpenRegister()
}//end class
