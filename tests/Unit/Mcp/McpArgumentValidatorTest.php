<?php

/**
 * Unit tests for the shillinq MCP argument validator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Mcp;

use OCA\Shillinq\Mcp\McpArgumentValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Required keys, unknown keys, types, enums, patterns and lengths.
 */
#[CoversClass(McpArgumentValidator::class)]
class McpArgumentValidatorTest extends TestCase {

	private const SCHEMA = [
		'type' => 'object',
		'properties' => [
			'id' => ['type' => 'string', 'pattern' => '^[a-z0-9-]{1,8}$'],
			'decision' => ['type' => 'string', 'enum' => ['yes', 'no']],
			'note' => ['type' => ['string', 'null'], 'maxLength' => 5],
		],
		'required' => ['id', 'decision'],
	];

	/**
	 * Valid arguments have no problems, with an optional field absent or null.
	 *
	 * @return void
	 */
	public function testValidArgumentsHaveNoProblems(): void {
		$validator = new McpArgumentValidator();

		$this->assertSame([], $validator->problems(self::SCHEMA, ['id' => 'po-1', 'decision' => 'yes']));
		$this->assertSame([], $validator->problems(self::SCHEMA, ['id' => 'po-1', 'decision' => 'no', 'note' => null]));
		$this->assertSame([], $validator->problems(self::SCHEMA, ['id' => 'po-1', 'decision' => 'no', 'note' => 'héllo']));
	}//end testValidArgumentsHaveNoProblems()

	/**
	 * Each kind of problem is named, one line per argument.
	 *
	 * @return void
	 */
	public function testEachProblemIsNamed(): void {
		$validator = new McpArgumentValidator();

		$this->assertSame(['id is required', 'decision is required'], $validator->problems(self::SCHEMA, []));
		$this->assertSame(
			['id is not well-formed', 'decision must be one of yes, no', 'note is longer than 5 characters', 'extra is not an argument of this tool'],
			$validator->problems(self::SCHEMA, ['id' => 'PO 1', 'decision' => 'maybe', 'note' => 'too long', 'extra' => 'x'])
		);
		$this->assertSame(
			['id must be a string', 'decision must be a string'],
			$validator->problems(self::SCHEMA, ['id' => 7, 'decision' => null])
		);
	}//end testEachProblemIsNamed()

	/**
	 * An integer argument must be an int within its bounds; a numeric string is refused.
	 *
	 * @return void
	 */
	public function testIntegerArgumentsAreBounded(): void {
		$schema = [
			'type' => 'object',
			'properties' => ['year' => ['type' => 'integer', 'minimum' => 2020, 'maximum' => 2099]],
			'required' => ['year'],
		];
		$validator = new McpArgumentValidator();

		$this->assertSame([], $validator->problems($schema, ['year' => 2020]));
		$this->assertSame([], $validator->problems($schema, ['year' => 2099]));
		$this->assertSame(['year must be at least 2020'], $validator->problems($schema, ['year' => 2019]));
		$this->assertSame(['year must be at most 2099'], $validator->problems($schema, ['year' => 2100]));
		$this->assertSame(['year must be an integer'], $validator->problems($schema, ['year' => '2024']));
		$this->assertSame(['year must be an integer'], $validator->problems($schema, ['year' => 2024.0]));
	}//end testIntegerArgumentsAreBounded()

	/**
	 * A number argument takes an int or a float within its bounds, and may be nullable.
	 *
	 * @return void
	 */
	public function testNumberArgumentsTakeIntsAndFloats(): void {
		$schema = [
			'type' => 'object',
			'properties' => ['amount' => ['type' => ['number', 'null'], 'minimum' => 0]],
			'required' => [],
		];
		$validator = new McpArgumentValidator();

		$this->assertSame([], $validator->problems($schema, ['amount' => 12]));
		$this->assertSame([], $validator->problems($schema, ['amount' => 12.5]));
		$this->assertSame([], $validator->problems($schema, ['amount' => null]));
		$this->assertSame(['amount must be at least 0'], $validator->problems($schema, ['amount' => -0.5]));
		$this->assertSame(['amount must be a number'], $validator->problems($schema, ['amount' => '12.5']));
	}//end testNumberArgumentsTakeIntsAndFloats()
}//end class
