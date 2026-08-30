<?php

/**
 * Unit tests for the bookkeeping-rechtmatigheidsverantwoording register fragment.
 *
 * Verifies the ADR-037 fragment is well-formed, declares the four new schemas,
 * additively extends JournalEntry (journaalpost) with the rechtmatigheid
 * sub-object via deep-merge, seeds a default tolerantiegrens, and that every
 * lifecycle `requires:` reference points at a real RechtmatigheidGuard method.
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
 * @spec openspec/changes/bookkeeping-rechtmatigheidsverantwoording/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Lifecycle\RechtmatigheidGuard;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Integrity tests for the rechtmatigheidsverantwoording register fragment.
 */
class RechtmatigheidFragmentTest extends TestCase {

	/**
	 * The decoded fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment;

	/**
	 * Load and decode the fragment once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-rechtmatigheidsverantwoording.json';
		self::assertFileExists(filename: $fragmentPath, message: 'Fragment file must exist.');

		$raw = file_get_contents($fragmentPath);
		self::assertNotFalse(condition: $raw, message: 'Fragment must be readable.');

		$decoded = json_decode($raw, true);
		self::assertSame(expected: JSON_ERROR_NONE, actual: json_last_error(), message: 'Fragment must be valid JSON.');
		self::assertIsArray(actual: $decoded, message: 'Fragment must decode to an array.');
		$this->fragment = $decoded;

	}//end setUp()

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end merge()

	/**
	 * The fragment declares the four new schemas (REQ-RV-001..003, 005).
	 *
	 * @return void
	 */
	public function testDeclaresFourNewSchemas(): void {
		$schemas = $this->fragment['components']['schemas'];
		self::assertArrayHasKey(key: 'Rechtmatigheidstoets', array: $schemas);
		self::assertArrayHasKey(key: 'Rechtmatigheidsbevinding', array: $schemas);
		self::assertArrayHasKey(key: 'Rechtmatigheidsparagraaf', array: $schemas);
		self::assertArrayHasKey(key: 'Tolerantiegrens', array: $schemas);

	}//end testDeclaresFourNewSchemas()

	/**
	 * Each new schema is tenant-scoped via a required administrationId (IDOR boundary).
	 *
	 * @return void
	 */
	public function testNewSchemasAreTenantScoped(): void {
		$schemas = $this->fragment['components']['schemas'];
		$names = ['Rechtmatigheidstoets', 'Rechtmatigheidsbevinding', 'Rechtmatigheidsparagraaf', 'Tolerantiegrens'];
		foreach ($names as $name) {
			self::assertContains(
				needle: 'administrationId',
				haystack: $schemas[$name]['required'],
				message: $name . ' must require administrationId for tenant scoping.'
			);
		}

	}//end testNewSchemasAreTenantScoped()

	/**
	 * The Rechtmatigheidstoets criterium enum carries all nine wettelijke criteria.
	 *
	 * @return void
	 */
	public function testCriteriumEnumIsComplete(): void {
		$enum = $this->fragment['components']['schemas']['Rechtmatigheidstoets']['properties']['criterium']['enum'];
		$criteria = [
			'begroting',
			'calculatie',
			'valutering',
			'adressering',
			'volledigheid',
			'europees_aanbesteden',
			'staatssteun',
			'terms',
			'misbruik_oneigenlijk_gebruik',
			'aanvaardbaarheid',
		];
		foreach ($criteria as $criterium) {
			self::assertContains(needle: $criterium, haystack: $enum, message: $criterium . ' must be in the criterium enum.');
		}

	}//end testCriteriumEnumIsComplete()

