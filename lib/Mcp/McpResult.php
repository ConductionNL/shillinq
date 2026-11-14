<?php

/**
 * Shillinq MCP result shapes
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

/**
 * The two answers a tool gives: a result, or a structured error the model can
 * read. Tools never throw to the caller.
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
final class McpResult {

	/**
	 * A structured error.
	 *
	 * @param string $code    A stable code: invalid_arguments, unauthorised, not_found, conflict, failed, unknown_tool.
	 * @param string $message A sentence for the model.
	 *
	 * @return array{isError: true, error: string, message: string}
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public static function error(string $code, string $message): array {
		return ['isError' => true, 'error' => $code, 'message' => $message];
	}//end error()

	/**
	 * Whether an answer is an error.
	 *
	 * @param array<string, mixed> $result The answer.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public static function isError(array $result): bool {
		return ($result['isError'] ?? false) === true;
	}//end isError()
}//end class
