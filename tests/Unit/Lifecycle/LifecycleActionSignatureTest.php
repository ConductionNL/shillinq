<?php

/**
 * Every shillinq lifecycle action takes OpenRegister's LifecycleActionContext.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * OpenRegister's executor calls `execute(new LifecycleActionContext(...))`, so
 * an action still declaring the four-array signature cannot be called.
 */
final class LifecycleActionSignatureTest extends TestCase {

	/**
	 * Every class under lib/Lifecycle that implements the interface declares
	 * exactly one parameter, typed LifecycleActionContext.
	 *
	 * @return void
	 */
	public function testEveryActionTakesTheContext(): void {
		$lib = __DIR__ . '/../../../lib/Lifecycle';
		$files = array_merge(glob($lib . '/*.php'), glob($lib . '/Action/*.php'));
		$checked = 0;
		$wrong = [];
		foreach ($files as $file) {
			$relative = substr($file, strlen($lib) + 1, -4);
			$class = 'OCA\\Shillinq\\Lifecycle\\' . str_replace('/', '\\', $relative);
			if (class_exists($class) === false) {
				continue;
			}

			$reflection = new ReflectionClass($class);
			if ($reflection->implementsInterface(LifecycleActionInterface::class) === false) {
				continue;
			}

			$checked++;
			$params = $reflection->getMethod('execute')->getParameters();
			$type = ($params[0] ?? null)?->getType();
			if (count($params) !== 1
				|| ($type instanceof ReflectionNamedType) === false
				|| $type->getName() !== LifecycleActionContext::class
			) {
				$wrong[] = $class;
			}
		}

		self::assertSame(expected: 16, actual: $checked, message: 'expected the 16 lifecycle actions');
		self::assertSame(expected: [], actual: $wrong, message: implode(separator: "\n", array: $wrong));
	}//end testEveryActionTakesTheContext()
}//end class
