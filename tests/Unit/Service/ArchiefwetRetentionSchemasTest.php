<?php

/**
 * The Archiefwet retention schemas are in the register again.
 *
 * RetentionPolicy, RetentionSchedule and DocumentRetention were added in
 * 6df26567 and lost in merge c0957515, while SettingsService::
 * seedRetentionPolicies() and RetentionGuard kept using them, so every install
 * logged "retention policy seeding failed". These tests pin the schemas, their
 * declaration on the register, and that the seed fits the schema it seeds.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-archiefwet-retention/spec.md#requirement-the-retention-schemas-the-code-uses-are-in-the-register-req-ret-014
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Guard\RetentionGuard;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Retention schemas, their declaration, and the seed against its schema.
 */
final class ArchiefwetRetentionSchemasTest extends TestCase {

	private const SETTINGS_DIR = __DIR__ . '/../../../lib/Settings';

	private const RESTORED = ['RetentionPolicy', 'RetentionSchedule', 'DocumentRetention'];

	/**
	 * The register as the repair step builds it.
	 *
	 * @return array<string, mixed>
	 */
	private function mergedRegister(): array {
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$config = json_decode((string)file_get_contents(self::SETTINGS_DIR . '/shillinq_register.json'), true);
		$files  = glob(self::SETTINGS_DIR . '/register.d/*.json');
		self::assertIsArray($files);
		sort($files);
		foreach ($files as $file) {
			$config = $merge->invoke(null, $config, json_decode((string)file_get_contents($file), true));
		}

		return $config;
	}//end mergedRegister()

	/**
	 * What a seed record does that its schema would not accept.
	 *
	 * @param array<string, mixed> $record The seed record.
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return array<int, string> One line per finding.
	 */
	private function misfits(array $record, array $schema): array {
		$found = [];
		foreach (array_keys($record) as $key) {
			if (isset($schema['properties'][$key]) === false) {
				$found[] = 'unknown property ' . $key;
			}
		}

		foreach ($schema['required'] as $key) {
			if (array_key_exists($key, $record) === false) {
				$found[] = 'missing ' . $key;
			}
		}

		foreach ($record as $key => $value) {
			$enum = ($schema['properties'][$key]['enum'] ?? null);
			if (is_array($enum) === true && in_array($value, $enum, true) === false) {
				$found[] = $key . ' not in enum';
			}
		}

		return $found;
	}//end misfits()

	/**
	 * The merged register carries the three schemas and the register declares them.
	 *
	 * @return void
	 */
	public function testTheRetentionSchemasAreInTheRegisterAndDeclared(): void {
		$schemas  = $this->mergedRegister()['components']['schemas'];
		$declared = json_decode((string)file_get_contents(self::SETTINGS_DIR . '/register.d/000-register-declaration.json'), true);

		foreach (self::RESTORED as $slug) {
			self::assertArrayHasKey($slug, $schemas);
			self::assertSame($slug, $schemas[$slug]['slug']);
			self::assertContains($slug, $declared['components']['registers']['shillinq']['schemas']);
		}
	}//end testTheRetentionSchemasAreInTheRegisterAndDeclared()

	/**
	 * Every seeded default policy fits RetentionPolicy, the seeder's match key included.
	 *
	 * @return void
	 */
	public function testEverySeededPolicyFitsTheSchema(): void {
		$schema = $this->mergedRegister()['components']['schemas']['RetentionPolicy'];
		$seed   = json_decode((string)file_get_contents(self::SETTINGS_DIR . '/seeds/retention-policies.json'), true);

		self::assertCount(3, $seed['policies']);
		foreach ($seed['policies'] as $policy) {
			self::assertSame([], $this->misfits(record: $policy, schema: $schema), (string)$policy['slug']);
		}
	}//end testEverySeededPolicyFitsTheSchema()

	/**
	 * Control: the fit check does find a record the schema would refuse.
	 *
	 * @return void
	 */
	public function testTheFitCheckFindsARecordTheSchemaRefuses(): void {
		$schema = $this->mergedRegister()['components']['schemas']['RetentionPolicy'];

		$found = $this->misfits(record: ['slug' => 'x', 'name' => 'X', 'documentType' => 'poster', 'colour' => 'red'], schema: $schema);

		self::assertSame(['unknown property colour', 'missing retentionYears', 'documentType not in enum'], $found);
	}//end testTheFitCheckFindsARecordTheSchemaRefuses()

	/**
	 * Every guard the DocumentRetention lifecycle names exists.
	 *
	 * @return void
	 */
	public function testEveryLifecycleGuardExists(): void {
		$lifecycle = $this->mergedRegister()['components']['schemas']['DocumentRetention']['x-openregister-lifecycle'];

		self::assertSame('active', $lifecycle['initial']);
		foreach ($lifecycle['transitions'] as $name => $transition) {
			if (isset($transition['requires']) === false) {
				continue;
			}

			[$class, $method] = explode('::', $transition['requires']);
			self::assertSame(RetentionGuard::class, $class, $name);
			self::assertTrue(method_exists($class, $method), $name . ' names ' . $transition['requires']);
		}
	}//end testEveryLifecycleGuardExists()
}//end class