	/**
	 * The fragment additively extends JournalEntry with the rechtmatigheid sub-object
	 * without clobbering existing JournalEntry properties on deep-merge (REQ-RV-001).
	 *
	 * @return void
	 */
	public function testJournalEntryExtensionMergesAdditively(): void {
		$base = [
			'components' => [
				'schemas' => [
					'JournalEntry' => [
						'properties' => [
							'journalNumber' => ['type' => 'string'],
							'state' => ['type' => 'string'],
						],
						'required' => ['journalNumber', 'state'],
					],
				],
			],
		];

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$merged = $this->merge($base, $this->fragment);
		// phpcs:enable CustomSniffs.Functions.NamedParameters
		$je = $merged['components']['schemas']['JournalEntry'];

		self::assertArrayHasKey(key: 'journalNumber', array: $je['properties'], message: 'Existing prop preserved.');
		self::assertArrayHasKey(key: 'state', array: $je['properties'], message: 'Existing prop preserved.');
		self::assertArrayHasKey(key: 'lawfulness', array: $je['properties'], message: 'rechtmatigheid sub-object added.');
		self::assertArrayHasKey(key: 'status', array: $je['properties']['lawfulness']['properties']);
		self::assertSame(
			expected: 'niet_getoetst',
			actual: $je['properties']['lawfulness']['default']['status'],
			message: 'Backward-compatible default must be niet_getoetst.'
		);
		self::assertArrayHasKey(key: 'Rechtmatigheidstoets', array: $merged['components']['schemas']);

	}//end testJournalEntryExtensionMergesAdditively()

	/**
	 * The fragment seeds a default tolerantiegrens (3% / 1% per BADO, REQ-RV-003).
	 *
	 * @return void
	 */
	public function testSeedsDefaultTolerantiegrens(): void {
		self::assertArrayHasKey(key: 'objects', array: $this->fragment);
		self::assertNotEmpty(actual: $this->fragment['objects'], message: 'Fragment must seed at least one object.');

		$seed = $this->fragment['objects'][0];
		self::assertSame(expected: 'Tolerantiegrens', actual: $seed['@self']['schema']);
		self::assertSame(expected: 'shillinq', actual: $seed['@self']['register']);
		self::assertSame(expected: 3.0, actual: $seed['error_percentage']);
		self::assertSame(expected: 1.0, actual: $seed['uncertainty_percentage']);
		self::assertSame(expected: 'draft', actual: $seed['status']);

	}//end testSeedsDefaultTolerantiegrens()

	/**
	 * Every lifecycle transition `requires:` reference resolves to a real
	 * RechtmatigheidGuard method (no dangling guard references, ADR-031).
	 *
	 * @return void
	 */
	public function testLifecycleGuardReferencesResolve(): void {
		$schemas = $this->fragment['components']['schemas'];
		$found = 0;
		$names = ['Rechtmatigheidstoets', 'Rechtmatigheidsbevinding', 'Rechtmatigheidsparagraaf'];

		foreach ($names as $name) {
			$transitions = ($schemas[$name]['x-openregister-lifecycle']['transitions'] ?? []);
			foreach ($transitions as $transition) {
				if (isset($transition['requires']) === false) {
					continue;
				}

				[$class, $method] = explode('::', $transition['requires']);
				self::assertSame(expected: RechtmatigheidGuard::class, actual: $class);
				self::assertTrue(
					condition: method_exists(RechtmatigheidGuard::class, $method),
					message: 'Guard method ' . $method . ' referenced from ' . $name . ' must exist.'
				);
				$found++;
			}
		}

		self::assertGreaterThanOrEqual(expected: 3, actual: $found, message: 'Expected at least three guarded transitions.');

	}//end testLifecycleGuardReferencesResolve()

	/**
	 * Each new schema declares an RBAC block (default-deny posture, ADR-005).
	 *
	 * @return void
	 */
	public function testNewSchemasDeclareRbac(): void {
		$schemas = $this->fragment['components']['schemas'];
		$names = ['Rechtmatigheidstoets', 'Rechtmatigheidsbevinding', 'Rechtmatigheidsparagraaf', 'Tolerantiegrens'];
		foreach ($names as $name) {
			self::assertArrayHasKey(
				key: 'x-openregister-rbac',
				array: $schemas[$name],
				message: $name . ' must declare an x-openregister-rbac block.'
			);
			self::assertArrayHasKey(key: 'roles', array: $schemas[$name]['x-openregister-rbac']);
		}

	}//end testNewSchemasDeclareRbac()
}//end class
