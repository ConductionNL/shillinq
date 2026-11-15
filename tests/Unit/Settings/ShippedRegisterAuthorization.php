<?php

/**
 * Assertions over the `authorization` blocks of the register shillinq ships.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\SettingsService;
use ReflectionClass;

/**
 * Reads the register exactly as SettingsService hands it to OpenRegister
 * (monolith plus every register.d fragment, deep-merged) and checks the
 * schema-level `authorization` blocks OpenRegister enforces.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
trait ShippedRegisterAuthorization {

	/**
	 * The merged `components.schemas` map.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function shippedSchemas(): array {
		$reflection = new ReflectionClass(SettingsService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$method = $reflection->getMethod('loadRegisterConfigData');
		$loaded = $method->invoke($service);
		self::assertArrayHasKey('data', $loaded, 'The shipped register must load.');

		return $loaded['data']['components']['schemas'];
	}//end shippedSchemas()

	/**
	 * Assert every named schema carries an enforceable authorization block.
	 *
	 * OpenRegister reads only `authorization`; an absent or empty block leaves
	 * the schema open to every signed-in user, and `x-openregister-rbac` is
	 * never read at all.
	 *
	 * @param array<int,string> $names Schema keys to check.
	 *
	 * @return void
	 */
	private function assertSchemasEnforceAuthorization(array $names): void {
		$schemas = $this->shippedSchemas();
		foreach ($names as $name) {
			self::assertArrayHasKey($name, $schemas, "$name must be shipped.");
			$schema = $schemas[$name];
			self::assertArrayNotHasKey('x-openregister-rbac', $schema, "$name still carries x-openregister-rbac, which OpenRegister ignores.");
			self::assertArrayHasKey('authorization', $schema, "$name has no authorization block, so it is open.");
			$authorization = $schema['authorization'];
			self::assertIsArray($authorization);
			self::assertNotSame([], $authorization, "$name has an empty authorization block, which OpenRegister treats as open.");
			self::assertArrayNotHasKey('public', $authorization, "$name must not opt into public access.");
			foreach ($authorization as $action => $rules) {
				self::assertContains($action, ['create', 'read', 'update', 'delete'], "$name names an unknown action $action.");
				self::assertIsList($rules, "$name.$action must be a rule list.");
				self::assertNotSame([], $rules, "$name.$action is empty; leave the action out instead.");
				foreach ($rules as $rule) {
					$group = $rule;
					if (is_array($rule) === true) {
						$group = ($rule['group'] ?? null);
					}

					self::assertNotSame('public', $group, "$name.$action must not grant anonymous callers.");
				}
			}
		}
	}//end assertSchemasEnforceAuthorization()

	/**
	 * Assert a schema grants the named actions to nobody but Nextcloud admins.
	 *
	 * @param string            $name    Schema key.
	 * @param array<int,string> $actions Actions that must be admin-only.
	 *
	 * @return void
	 */
	private function assertAdminOnly(string $name, array $actions): void {
		$authorization = ($this->shippedSchemas()[$name]['authorization'] ?? []);
		foreach ($actions as $action) {
			self::assertArrayNotHasKey($action, $authorization, "$name.$action must be left to Nextcloud admins.");
		}
	}//end assertAdminOnly()
}//end trait
