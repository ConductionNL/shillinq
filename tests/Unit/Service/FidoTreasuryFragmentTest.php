<?php

/**
 * Unit tests for the bookkeeping-wet-fido-treasury register fragment.
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
 * @spec openspec/changes/bookkeeping-wet-fido-treasury/specs/bookkeeping-wet-fido-treasury/spec.md
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
 * Verifies the Wet Fido / Treasurystatuut fragment is valid JSON, declares the
 * eight treasury schemas with their declarative lifecycle / aggregation metadata,
 * references the FidoTreasuryGuard from the enforcement transitions (ADR-031),
 * merges additively onto the monolith (ADR-037), and ships seed objects.
 */
final class FidoTreasuryFragmentTest extends TestCase {
	// PHPUnit assertions take positional ($actual, $expected, $message) arguments;
	// the custom named-parameter sniff does not apply to them.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-wet-fido-treasury.json';

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
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the eight new treasury schemas (REQ-FDO-001..007).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresEightSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'Treasurystatuut',
			'KasgeldLimiet',
			'RenteRisicoNorm',
			'SchatkistbankierenSaldo',
			'Lening',
			'Derivaat',
			'QuartaalrapportageFido',
			'TreasuryParagraaf',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
		}

	}//end testFragmentDeclaresEightSchemas()

	/**
	 * State-bearing schemas declare an x-openregister-lifecycle on `status`
	 * (REQ-FDO-001/004/006).
	 *
	 * @return void
	 */
	public function testStateBearingSchemasDeclareLifecycle(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = ['Treasurystatuut', 'Lening', 'Derivaat', 'QuartaalrapportageFido'];
		foreach ($expected as $name) {
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
			self::assertSame('status', $schemas[$name]['x-openregister-lifecycle']['field']);
		}

	}//end testStateBearingSchemasDeclareLifecycle()

	/**
	 * The limiet logic is declarative: KasgeldLimiet rolling-3-month average and
	 * RenteRisicoNorm 4-year projection are aggregations (ADR-031).
	 *
	 * @return void
	 */
	public function testLimietLogicIsDeclarative(): void {
		$schemas = $this->fragment()['components']['schemas'];

		self::assertArrayHasKey('x-openregister-aggregations', $schemas['KasgeldLimiet']);
		self::assertArrayHasKey('kasgeldRolling3Month', $schemas['KasgeldLimiet']['x-openregister-aggregations']);

		self::assertArrayHasKey('x-openregister-aggregations', $schemas['RenteRisicoNorm']);
		self::assertArrayHasKey('renteRisico4YearProjection', $schemas['RenteRisicoNorm']['x-openregister-aggregations']);

	}//end testLimietLogicIsDeclarative()

	/**
	 * Enforcement transitions reference the FidoTreasuryGuard where a cross-field
	 * precondition is required (ADR-031 exception path).
	 *
	 * @return void
	 */
	public function testLifecycleTransitionsReferenceGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$recordLening = $schemas['Lening']['x-openregister-lifecycle']['transitions']['record'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\FidoTreasuryGuard::canRecordLening',
			$recordLening['requires']
		);

		$recordDerivaat = $schemas['Derivaat']['x-openregister-lifecycle']['transitions']['record'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\FidoTreasuryGuard::canRecordDerivaat',
			$recordDerivaat['requires']
		);

		$signRapportage = $schemas['QuartaalrapportageFido']['x-openregister-lifecycle']['transitions']['sign'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\FidoTreasuryGuard::canSubmitRapportage',
			$signRapportage['requires']
		);

	}//end testLifecycleTransitionsReferenceGuard()

	/**
	 * Every guard method referenced from the fragment exists on the guard class
	 * (no dangling lifecycle reference, ADR-031).
	 *
	 * @return void
	 */
	public function testReferencedGuardMethodsExist(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$referenced = [];
		foreach ($schemas as $schema) {
			$transitions = $schema['x-openregister-lifecycle']['transitions'] ?? [];
			foreach ($transitions as $transition) {
				if (isset($transition['requires']) === true) {
					$referenced[] = $transition['requires'];
				}
			}
		}

		self::assertNotEmpty($referenced);
		foreach (array_unique($referenced) as $reference) {
			[$class, $method] = explode('::', $reference);
			self::assertTrue(method_exists($class, $method), "Referenced guard $reference must exist");
		}

	}//end testReferencedGuardMethodsExist()

	/**
	 * Merging the fragment onto the monolith adds the treasury schemas without
	 * dropping any pre-existing schema (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$beforeSchemas = array_keys($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('Treasurystatuut', $schemas);
		self::assertArrayHasKey('Lening', $schemas);
		self::assertArrayHasKey('Derivaat', $schemas);

		// No pre-existing schema dropped by the merge.
		foreach ($beforeSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "Monolith schema $name must survive the merge");
		}

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas in the shillinq register
	 * (REQ-FDO seed-data pattern).
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetDeclaredSchemas(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			$schema = $object['@self']['schema'];
			self::assertArrayHasKey($schema, $schemas, "Seed object targets undeclared schema $schema");
		}

	}//end testSeedObjectsTargetDeclaredSchemas()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
