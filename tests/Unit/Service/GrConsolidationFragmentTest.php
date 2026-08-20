<?php

/**
 * Unit tests for the bookkeeping-gr-consolidation register fragment.
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
 * @spec openspec/changes/bookkeeping-gr-consolidation/specs/bookkeeping-intercompany-posting.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the T5 GR-consolidation inter-company fragment is valid JSON,
 * declares the IntercompanyTransaction + EliminationRule schemas, wires the
 * EliminationGuard immutability transition, and merges additively onto the
 * monolith (ADR-037) without disturbing the pre-existing ConsolidationGroup /
 * ConsolidatedReport schemas.
 */
final class GrConsolidationFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/add-shillinq-bookkeeping-gr-consolidation.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

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
	 * Decode the fragment file.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(expected: JSON_ERROR_NONE, actual: json_last_error(), message: json_last_error_msg());
		self::assertIsArray(actual: $data);
		return $data;
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with a schemas block.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists(filename: $this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey(key: 'schemas', array: $data['components']);
		self::assertArrayHasKey(key: 'objects', array: $data);
	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares both inter-company schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertArrayHasKey(key: 'IntercompanyTransaction', array: $schemas);
		self::assertArrayHasKey(key: 'EliminationRule', array: $schemas);
	}//end testFragmentDeclaresSchemas()

	/**
	 * IntercompanyTransaction declares its eliminationStatus lifecycle and
	 * references the EliminationGuard immutability precondition (REQ-ICP-006).
	 *
	 * @return void
	 */
	public function testIntercompanyTransactionLifecycleWiresGuard(): void {
		$schema = $this->fragment()['components']['schemas']['IntercompanyTransaction'];
		$lifecycle = $schema['x-openregister-lifecycle'];

		self::assertSame(expected: 'eliminationStatus', actual: $lifecycle['field']);
		self::assertSame(expected: 'pending', actual: $lifecycle['initialState']);
		self::assertArrayHasKey(key: 'eliminated', array: $lifecycle['states']);
		self::assertArrayHasKey(key: 'excluded', array: $lifecycle['states']);

		foreach (['eliminate', 'exclude', 'restore', 'reinstate'] as $transition) {
			self::assertArrayHasKey(
				key: $transition,
				array: $lifecycle['transitions'],
				message: "missing transition $transition"
			);
			self::assertSame(
				expected: 'OCA\\Shillinq\\Lifecycle\\EliminationGuard::canChangeEliminationStatus',
				actual: $lifecycle['transitions'][$transition]['requires'],
				message: "$transition must be guarded by EliminationGuard (REQ-ICP-006)"
			);
		}
	}//end testIntercompanyTransactionLifecycleWiresGuard()

	/**
	 * Both schemas declare an RBAC block scoping write to controller/bookkeeper
	 * and read-only to auditor (ADR-005 / consolidation-officer surface).
	 *
	 * @return void
	 */
	public function testSchemasDeclareRbac(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['IntercompanyTransaction', 'EliminationRule'] as $name) {
			$roles = $schemas[$name]['x-openregister-rbac']['roles'];
			self::assertArrayHasKey(key: 'controller', array: $roles, message: "$name must grant controller");
			self::assertArrayHasKey(key: 'bookkeeper', array: $roles, message: "$name must grant bookkeeper");
			self::assertArrayHasKey(key: 'auditor', array: $roles, message: "$name must grant auditor");
			self::assertSame(
				expected: ['read'],
				actual: $roles['auditor']['permissions'],
				message: "$name auditor must be read-only"
			);
		}
	}//end testSchemasDeclareRbac()

	/**
	 * Seed objects use the @self envelope, the shillinq register, and unique slugs.
	 *
	 * @return void
	 */
	public function testSeedObjectsAreWellFormed(): void {
		$objects = $this->fragment()['objects'];
		self::assertNotEmpty(actual: $objects);

		$slugs = [];
		foreach ($objects as $object) {
			self::assertArrayHasKey(key: '@self', array: $object);
			self::assertSame(expected: 'shillinq', actual: $object['@self']['register']);
			self::assertContains(
				needle: $object['@self']['schema'],
				haystack: ['IntercompanyTransaction', 'EliminationRule'],
				message: 'Seed objects must target the fragment schemas only'
			);
			$slug = $object['@self']['slug'];
			self::assertNotContains(needle: $slug, haystack: $slugs, message: "Duplicate seed slug $slug");
			$slugs[] = $slug;
		}
	}//end testSeedObjectsAreWellFormed()

	/**
	 * Merging the fragment onto the monolith adds the two schemas without
	 * dropping the pre-existing ConsolidationGroup / ConsolidatedReport schemas.
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		// Pre-existing consolidation schemas are present before the merge.
		self::assertArrayHasKey(key: 'ConsolidationGroup', array: $base['components']['schemas']);
		self::assertArrayHasKey(key: 'ConsolidatedReport', array: $base['components']['schemas']);
		$objectsBefore = count(($base['objects'] ?? []));

		$merged = $this->merge(base: $base, overlay: $frag);
		$schemas = $merged['components']['schemas'];

		// New schemas present.
		self::assertArrayHasKey(key: 'IntercompanyTransaction', array: $schemas);
		self::assertArrayHasKey(key: 'EliminationRule', array: $schemas);

		// Pre-existing consolidation schemas survive the merge.
		self::assertArrayHasKey(key: 'ConsolidationGroup', array: $schemas);
		self::assertArrayHasKey(key: 'ConsolidatedReport', array: $schemas);

		// Seed objects are concatenated, not replaced.
		self::assertGreaterThan(
			expected: $objectsBefore,
			actual: count($merged['objects']),
			message: 'Fragment objects must be appended to the monolith objects list'
		);
	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
