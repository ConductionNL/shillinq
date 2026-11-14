<?php

/**
 * Shillinq MCP argument validator
 *
 * Checks a tool call's arguments against the tool's input schema before any
 * gate or service runs.
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
 * Validates arguments against the subset of JSON Schema the catalogue uses:
 * an object with `required` keys and string properties carrying `pattern`,
 * `enum` and `maxLength`. Unknown keys are refused, so a model cannot slip a
 * field past the tool (the controller ignores body fields it does not read;
 * a tool names every field it reads).
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
class McpArgumentValidator {

	/**
	 * The problems with a call's arguments, empty when they are valid.
	 *
	 * @param array<string, mixed> $schema    The tool's input schema.
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return list<string> One line per problem, naming the argument.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function problems(array $schema, array $arguments): array {
		$properties = (array)($schema['properties'] ?? []);
		$problems = [];

		foreach ((array)($schema['required'] ?? []) as $name) {
			if (array_key_exists($name, $arguments) === false) {
				$problems[] = $name . ' is required';
			}
		}

		foreach ($arguments as $name => $value) {
			if (isset($properties[$name]) === false) {
				$problems[] = $name . ' is not an argument of this tool';
				continue;
			}

			$problem = $this->propertyProblem(name: (string)$name, property: (array)$properties[$name], value: $value);
			if ($problem !== null) {
				$problems[] = $problem;
			}
		}

		return $problems;
	}//end problems()

	/**
	 * The problem with one argument, or null.
	 *
	 * @param string               $name     The argument name.
	 * @param array<string, mixed> $property Its schema.
	 * @param mixed                $value    Its value.
	 *
	 * @return string|null
	 */
	private function propertyProblem(string $name, array $property, mixed $value): ?string {
		$nullable = in_array('null', (array)($property['type'] ?? []), true);
		if ($value === null && $nullable === true) {
			return null;
		}

		if (is_string($value) === false) {
			return $name . ' must be a string';
		}

		if (isset($property['enum']) === true && in_array($value, (array)$property['enum'], true) === false) {
			return $name . ' must be one of ' . implode(', ', (array)$property['enum']);
		}

		if (isset($property['pattern']) === true && preg_match('/' . $property['pattern'] . '/', $value) !== 1) {
			return $name . ' is not well-formed';
		}

		if (isset($property['maxLength']) === true && mb_strlen($value) > (int)$property['maxLength']) {
			return $name . ' is longer than ' . (int)$property['maxLength'] . ' characters';
		}

		return null;
	}//end propertyProblem()
}//end class
