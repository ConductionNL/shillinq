<?php

/**
 * Every `x-openregister-aggregate-refs` filter names a property its target schema declares.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude register integrity test, not tied to one requirement
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * OpenRegister turns each aggregate-ref filter into a column of the target
 * schema's table. A filter on an undeclared property is a query on a column
 * that does not exist: on PostgreSQL that error aborts the surrounding
 * transaction, so during a register import every later seed object in it
 * fails with "current transaction is aborted".
 */
class AggregateRefFiltersDeclaredTest extends TestCase {
	use ShippedRegisterAuthorization;

	/**
	 * Each filter key of each aggregate-ref is a declared property of the target schema.
	 *
	 * @return void
	 */
	public function testEveryAggregateRefFilterIsADeclaredTargetProperty(): void {
		$schemas = $this->shippedSchemas();
		$undeclared = [];
		$checked = 0;
		foreach ($schemas as $name => $schema) {
			foreach (($schema['x-openregister-aggregate-refs'] ?? []) as $refName => $ref) {
				$target = ($ref['schema'] ?? '');
				self::assertArrayHasKey($target, $schemas, "$name.$refName targets unknown schema $target.");
				$properties = ($schemas[$target]['properties'] ?? []);
				foreach (array_keys($ref['filters'] ?? []) as $field) {
					$checked++;
					if (array_key_exists($field, $properties) === false) {
						$undeclared[] = "$name.$refName -> $target.$field";
					}
				}
			}
		}

		self::assertGreaterThan(0, $checked, 'No aggregate-ref filters were found, so nothing was checked.');
		self::assertSame([], $undeclared, 'Aggregate-ref filters on undeclared properties: '.implode(', ', $undeclared));
	}//end testEveryAggregateRefFilterIsADeclaredTargetProperty()
}//end class
