<?php

/**
 * Test stub for OCA\OpenRegister\Mcp\IMcpToolProvider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

/**
 * The per-app MCP tool provider contract, as OpenRegister declares it.
 */
interface IMcpToolProvider {
	/**
	 * The app id that namespaces every tool id.
	 *
	 * @return string
	 */
	public function getAppId(): string;

	/**
	 * The tool descriptors.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getTools(): array;

	/**
	 * Invoke a tool by id.
	 *
	 * @param string               $toolId    The namespaced tool id.
	 * @param array<string, mixed> $arguments The decoded arguments.
	 *
	 * @return array<string, mixed>
	 */
	public function invokeTool(string $toolId, array $arguments): array;
}//end interface
