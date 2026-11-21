<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

/**
 * Assertions that a schema carries an authorization block OpenRegister enforces.
 *
 * OpenRegister reads `authorization` and nothing else: `x-openregister-rbac` and a
 * transition's `x-rbac-role` are dropped on import, so a schema that only declares
 * those is open to every logged-in user through the object API.
 */
trait SchemaAuthorizationAssertions {

	/** @var array<string, array<int, array{file: string, schema: array<string, mixed>}>>|null */
	private static ?array $schemaOccurrences = null;

	/**
	 * Every declaration of every schema, per fragment, in the order SettingsService merges them.
	 *
	 * @return array<string, array<int, array{file: string, schema: array<string, mixed>}>>
	 */
	private static function schemaOccurrences(): array {
		if (self::$schemaOccurrences !== null) {
			return self::$schemaOccurrences;
		}

		$settings = __DIR__ . '/../../../lib/Settings';
		$files = glob($settings . '/register.d/*.json');
		sort($files);
		array_unshift($files, $settings . '/shillinq_register.json');

		$occurrences = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			foreach (($data['components']['schemas'] ?? []) as $name => $schema) {
				$occurrences[(string)$name][] = ['file' => basename($file), 'schema' => (array)$schema];
			}
		}

		self::$schemaOccurrences = $occurrences;
		return $occurrences;
	}

	/**
	 * Assert that one schema is protected by an enforced authorization block.
	 */
	private function assertSchemaAuthorized(string $name): void {
		$occurrences = (self::schemaOccurrences()[$name] ?? []);
		self::assertNotSame([], $occurrences, $name . ' is declared nowhere');

		$blocks = [];
		$lifecycle = null;
		foreach ($occurrences as $occurrence) {
			$schema = $occurrence['schema'];
			self::assertArrayNotHasKey(
				'x-openregister-rbac',
				$schema,
				$name . ' still declares x-openregister-rbac in ' . $occurrence['file'] . ', which OpenRegister never reads'
			);
			if (isset($schema['authorization']) === true) {
				$blocks[] = $schema['authorization'];
			}

			if (isset($schema['x-openregister-lifecycle']['transitions']) === true) {
				$lifecycle = $schema['x-openregister-lifecycle'];
			}
		}

		self::assertCount(
			1,
			$blocks,
			$name . ' must declare authorization in exactly one fragment: fragments merge lists by appending, so two blocks grant the union'
		);
		$block = $blocks[0];

		self::assertArrayHasKey('read', $block, $name . ' grants no read: only owners and Nextcloud admins would see it');
		foreach ($block as $action => $rules) {
			if ($action === 'roles') {
				foreach ($rules as $role => $groups) {
					foreach ($groups as $group) {
						self::assertStringStartsWith('shillinq_', $group, $name . ' role ' . $role);
					}
				}

				continue;
			}

			self::assertContains($action, ['create', 'read', 'update', 'delete'], $name . ' has an action OpenRegister refuses: ' . $action);
			foreach ($rules as $rule) {
				$group = is_array($rule) === true ? (string)($rule['group'] ?? '') : (string)$rule;
				self::assertStringStartsWith('shillinq_', $group, $name . '.' . $action . ' grants a group outside the shillinq roles');
			}

			self::assertNotSame([], $rules, $name . '.' . $action . ' is an empty list; leave an action out to keep it admin-only');
		}

		foreach ((array)($lifecycle['transitions'] ?? []) as $transition => $spec) {
			self::assertArrayNotHasKey('x-rbac-role', (array)$spec, $name . '.' . $transition . ' still declares x-rbac-role');
			foreach ((array)(($spec['authorization'] ?? [])) as $entry) {
				if (is_array($entry) === true && isset($entry['role']) === true) {
					self::assertArrayHasKey(
						$entry['role'],
						(array)($block['roles'] ?? []),
						$name . '.' . $transition . ' names role ' . $entry['role'] . ' that the schema does not assign to a group'
					);
				}
			}
		}
	}
}
