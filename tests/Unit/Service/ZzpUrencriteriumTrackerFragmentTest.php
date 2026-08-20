<?php

/**
 * Unit tests for the zzp-urencriterium-tracker register fragment.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the zzp-urencriterium-tracker fragment is valid JSON, declares the six
 * urencriterium schemas with their lifecycle/guard, seeds the standard category
 * table, and merges additively onto the monolith without dropping pre-existing
 * schemas or objects (ADR-037). The new daily-ledger schema is named
 * UrenDagregistratie to avoid colliding with the existing monolith UrenRegistratie.
 */
final class ZzpUrencriteriumTrackerFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/20-zzp-urencriterium-tracker.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * The six schemas declared by the fragment.
	 *
	 * @var array<int, string>
	 */
	private array $expectedSchemas = [
		'UrencriteriumYear',
		'UrenDagregistratie',
		'UrenCategorie',
		'UrenPrognose',
		'UrenAlert',
		'UrenEvidence',
	];

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Decode the fragment to an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with a schemas object.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares all six urencriterium schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresAllSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach ($this->expectedSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}

	}//end testFragmentDeclaresAllSchemas()

	/**
	 * The fragment does NOT redeclare the existing monolith UrenRegistratie schema
	 * (ADR-037: no shape collision with the billable time-tracking ledger).
	 *
	 * @return void
	 */
	public function testFragmentDoesNotRedeclareUrenRegistratie(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertArrayNotHasKey(
			'UrenRegistratie',
			$schemas,
			'Fragment must not redeclare the existing monolith UrenRegistratie schema'
		);

		$monolith = json_decode((string)file_get_contents($this->registerPath), true);
		self::assertArrayHasKey(
			'UrenRegistratie',
			$monolith['components']['schemas'],
			'The billable UrenRegistratie must remain the monolith time-tracking schema'
		);

	}//end testFragmentDoesNotRedeclareUrenRegistratie()

	/**
	 * The UrencriteriumYear schema declares its drempel-status lifecycle with the
	 * UrencriteriumYearGuard save precondition and the four canonical statuses.
	 *
	 * @return void
	 */
	public function testUrencriteriumYearDeclaresLifecycleWithGuard(): void {
		$year = $this->fragment()['components']['schemas']['UrencriteriumYear'];

		self::assertArrayHasKey('x-openregister-lifecycle', $year);
		$lifecycle = $year['x-openregister-lifecycle'];
		self::assertSame('thresholdStatus', $lifecycle['field']);
		self::assertSame(
			'OCA\\Shillinq\\Guard\\UrencriteriumYearGuard::validateOnSave',
			$lifecycle['preconditions']['save'],
			'UrencriteriumYear save must be guarded by UrencriteriumYearGuard'
		);

		foreach (['ON_RATE', 'RISK', 'CRITICAL', 'ACHIEVED'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Lifecycle must declare $state");
		}

	}//end testUrencriteriumYearDeclaresLifecycleWithGuard()

	/**
	 * The UrencriteriumYear schema constrains doelNorm to the three legal values.
	 *
	 * @return void
	 */
	public function testUrencriteriumYearConstrainsNorm(): void {
		$year = $this->fragment()['components']['schemas']['UrencriteriumYear'];
		$enum = $year['properties']['purposeNorm']['enum'];
		self::assertSame([1225, 800, 525], $enum);

	}//end testUrencriteriumYearConstrainsNorm()

	/**
	 * The reistijd category seed declares the 4-hour daily cap (REQ-URC-001).
	 *
	 * @return void
	 */
	public function testReistijdCategorySeedsDailyCap(): void {
		$objects = $this->fragment()['objects'];
		$reistijd = null;
		foreach ($objects as $object) {
			if (($object['code'] ?? '') === 'TRAVEL_TIME_BUSINESS') {
				$reistijd = $object;
				break;
			}
		}

		self::assertNotNull($reistijd, 'A REISTIJD_ZAKELIJK category must be seeded');
		self::assertSame(4, $reistijd['maxPerDag'], 'Reistijd cap must be 4 hours/day');

	}//end testReistijdCategorySeedsDailyCap()

	/**
	 * All seven standard categories are seeded, each with a fiscal grondslag.
	 *
	 * @return void
	 */
	public function testSevenCategoriesSeededWithGrondslag(): void {
		$objects = array_filter(
			$this->fragment()['objects'],
			static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'UrenCategorie'
		);
		$codes = array_map(static fn (array $o): string => $o['code'], $objects);
		$expected = [
			'BILLABLE_CLIENT_WORK',
			'ACQUISITION',
			'ADMINISTRATION',
			'TRAVEL_TIME_BUSINESS',
			'TRAINING',
			'FICTION_ZEZ',
			'R_AND_D_WBSO',
		];

		foreach ($expected as $code) {
			self::assertContains($code, $codes, "Category $code must be seeded");
		}

		foreach ($objects as $object) {
			self::assertNotEmpty(
				($object['fiscalSource'] ?? ''),
				'Each seeded category must cite a fiscal grondslag'
			);
		}

	}//end testSevenCategoriesSeededWithGrondslag()

	/**
	 * Merging the fragment onto the monolith adds the six schemas and the seven
	 * category seed objects without dropping any pre-existing schema or object.
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$schemaCountBefore = count($base['components']['schemas']);
		$objectCountBefore = count(($base['objects'] ?? []));

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		foreach ($this->expectedSchemas as $name) {
			self::assertArrayHasKey($name, $schemas);
		}

		self::assertCount($schemaCountBefore + 6, $schemas, 'Exactly six schemas must be added');

		foreach (array_keys($base['components']['schemas']) as $name) {
			self::assertArrayHasKey($name, $schemas, "$name must survive merge");
		}

		self::assertCount(
			$objectCountBefore + 7,
			$merged['objects'],
			'Seven category seed objects must be appended'
		);

	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
